<?php

/*
 * Payment Processor class for Bitpay
 */

class CRM_Core_Payment_Bitpay extends CRM_Core_Payment {

  public static $className = 'Payment_Bitpay';

  /**
   * @var CRM_Bitpay_Client The Bitpay client object
   */
  private $_client = NULL;

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
    $this->_processorName = ts('Bitpay');
    $this->_client = new CRM_Bitpay_Client($this->_paymentProcessor);
  }

  /**
   * This function checks to see if we have the right config values.
   *
   * @return null|string
   *   The error message if any.
   */
  public function checkConfig() {
    $error = array();

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
   * @param array $params
   *   Assoc array of input parameters for this transaction.
   *
   * @param string $component
   *
   * @return array
   *   Result array
   *
   * @throws \CRM_Core_Exception
   * @throws \CiviCRM_API3_Exception
   */
  public function doPayment(&$params, $component = 'contribute') {
    // Get the bitpay client object
    $client = $this->_client->getClient();

    /**
     * This is where we will start to create an Invoice object, make sure to check
     * the InvoiceInterface for methods that you can use.
     */
    $invoice = new \Bitpay\Invoice();
    $buyer = new \Bitpay\Buyer();
    $buyer->setEmail($this->getBillingEmail($params, $this->getContactId($params)));
    // Add the buyers info to invoice
    $invoice->setBuyer($buyer);
    /**
     * Item is used to keep track of a few things
     */
    $item = new \Bitpay\Item();
    $item
      ->setCode(CRM_Utils_Array::value('item_name', $params))
      ->setDescription($params['description'])
      ->setPrice($this->getAmount($params));
    $invoice->setItem($item);
    /**
     * BitPay supports multiple different currencies. Most shopping cart applications
     * and applications in general have defined set of currencies that can be used.
     * Setting this to one of the supported currencies will create an invoice using
     * the exchange rate for that currency.
     *
     * @see https://test.bitpay.com/bitcoin-exchange-rates for supported currencies
     */
    $invoice->setCurrency(new \Bitpay\Currency($this->getCurrency($params)));
    // Configure the rest of the invoice
    $invoice
      ->setOrderId($params['contributionID'])
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
      Civi::log()->debug($msg);
      Throw new CRM_Core_Exception($msg);
    }
    Civi::log()->debug('invoice created: ' . $invoice->getId(). '" url: ' . $invoice->getUrl() . ' Verbose details: ' . print_r($invoice, TRUE));

    $params['trxn_id'] = $invoice->getId();
    $pendingStatusId = CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'contribution_status_id', 'Pending');
    $params['payment_status_id'] = $pendingStatusId;
    return $params;
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
   * @throws \CiviCRM_API3_Exception
   */
  public static function handlePaymentNotification() {
    $dataRaw = file_get_contents("php://input");
    $data = json_decode($dataRaw);
    $ipnClass = new CRM_Core_Payment_BitpayIPN($data);
    if ($ipnClass->main()) {
      //Respond with HTTP 200, so BitPay knows the IPN has been received correctly
      http_response_code(200);
    }
  }


  /*******************************************************************
   * THE FOLLOWING FUNCTIONS SHOULD BE REMOVED ONCE THEY ARE IN CORE
   * getBillingEmail
   * getContactId
   ******************************************************************/

  /**
   * Get the billing email address
   *
   * @param array $params
   * @param int $contactId
   *
   * @return string|NULL
   */
  protected function getBillingEmail($params, $contactId) {
    $billingLocationId = CRM_Core_BAO_LocationType::getBilling();

    $emailAddress = CRM_Utils_Array::value("email-{$billingLocationId}", $params,
      CRM_Utils_Array::value('email-Primary', $params,
        CRM_Utils_Array::value('email', $params, NULL)));

    if (empty($emailAddress) && !empty($contactId)) {
      // Try and retrieve an email address from Contact ID
      try {
        $emailAddress = civicrm_api3('Email', 'getvalue', array(
          'contact_id' => $contactId,
          'return' => ['email'],
        ));
      }
      catch (CiviCRM_API3_Exception $e) {
        return NULL;
      }
    }
    return $emailAddress;
  }

  /**
   * Get the contact id
   *
   * @param array $params
   *
   * @return int ContactID
   */
  protected function getContactId($params) {
    $contactId = CRM_Utils_Array::value('contactID', $params,
      CRM_Utils_Array::value('contact_id', $params,
        CRM_Utils_Array::value('cms_contactID', $params,
          CRM_Utils_Array::value('cid', $params, NULL
          ))));
    if (!empty($contactId)) {
      return $contactId;
    }
    // FIXME: Ref: https://lab.civicrm.org/extensions/stripe/issues/16
    // The problem is that when registering for a paid event, civicrm does not pass in the
    // contact id to the payment processor (civicrm version 5.3). So, I had to patch your
    // getContactId to check the session for a contact id. It's a hack and probably should be fixed in core.
    // The code below is exactly what CiviEvent does, but does not pass it through to the next function.
    $session = CRM_Core_Session::singleton();
    return $session->get('transaction.userID', NULL);
  }

  /**
   * Get url for users to manage this recurring contribution for this processor.
   * FIXME: In 5.10.0 release - Merged via https://github.com/civicrm/civicrm-core/pull/13215
   *
   * @param int $entityID
   * @param null $entity
   * @param string $action
   *
   * @return string
   */
  public function subscriptionURL($entityID = NULL, $entity = NULL, $action = 'cancel') {
    // Set URL
    switch ($action) {
      case 'cancel':
        if (!$this->supports('cancelRecurring')) {
          return NULL;
        }
        $url = 'civicrm/contribute/unsubscribe';
        break;

      case 'billing':
        //in notify mode don't return the update billing url
        if (!$this->supports('updateSubscriptionBillingInfo')) {
          return NULL;
        }
        $url = 'civicrm/contribute/updatebilling';
        break;

      case 'update':
        if (!$this->supports('changeSubscriptionAmount') && !$this->supports('editRecurringContribution')) {
          return NULL;
        }
        $url = 'civicrm/contribute/updaterecur';
        break;
    }

    $userId = CRM_Core_Session::singleton()->get('userID');
    $contactID = 0;
    $checksumValue = '';
    $entityArg = '';

    // Find related Contact
    if ($entityID) {
      switch ($entity) {
        case 'membership':
          $contactID = CRM_Core_DAO::getFieldValue("CRM_Member_DAO_Membership", $entityID, "contact_id");
          $entityArg = 'mid';
          break;

        case 'contribution':
          $contactID = CRM_Core_DAO::getFieldValue("CRM_Contribute_DAO_Contribution", $entityID, "contact_id");
          $entityArg = 'coid';
          break;

        case 'recur':
          $sql = "
    SELECT DISTINCT con.contact_id
      FROM civicrm_contribution_recur rec
INNER JOIN civicrm_contribution con ON ( con.contribution_recur_id = rec.id )
     WHERE rec.id = %1";
          $contactID = CRM_Core_DAO::singleValueQuery($sql, array(1 => array($entityID, 'Integer')));
          $entityArg = 'crid';
          break;
      }
    }

    // Add entity arguments
    if ($entityArg != '') {
      // Add checksum argument
      if ($contactID != 0 && $userId != $contactID) {
        $checksumValue = '&cs=' . CRM_Contact_BAO_Contact_Utils::generateChecksum($contactID, NULL, 'inf');
      }
      return CRM_Utils_System::url($url, "reset=1&{$entityArg}={$entityID}{$checksumValue}", TRUE, NULL, FALSE, TRUE);
    }

    // Else login URL
    if ($this->supports('accountLoginURL')) {
      return $this->accountLoginURL();
    }

    // Else default
    return isset($this->_paymentProcessor['url_recur']) ? $this->_paymentProcessor['url_recur'] : '';
  }

}

