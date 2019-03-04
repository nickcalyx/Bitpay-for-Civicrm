<?php
/*
 * @file
 * Handle Bitpay Webhooks for recurring payments.
 */

class CRM_Core_Payment_BitpayIPN extends CRM_Core_Payment_BaseIPN {

  use CRM_Core_Payment_BitpayIPNTrait;

  /**
   * @var CRM_Bitpay_Client The Bitpay client object
   */
  private $_client = NULL;

  /**
   * @var \Bitpay\Invoice
   */
  private $_invoice = NULL;

  /**
   * CRM_Core_Payment_BitpayIPN constructor.
   *
   * @param array $ipnData
   * @param bool $verify
   *
   * @throws \CRM_Core_Exception
   */
  public function __construct($ipnData) {
    $this->setInputParameters($ipnData);
    parent::__construct();
  }

  public function setInputParameters($ipnData) {
    // Get the payment processor
    $this->getPaymentProcessor();

    // Get the bitpay client
    $this->_client = new CRM_Bitpay_Client($this->_paymentProcessor);
    $client = $this->_client->getClient();

    // Now fetch the invoice from BitPay
    // This is needed, since the IPN does not contain any authentication
    $invoice = $client->getInvoice($ipnData->id);
    $this->_invoice = $invoice;

    // FIXME: this is for debug, we could remove it...
    $invoiceId = $invoice->getId();
    $invoiceStatus = $invoice->getStatus();
    $invoiceExceptionStatus = $invoice->getExceptionStatus();
    $invoicePrice = $invoice->getPrice();
    Civi::log()->debug("IPN received for BitPay invoice ".$invoiceId." . Status = " .$invoiceStatus." / exceptionStatus = " . $invoiceExceptionStatus." Price = ". $invoicePrice. "\n");
    Civi::log()->debug("Raw IPN data: ". print_r($ipnData, TRUE));
  }

  /**
   * Main handler for bitpay IPN callback
   *
   * @return bool
   * @throws \CiviCRM_API3_Exception
   */
  public function main() {
    // First we receive an IPN with status "paid" - contribution remains pending - how do we indicate we received "paid"?
    // Then we receive an IPN with status "confirmed" - we set contribution = completed.

    switch ($this->_invoice->getStatus()) {
      case \Bitpay\Invoice::STATUS_NEW:
        // We don't do anything in this state
        return TRUE;

      case \Bitpay\Invoice::STATUS_EXPIRED:
        // Mark as cancelled
        $this->canceltransaction(['id' => $this->getContributionId(), $this->_paymentProcessor['id']]);
        break;

      case \Bitpay\Invoice::STATUS_INVALID:
        // Mark as failed
        $this->failtransaction(['id' => $this->getContributionId(), $this->_paymentProcessor['id']]);
        break;

      case \Bitpay\Invoice::STATUS_PAID:
        // Remain in pending status
        // FIXME: Should we record the paid status?
        return TRUE;

      case \Bitpay\Invoice::STATUS_CONFIRMED:
        // Mark payment as completed
        civicrm_api3('Contribution', 'completetransaction', [
          'id' => $this->getContributionId(),
          'trxn_date' => $this::$_now,
        ]);
        break;

      case \Bitpay\Invoice::STATUS_COMPLETE:
        // Don't do anything, confirmed is ok.
        return TRUE;

    }
  }

  /**
   * @return int Contribution ID
   */
  private function getContributionId() {
    try {
      return civicrm_api3('Contribution', 'getvalue', [
        'return' => "id",
        'trxn_id' => $this->_invoice->getId(),
        'contribution_test' => $this->_paymentProcessor['is_test'],
      ]);
    }
    catch (Exception $e) {
      $this->exception('Could not find contribution ID for invoice ' . $this->_invoice->getId());
    }
  }

  private function exception($message) {
    $errorMessage = 'BitpayIPN Exception: Error: ' . $message;
    Civi::log()->debug($errorMessage);
    http_response_code(400);
    exit(1);
  }

}
