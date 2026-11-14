<?php

namespace Drupal\commerce_arionpay\Plugin\Commerce\PaymentGateway;

use ArionPay\ApiException;
use ArionPay\Client;
use ArionPay\SignatureException;
use ArionPay\Webhook;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Exception\PaymentGatewayException;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OffsitePaymentGatewayBase;
use Drupal\commerce_price\Price;
use Drupal\Component\Utility\Html;
use Drupal\Core\Entity\EntityFormInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Form\SubformStateInterface;
use Drupal\Core\Url;
use Drupal\commerce\Response\NeedsRedirectException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

// Installed from a zip (not with Composer): use the copy of the ArionPay PHP SDK inside the module.
if (!class_exists(Client::class) && is_file(dirname(__DIR__, 4) . '/lib/arionpay-php/autoload.php')) {
  require_once dirname(__DIR__, 4) . '/lib/arionpay-php/autoload.php';
}

/**
 * ArionPay: crypto payments paid straight to the merchant's own wallet.
 *
 * The customer pays on the ArionPay page. A payment is recorded only from ArionPay itself: the signed webhook
 * (onNotify), or a signed invoice read when the customer comes back (onReturn). Commerce then places the order.
 * Whether an invoice is paid is decided by ArionPay alone; this module never compares crypto amounts.
 *
 * @CommercePaymentGateway(
 *   id = "arionpay",
 *   label = "ArionPay (crypto)",
 *   display_label = "Crypto (Bitcoin, USDT and more)",
 *   forms = {
 *     "offsite-payment" = "Drupal\commerce_arionpay\PluginForm\ArionPayOffsiteForm",
 *   },
 *   payment_method_types = {"credit_card"},
 *   requires_billing_information = FALSE,
 * )
 */
class ArionPay extends OffsitePaymentGatewayBase {

  public const CURRENCIES = ['USD', 'EUR', 'GBP', 'CAD', 'AUD', 'CHF', 'JPY', 'TRY', 'AED', 'INR', 'BRL', 'RUB', 'CNY', 'SGD', 'HKD', 'KRW', 'MXN', 'PLN', 'SEK', 'NOK', 'ZAR'];

  private const OPEN = ['pending', 'paid_partial', 'claim_pending'];

  private const MAX_BODY_BYTES = 262144;

  public function defaultConfiguration() {
    return [
      'api_key' => '',
      'api_secret' => '',
      'webhook_secret' => '',
      'base_url' => '',
    ] + parent::defaultConfiguration();
  }

  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);
    $form['mode']['#description'] = $this->t('Test makes test invoices: no real money moves.');
    $form['api_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('API key'),
      '#description' => $this->t('ArionPay dashboard > your store > API key (starts with pk_).'),
      '#default_value' => $this->configuration['api_key'],
      '#required' => TRUE,
    ];
    $saved = $this->t('Saved. Leave empty to keep it.');
    $form['api_secret'] = [
      '#type' => 'password',
      '#title' => $this->t('API secret'),
      '#description' => $this->t('ArionPay dashboard > your store > API secret (starts with sk_). It is never shown again.'),
      '#attributes' => ['placeholder' => $this->configuration['api_secret'] !== '' ? $saved : 'sk_...', 'autocomplete' => 'new-password'],
      '#required' => $this->configuration['api_secret'] === '',
    ];
    $form['webhook_secret'] = [
      '#type' => 'password',
      '#title' => $this->t('Webhook signing secret'),
      '#description' => $this->t('ArionPay dashboard > Webhooks > signing secret (starts with whsec_). It is never shown again.'),
      '#attributes' => ['placeholder' => $this->configuration['webhook_secret'] !== '' ? $saved : 'whsec_...', 'autocomplete' => 'new-password'],
      '#required' => $this->configuration['webhook_secret'] === '',
    ];
    $form['base_url'] = [
      '#type' => 'url',
      '#title' => $this->t('ArionPay address'),
      '#description' => $this->t('Leave empty unless ArionPay tells you otherwise.'),
      '#default_value' => $this->configuration['base_url'],
      '#attributes' => ['placeholder' => Client::DEFAULT_BASE_URL],
    ];
    // The plugin does not know its gateway inside the settings form: take it from the form.
    $complete = $form_state instanceof SubformStateInterface ? $form_state->getCompleteFormState() : $form_state;
    $object = $complete->getFormObject();
    $gateway = $object instanceof EntityFormInterface ? $object->getEntity() : NULL;
    if ($gateway && !$gateway->isNew()) {
      $notify = Url::fromRoute('commerce_payment.notify', ['commerce_payment_gateway' => $gateway->id()], ['absolute' => TRUE])->toString();
      $form['notify'] = [
        '#type' => 'item',
        '#title' => $this->t('Payment updates'),
        '#markup' => '<code>' . Html::escape($notify) . '</code>',
        '#description' => $this->t('Nothing to paste: every invoice tells ArionPay to send its updates here. You can also paste it on the ArionPay Webhooks page to use "Send test webhook".'),
      ];
    }
    return $form;
  }

  public function validateConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::validateConfigurationForm($form, $form_state);
    $values = $form_state->getValue($form['#parents']);
    if (!preg_match('/^pk_[A-Za-z0-9_-]{8,64}$/', trim((string) $values['api_key']))) {
      $form_state->setError($form['api_key'], $this->t('The API key starts with pk_.'));
    }
    if (trim((string) $values['api_secret']) !== '' && !str_starts_with(trim((string) $values['api_secret']), 'sk_')) {
      $form_state->setError($form['api_secret'], $this->t('The API secret starts with sk_.'));
    }
    if (trim((string) $values['webhook_secret']) !== '' && !str_starts_with(trim((string) $values['webhook_secret']), 'whsec_')) {
      $form_state->setError($form['webhook_secret'], $this->t('The webhook signing secret starts with whsec_.'));
    }
  }

  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    // The parent empties the configuration, so remember the saved secrets first.
    $saved = $this->configuration;
    parent::submitConfigurationForm($form, $form_state);
    if (!$form_state->getErrors()) {
      $values = $form_state->getValue($form['#parents']);
      $this->configuration['api_key'] = trim((string) $values['api_key']);
      foreach (['api_secret', 'webhook_secret'] as $key) {
        // An empty field keeps the saved secret (the form never shows it).
        $typed = trim((string) $values[$key]);
        $this->configuration[$key] = $typed !== '' ? $typed : (string) ($saved[$key] ?? '');
      }
      $this->configuration['base_url'] = rtrim(trim((string) $values['base_url']), '/');
    }
  }

  // ------------------------------------------------------------------ paying

  /**
   * The ArionPay page for this order: the open invoice when it still fits, otherwise a new one.
   *
   * @return string|null
   *   The payment page address, or NULL when ArionPay already has this payment as paid (it is then recorded).
   */
  public function paymentPageUrl(OrderInterface $order, Price $amount, string $returnUrl, string $cancelUrl): ?string {
    $currency = $amount->getCurrencyCode();
    if (!in_array($currency, self::CURRENCIES, TRUE)) {
      throw new PaymentGatewayException(sprintf('ArionPay does not take %s.', $currency));
    }
    $money = self::money($amount->getNumber());
    $test = $this->isTest();
    $data = (array) $order->getData('arionpay', []);
    $attempt = max(1, (int) ($data['attempt'] ?? 1));
    $client = $this->client();

    if (!empty($data['invoice_id'])) {
      try {
        $read = $client->getInvoice($data['invoice_id']);
      }
      catch (ApiException $e) {
        if ($e->getCode() !== 404) {
          throw $e;
        }
        $read = ['status' => 'missing'];
      }
      if ($read['status'] === 'paid') {
        $this->recordPaid($order, $read, 'signed read at checkout');
        return NULL;
      }
      $expires = isset($read['raw']['expiresAt']) ? strtotime((string) $read['raw']['expiresAt']) : 0;
      $open = in_array($read['status'], self::OPEN, TRUE) || ($read['status'] === 'new' && ($expires === 0 || $expires > time() + 300));
      if ($open && ($data['amount'] ?? '') === $money && ($data['currency'] ?? '') === $currency && !empty($data['url'])) {
        return $data['url'];
      }
      $attempt++;
    }

    for ($tries = 0; $tries < 20; $tries++, $attempt++) {
      $created = $client->createInvoice([
        'amount' => $money,
        'currency' => $currency,
        'orderId' => (string) $order->id(),
        'description' => mb_substr($order->getStore()->label() . ' order ' . $order->id(), 0, 500),
        'successUrl' => $returnUrl,
        'cancelUrl' => $cancelUrl,
        'callbackUrl' => $this->getNotifyUrl()->toString(),
        'metadata' => [
          'drupal_order_id' => (int) $order->id(),
          'drupal_amount' => $money,
          'drupal_currency' => $currency,
          'drupal_site' => self::siteId(),
          'platform' => 'drupal-commerce',
        ],
        'idempotencyKey' => 'drupal-' . self::siteId() . '-' . $order->id() . '-' . str_replace('.', '', $money) . $currency . ($test ? '-test' : '') . '-' . $attempt,
        'test' => $test,
      ]);
      if ($created['status'] === 'paid') {
        return NULL;
      }
      $expires = $created['expiresAt'] ? strtotime((string) $created['expiresAt']) : 0;
      if (in_array($created['status'], self::OPEN, TRUE) || ($created['status'] === 'new' && ($expires === 0 || $expires > time() + 300))) {
        $order->setData('arionpay', ['invoice_id' => $created['id'], 'url' => $created['invoiceUrl'], 'amount' => $money, 'currency' => $currency, 'attempt' => $attempt]);
        $order->save();
        return $created['invoiceUrl'];
      }
    }
    throw new PaymentGatewayException('Too many finished ArionPay invoices for this order.');
  }

  /**
   * The customer came back from the ArionPay page. Only a signed read can record the payment. When it is not paid
   * yet, the customer goes back to the ArionPay page (it shows the payment's progress), so the order is not placed
   * unpaid.
   */
  public function onReturn(OrderInterface $order, Request $request) {
    $data = (array) $order->getData('arionpay', []);
    if (empty($data['invoice_id'])) {
      throw new PaymentGatewayException('No ArionPay invoice for this order.');
    }
    $read = $this->client()->getInvoice($data['invoice_id']);
    if ($read['status'] === 'paid') {
      $result = $this->recordPaid($order, $read, 'signed read on return');
      if (in_array($result, ['recorded', 'duplicate'], TRUE)) {
        return;
      }
      throw new PaymentGatewayException('The ArionPay payment does not match this order: ' . $result);
    }
    if (!empty($data['url'])) {
      throw new NeedsRedirectException($data['url']);
    }
    throw new PaymentGatewayException('The ArionPay invoice is not paid.');
  }

  // ------------------------------------------------------------------ webhook

  /** POST /payment/notify/{gateway}. */
  public function onNotify(Request $request) {
    $secret = (string) $this->configuration['webhook_secret'];
    if ($secret === '') {
      return new JsonResponse(['error' => 'webhook secret not set'], 503);
    }
    $raw = (string) $request->getContent();
    if (strlen($raw) > self::MAX_BODY_BYTES) {
      return new JsonResponse(['error' => 'too large'], 413);
    }
    try {
      $event = Webhook::verify($raw, $request->headers->get('ArionPay-Signature'), $secret);
    }
    catch (SignatureException $e) {
      $this->logger()->warning('Webhook refused: @m', ['@m' => $e->getMessage()]);
      return new JsonResponse(['error' => 'invalid signature'], 401);
    }
    if (Webhook::isTestEvent($event)) {
      $this->logger()->info('Verified ArionPay test webhook received (@t). Nothing changed.', ['@t' => $event['type']]);
      return new JsonResponse(['received' => TRUE, 'test' => TRUE]);
    }
    $invoice = $event['data']['invoice'];
    $meta = (array) ($invoice['metadata'] ?? []);
    if (empty($meta['drupal_order_id']) || ($meta['drupal_site'] ?? '') !== self::siteId()) {
      return new JsonResponse(['received' => TRUE, 'ignored' => 'not an order of this site']);
    }
    if ((bool) ($invoice['test'] ?? FALSE) !== $this->isTest()) {
      $this->logger()->warning('Invoice @i refused: test/live mode differs from the gateway.', ['@i' => $invoice['id']]);
      return new JsonResponse(['error' => 'test/live mismatch'], 409);
    }

    if ($event['type'] !== 'invoice.paid') {
      $this->logger()->info('Order @o: ArionPay @t for invoice @i (status @s).', ['@o' => $meta['drupal_order_id'], '@t' => $event['type'], '@i' => $invoice['id'], '@s' => $invoice['status'] ?? '']);
      return new JsonResponse(['received' => TRUE]);
    }

    /** @var \Drupal\commerce_order\OrderStorage $storage */
    $storage = $this->entityTypeManager->getStorage('commerce_order');
    $lock = method_exists($storage, 'loadForUpdate');
    $order = $lock ? $storage->loadForUpdate((int) $meta['drupal_order_id']) : $storage->load((int) $meta['drupal_order_id']);
    try {
      if (!$order) {
        return new JsonResponse(['received' => TRUE, 'ignored' => 'unknown order']);
      }
      $result = $this->recordPaid($order, $invoice, 'webhook');
    }
    finally {
      if ($lock && $order) {
        $storage->releaseLock($order->id());
      }
    }
    return new JsonResponse(['received' => TRUE, 'result' => $result]);
  }

  /**
   * Record a paid ArionPay invoice as one completed payment. Safe to call many times (the invoice id is the payment's
   * remote id). Returns recorded | duplicate | mismatch | wrong_gateway.
   */
  private function recordPaid(OrderInterface $order, array $invoice, string $source): string {
    $meta = (array) ($invoice['metadata'] ?? []);
    if ((int) ($meta['drupal_order_id'] ?? 0) !== (int) $order->id() || ($meta['drupal_site'] ?? '') !== self::siteId()) {
      $this->logger()->error('Invoice @i does not belong to order @o. NOT recorded.', ['@i' => $invoice['id'], '@o' => $order->id()]);
      return 'mismatch';
    }
    $gateway = $order->get('payment_gateway')->entity;
    if (!$gateway || $gateway->id() !== $this->parentEntity->id()) {
      $this->logger()->error('Order @o does not use this ArionPay gateway. NOT recorded.', ['@o' => $order->id()]);
      return 'wrong_gateway';
    }
    $paid = is_numeric($invoice['amountFiat'] ?? NULL) ? self::money($invoice['amountFiat']) : '';
    $currency = strtoupper((string) ($invoice['currency'] ?? ''));
    if ($paid === '' || $paid !== (string) ($meta['drupal_amount'] ?? '') || $currency !== strtoupper((string) ($meta['drupal_currency'] ?? ''))) {
      $this->logger()->error('Invoice @i for order @o: paid amount or currency differs from the payment that was started. NOT recorded.', ['@i' => $invoice['id'], '@o' => $order->id()]);
      return 'mismatch';
    }

    $payments = $this->entityTypeManager->getStorage('commerce_payment');
    if ($payments->loadByProperties(['remote_id' => (string) $invoice['id'], 'payment_gateway' => $this->parentEntity->id()])) {
      return 'duplicate';
    }
    if ($order->isPaid()) {
      $this->logger()->warning('Order @o was already paid, and ArionPay invoice @i was paid too: possible double payment, refund it from your wallet.', ['@o' => $order->id(), '@i' => $invoice['id']]);
    }
    $payment = $payments->create([
      'state' => 'completed',
      'amount' => new Price($paid, $currency),
      'payment_gateway' => $this->parentEntity->id(),
      'order_id' => $order->id(),
      'test' => $this->isTest(),
      'remote_id' => (string) $invoice['id'],
      'remote_state' => (string) ($invoice['status'] ?? 'paid'),
    ]);
    $payment->save();
    $this->logger()->info('Order @o: @test ArionPay invoice @i paid (@a @c@late) from @s.', [
      '@o' => $order->id(),
      '@test' => !empty($invoice['test']) ? 'TEST' : 'live',
      '@i' => $invoice['id'],
      '@a' => $paid,
      '@c' => $currency,
      '@late' => !empty($invoice['late']) ? ', late' : '',
      '@s' => $source,
    ]);
    return 'recorded';
  }

  // ------------------------------------------------------------------ helpers

  public function isTest(): bool {
    return $this->getMode() !== 'live';
  }

  public function client(): Client {
    $base = (string) $this->configuration['base_url'];
    return new Client((string) $this->configuration['api_key'], (string) $this->configuration['api_secret'], [
      'baseUrl' => $base !== '' ? $base : Client::DEFAULT_BASE_URL,
      'appInfo' => 'drupal/' . \Drupal::VERSION . ' commerce_arionpay/1.0.0',
    ]);
  }

  /** Random id of this site (made once), so two sites on one ArionPay store never touch each other's orders. */
  public static function siteId(): string {
    $state = \Drupal::state();
    $id = (string) $state->get('commerce_arionpay.site_id', '');
    if (!preg_match('/^[a-f0-9]{12}$/', $id)) {
      $id = bin2hex(random_bytes(6));
      $state->set('commerce_arionpay.site_id', $id);
    }
    return $id;
  }

  private static function money($value): string {
    return number_format((float) $value, 2, '.', '');
  }

  private function logger() {
    return \Drupal::logger('commerce_arionpay');
  }

}
