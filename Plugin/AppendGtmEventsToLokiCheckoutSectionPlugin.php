<?php declare(strict_types=1);

namespace Yireo\GoogleTagManager2LokiCheckout\Plugin;

use LokiCheckout\Core\CustomerData\Checkout;
use Magento\Checkout\Model\Session as CheckoutSession;
use Psr\Log\LoggerInterface;
use Throwable;
use Yireo\GoogleTagManager2\Config\Config;
use Yireo\GoogleTagManager2\DataLayer\Event\AddPaymentInfoFactory;
use Yireo\GoogleTagManager2\DataLayer\Event\AddShippingInfo;
use Yireo\GoogleTagManager2\DataLayer\Event\BeginCheckout;

class AppendGtmEventsToLokiCheckoutSectionPlugin
{
    public function __construct(
        private readonly CheckoutSession $checkoutSession,
        private readonly BeginCheckout $beginCheckout,
        private readonly AddShippingInfo $addShippingInfo,
        private readonly AddPaymentInfoFactory $addPaymentInfoFactory,
        private readonly Config $config,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function afterGetSectionData(Checkout $checkoutSection, array $sectionData): array
    {
        if (!$this->config->isEnabled()) {
            return $sectionData;
        }

        $sectionData['gtm_events'] = $this->getGtmEvents();
        return $sectionData;
    }

    private function getGtmEvents(): array
    {
        $events = [];
        if (false === (bool)$this->checkoutSession->hasQuote()) {
            return $events;
        }

        $events[] = $this->getEvent('begin_checkout', fn () => $this->getBeginCheckoutInfo());
        $events[] = $this->getEvent('add_shipping_info', fn () => $this->getShippingInfo());
        $events[] = $this->getEvent('add_payment_info', fn () => $this->getPaymentInfo());

        return $events;
    }

    private function getEvent(string $eventName, callable $callback): false|array
    {
        try {
            return $callback();
        } catch (Throwable $e) {
            $this->logger->warning(
                'Yireo_GoogleTagManager2LokiCheckout: Failed to generate GTM event "' . $eventName . '": '
                . $e->getMessage()
            );
            return false;
        }
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
        $quote = $this->checkoutSession->getQuote();
        $addPaymentInfo = $this->addPaymentInfoFactory->create();
        $addPaymentInfo->setCartId((int)$quote->getId())
            ->setPaymentMethod((string)$quote->getPayment()->getMethod());

        $paymentInfo = $addPaymentInfo->get();
        if (empty($paymentInfo)) {
            return false;
        }

        if (empty($paymentInfo['ecommerce']['payment_type'])) {
            return false;
        }

        return $paymentInfo;
    }
}
