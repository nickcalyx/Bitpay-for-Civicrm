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
    PaymentprocessorWebhook::create(FALSE)
      ->addValue('payment_processor_id', $this->_paymentProcessor->getID())
      ->addValue('trigger', $this->invoice->getStatus())
      ->addValue('identifier', $this->invoice->getOrderId())
      ->addValue('event_id', $this->invoice->getId())
      ->addValue('data', $this->getData())
      ->execute();

    $processingResult = $this->processWebhookEvent($this->getData());
    // Update the stored webhook event.
    PaymentprocessorWebhook::update(FALSE)
      ->setCheckPermissions(FALSE) // Remove line when minversion>=5.29
      ->addWhere('id', '=', $this->invoice->getId())
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
    // This event ID is only used for logging messages.
    // Get the bitpay client
    $this->client = new CRM_Bitpay_Client($this->getPaymentProcessor());
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
    \Civi::log('bitpay')->debug("IPN received for BitPay invoice ".$invoiceId." . Status = " .$invoiceStatus." / exceptionStatus = " . $invoiceExceptionStatus." Price = ". $invoicePrice. "\n");
    \Civi::log('bitpay')->debug("Raw IPN data: ". print_r($event, TRUE));

    try {
      $this->main();
      $method = 'do' . ucfirst($event->resource_type) . ucfirst($event->action);
      $return->message = $this->$method($event);
      $return->ok = TRUE;
      \Civi::log('bitpay')->error($return->message);
    }
    catch (Exception $e) {
      $return->message = "FAILED: Had to skip webhook event. Reason: " . $e->getMessage(). "\n" . $e->getTraceAsString();
      $return->exception = $e;
      \Civi::log('bitpay')->error($return->message);
    }
    // Add message to log with appropriate value
    $this->setEventID('');
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
          'contribution_id' => $this->getContributionId(),
          'order_reference' => $this->invoice->getId(),
          'cancel_reason' => E::ts('Expired'),
        ]);
        return TRUE;

      case \Bitpay\Invoice::STATUS_INVALID:
        // Mark as failed
        $this->updateContributionFailed([
          'contribution_id' => $this->getContributionId(),
          'order_reference' => $this->invoice->getId(),
          'cancel_reason' => E::ts('Invalid'),
        ]);
        return TRUE;

      case \Bitpay\Invoice::STATUS_PAID:
        // Remain in pending status
        // FIXME: Should we record the paid status?
        return TRUE;

      case \Bitpay\Invoice::STATUS_CONFIRMED:
        // Mark payment as completed

        $this->updateContributionCompleted([
          'contribution_id' => $this->getContributionId(),
          'trxn_date' => date('YmdHis'),
          'order_reference' => $this->invoice->getId(),
          'trxn_id' => $this->invoice->getId(),
          'total_amount' => $this->invoice->getAmountPaid(),
        ]);
        return TRUE;

      case \Bitpay\Invoice::STATUS_COMPLETE:
        // Don't do anything, confirmed is ok.
        return TRUE;
    }
    return TRUE;
  }

  /**
   * @return int Contribution ID
   */
  private function getContributionId(): int {
    try {
      return (int) civicrm_api3('Contribution', 'getvalue', [
        'return' => 'id',
        'trxn_id' => $this->invoice->getId(),
        'contribution_test' => $this->_paymentProcessor['is_test'],
      ]);
    }
    catch (Exception $e) {
      $errorMessage = 'BitpayIPN Exception: Error: ' . $e->getMessage();
      Civi::log('bitpay')->debug($errorMessage);
      http_response_code(400);
      exit(1);
    }
  }

}
