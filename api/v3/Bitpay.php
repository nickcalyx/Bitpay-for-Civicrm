<?php
/*--------------------------------------------------------------------+
 | CiviCRM version 4.7                                                |
+--------------------------------------------------------------------+
 | Copyright CiviCRM LLC (c) 2004-2017                                |
+--------------------------------------------------------------------+
 | This file is a part of CiviCRM.                                    |
 |                                                                    |
 | CiviCRM is free software; you can copy, modify, and distribute it  |
 | under the terms of the GNU Affero General Public License           |
 | Version 3, 19 November 2007 and the CiviCRM Licensing Exception.   |
 |                                                                    |
 | CiviCRM is distributed in the hope that it will be useful, but     |
 | WITHOUT ANY WARRANTY; without even the implied warranty of         |
 | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.               |
 | See the GNU Affero General Public License for more details.        |
 |                                                                    |
 | You should have received a copy of the GNU Affero General Public   |
 | License and the CiviCRM Licensing Exception along                  |
 | with this program; if not, contact CiviCRM LLC                     |
 | at info[AT]civicrm[DOT]org. If you have questions about the        |
 | GNU Affero General Public License or the licensing of CiviCRM,     |
 | see the CiviCRM license FAQ at http://civicrm.org/licensing        |
 +-------------------------------------------------------------------*/

function civicrm_api3_bitpay_createkeys($params) {
  $result = CRM_Bitpay_Keys::createNewKeys($params['payment_processor_id']);
  return civicrm_api3_create_success(['result' => $result], $params, 'Bitpay', 'createkeys');
}

function _civicrm_api3_bitpay_createkeys_spec(&$spec) {
  $spec['payment_processor_id']['api.required'] = 1;
  $spec['payment_processor_id']['title'] = 'Payment Processor ID';
  $spec['payment_processor_id']['description'] = 'The Payment Processor ID';
  $spec['payment_processor_id']['type'] = CRM_Utils_Type::T_INT;
}

/**
 * @param $params
 *
 * @return array
 * @throws \CRM_Core_Exception
 */
function civicrm_api3_bitpay_pair($params) {
  $pairingToken = CRM_Bitpay_Keys::pair($params['payment_processor_id'], $params['pairingkey']);
  return civicrm_api3_create_success(['token' => $pairingToken], $params, 'Bitpay', 'pair');
}

function _civicrm_api3_bitpay_pair_spec(&$spec) {
  $spec['pairingkey']['api.required'] = 1;
  $spec['pairingkey']['title'] = 'Pairing key from https://test.bitpay.com/api-tokens';
  $spec['pairingkey']['type'] = CRM_Utils_Type::T_STRING;
  $spec['payment_processor_id']['api.required'] = 1;
  $spec['payment_processor_id']['title'] = 'Payment Processor ID';
  $spec['payment_processor_id']['description'] = 'The Payment Processor ID';
  $spec['payment_processor_id']['type'] = CRM_Utils_Type::T_INT;
}
