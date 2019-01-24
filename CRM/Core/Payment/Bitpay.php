<?php

/*
 * Payment Processor class for Bitpay
 */

class CRM_Core_Payment_Bitpay extends CRM_Core_Payment {

  public static $className = 'Payment_Bitpay';

  /**
   * We only need one instance of this object. So we use the singleton
   * pattern and cache the instance in this variable
   *
   * @var object
   */
  private static $_singleton = NULL;

  /**
   * Mode of operation: live or test.
   *
   * @var object
   */
  protected $_mode = NULL;

  /**
   * Constructor
   *
   * @param string $mode
   *   The mode of operation: live or test.
   *
   * @return void
   */
  public function __construct($mode, &$paymentProcessor) {
    $this->_mode = $mode;
    $this->_paymentProcessor = $paymentProcessor;
    $this->_processorName = ts('Bitpay');
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
   * Get the currency for the transaction.
   *
   * Handle any inconsistency about how it is passed in here.
   *
   * @param $params
   *
   * @return string
   */
  public function getAmount($params) {
    // TODO: What do we need to return?
    return $params['amount'];
  }

  /**
   * Override CRM_Core_Payment function
   *
   * @return array
   */
  public function getPaymentFormFields() {
    // TODO What form fields do we need?
    return [];
  }

  /**
   * Return an array of all the details about the fields potentially required for payment fields.
   *
   * Only those determined by getPaymentFormFields will actually be assigned to the form
   *
   * @return array
   *   field metadata
   */
  public function getPaymentFormFieldsMetadata() {
    // TODO What form fields do we need?
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
    // TODO: What billing fields do we need?
    $metadata = parent::getBillingAddressFieldsMetadata($billingLocationID);
    if (!$billingLocationID) {
      // Note that although the billing id is passed around the forms the idea that it would be anything other than
      // the result of the function below doesn't seem to have eventuated.
      // So taking this as a param is possibly something to be removed in favour of the standard default.
      $billingLocationID = CRM_Core_BAO_LocationType::getBilling();
    }

    // Stripe does not require the state/county field
    if (!empty($metadata["billing_state_province_id-{$billingLocationID}"]['is_required'])) {
      $metadata["billing_state_province_id-{$billingLocationID}"]['is_required'] = FALSE;
    }

    return $metadata;
  }

  /**
   * Set default values when loading the (payment) form
   *
   * @param \CRM_Core_Form $form
   */
  public function buildForm(&$form) {
    // TODO: What defaults
    // Set default values
    //$form->setDefaults($defaults);
  }

  /**
   * Process payment
   * Submit a payment using Stripe's PHP API:
   * https://stripe.com/docs/api?lang=php
   * Payment processors should set payment_status_id.
   *
   * @param array $params
   *   Assoc array of input parameters for this transaction.
   *
   * @param string $component
   *
   * @return array
   *   Result array
   *
   * @throws \Civi\Payment\Exception\PaymentProcessorException
   */
  public function doPayment(&$params, $component = 'contribute') {
    if (array_key_exists('credit_card_number', $params)) {
      $cc = $params['credit_card_number'];
      if (!empty($cc) && substr($cc, 0, 8) != '00000000') {
        Civi::log()->debug(ts('ALERT! Unmasked credit card received in back end. Please report this error to the site administrator.'));
      }
    }

    $completedStatusId = CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'contribution_status_id', 'Completed');
    $pendingStatusId = CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'contribution_status_id', 'Pending');

    // If we have a $0 amount, skip call to processor and set payment_status to Completed.
    if (empty($params['amount'])) {
      $params['payment_status_id'] = $completedStatusId;
      return $params;
    }

    $this->setAPIParams();

    // Get proper entry URL for returning on error.
    if (!(array_key_exists('qfKey', $params))) {
      // Probably not called from a civicrm form (e.g. webform) -
      // will return error object to original api caller.
      $params['stripe_error_url'] = NULL;
    }
    else {
      $qfKey = $params['qfKey'];
      $parsed_url = parse_url($params['entryURL']);
      $url_path = substr($parsed_url['path'], 1);
      $params['stripe_error_url'] = CRM_Utils_System::url($url_path,
      $parsed_url['query'] . "&_qf_Main_display=1&qfKey={$qfKey}", FALSE, NULL, FALSE);
    }
    $amount = self::getAmount($params);

    // Use Stripe.js instead of raw card details.
    if (!empty($params['stripe_token'])) {
      $card_token = $params['stripe_token'];
    }
    else if(!empty(CRM_Utils_Array::value('stripe_token', $_POST, NULL))) {
      $card_token = CRM_Utils_Array::value('stripe_token', $_POST, NULL);
    }
    else {
      CRM_Core_Error::statusBounce(ts('Unable to complete payment! Please this to the site administrator with a description of what you were trying to do.'));
      Civi::log()->debug('Stripe.js token was not passed!  Report this message to the site administrator. $params: ' . print_r($params, TRUE));
    }

    $contactId = self::getContactId($params);
    $email = self::getBillingEmail($params, $contactId);

    // See if we already have a stripe customer
    $customerParams = [
      'contact_id' => $contactId,
      'card_token' => $card_token,
      'is_live' => $this->_islive,
      'processor_id' => $this->_paymentProcessor['id'],
      'email' => $email,
    ];

    $stripeCustomerId = CRM_Stripe_Customer::find($customerParams);

    // Customer not in civicrm database.  Create a new Customer in Stripe.
    if (!isset($stripeCustomerId)) {
      $stripeCustomer = CRM_Stripe_Customer::create($customerParams, $this);
    }
    else {
      // Customer was found in civicrm database, fetch from Stripe.
      $deleteCustomer = FALSE;
      try {
        $stripeCustomer = \Stripe\Customer::retrieve($stripeCustomerId);
      }
      catch (Exception $e) {
        $err = self::parseStripeException('retrieve_customer', $e, FALSE);
        if (($err['type'] == 'invalid_request_error') && ($err['code'] == 'resource_missing')) {
          $deleteCustomer = TRUE;
        }
        $errorMessage = self::handleErrorNotification($err, $params['stripe_error_url']);
        throw new \Civi\Payment\Exception\PaymentProcessorException('Failed to create Stripe Charge: ' . $errorMessage);
      }

      if ($deleteCustomer || $stripeCustomer->isDeleted()) {
        // Customer doesn't exist, create a new one
        CRM_Stripe_Customer::delete($customerParams);
        try {
          $stripeCustomer = CRM_Stripe_Customer::create($customerParams, $this);
        }
        catch (Exception $e) {
          // We still failed to create a customer
          $errorMessage = self::handleErrorNotification($stripeCustomer, $params['stripe_error_url']);
          throw new \Civi\Payment\Exception\PaymentProcessorException('Failed to create Stripe Customer: ' . $errorMessage);
        }
      }

      $stripeCustomer->card = $card_token;
      try {
        $stripeCustomer->save();
      }
      catch (Exception $e) {
        $err = self::parseStripeException('update_customer', $e, TRUE);
        if (($err['type'] == 'invalid_request_error') && ($err['code'] == 'token_already_used')) {
          // This error is ok, we've already used the token during create_customer
        }
        else {
          $errorMessage = self::handleErrorNotification($err, $params['stripe_error_url']);
          throw new \Civi\Payment\Exception\PaymentProcessorException('Failed to update Stripe Customer: ' . $errorMessage);
        }
      }
    }

    // Prepare the charge array, minus Customer/Card details.
    if (empty($params['description'])) {
      $params['description'] = ts('Backend Stripe contribution');
    }

    // Handle recurring payments in doRecurPayment().
    if (CRM_Utils_Array::value('is_recur', $params) && $params['contributionRecurID']) {
      // We set payment status as pending because the IPN will set it as completed / failed
      $params['payment_status_id'] = $pendingStatusId;
      return $this->doRecurPayment($params, $amount, $stripeCustomer);
    }

    // Stripe charge.
    $stripeChargeParams = [
      'amount' => $amount,
      'currency' => strtolower($params['currencyID']),
      'description' => $params['description'] . ' # Invoice ID: ' . CRM_Utils_Array::value('invoiceID', $params),
    ];

    // Use Stripe Customer if we have a valid one.  Otherwise just use the card.
    if (!empty($stripeCustomer->id)) {
      $stripeChargeParams['customer'] = $stripeCustomer->id;
    }
    else {
      $stripeChargeParams['card'] = $card_token;
    }

    try {
      $stripeCharge = \Stripe\Charge::create($stripeChargeParams);
    }
    catch (Exception $e) {
      $err = self::parseStripeException('charge_create', $e, FALSE);
      if ($e instanceof \Stripe\Error\Card) {
        civicrm_api3('Note', 'create', [
          'entity_id' => $params['contributionID'],
          'contact_id' => self::getContactId($params),
          'subject' => $err['type'],
          'note' => $err['code'],
          'entity_table' => 'civicrm_contribution',
        ]);
      }
      $errorMessage = self::handleErrorNotification($err, $params['stripe_error_url']);
      throw new \Civi\Payment\Exception\PaymentProcessorException('Failed to create Stripe Charge: ' . $errorMessage);
    }

    // Success!  Return some values for CiviCRM.
    $params['trxn_id'] = $stripeCharge->id;
    $params['payment_status_id'] = $completedStatusId;

    // Return fees & net amount for Civi reporting.
    // Uses new Balance Trasaction object.
    try {
      $stripeBalanceTransaction = \Stripe\BalanceTransaction::retrieve($stripeCharge->balance_transaction);
    }
    catch (Exception $e) {
      $err = self::parseStripeException('retrieve_balance_transaction', $e, FALSE);
      $errorMessage = self::handleErrorNotification($err, $params['stripe_error_url']);
      throw new \Civi\Payment\Exception\PaymentProcessorException('Failed to retrieve Stripe Balance Transaction: ' . $errorMessage);
    }
    $params['fee_amount'] = $stripeBalanceTransaction->fee / 100;
    $params['net_amount'] = $stripeBalanceTransaction->net / 100;

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
   * Process incoming notification.
   *
   * @throws \CRM_Core_Exception
   * @throws \CiviCRM_API3_Exception
   */
  public static function handlePaymentNotification() {
    // TODO process IPN
    $data_raw = file_get_contents("php://input");
    $data = json_decode($data_raw);
    //$ipnClass = new CRM_Core_Payment_StripeIPN($data);
    //if ($ipnClass->main()) {
    //  http_response_code(200);
    //}
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
  protected static function getBillingEmail($params, $contactId) {
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
  protected static function getContactId($params) {
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
   * FIXME: Remove and increment min version once https://github.com/civicrm/civicrm-core/pull/13215 is merged.
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

