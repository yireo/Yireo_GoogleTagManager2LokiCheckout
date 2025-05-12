<?php
declare(strict_types=1);

namespace Yireo\GoogleTagManager2LokiCheckout\Component;

use Magento\Checkout\Model\Session as CheckoutSession;
use Yireo\GoogleTagManager2\DataLayer\Event\AddPaymentInfo;
use Yireo\GoogleTagManager2\DataLayer\Event\AddShippingInfo;
use Yireo\GoogleTagManager2\DataLayer\Event\BeginCheckout;
use Yireo\LokiComponents\Component\ComponentViewModel;

class GoogleTagManagerViewModel extends ComponentViewModel
{
    public function __construct(
        private CheckoutSession $checkoutSession,
        private BeginCheckout $beginCheckout,
        private AddShippingInfo $addShippingInfo,
        private AddPaymentInfo $addPaymentInfo,
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
            'beginCheckout' => $this->getBeginCheckoutInfo(),
            'shippingInfo' => $this->getShippingInfo(),
            'paymentInfo' => $this->getPaymentInfo(),
        ];
    }

    private function getBeginCheckoutInfo(): array
    {
        return $this->beginCheckout->get();
    }

    private function getShippingInfo(): array
    {
        return $this->addShippingInfo->get();
    }

    private function getPaymentInfo(): array
    {
        $this->addPaymentInfo->setCartId((int)$this->checkoutSession->getQuote()->getId());
        $this->addPaymentInfo->setPaymentMethod((string)$this->checkoutSession->getQuote()->getPayment()->getMethod());

        return $this->addPaymentInfo->get();
    }
}
