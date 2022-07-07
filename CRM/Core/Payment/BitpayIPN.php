<?php
/*
 * @file
 * Handle Bitpay Webhooks for recurring payments.
 */

use Civi\Api4\PaymentprocessorWebhook;
use CRM_Bitpay_ExtensionUtil as E;

class CRM_Core_Payment_BitpayIPN {

  use CRM_Core_Payment_MJWIPNTrait;

  /**
   * @var CRM_Bitpay_Client The Bitpay client object
   */
  private $client = NULL;

  /**
   * @var \Bitpay\Invoice
   */
  private $invoice = NULL;

  /**
   * CRM_Core_Payment_BitpayIPN constructor.
   *
   * @param array $ipnData
   * @param bool $verify
   *
   * @throws \CRM_Core_Exception
   */
  public function __construct(?CRM_Core_Payment_Bitpay $paymentObject = NULL) {
    if ($paymentObject !== NULL && !($paymentObject instanceof CRM_Core_Payment_Bitpay)) {
      // This would be a coding error.
      throw new Exception(__CLASS__ . " constructor requires CRM_Core_Payment_Bitpay object (or NULL for legacy use).");
    }
    $this->_paymentProcessor = $paymentObject;
  }

  /**
   * When CiviCRM receives a webhook call this method (via handlePaymentNotification()).
   * This checks the webhook and either queues or triggers processing (depending on existing webhooks in queue)
   *
   * @return bool
   */
  public function onReceiveWebhook(): bool {
    $event = $this->getData();
    \Civi::log('bitpay')->debug('event: ' . print_r($event, TRUE));

    // Bitpay sends a lot of duplicate webhooks.
    // We only need to record / process once
    $paymentProcessorWebhook = PaymentprocessorWebhook::get(FALSE)
      ->addWhere('payment_processor_id', '=', $this->_paymentProcessor->getID())
      ->addWhere('trigger', '=', $event->status)
      ->addWhere('identifier', '=', $event->orderId)
      ->addWhere('event_id', '=', $event->id)
      ->execute()
      ->first();
    if (!empty($paymentProcessorWebhook)) {
      \Civi::log('bitpay')->info("Duplicate webhook ignored: {$event->id}.{$event->status}");
      return TRUE;
    }

    // Add webhook to queue
    PaymentprocessorWebhook::create(FALSE)
      ->addValue('payment_processor_id', $this->getPaymentProcessor()->getID())
      ->addValue('trigger', $event->status)
      ->addValue('identifier', $event->orderId)
      ->addValue('event_id', $event->id)
      ->addValue('data', json_encode($event))
      ->execute()
      ->first();

    // Process the webhook
    return $this->processQueuedWebhookEvent($this->getData());
  }

  /**
   * Process a single queued event and update it.
   *
   * Returns TRUE/FALSE for success.
   */
  public function processQueuedWebhookEvent(array $webhookEvent) :bool {
    $event = json_decode($webhookEvent['data']);

    $processingResult = $this->processWebhookEvent($event);
    // Update the stored webhook event.
    PaymentprocessorWebhook::update(FALSE)
      ->addWhere('id', '=', $webhookEvent['id'])
      ->addValue('status', $processingResult->ok ? 'success' : 'error')
      ->addValue('message', preg_replace('/^(.{250}).*/su', '$1 ...', $processingResult->message))
      ->addValue('processed_date', 'now')
      ->execute();

    return $processingResult->ok;
  }

  /**
   * Processes a single event, catches exceptions and returns an object.
   *
   * This is called by both queued (processQueuedWebhookEvent) and non-queued (processWebhookEvents) contexts.
   *
   * The result class includes keys:
   * - message string
   * - ok boolean
   * - exception if one occurred.
   */
  public function processWebhookEvent($event) :StdClass {
    $return = (object) ['message' => NULL, 'ok' => FALSE, 'exception' => NULL];

    try {
      // This event ID is only used for logging messages.
      // Get the bitpay client
      $this->client = new CRM_Bitpay_Client($this->getPaymentProcessor()->getPaymentProcessor());
      $client = $this->client->getClient();

      // Now fetch the invoice from BitPay
      // This is needed, since the IPN does not contain any authentication
      $invoice = $client->getInvoice($event->id);
      $this->invoice = $invoice;

      // FIXME: this is for debug, we could remove it...
      $invoiceId = $invoice->getId();
      $invoiceStatus = $invoice->getStatus();
      $invoiceExceptionStatus = $invoice->getExceptionStatus();
      $invoicePrice = $invoice->getPrice();
      \Civi::log('bitpay')
        ->debug("IPN received for BitPay invoice " . $invoiceId . " . Status = " . $invoiceStatus . " / exceptionStatus = " . $invoiceExceptionStatus . "; Price = " . $invoicePrice . "\n");
      \Civi::log('bitpay')->debug("Raw IPN data: " . print_r($event, TRUE));

      $return->ok = $this->main();
      // Add message to log with appropriate value
      $this->setEventID('');
    }
    catch (\Civi\Paymentshared\WebhookEventIgnoredException $e) {
        $return->message = $e->getMessage();
        $return->ok = $e->isOk();
        $return->exception = $e;
        \Civi::log()->debug($return->message, $e->getCode());
    }
    catch (Exception $e) {
        $return->message = "FAILED: Had to skip webhook event. Reason: " . $e->getMessage() . "\n" . $e->getTraceAsString();
        $return->exception = $e;
        \Civi::log('bitpay')->error($return->message);
    }
    return $return;
  }

  /**
   * Main handler for bitpay IPN callback
   *
   * @return bool
   * @throws \CiviCRM_API3_Exception
   */
  public function main(): bool {
    // First we receive an IPN with status "paid" - contribution remains pending - how do we indicate we received "paid"?
    // Then we receive an IPN with status "confirmed" - we set contribution = completed.

    switch ($this->invoice->getStatus()) {
      case \Bitpay\Invoice::STATUS_NEW:
        // We don't do anything in this state
        return TRUE;

      case \Bitpay\Invoice::STATUS_EXPIRED:
        // Mark as cancelled
        $this->updateContributionFailed([
          'contribution_id' => $this->getContribution()['id'],
          'order_reference' => $this->invoice->getId(),
          'cancel_reason' => E::ts('Expired'),
        ]);
        return TRUE;

      case \Bitpay\Invoice::STATUS_INVALID:
        // Mark as failed
        $this->updateContributionFailed([
          'contribution_id' => $this->getContribution()['id'],
          'order_reference' => $this->invoice->getId(),
          'cancel_reason' => E::ts('Invalid'),
        ]);
        return TRUE;

      case \Bitpay\Invoice::STATUS_PAID:
        // Remain in pending status
        // FIXME: Should we record the paid status?
        return TRUE;

      case \Bitpay\Invoice::STATUS_CONFIRMED:
      case \Bitpay\Invoice::STATUS_COMPLETE:
        // See https://bitpay.com/api/#notifications-webhooks-instant-payment-notifications
        // We should listen for both CONFIRMED AND COMPLETE in case one is not sent

        // Mark payment as completed
        $contribution = $this->getContribution();

        $lock = Civi::lockManager()->acquire('data.contribute.contribution.' . $contribution['id']);
        if (!$lock->isAcquired()) {
          \Civi::log()->error('Could not acquire lock to record payment for contribution: ' . $contribution['id']);
        }
        $payment = civicrm_api3('Payment', 'get', [
          'contribution_id' => $contribution['id'],
          'trxn_id' => $this->invoice->getId(),
          'total_amount' => $contribution['total_amount'],
        ]);
        if (empty($payment['count'])) {
          \Civi::log()->debug($this->invoice->getInvoiceTime());
          $this->updateContributionCompleted([
            'contribution_id' => $contribution['id'],
            'trxn_date' => $this->invoice->getInvoiceTime()->format('YmdHis'),
            'order_reference' => $this->invoice->getId(),
            'trxn_id' => $this->invoice->getId(),
            'total_amount' => $contribution['total_amount'],
          ]);
        }
        $lock->release();
        return TRUE;
    }
    return TRUE;
  }

  /**
   * @return array Contribution
   */
  private function getContribution(): array {
    try {
      // First try retrieving by the order ID (invoice ID) that we set in doPayment()
      $contribution = \Civi\Api4\Contribution::get(FALSE)
        ->addWhere('invoice_id', '=', $this->invoice->getOrderId())
        ->addWhere('is_test', 'IN', [TRUE, FALSE])
        ->execute()
        ->first();
      if (!empty($contribution)) {
        return $contribution;
      }
      // Otherwise try by trxn_id (probably won't work because Pending contribution probably didn't get trxn_id set)
      $contribution = \Civi\Api4\Contribution::get(FALSE)
        ->addWhere('trxn_id', '=', $this->invoice->getId())
        ->addWhere('is_test', 'IN', [TRUE, FALSE])
        ->execute()
        ->first();
      if (!empty($contribution)) {
        return $contribution;
      }
    }
    catch (Exception $e) {
      $errorMessage = 'BitpayIPN Exception: Error: ' . $e->getMessage();
      Civi::log('bitpay')->debug($errorMessage);
      http_response_code(400);
      exit(1);
    }
  }

}
