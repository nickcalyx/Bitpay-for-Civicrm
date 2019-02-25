<?php
/*
 * @file
 * Handle Stripe Webhooks for recurring payments.
 */

class CRM_Core_Payment_BitpayIPN extends CRM_Core_Payment_BaseIPN {

  /**
   * @var array Payment processor
   */
  private $_paymentProcessor;

  /**
   * @var CRM_Bitpay_Client The Bitpay client object
   */
  private $_client = NULL;

  /**
   * @var \Bitpay\Invoice
   */
  private $_invoice = NULL;

  /**
   * CRM_Core_Payment_StripeIPN constructor.
   *
   * @param $ipnData
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

    // TODO: this is for debug, we could remove it...
    $invoiceId = $invoice->getId();
    $invoiceStatus = $invoice->getStatus();
    $invoiceExceptionStatus = $invoice->getExceptionStatus();
    $invoicePrice = $invoice->getPrice();
    Civi::log()->debug("IPN received for BitPay invoice ".$invoiceId." . Status = " .$invoiceStatus." / exceptionStatus = " . $invoiceExceptionStatus." Price = ". $invoicePrice. "\n");
    Civi::log()->debug("Raw IPN data: ". print_r($ipnData, TRUE));
  }

  /**
   * @throws \CRM_Core_Exception
   * @throws \CiviCRM_API3_Exception
   */
  public function main() {
    // First we receive an IPN with status "paid" - contribution remains pending - how do we indicate we received "paid"?
    // Then we receive an IPN with status "confirmed" - we set contribution = completed.

    $invoiceStatus = $this->_invoice->getStatus();

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

  /*******************************************************************
   * THE FOLLOWING FUNCTIONS SHOULD BE REMOVED ONCE THEY ARE IN CORE
   * START
   ******************************************************************/

  /**
   * Get the payment processor
   *   The $_GET['processor_id'] value is set by CRM_Core_Payment::handlePaymentMethod.
   */
  protected function getPaymentProcessor() {
    $paymentProcessorId = (int) CRM_Utils_Array::value('processor_id', $_GET);
    if (empty($paymentProcessorId)) {
      $this->exception('Failed to get payment processor id');
    }

    // Get the Stripe secret key.
    try {
      $this->_paymentProcessor = \Civi\Payment\System::singleton()->getById($paymentProcessorId)->getPaymentProcessor();
    }
    catch(Exception $e) {
      $this->exception('Failed to get payment processor');
    }
  }

  /**
   * @param $params [ 'id' -> contribution_id, 'payment_processor_id' -> payment_processor_id]
   *
   * @return bool
   * @throws \API_Exception
   */
  protected function canceltransaction($params) {
    $requiredParams = ['id', 'payment_processor_id'];
    foreach ($requiredParams as $required) {
      if (!isset($params[$required])) {
        $this->exception('canceltransaction: Missing mandatory parameter: ' . $required);
      }
    }

    if (isset($params['payment_processor_id'])) {
      $input['payment_processor_id'] = $params['payment_processor_id'];
    }
    $contribution = new CRM_Contribute_BAO_Contribution();
    $contribution->id = $params['id'];
    if (!$contribution->find(TRUE)) {
      throw new API_Exception('A valid contribution ID is required', 'invalid_data');
    }

    if (!$contribution->loadRelatedObjects($input, $ids, TRUE)) {
      throw new API_Exception('failed to load related objects');
    }

    $input['trxn_id'] = !empty($params['trxn_id']) ? $params['trxn_id'] : $contribution->trxn_id;
    if (!empty($params['fee_amount'])) {
      $input['fee_amount'] = $params['fee_amount'];
    }

    $objects['contribution'] = &$contribution;
    $objects = array_merge($objects, $contribution->_relatedObjects);

    $transaction = new CRM_Core_Transaction();
    return $this->cancelled($objects, $transaction);
  }

  /**
   * @param $params [ 'id' -> contribution_id, 'payment_processor_id' -> payment_processor_id]
   *
   * @return bool
   * @throws \API_Exception
   */
  protected function failtransaction($params) {
    $requiredParams = ['id', 'payment_processor_id'];
    foreach ($requiredParams as $required) {
      if (!isset($params[$required])) {
        $this->exception('failtransaction: Missing mandatory parameter: ' . $required);
      }
    }

    if (isset($params['payment_processor_id'])) {
      $input['payment_processor_id'] = $params['payment_processor_id'];
    }
    $contribution = new CRM_Contribute_BAO_Contribution();
    $contribution->id = $params['id'];
    if (!$contribution->find(TRUE)) {
      throw new API_Exception('A valid contribution ID is required', 'invalid_data');
    }

    if (!$contribution->loadRelatedObjects($input, $ids, TRUE)) {
      throw new API_Exception('failed to load related objects');
    }

    $input['trxn_id'] = !empty($params['trxn_id']) ? $params['trxn_id'] : $contribution->trxn_id;
    if (!empty($params['fee_amount'])) {
      $input['fee_amount'] = $params['fee_amount'];
    }

    $objects['contribution'] = &$contribution;
    $objects = array_merge($objects, $contribution->_relatedObjects);

    $transaction = new CRM_Core_Transaction();
    return $this->failed($objects, $transaction);
  }

  /*******************************************************************
   * THE FOLLOWING FUNCTIONS SHOULD BE REMOVED ONCE THEY ARE IN CORE
   * START
   ******************************************************************/
}
