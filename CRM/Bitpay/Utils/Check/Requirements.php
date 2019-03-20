<?php

use CRM_Bitpay_ExtensionUtil as E;

class CRM_Bitpay_Utils_Check_Requirements {

  /**
   * Checks whether all the requirements for bitpay have been met.
   *
   * @see bitpay_civicrm_check()
   */
  public static function check(&$messages) {
    $requirements = \Bitpay\Util\Util::checkRequirements();

    $failedRequirements = [];
    foreach ($requirements as $key => $requirement) {
      if ($requirement !== TRUE) {
        $failedRequirements[] = $requirement;
      }
    }
    if (!empty($failedRequirements)) {
      $messages[] = new CRM_Utils_Check_Message(
        'bitpay_requirements',
        'The bitpay payment processor has missing requirements: ' . implode('<br />', $failedRequirements),
        E::ts('Bitpay - Missing Requirements'),
        \Psr\Log\LogLevel::ERROR,
        'fa-money'
      );
    }
  }

}
