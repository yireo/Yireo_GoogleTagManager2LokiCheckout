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
        try {
            if (false === $this->config->isEnabled()) {
                return $sectionData;
            }

            $sectionData['gtm_events'] = $this->getGtmEvents();
        } catch (Throwable $exception) {
            $this->logFailure('gtm_events', $exception);
        }

        return $sectionData;
    }

    private function getGtmEvents(): array
    {
        if (false === (bool)$this->checkoutSession->hasQuote()) {
            return [];
        }

        return [
            $this->getEvent('begin_checkout', fn () => $this->getBeginCheckoutInfo()),
            $this->getEvent('add_shipping_info', fn () => $this->getShippingInfo()),
            $this->getEvent('add_payment_info', fn () => $this->getPaymentInfo()),
        ];
    }

    private function getEvent(string $eventName, callable $callback): false|array
    {
        try {
            $event = $callback();
        } catch (Throwable $exception) {
            $this->logFailure($eventName, $exception);
            return false;
        }

        if (is_array($event) && false === json_encode($event)) {
            $this->logFailure($eventName, null);
            return false;
        }

        return $event;
    }

    private function logFailure(string $eventName, ?Throwable $exception): void
    {
        $reason = $exception instanceof Throwable ? get_class($exception) : 'data is not JSON serializable';

        try {
            $this->logger->warning(
                'Yireo_GoogleTagManager2LokiCheckout: Failed to generate GTM event "' . $eventName . '" ('
                . $reason . ')'
            );
        } catch (Throwable) {
            return;
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
