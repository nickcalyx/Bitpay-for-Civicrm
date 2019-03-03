<?php

require_once 'bitpay.civix.php';
require_once __DIR__.'/vendor/autoload.php';

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
 * Implements hook_civicrm_xmlMenu().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_xmlMenu
 */
function bitpay_civicrm_xmlMenu(&$files) {
  _bitpay_civix_civicrm_xmlMenu($files);
}

/**
 * Implements hook_civicrm_install().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_install
 */
function bitpay_civicrm_install() {
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
 * Implements hook_civicrm_managed().
 *
 * Generate a list of entities to create/deactivate/delete when this module
 * is installed, disabled, uninstalled.
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_managed
 */
function bitpay_civicrm_managed(&$entities) {
  _bitpay_civix_civicrm_managed($entities);
}

/**
 * Implements hook_civicrm_caseTypes().
 *
 * Generate a list of case-types.
 *
 * Note: This hook only runs in CiviCRM 4.4+.
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_caseTypes
 */
function bitpay_civicrm_caseTypes(&$caseTypes) {
  _bitpay_civix_civicrm_caseTypes($caseTypes);
}

/**
 * Implements hook_civicrm_angularModules().
 *
 * Generate a list of Angular modules.
 *
 * Note: This hook only runs in CiviCRM 4.5+. It may
 * use features only available in v4.6+.
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_angularModules
 */
function bitpay_civicrm_angularModules(&$angularModules) {
  _bitpay_civix_civicrm_angularModules($angularModules);
}

/**
 * Implements hook_civicrm_alterSettingsFolders().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_alterSettingsFolders
 */
function bitpay_civicrm_alterSettingsFolders(&$metaDataFolders = NULL) {
  _bitpay_civix_civicrm_alterSettingsFolders($metaDataFolders);
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
  if ($form->isSubmitted()) return;

  if (!isset($form->_paymentProcessor)) {
    return;
  }
  $paymentProcessor = $form->_paymentProcessor;
  if (empty($paymentProcessor['class_name']) || ($paymentProcessor['class_name'] !== 'Payment_Bitpay')) {
    return;
  }

  if ($formName == 'CRM_Contribute_Form_Contribution_ThankYou') {
    // Contribution Thankyou form
    // Add the bitpay invoice handling
    $form->assign('bitpayTrxnId', $form->_trxnId);
    $form->assign('bitpayTestMode', $paymentProcessor['is_test']);
    CRM_Core_Region::instance('contribution-thankyou-billing-block')->update('default', array(
      'disabled' => TRUE,
    ));
    CRM_Core_Region::instance('contribution-thankyou-billing-block')->add(array(
      'template' => 'Bitpaycontribution-thankyou-billing-block.tpl',
    ));
  }

}
