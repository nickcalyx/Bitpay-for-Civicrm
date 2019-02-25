<?php
/**
 * Created by PhpStorm.
 * User: matthew
 * Date: 20-02-2019
 * Time: 10:03
 */

class CRM_Bitpay_Client {

  /**
   * @var array Payment processor
   */
  private $_paymentProcessor = NULL;

  /**
   * CRM_Bitpay_Client constructor.
   *
   * @param $paymentProcessor
   */
  public function __construct($paymentProcessor) {
    $this->_paymentProcessor = $paymentProcessor;
  }

  /**
   * Get the bitpay processor client object
   *
   * @return \Bitpay\Client\Client
   * @throws \CRM_Core_Exception
   * @throws \CiviCRM_API3_Exception
   */
  public function getClient() {
    $storageEngine = new \Bitpay\Storage\EncryptedFilesystemStorage(CRM_Bitpay_Keys::getKeyPassword($this->_paymentProcessor['id'])); // Password may need to be updated if you changed it
    $privateKey = $storageEngine->load(CRM_Bitpay_Keys::getKeyPath($this->_paymentProcessor['id']));
    $publicKey = $storageEngine->load(CRM_Bitpay_Keys::getKeyPath($this->_paymentProcessor['id'], FALSE));
    $client = new \Bitpay\Client\Client();
    if ($this->_paymentProcessor['is_test']) {
      $network = new \Bitpay\Network\Testnet();
    }
    else {
      $network = new \Bitpay\Network\Livenet();
    }
    $adapter = new \Bitpay\Client\Adapter\CurlAdapter();
    $client->setPrivateKey($privateKey);
    $client->setPublicKey($publicKey);
    $client->setNetwork($network);
    $client->setAdapter($adapter);
    // ---------------------------
    /**
     * The last object that must be injected is the token object.
     */
    $token = new \Bitpay\Token();
    $token->setToken($this->_paymentProcessor['signature']);
    /**
     * Token object is injected into the client
     */
    $client->setToken($token);
    return $client;
  }

}