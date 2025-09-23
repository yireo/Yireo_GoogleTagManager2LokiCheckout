<?php declare(strict_types=1);

namespace Yireo\GoogleTagManager2LokiCheckout\Plugin;

use LokiCheckout\Core\CustomerData\Checkout;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\Exception\NoSuchEntityException;
use Yireo\GoogleTagManager2\DataLayer\Event\AddPaymentInfo;
use Yireo\GoogleTagManager2\DataLayer\Event\AddShippingInfo;
use Yireo\GoogleTagManager2\DataLayer\Event\BeginCheckout;

class AppendGtmEventsToLokiCheckoutSectionPlugin
{
    public function __construct(
        private CheckoutSession $checkoutSession,
        private BeginCheckout $beginCheckout,
        private AddShippingInfo $addShippingInfo,
        private AddPaymentInfo $addPaymentInfo,
    ) {
    }

    public function afterGetSectionData(Checkout $checkoutSection, array $sectionData): array
    {
        $sectionData['gtm_events'] = $this->getGtmEvents();
        return $sectionData;
    }

    private function getGtmEvents(): array
    {
        $events = [];
        if (false === (bool)$this->checkoutSession->hasQuote()) {
            return $events;
        }

        try {
            $events[] = $this->getBeginCheckoutInfo();
            $events[] = $this->getShippingInfo();
            $events[] = $this->getPaymentInfo();
        } catch (NoSuchEntityException $entityException) {

        }


        return $events;
    }

    private function getBeginCheckoutInfo(): false|array
    {
        return $this->beginCheckout->get();
    }

    private function getShippingInfo(): false|array
    {
        $shippingInfo = $this->addShippingInfo->get();
        if (empty($shippingInfo)) {
            return false;
        }

        if (empty($shippingInfo['ecommerce']['shipping_tier'])) {
            return false;
        }

        return $shippingInfo;
    }

    private function getPaymentInfo(): false|array
    {
        $this->addPaymentInfo->setCartId((int)$this->checkoutSession->getQuote()->getId());
        $this->addPaymentInfo->setPaymentMethod((string)$this->checkoutSession->getQuote()->getPayment()->getMethod());

        $paymentInfo = $this->addPaymentInfo->get();
        if (empty($paymentInfo)) {
            return false;
        }

        if (empty($paymentInfo['ecommerce']['payment_type'])) {
            return false;
        }

        return $paymentInfo;
    }
}
