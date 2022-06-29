<?php

require_once 'bitpay.civix.php';
$autoload = __DIR__ . '/vendor/autoload.php';
if (file_exists($autoload)) {
  require_once $autoload;
}

use CRM_Bitpay_ExtensionUtil as E;

/**
 * Implements hook_civicrm_config().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_config
 */
function bitpay_civicrm_config(&$config) {
  _bitpay_civix_civicrm_config($config);
}

/**
 * Implements hook_civicrm_install().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_install
 */
function bitpay_civicrm_install() {
  _bitpay_civicrm_cleanupOldExtension();
  _bitpay_civix_civicrm_install();
}

/**
 * Implements hook_civicrm_postInstall().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_postInstall
 */
function bitpay_civicrm_postInstall() {
  CRM_Core_Payment_Bitpay::createPaymentInstrument(['name' => 'Bitcoin']);
  _bitpay_civix_civicrm_postInstall();
}

/**
 * Implements hook_civicrm_uninstall().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_uninstall
 */
function bitpay_civicrm_uninstall() {
  _bitpay_civix_civicrm_uninstall();
}

/**
 * Implements hook_civicrm_enable().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_enable
 */
function bitpay_civicrm_enable() {
  _bitpay_civix_civicrm_enable();
}

/**
 * Implements hook_civicrm_disable().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_disable
 */
function bitpay_civicrm_disable() {
  _bitpay_civix_civicrm_disable();
}

/**
 * Implements hook_civicrm_upgrade().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_upgrade
 */
function bitpay_civicrm_upgrade($op, CRM_Queue_Queue $queue = NULL) {
  return _bitpay_civix_civicrm_upgrade($op, $queue);
}

/**
 * Implements hook_civicrm_entityTypes().
 *
 * Declare entity types provided by this module.
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_entityTypes
 */
function bitpay_civicrm_entityTypes(&$entityTypes) {
  _bitpay_civix_civicrm_entityTypes($entityTypes);
}

/**
 * Add {payment_library}.js to forms, for payment processor handling
 * hook_civicrm_alterContent is not called for all forms (eg. CRM_Contribute_Form_Contribution on backend)
 *
 * @param string $formName
 * @param CRM_Core_Form $form
 */
function bitpay_civicrm_buildForm($formName, &$form) {
  if (!isset($form->_paymentProcessor)) {
    return;
  }
  $paymentProcessor = $form->_paymentProcessor;
  if (empty($paymentProcessor['class_name']) || ($paymentProcessor['class_name'] !== 'Payment_Bitpay')) {
    return;
  }

  switch ($formName) {
    case 'CRM_Event_Form_Registration_Confirm':
    case 'CRM_Contribute_Form_Contribution_Confirm':
      // Confirm Contribution (check details and confirm)
      CRM_Core_Region::instance('contribution-confirm-billing-block')
        ->update('default', ['disabled' => TRUE]);
      CRM_Core_Region::instance('contribution-confirm-billing-block')
        ->add(['template' => 'Bitpaycontribution-confirm-billing-block.tpl']);
      break;

    case 'CRM_Event_Form_Registration_ThankYou':
      $billingBlockRegion = 'event-thankyou-billing-block';
    case 'CRM_Contribute_Form_Contribution_ThankYou':
      if (!isset($billingBlockRegion)) {
        $billingBlockRegion = 'contribution-thankyou-billing-block';
      }
      // Contribution /Event Thankyou form
      // Add the bitpay invoice handling
      $contributionParams = [
        'contact_id' => $form->_contactID,
        'total_amount' => $form->_amount,
        'contribution_test' => '',
        'options' => ['limit' => 1, 'sort' => ['id DESC']],
      ];
      $trxnId = $form->trxnId ?? $form->_trxnId ?? NULL;
      if (empty($trxnId)) {
        $contribution = civicrm_api3('Contribution', 'get', $contributionParams);
        $trxnId = CRM_Utils_Array::first($contribution['values'])['trxn_id'];
        if (empty($trxnId)) {
          \Civi::log('bitpay')->error('bitpay buildForm: Could not find trxnId');
        }
      }
      $form->assign('bitpayTrxnId', $trxnId);
      $form->assign('bitpayTestMode', $paymentProcessor['is_test']);
      CRM_Core_Region::instance($billingBlockRegion)
        ->update('default', ['disabled' => TRUE]);
      CRM_Core_Region::instance($billingBlockRegion)
        ->add(['template' => 'Bitpaycontribution-thankyou-billing-block.tpl']);
      break;
  }
}

/**
 * Cleanup the old uk.co.circleinteractive.payment.bitcoin extension
 */
function _bitpay_civicrm_cleanupOldExtension() {
  // Remove old scheduled job
  try {
    $jobId = civicrm_api3('job', 'getvalue', [
      'api_entity' => 'job',
      'api_action' => 'update_bitpay_invoices',
      'return'     => 'id',
    ]);
    civicrm_api3('job', 'delete', [
      'id' => $jobId,
    ]);
    $successes[] = 'Deleted scheduled job update_bitpay_invoices';
  } catch (CiviCRM_API3_Exception $e) {
    $errors[] = 'Unable to delete scheduled job: ' . $e->getMessage();
  }

  // Uninstall existing bitcoin
  try {
    $bitcoinId = civicrm_api3('PaymentProcessorType', 'getvalue', [
      'class_name' => 'Payment_BitcoinD',
      'return'     => 'id'
    ]);
    civicrm_api3('PaymentProcessorType', 'create', [
      'id' => $bitcoinId,
      'class_name' => 'Payment_BitcoinD_Old',
    ]);
  } catch (CiviCRM_API3_Exception $e) {
    $errors[] = 'Could not get PaymentProcessorType for Payment_BitcoinD - it is not installed';
  }

  // Uninstall existing bitpay
  try {
    $bitPayOldId = civicrm_api3('PaymentProcessorType', 'getvalue', [
      'class_name' => 'Payment_BitPay_Old',
      'return' => 'id'
    ]);
    if (!empty($bitPayOldId)) {
      return;
    }
  } catch (CiviCRM_API3_Exception $e) {
    // That's fine, we haven't already upgraded.
  }

  try {
    $bitPayId = civicrm_api3('PaymentProcessorType', 'getvalue', [
      'class_name' => 'Payment_BitPay',
      'return'     => 'id'
    ]);
    civicrm_api3('PaymentProcessorType', 'create', [
      'id' => $bitPayId,
      'class_name' => 'Payment_BitPay_Old',

    ]);
  } catch (CiviCRM_API3_Exception $e) {
    $errors[] = 'Could not get PaymentProcessorType for Payment_BitPay - it is not installed';
  }

  // Clear caches
  // Access config object.
  $config = CRM_Core_Config::singleton();

  // Clear database cache.
  CRM_Core_Config::clearDBCache();

  // Cleanup the "templates_c" directory.
  $config->cleanup( 1, TRUE );

  // Cleanup the session object.
  $session = CRM_Core_Session::singleton();
  $session->reset( 1 );
}

/**
 * Implements hook_civicrm_check().
 */
function bitpay_civicrm_check(&$messages) {
  $checks = new CRM_Bitpay_Check($messages);
  $messages = $checks->checkRequirements();
}
