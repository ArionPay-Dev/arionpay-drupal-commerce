<?php

namespace Drupal\commerce_arionpay\PluginForm;

use Drupal\commerce_payment\Exception\PaymentGatewayException;
use Drupal\commerce_payment\PluginForm\PaymentOffsiteForm;
use Drupal\Core\Form\FormStateInterface;

/**
 * Sends the customer to the ArionPay page (the open invoice is reused when they come back).
 */
class ArionPayOffsiteForm extends PaymentOffsiteForm {

  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);
    /** @var \Drupal\commerce_payment\Entity\PaymentInterface $payment */
    $payment = $this->entity;
    /** @var \Drupal\commerce_arionpay\Plugin\Commerce\PaymentGateway\ArionPay $plugin */
    $plugin = $payment->getPaymentGateway()->getPlugin();
    $order = $payment->getOrder();
    try {
      $url = $plugin->paymentPageUrl($order, $payment->getAmount(), $form['#return_url'], $form['#cancel_url']);
    }
    catch (PaymentGatewayException $e) {
      throw $e;
    }
    catch (\Throwable $e) {
      \Drupal::logger('commerce_arionpay')->error('Could not start the ArionPay payment for order @o: @m', ['@o' => $order->id(), '@m' => $e->getMessage()]);
      throw new PaymentGatewayException('The crypto payment could not be started right now. Please try again in a minute.');
    }
    if ($url === NULL) {
      // Already paid at ArionPay (and recorded): finish checkout through the normal return step.
      $url = $form['#return_url'];
    }
    return $this->buildRedirectForm($form, $form_state, $url, [], self::REDIRECT_GET);
  }

}
