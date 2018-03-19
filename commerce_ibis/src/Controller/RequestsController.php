<?php

namespace Drupal\commerce_ibis\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\commerce_order\Entity\Order;
use Symfony\Component\HttpFoundation\Request;
use Drupal\Core\Database\Database;
use Drupal\commerce_ibis\Merchant\Merchant;

/**
 * @file
 * Contains Drupal\commerce_ibis\Controller to redirect the request to Commerce.
 */

/**
 * Class RequestsController.
 *
 * @package Drupal\commerce_ibis\Controller
 */
class RequestsController extends ControllerBase {

  /**
   * Translates into commerce_payment.checkout.return call
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   */
  public function complete(Request $request) {
    // Load order id from passed request data.
    $order_id = $request->get('order_id');
    $trans_id = $request->get('trans_id');
    $messenger = \Drupal::messenger();

    if (empty($order_id) || empty($trans_id)) {
      // Explain.
      $messenger->addError(t('Invalid request!'));

      return $this->redirect('<front>');
    }

    $options = [];
    $options['query']['trans_id'] = $trans_id;
    $commerce_order = Order::load($order_id);

    return $this->redirect('commerce_payment.checkout.return', [
      'commerce_order' => $order_id,
      'step' => 'payment',
    ], $options);
  }

  /**
   * Translates into commerce_payment.checkout.cancel call
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   */
  public function fail(Request $request) {
    // Load order id from passed request data.
    $order_id = $request->get('order_id');
    $trans_id = $request->get('trans_id');
    $messenger = \Drupal::messenger();

    if (empty($order_id) || empty($trans_id)) {
      // Explain.
      $messenger->addError(t('Invalid request!'));

      return $this->redirect('<front>');
    }

    $options = [];

    $options['query']['trans_id'] = $trans_id;

    $commerce_order = Order::load($order_id);

    return $this->redirect('commerce_payment.checkout.cancel', [
      'commerce_order' => $order_id,
      'step' => 'payment',
    ], $options);
  }

  /**
   * Provides manual reverse payment.
   *
   * Reverse payment in full amount.
   */
  public function reverse($order_id) {

    $messenger = \Drupal::messenger();
    // Validate given id.
    if (!is_numeric($order_id)) {
      $messenger->addError(t('Invalid request!'));

      \Drupal::logger('commerce_ibis')->error('Order ID not numeral.');
      return $this->redirect('<front>');
    }
    // Conn.
    $connection = Database::getConnection();

    $query = $connection->select('commerce_ibis_transaction', 't')
      ->fields('t')
      ->condition('t.order_id', $order_id)
      ->condition('t.dms_ok', 'YES');
    $data = $query->execute();
    $results = $data->fetchAll(\PDO::FETCH_OBJ);

    if (count($results) == 0) {
      $messenger->addError(t('Invalid request!'));

      \Drupal::logger('commerce_ibis')->error('Cannot reverse. No matching entries found.');
      return $this->redirect('<front>');
    }

    $row = reset($results);
    $amount = $row->amount;
    $trans_id = $row->trans_id;
    $now = REQUEST_TIME;

    $settings = \Drupal::config('commerce_payment.commerce_payment_gateway.worldline_creditcard_payment');
    $configuration = $settings->getRawData()['configuration'];

    $ecomm_server_url = $configuration['ecomm_server_url'];

    $cert_url = $configuration['cert_location'];
    // Real directory path to cert file.
    $real_cert_path = \Drupal::service('file_system')->realpath($cert_url);
    $cert_pass = $configuration['cert_pass'];

    $merchant = new Merchant($ecomm_server_url, $real_cert_path, $cert_pass, 1);

    $resp = $merchant->reverse($trans_id, $amount);

    if (substr($resp, 8, 2) == "OK" || substr($resp, 8, 8) == "REVERSED") {
      if (strstr($resp, 'RESULT:')) {
        $result = explode('RESULT: ', $resp);
        $result = preg_split('/\r\n|\r|\n/', $result[1]);
        $result = $result[0];
      }
      else {
        $result = '';
      }
      if (strstr($resp, 'RESULT_CODE:')) {
        $result_code = explode('RESULT_CODE: ', $resp);
        $result_code = preg_split('/\r\n|\r|\n/', $result_code[1]);
        $result_code = $result_code[0];
      }
      else {
        $result_code = '';
      }
      $result = $connection->update('commerce_ibis_transaction')
        ->fields([
            'reversal_amount' => $amount,
            'result_code' => $result_code,
            'result' => $result,
            'response' => $resp,
          ])
        ->condition('trans_id', $trans_id)
        ->execute();

      $message = t('Payment reversed for # @order: @resp', ['@resp' => $resp, '@order' => $order_id]);
      \Drupal::logger('commerce_ibis')->info($message);
      $messenger->addMessage(t('Payment reversed for @order by @amount.', ['@order' => $order_id, '@amount' => $amount / 100]));

      return $this->redirect('<front>');
    }
    else {
      $result = $connection->insert('commerce_ibis_error')
        ->fields([
          'error_time' => $now,
          'action' => 'reverse',
          'response' => $resp,
        ])
        ->execute();

      $message = t('Payment reverse failed for # @order: @resp', ['@resp' => $resp, '@order' => $order_id]);
      \Drupal::logger('commerce_ibis')->error($message);

      $messenger->addError(t('Payment reverse failed.'));

      return $this->redirect('<front>');
    }
  }

}
