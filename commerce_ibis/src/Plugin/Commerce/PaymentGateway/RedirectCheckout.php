<?php

namespace Drupal\commerce_ibis\Plugin\Commerce\PaymentGateway;

use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OffsitePaymentGatewayBase;
use Drupal\commerce_payment\Exception\HardDeclineException;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_ibis\Merchant\Merchant;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Database\Database;
use Symfony\Component\HttpFoundation\Request;

/**
 * Provides the Worldline (ex. Firstdata) LV offsite Checkout payment gateway.
 *
 * @CommercePaymentGateway(
 *   id = "worldline_redirect_checkout",
 *   label = @Translation("Credit card (Worldline LV)"),
 *   display_label = @Translation("Worldline LV"),
 *    forms = {
 *     "offsite-payment" = "Drupal\commerce_ibis\PluginForm\RedirectCheckoutForm",
 *   },
 *   payment_method_types = {"credit_card"},
 *   credit_card_types = {
 *     "mastercard", "visa", "maestro",
 *   },
 * )
 */
class RedirectCheckout extends OffsitePaymentGatewayBase {

  public function defaultConfiguration() {
    return [
        'merchant_id' => '',
        'cert_pass' => '',
        'cert_location' => '',
        'ecomm_client_url' => '',
        'ecomm_server_url' => '',
      ] + parent::defaultConfiguration();
  }

  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);

    $form['merchant_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Merchant ID'),
      '#description' => $this->t('Merchant ID.'),
      '#default_value' => $this->configuration['merchant_id'],
      '#required' => TRUE,
    ];

    $form['cert_pass'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Password'),
      '#description' => $this->t('Certificate password from Merchant.'),
      '#default_value' => $this->configuration['cert_pass'],
      '#required' => TRUE,
    ];

    $form['cert_location'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Certificate'),
      '#description' => $this->t('Active Certificates filename located at commerce_ibis/certs folder.'),
      '#default_value' => $this->configuration['cert_location'],
      '#required' => TRUE,
    ];

    return $form;
  }

  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::submitConfigurationForm($form, $form_state);
    $values = $form_state->getValue($form['#parents']);

    $module_handler = \Drupal::service('module_handler');
    $module_path = $module_handler->getModule('commerce_ibis')->getPath();
    $cert_base = $module_path;

    $cert_loc_in_module = '/certs/';
    $cert_path = '';
    if (file_exists($values['cert_location'])) {
      $cert_path = $values['cert_location'];
    }
    else {
      $cert_name = $values['cert_location'];
      // Add extension if not present.
      if (strpos($cert_name, '.pem') === FALSE) {
        $cert_name .= '.pem';
      }

      $cert_path = $cert_base . $cert_loc_in_module . $cert_name;
    }

    if (!file_exists($cert_path)) {
      $form_state->setErrorByName('cert_location', t('Invalid path or file does not exist!'));
    }

    $this->configuration['merchant_id'] = $values['merchant_id'];
    $this->configuration['cert_pass'] = $values['cert_pass'];
    $this->configuration['cert_location'] = $cert_path;

    $ecomm_urls = $this->getEcommUrls($this->configuration['mode']);
    $this->configuration['ecomm_client_url'] = $ecomm_urls['ecomm_client_url'];
    $this->configuration['ecomm_server_url'] = $ecomm_urls['ecomm_server_url'];
  }

  public function getEcommUrls($type = 'test') {
    $data = [];
    if ($type == 'test') {
      $data['ecomm_server_url'] = 'https://secureshop-test.firstdata.lv:8443/ecomm/MerchantHandler';
      $data['ecomm_client_url'] = 'https://secureshop-test.firstdata.lv/ecomm/ClientHandler';
    }
    else {
      $data['ecomm_server_url'] = 'https://secureshop.firstdata.lv:8443/ecomm/MerchantHandler';
      $data['ecomm_client_url'] = 'https://secureshop.firstdata.lv/ecomm/ClientHandler';
    }
    return $data;
  }

  public function onReturn(OrderInterface $order, Request $request) {

    $trans_id = $request->get('trans_id');
    $trans_id = str_replace(' ', '+', $trans_id);

    $ecomm_server_url = $this->configuration['ecomm_server_url'];
    $cert_location = $this->configuration['cert_location'];
    $cert_pass = $this->configuration['cert_pass'];

    $merchant = new Merchant($ecomm_server_url, $cert_location, $cert_pass, 1);

    $order_id = $order->id();
    $connection = Database::getConnection();

    $query = $connection->select('commerce_ibis_transaction', 't')
      ->fields('t')
      ->condition('t.trans_id', $trans_id)
      ->condition('t.order_id', $order_id)
      ->condition('t.dms_ok', 'YES', '!=');

    $data = $query->execute();

    $results = $data->fetchAll(\PDO::FETCH_OBJ);

    if (count($results) == 0) {
      $message = t('Mismatching data. No trans: @trans for order # @order.', [
        '@trans' => $trans_id,
        '@order' => $order_id,
      ]);
      throw new HardDeclineException($message);
    }

    $row = reset($results);
    $auth_id = urlencode($row->trans_id);
    $amount = $row->amount;
    $currency = urlencode($row->currency);
    $ip = urlencode($row->client_ip_addr);
    $desc = urlencode($row->description);
    $language = urlencode($row->language);
    $now = REQUEST_TIME;

    $resp = (string) $merchant->makeDMSTrans($auth_id, $amount, $currency, $ip, $desc, $language);

    if (substr($resp, 8, 2) == 'OK') {
      $result = $connection->update('commerce_ibis_transaction')
        ->fields([
            'dms_ok' => 'YES',
            'makeDMS_amount' => $amount,
          ])
        ->condition('trans_id', $trans_id)
        ->execute();

      $message = t('Created successful transaction for @order. Response: @resp', ['@resp' => $resp, '@order' => $order_id]);
      \Drupal::logger('commerce_ibis')->notice($message);

      $payment_storage = $this->entityTypeManager->getStorage('commerce_payment');
      $payment = $payment_storage->create([
        'state' => 'completed',
        'amount' => $order->getTotalPrice(),
        'payment_gateway' => $order->get('payment_gateway')->entity->id(),
        'order_id' => $order->id(),
      ]);

      $payment->save();
      $message = t('Paldies, ka iepirkāties VALLETA interneta veikalā!<br>
      5 min laikā i-dāvanu karte/-es un informatīvs rēķins tiks nosūtīts uz Jūsu norādīto e-pastu.<br>
      Lai pārliecinātos par to saņemšanu, lūdzu, pārbaudiet arī „Junk” un „Spam” folderus.');
      drupal_set_message($message);
    }
    else {
      $result = $connection->insert('commerce_ibis_error')
        ->fields([
          'error_time' => $now,
          'action' => 'makeDMSTrans',
          'response' => $resp,
        ])
        ->execute();

      $message = t('Invalid card data. Response: @resp', ['@resp' => $resp]);

      throw new HardDeclineException($message);
    }
  }

  public function onCancel(OrderInterface $order, Request $request) {
    // Logs an error.
    $connection = Database::getConnection();

    $trans_id = $request->get('trans_id');
    $trans_id = str_replace(' ', '+', $trans_id);
    // $error_msg = $request->get('error');
    $now = REQUEST_TIME;

    $ecomm_server_url = $this->configuration['ecomm_server_url'];
    $cert_location = $this->configuration['cert_location'];
    $cert_pass = $this->configuration['cert_pass'];

    $merchant = new Merchant($ecomm_server_url, $cert_location, $cert_pass, 1);

    $order_id = $order->id();

    $query = $connection->select('commerce_ibis_transaction', 't')
      ->fields('t', ['client_ip_addr'])
      ->condition('t.trans_id', $trans_id)
      ->condition('t.order_id', $order_id)
      ->condition('t.dms_ok', 'YES', '!=');

    $data = $query->execute();

    $results = $data->fetchAll(\PDO::FETCH_OBJ);

    if (count($results) == 0) {
      $message = t('Mismatching data. No trans: @trans for order # @order.', [
        '@trans' => $trans_id,
        '@order' => $order_id,
      ]);
      throw new HardDeclineException($message);
    }

    $client_ip_addr = reset($results)->client_ip_addr;

    $resp = $merchant->getTransResult(urlencode($trans_id), $client_ip_addr);

    $result = $connection->insert('commerce_ibis_error')
      ->fields([
        'error_time' => $now,
        'action' => 'ReturnFailURL',
        'response' => $resp,
      ])
      ->execute();

    $message = t('Failed response. @trans for order @order.', [
      '@trans' => $trans_id,
      '@order' => $order_id,
    ]);
    throw new HardDeclineException($message);

  }

}
