<?php

namespace Drupal\commerce_ibis\Merchant;

/**
 * @author First Data Latvia
 * @version 1.0
 * @package sample
 */


/**
 * Package
 * @package sample
 * @subpackage classes
 */
class Merchant {
  /**
   * Variable $url
   * @access private
   * @var string
   */
  private $url;

  /**
   * Variable $keystore
   * @access private
   * @var string
   */
  private $keystore;

  /**
   * Variable $keystorepassword
   * @access private
   * @var string
   */
  private $keystorepassword;

  /**
   * Variable $verbose
   * @access private
   * @var bool
   */
  private $verbose;

  /**
   * Constructor sets up {$link, $keystore, $keystorepassword, $verbose}
   * @param string $url url to declare
   * @param string $keystore value of the keystore
   * @param string $keystorepassword value of the keystorepassword
   * @param boolean $verbose TRUE to output verbose information. Writes output to STDERR, or the file specified using CURLOPT_STDERR.
   */

  public function __construct($url, $keystore, $keystorepassword, $verbose = 0) {

    $this->url = $url;
    $this->keystore = $keystore;
    $this->keystorepassword = $keystorepassword;
    $this->verbose = $verbose;
  }

  /**
   * Send parameters
   *
   * @param array post parameters
   * @return string result
   */

  public function sentPost($params) {

    if (!file_exists($this->keystore)) {
      $result = "file " . $this->keystore . " not exists";
      error_log($result);
      return $result;
    }

    if (!is_readable($this->keystore)) {
      $result = "Please check CHMOD for file \"" . $this->keystore . "\"! It must be readable!";
      error_log($result);
      return $result;
    }

    $post = "";

    foreach ($params as $key => $value) {
      $post .= "$key=$value&";
    }

    $curl = curl_init();
    if ($this->verbose) {
      curl_setopt($curl, CURLOPT_VERBOSE, TRUE);
    }

    curl_setopt($curl, CURLOPT_URL, $this->url);
    curl_setopt($curl, CURLOPT_HEADER, 0);
    curl_setopt($curl, CURLOPT_POST, TRUE);
    curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, FALSE);
    //curl_setopt($curl, CURLOPT_SSLVERSION,2);
    curl_setopt($curl, CURLOPT_SSLCERT, $this->keystore);
    curl_setopt($curl, CURLOPT_CAINFO, $this->keystore);
    curl_setopt($curl, CURLOPT_SSLKEYPASSWD, $this->keystorepassword);
    curl_setopt($curl, CURLOPT_POSTFIELDS, $post);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, TRUE);
    $result = curl_exec($curl);

    if (curl_error($curl)) {
      $result = curl_error($curl);
      error_log($result);
    }
    curl_close($curl);
    return $result;
  }

  /**
   * Registering of SMS transaction
   * @param int $amount transaction amount in minor units, mandatory
   * @param int $currency transaction currency code, mandatory
   * @param string $ip client's IP address, mandatory
   * @param string $desc description of transaction, optional
   * @param string $language authorization language identificator, optional
   * @return string TRANSACTION_ID
   */

  public function startSMSTrans($amount, $currency, $ip, $desc, $language) {

    $params = [
      'command' => 'v',
      'amount' => $amount,
      'currency' => $currency,
      'client_ip_addr' => $ip,
      'description' => $desc,
      'language' => $language
    ];
    return $this->sentPost($params);
  }

  /**
   * Registering of DMS authorisation
   * @param int $amount transaction amount in minor units, mandatory
   * @param int $currency transaction currency code, mandatory
   * @param string $ip client's IP address, mandatory
   * @param string $desc description of transaction, optional
   * @param string $language authorization language identificator, optional
   * @return string TRANSACTION_ID
   */

  public function startDMSAuth($amount, $currency, $ip, $desc, $language) {

    $params = [
      'command' => 'a',
      'msg_type' => 'DMS',
      'amount' => $amount,
      'currency' => $currency,
      'client_ip_addr' => $ip,
      'description' => $desc,
      'language' => $language,
    ];
    return $this->sentPost($params);
  }

  /**
   * Making of DMS transaction
   * @param int $auth_id id of previously made successeful authorisation
   * @param int $amount transaction amount in minor units, mandatory
   * @param int $currency transaction currency code, mandatory
   * @param string $ip client's IP address, mandatory
   * @param string $desc description of transaction, optional
   * @return string RESULT, RESULT_CODE, RRN, APPROVAL_CODE
   */

  public function makeDMSTrans($auth_id, $amount, $currency, $ip, $desc, $language) {

    $params = [
      'command' => 't',
      'msg_type' => 'DMS',
      'trans_id' => $auth_id,
      'amount' => $amount,
      'currency' => $currency,
      'client_ip_addr' => $ip
    ];

    $str = $this->sentPost($params);
    return $str;
  }

  /**
   * Transaction result
   * @param int $trans_id transaction identifier, mandatory
   * @param string $ip client's IP address, mandatory
   * @return string RESULT, RESULT_CODE, 3DSECURE, AAV, RRN, APPROVAL_CODE
   */

  public function getTransResult($trans_id, $ip) {

    $params = [
      'command' => 'c',
      'trans_id' => $trans_id,
      'client_ip_addr' => $ip
    ];

    $str = $this->sentPost($params);
    return $str;
  }

  /**
   * Transaction reversal
   * @param int $trans_id transaction identifier, mandatory
   * @param int $amount transaction amount in minor units, mandatory
   * @return string RESULT, RESULT_CODE
   */

  public function reverse($trans_id, $amount) {

    $params = [
      'command' => 'r',
      'trans_id' => $trans_id,
      'amount' => $amount
    ];

    $str = $this->sentPost($params);
    return $str;
  }

  /**
   * Closing of business day
   * @return string RESULT, RESULT_CODE, FLD_075, FLD_076, FLD_087, FLD_088
   */

  public function closeDay() {

    $params = [
      'command' => 'b',
    ];

    $str = $this->sentPost($params);
    return $str;
  }

}
