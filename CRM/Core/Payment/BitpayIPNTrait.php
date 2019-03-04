<?php
/**
 * Shared payment IPN functions that should one day be migrated to CiviCRM core
 * Version: 20190304
 */

trait CRM_Core_Payment_BitpayIPNTrait {

  /**
   * @var array Payment processor
   */
  private $_paymentProcessor;

  /**
   * Get the payment processor
   *   The $_GET['processor_id'] value is set by CRM_Core_Payment::handlePaymentMethod.
   */
  protected function getPaymentProcessor() {
    $paymentProcessorId = (int) CRM_Utils_Array::value('processor_id', $_GET);
    if (empty($paymentProcessorId)) {
      $this->exception('Failed to get payment processor id');
    }

    try {
      $this->_paymentProcessor = \Civi\Payment\System::singleton()->getById($paymentProcessorId)->getPaymentProcessor();
    }
    catch(Exception $e) {
      $this->exception('Failed to get payment processor');
    }
  }

  /**
   * Mark a contribution as cancelled and update related entities
   *
   * @param array $params [ 'id' -> contribution_id, 'payment_processor_id' -> payment_processor_id]
   *
   * @return bool
   * @throws \CiviCRM_API3_Exception
   */
  protected function canceltransaction($params) {
    return $this->incompletetransaction($params, 'cancel');
  }

  /**
   * Mark a contribution as failed and update related entities
   *
   * @param array $params [ 'id' -> contribution_id, 'payment_processor_id' -> payment_processor_id]
   *
   * @return bool
   * @throws \CiviCRM_API3_Exception
   */
  protected function failtransaction($params) {
    return $this->incompletetransaction($params, 'fail');
  }

  /**
   * Handler for failtransaction and canceltransaction - do not call directly
   *
   * @param array $params
   * @param string $mode
   *
   * @return bool
   * @throws \CiviCRM_API3_Exception
   */
  protected function incompletetransaction($params, $mode) {
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
      throw new CiviCRM_API3_Exception('A valid contribution ID is required', 'invalid_data');
    }

    if (!$contribution->loadRelatedObjects($input, $ids, TRUE)) {
      throw new CiviCRM_API3_Exception('failed to load related objects');
    }

    $input['trxn_id'] = !empty($params['trxn_id']) ? $params['trxn_id'] : $contribution->trxn_id;
    if (!empty($params['fee_amount'])) {
      $input['fee_amount'] = $params['fee_amount'];
    }

    $objects['contribution'] = &$contribution;
    $objects = array_merge($objects, $contribution->_relatedObjects);

    $transaction = new CRM_Core_Transaction();
    switch ($mode) {
      case 'cancel':
        return $this->cancelled($objects, $transaction);

      case 'fail':
        return $this->failed($objects, $transaction);

      default:
        throw new CiviCRM_API3_Exception('Unknown incomplete transaction type: ' . $mode);
    }

  }

}
