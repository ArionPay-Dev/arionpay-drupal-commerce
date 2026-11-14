<?php

namespace Drupal\commerce_arionpay\EventSubscriber;

use Drupal\commerce_arionpay\Plugin\Commerce\PaymentGateway\ArionPay;
use Drupal\commerce_payment\Event\FilterPaymentGatewaysEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Hides ArionPay for orders in a currency ArionPay does not take.
 */
class FilterGatewaysSubscriber implements EventSubscriberInterface {

  public static function getSubscribedEvents(): array {
    return ['commerce_payment.filter_payment_gateways' => 'onFilter'];
  }

  public function onFilter(FilterPaymentGatewaysEvent $event): void {
    $order = $event->getOrder();
    $price = $order->getTotalPrice();
    if (!$price) {
      foreach ($order->getItems() as $item) {
        $price = $item->getUnitPrice();
        break;
      }
    }
    $currency = $price ? $price->getCurrencyCode() : ($order->getStore() ? $order->getStore()->getDefaultCurrencyCode() : '');
    if ($currency === '' || in_array($currency, ArionPay::CURRENCIES, TRUE)) {
      return;
    }
    $gateways = array_filter($event->getPaymentGateways(), static fn ($g) => !($g->getPlugin() instanceof ArionPay));
    $event->setPaymentGateways($gateways);
  }

}
