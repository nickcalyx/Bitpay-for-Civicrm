<?php

use Bitpay\Buyer;
use Bitpay\Invoice;
use Civi\Api4\PaymentprocessorWebhook;
use CRM_Bitpay_ExtensionUtil as E;
use Civi\Payment\PropertyBag;

/*
 * Payment Processor class for Bitpay
 */
class CRM_Core_Payment_Bitpay extends CRM_Core_Payment {

  use CRM_Core_Payment_MJWTrait;

  /**
   * @var CRM_Bitpay_Client The Bitpay client object
   */
  private $client = NULL;

  /**
   * Constructor
   *
   * @param string $mode
   *   The mode of operation: live or test.
   *
   * @return void
   */
  public function __construct($mode, &$paymentProcessor) {
    $this->_paymentProcessor = $paymentProcessor;
    $this->client = new CRM_Bitpay_Client($this->_paymentProcessor);
  }

  /**
   * This function checks to see if we have the right config values.
   *
   * @return null|string
   *   The error message if any.
   */
  public function checkConfig() {
    $error = [];

    if (empty($this->_paymentProcessor['password'])) {
      $error[] = ts('The decryption password has not been set.');
    }

    if (!empty($error)) {
      return implode('<p>', $error);
    }
    else {
      return NULL;
    }
  }

  /**
   * We can use the bitpay processor on the backend
   * @return bool
   */
  public function supportsBackOffice() {
    return FALSE;
  }

  /**
   * We can edit recurring contributions
   * @return bool
   */
  public function supportsEditRecurringContribution() {
    return FALSE;
  }

  /**
   * We can configure a start date
   * @return bool
   */
  public function supportsFutureRecurStartDate() {
    return FALSE;
  }

  /**
   * Override CRM_Core_Payment function
   *
   * @return array
   */
  public function getPaymentFormFields() {
    // Bitpay loads a payment modal via JS, we don't need any payment fields
    return [];
  }

  /**
   * Return an array of all the details about the fields potentially required for payment fields.
   * Only those determined by getPaymentFormFields will actually be assigned to the form
   *
   * @return array
   *   field metadata
   */
  public function getPaymentFormFieldsMetadata() {
    // Bitpay loads a payment modal via JS, we don't need any payment fields
    return [];
  }

  /**
   * Get form metadata for billing address fields.
   *
   * @param int $billingLocationID
   *
   * @return array
   *    Array of metadata for address fields.
   */
  public function getBillingAddressFieldsMetadata($billingLocationID = NULL) {
    // Bitpay loads a payment modal via JS, we don't need any billing fields - could optionally add some though?
    return [];
  }

  public function getBillingAddressFields($billingLocationID = NULL) {
    // Bitpay loads a payment modal via JS, we don't need any billing fields - could optionally add some though?
    return [];
  }

  /**
   * Process payment
   * Submit a payment using Bitpay's PHP API:
   * https://github.com/bitpay/php-bitpay-client
   *
   * Payment processors should set payment_status_id and trxn_id (if available).
   *
   * @param array|PropertyBag $paymentParams
   *   Assoc array of input parameters for this transaction.
   *
   * @param string $component
   *
   * @return array
   *   Result array
   *
   * @throws \CRM_Core_Exception
   * @throws \CRM_Core_Exception
   */
  public function doPayment(&$paymentParams, $component = 'contribute') {
    // Get the bitpay client object
    $client = $this->client->getClient();

    /* @var \Civi\Payment\PropertyBag $propertyBag */
    $propertyBag = PropertyBag::cast($paymentParams);

    $zeroAmountPayment = $this->processZeroAmountPayment($propertyBag);
    if ($zeroAmountPayment) {
      return $zeroAmountPayment;
    }
    $propertyBag = $this->beginDoPayment($propertyBag);

    /**
     * This is where we will start to create an Invoice object, make sure to check
     * the InvoiceInterface for methods that you can use.
     */
    $invoice = new Invoice();
    $buyer = new Buyer();
    $email = $this->getBillingEmail($paymentParams, $propertyBag->getContactID());
    $buyer->setEmail($email);
    // Add the buyers info to invoice
    $invoice->setBuyer($buyer);
    /**
     * Item is used to keep track of a few things
     */
    $item = new \Bitpay\Item();
    $item
      ->setCode($paymentParams['item_name'] ?? NULL)
      ->setDescription($paymentParams['description'])
      ->setPrice($this->getAmount($paymentParams));
    $invoice->setItem($item);
    /**
     * BitPay supports multiple different currencies. Most shopping cart applications
     * and applications in general have defined set of currencies that can be used.
     * Setting this to one of the supported currencies will create an invoice using
     * the exchange rate for that currency.
     *
     * @see https://test.bitpay.com/bitcoin-exchange-rates for supported currencies
     */
    $invoice->setCurrency(new \Bitpay\Currency($propertyBag->getCurrency()));
    // Configure the rest of the invoice
    $invoice
      ->setOrderId($propertyBag->getInvoiceID())
      // You will receive IPN's at this URL, should be HTTPS for security purposes!
      ->setNotificationUrl($this->getNotifyUrl());
    /**
     * Updates invoice with new information such as the invoice id and the URL where
     * a customer can view the invoice.
     */
    try {
      $client->createInvoice($invoice);
    } catch (\Exception $e) {
      $msg = "Bitpay doPayment Exception occured: " . $e->getMessage().PHP_EOL;
      $request  = $client->getRequest();
      $response = $client->getResponse();
      $msg .= (string) $request.PHP_EOL.PHP_EOL.PHP_EOL;
      $msg .= (string) $response.PHP_EOL.PHP_EOL;
      \Civi::log('bitpay')->debug($msg);
      Throw new CRM_Core_Exception($msg);
    }
    \Civi::log('bitpay')->debug('invoice created: ' . $invoice->getId(). '" url: ' . $invoice->getUrl() . ' Verbose details: ' . print_r($invoice, TRUE));

    // Success!
    // For contribution workflow we have a contributionId so we can set parameters directly.
    // For events/membership workflow we have to return the parameters and they might get set...
    $this->setPaymentProcessorTrxnID($invoice->getId());
    $returnParams = [];
    // We always return "Pending" because payment is Completed later by webhook.
    $returnParams = $this->setStatusPaymentPending($returnParams);

    // For a single charge there is no invoice, we set OrderID to the TrxnID.
    if (empty($this->getPaymentProcessorOrderID())) {
      $this->setPaymentProcessorOrderID($this->getPaymentProcessorTrxnID());
    }

    // For contribution workflow we have a contributionId so we can set parameters directly.
    // For events/membership workflow we have to return the parameters and they might get set...
    return $this->endDoPayment($returnParams);
  }

  /**
   * Default payment instrument validation.
   *
   * Implement the usual Luhn algorithm via a static function in the CRM_Core_Payment_Form if it's a credit card
   * Not a static function, because I need to check for payment_type.
   *
   * @param array $values
   * @param array $errors
   */
  public function validatePaymentInstrument($values, &$errors) {
    // Use $_POST here and not $values - for webform fields are not set in $values, but are in $_POST
    CRM_Core_Form::validateMandatoryFields($this->getMandatoryFields(), $_POST, $errors);
  }

  /**
   * Process incoming payment notification (IPN).
   * https://bitpay.com/docs/invoice-callbacks
   *
   * @throws \CRM_Core_Exception
   * @throws \CRM_Core_Exception
   */
  public function handlePaymentNotification() {
    // Set default http response to 200
    http_response_code(200);
    $dataRaw = file_get_contents("php://input");
    $data = json_decode($dataRaw);
    $ipnClass = new CRM_Core_Payment_BitpayIPN($this);
    $ipnClass->setData($data);
    if (!$ipnClass->onReceiveWebhook()) {
      http_response_code(400);
    }
  }

  /**
   * Called by mjwshared extension's queue processor api3 Job.process_paymentprocessor_webhooks
   *
   * The array parameter contains a row of PaymentprocessorWebhook data, which represents a single GC event
   *
   * Return TRUE for success, FALSE if there's a problem
   */
  public function processWebhookEvent(array $webhookEvent) :bool {
    // If there is another copy of this event in the table with a lower ID, then
    // this is a duplicate that should be ignored. We do not worry if there is one with a higher ID
    // because that means that while there are duplicates, we'll only process the one with the lowest ID.
    $duplicates = PaymentprocessorWebhook::get(FALSE)
      ->selectRowCount()
      ->addWhere('event_id', '=', $webhookEvent['event_id'])
      ->addWhere('trigger', '=', $webhookEvent['trigger'])
      ->addWhere('id', '<', $webhookEvent['id'])
      ->execute()->count();
    if ($duplicates) {
      PaymentprocessorWebhook::update(FALSE)
        ->addWhere('id', '=', $webhookEvent['id'])
        ->addValue('status', 'error')
        ->addValue('message', 'Refusing to process this event as it is a duplicate.')
        ->execute();
      return FALSE;
    }

    $handler = new CRM_Core_Payment_BitpayIPN($this);
    $result = $handler->processQueuedWebhookEvent($webhookEvent);

    return $result;
  }

}

