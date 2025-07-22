<?php
declare(strict_types=1);

namespace Yireo\GoogleTagManager2LokiCheckout\Component;

use Magento\Checkout\Model\Session as CheckoutSession;
use Yireo\GoogleTagManager2\DataLayer\Event\AddPaymentInfo;
use Yireo\GoogleTagManager2\DataLayer\Event\AddShippingInfo;
use Yireo\GoogleTagManager2\DataLayer\Event\BeginCheckout;
use Loki\Components\Component\ComponentViewModel;
use Loki\Components\Util\Ajax;

class GoogleTagManagerViewModel extends ComponentViewModel
{
    public function __construct(
        private CheckoutSession $checkoutSession,
        private BeginCheckout $beginCheckout,
        private AddShippingInfo $addShippingInfo,
        private AddPaymentInfo $addPaymentInfo,
        private Ajax $ajax,
    ) {
    }

    public function getJsComponentName(): ?string
    {
        return 'LokiCheckoutGoogleTagManager';
    }

    public function getJsData(): array
    {
        return [
            ...parent::getJsData(),
            'gtmEvents' => $this->getGtmEvents()
        ];
    }

    private function getGtmEvents(): array
    {
        $events = [];
        $events[] = $this->getBeginCheckoutInfo();
        $events[] = $this->getShippingInfo();
        $events[] = $this->getPaymentInfo();

        return $events;
    }

    private function getBeginCheckoutInfo(): false|array
    {
        if ($this->ajax->isAjax()) {
            return false;
        }

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
