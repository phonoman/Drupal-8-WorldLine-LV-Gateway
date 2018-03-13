<?php

namespace Drupal\commerce_ibis\PluginForm;

use Drupal\commerce_ibis\Merchant\Merchant;
use Drupal\commerce_payment\PluginForm\PaymentOffsiteForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\commerce_payment\Exception\SoftDeclineException;

class RedirectCheckoutForm extends PaymentOffsiteForm {

  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {

    $form = parent::buildConfigurationForm($form, $form_state);
    // Worldline Commerce configurations.
    $settings = \Drupal::config('commerce_payment.commerce_payment_gateway.worldline_creditcard_payment');
    $configuration = $settings->getRawData()['configuration'];

    $redirect_url = '';

    $ecomm_client_url = $configuration['ecomm_client_url'];
    $ecomm_server_url = $configuration['ecomm_server_url'];

    $cert_url = $configuration['cert_location'];
    // Real directory path to cert file.
    $real_cert_path = \Drupal::service('file_system')->realpath($cert_url);
    $cert_pass = $configuration['cert_pass'];
    $ip = $_SERVER['REMOTE_ADDR'];
    // Use localhost IP address to make testing procedure for blakclist IP.
    // $ip = '192.168.1.2';
    $language = \Drupal::languageManager()->getCurrentLanguage()->getId();
    $merchant = new Merchant($ecomm_server_url, $real_cert_path, $cert_pass, 1);

    /** @var \Drupal\commerce_payment\Entity\PaymentInterface $payment */
    $payment = $this->entity;
    $order_id = $payment->getOrderId();
    $order = $payment->getOrder();

    $data = [];
    $data['order_id'] = $order_id;
    $data['ecomm_server_url'] = $ecomm_server_url;
    $amount = (int) $order->total_price->number * 100;

    $order_currency = $order->total_price->currency_code;
    $currencies = [
      '978' => 'EUR',
      '840' => 'USD',
      '941' => 'RSD',
      '703' => 'SKK',
      '440' => 'LTL',
      '233' => 'EEK',
      '643' => 'RUB',
      '891' => 'YUM',
    ];
    $currency = array_search($order_currency, $currencies);

    $description = t('Order # @order_id payment (IBIS).', ['@order_id' => $order_id]);

    $resp = $merchant->startDMSAuth($amount, $currency, $ip, $description, $language);

    $connection = \Drupal::service('database');

    if (substr($resp, 0, 14) == "TRANSACTION_ID") {
      $trans_id = substr($resp, 16, 28);
      $redirect_url = $ecomm_client_url . "?trans_id=" . urlencode($trans_id);

      $result = $connection->insert('commerce_ibis_transaction')
        ->fields([
          'trans_id' => $trans_id,
          'amount' => $amount,
          'currency' => $currency,
          'order_id' => $order_id,
          'client_ip_addr' => $ip,
          'description' => $description,
          'language' => $language,
          't_date' => REQUEST_TIME,
          'response' => $resp,
        ])
        ->execute();

      return $this->buildRedirectForm(
        $form,
        $form_state,
        $redirect_url,
        $data,
        PaymentOffsiteForm::REDIRECT_POST
      );
    }
    else {
      $resp = htmlentities($resp, ENT_QUOTES);

      $result = $connection->insert('commerce_ibis_error')
        ->fields([
          'error_time' => REQUEST_TIME,
          'action' => 'startDMSAuth',
          'response' => $resp,
        ])
        ->execute();

      $message = t('@order could not connect to payment server. Response: @resp', ['@resp' => $resp, '@order' => $order_id]);
      throw new SoftDeclineException($message);
    }

  }

}
