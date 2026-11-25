<?php

declare(strict_types=1);

namespace Yireo\GoogleTagManager2LokiCheckout\Test\Integration\Plugin;

use LokiCheckout\Core\CustomerData\Checkout;
use Magento\Catalog\Test\Fixture\Product as ProductFixture;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Test\Fixture\AddProductToCart as AddProductToCartFixture;
use Magento\Quote\Test\Fixture\GuestCart as GuestCartFixture;
use Magento\Store\Model\ScopeInterface;
use Magento\TestFramework\Fixture\AppArea;
use Magento\TestFramework\Fixture\AppIsolation;
use Magento\TestFramework\Fixture\Config;
use Magento\TestFramework\Fixture\DataFixture;
use Magento\TestFramework\Fixture\DataFixtureStorageManager;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Yireo\GoogleTagManager2\Config\Config as GtmConfig;
use Yireo\GoogleTagManager2\DataLayer\Event\AddPaymentInfoFactory;
use Yireo\GoogleTagManager2\DataLayer\Event\AddShippingInfo;
use Yireo\GoogleTagManager2\DataLayer\Event\BeginCheckout;
use Yireo\GoogleTagManager2LokiCheckout\Plugin\AppendGtmEventsToLokiCheckoutSectionPlugin;
use Yireo\IntegrationTestHelper\Test\Integration\Traits\AssertInterceptorPluginIsRegistered;

#[AppArea('frontend')]
#[AppIsolation(true)]
#[DbIsolation(true)]
final class AppendGtmEventsToLokiCheckoutSectionPluginTest extends TestCase
{
    use AssertInterceptorPluginIsRegistered;

    public function testPluginIsRegisteredInFrontendArea(): void
    {
        $this->assertInterceptorPluginIsRegistered(
            Checkout::class,
            AppendGtmEventsToLokiCheckoutSectionPlugin::class,
            'Yireo_GoogleTagManager2LokiCheckout::appendGtmEventsToLokiCheckoutSection'
        );
    }

    #[Config('googletagmanager2/settings/enabled', '0', ScopeInterface::SCOPE_STORE)]
    #[Config('googletagmanager2/settings/id', 'GTM-TEST', ScopeInterface::SCOPE_STORE)]
    #[DataFixture(ProductFixture::class, as: 'product')]
    #[DataFixture(GuestCartFixture::class, as: 'cart')]
    #[DataFixture(AddProductToCartFixture::class, ['cart_id' => '$cart.id$', 'product_id' => '$product.id$'])]
    public function testDisabledGtmLeavesSectionDataUnchangedAndSkipsEventGeneration(): void
    {
        $this->loadQuoteIntoSession();
        $beginCheckout = $this->createMock(BeginCheckout::class);
        $beginCheckout->expects(self::never())->method('get');
        $addShippingInfo = $this->createMock(AddShippingInfo::class);
        $addShippingInfo->expects(self::never())->method('get');
        $addPaymentInfoFactory = $this->createMock(AddPaymentInfoFactory::class);
        $addPaymentInfoFactory->expects(self::never())->method('create');

        $objectManager = Bootstrap::getObjectManager();
        $sectionData = ['customer_email' => 'guest@example.com'];
        $plugin = $objectManager->create(
            AppendGtmEventsToLokiCheckoutSectionPlugin::class,
            [
                'beginCheckout' => $beginCheckout,
                'addShippingInfo' => $addShippingInfo,
                'addPaymentInfoFactory' => $addPaymentInfoFactory,
            ]
        );

        $result = $plugin->afterGetSectionData($objectManager->get(Checkout::class), $sectionData);

        self::assertSame($sectionData, $result);
    }

    #[Config('googletagmanager2/settings/enabled', '1', ScopeInterface::SCOPE_STORE)]
    #[Config('googletagmanager2/settings/id', 'GTM-TEST', ScopeInterface::SCOPE_STORE)]
    public function testEnabledGtmWithNoQuoteReturnsNoEvents(): void
    {
        $objectManager = Bootstrap::getObjectManager();
        $objectManager->get(CheckoutSession::class)->clearQuote();
        $plugin = $objectManager->get(AppendGtmEventsToLokiCheckoutSectionPlugin::class);

        self::assertSame(['gtm_events' => []], $plugin->afterGetSectionData($objectManager->get(Checkout::class), []));
    }

    #[Config('googletagmanager2/settings/enabled', '1', ScopeInterface::SCOPE_STORE)]
    #[Config('googletagmanager2/settings/id', 'GTM-TEST', ScopeInterface::SCOPE_STORE)]
    #[DataFixture(ProductFixture::class, as: 'product')]
    #[DataFixture(GuestCartFixture::class, as: 'cart')]
    #[DataFixture(AddProductToCartFixture::class, ['cart_id' => '$cart.id$', 'product_id' => '$product.id$'])]
    public function testEnabledGtmAddsBeginCheckoutWithTheQuoteItem(): void
    {
        $this->loadQuoteIntoSession();
        $sectionData = Bootstrap::getObjectManager()->get(Checkout::class)->getSectionData();
        $product = DataFixtureStorageManager::getStorage()->get('product');

        self::assertArrayHasKey('gtm_events', $sectionData);
        $events = array_column(array_filter($sectionData['gtm_events'], 'is_array'), null, 'event');
        self::assertArrayHasKey('begin_checkout', $events);
        $itemIds = array_column($events['begin_checkout']['ecommerce']['items'], 'item_id');
        self::assertContains($product->getSku(), $itemIds);
    }

    #[Config('googletagmanager2/settings/enabled', '1', ScopeInterface::SCOPE_STORE)]
    #[Config('googletagmanager2/settings/id', 'GTM-TEST', ScopeInterface::SCOPE_STORE)]
    #[DataFixture(ProductFixture::class, as: 'product')]
    #[DataFixture(GuestCartFixture::class, as: 'cart')]
    #[DataFixture(AddProductToCartFixture::class, ['cart_id' => '$cart.id$', 'product_id' => '$product.id$'])]
    public function testBeginCheckoutFailureIsLoggedWithoutMessageAndDoesNotSuppressPaymentEvent(): void
    {
        $this->loadQuoteIntoSession()->getPayment()->setMethod('checkmo');
        $sensitiveMessage = 'guest@example.com could not be processed';
        $beginCheckout = $this->createMock(BeginCheckout::class);
        $beginCheckout->expects(self::once())
            ->method('get')
            ->willThrowException(new RuntimeException($sensitiveMessage));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('warning')
            ->with(self::logicalAnd(
                self::stringContains('begin_checkout'),
                self::stringContains(RuntimeException::class),
                self::logicalNot(self::stringContains($sensitiveMessage))
            ));

        $result = $this->createPlugin(['beginCheckout' => $beginCheckout, 'logger' => $logger])
            ->afterGetSectionData($this->getCheckoutSection(), ['customer_email' => 'guest@example.com']);

        self::assertSame('guest@example.com', $result['customer_email']);
        $eventNames = $this->getEventNames($result);
        self::assertNotContains('begin_checkout', $eventNames);
        self::assertContains('add_payment_info', $eventNames);
    }

    #[Config('googletagmanager2/settings/enabled', '1', ScopeInterface::SCOPE_STORE)]
    #[Config('googletagmanager2/settings/id', 'GTM-TEST', ScopeInterface::SCOPE_STORE)]
    #[DataFixture(ProductFixture::class, as: 'product')]
    #[DataFixture(GuestCartFixture::class, as: 'cart')]
    #[DataFixture(AddProductToCartFixture::class, ['cart_id' => '$cart.id$', 'product_id' => '$product.id$'])]
    public function testThrowingLoggerDoesNotBreakTheSection(): void
    {
        $this->loadQuoteIntoSession()->getPayment()->setMethod('checkmo');
        $beginCheckout = $this->createMock(BeginCheckout::class);
        $beginCheckout->method('get')->willThrowException(new RuntimeException('Event failure'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->method('warning')->willThrowException(new RuntimeException('Logger failure'));

        $result = $this->createPlugin(['beginCheckout' => $beginCheckout, 'logger' => $logger])
            ->afterGetSectionData($this->getCheckoutSection(), ['customer_email' => 'guest@example.com']);

        self::assertSame('guest@example.com', $result['customer_email']);
        self::assertContains('add_payment_info', $this->getEventNames($result));
    }

    public function testConfigFailureReturnsTheOriginalSection(): void
    {
        $config = $this->createMock(GtmConfig::class);
        $config->method('isEnabled')->willThrowException(new RuntimeException('Config failure'));
        $sectionData = ['customer_email' => 'guest@example.com'];

        $result = $this->createPlugin(['config' => $config])
            ->afterGetSectionData($this->getCheckoutSection(), $sectionData);

        self::assertSame($sectionData, $result);
    }

    #[Config('googletagmanager2/settings/enabled', '1', ScopeInterface::SCOPE_STORE)]
    #[Config('googletagmanager2/settings/id', 'GTM-TEST', ScopeInterface::SCOPE_STORE)]
    #[DataFixture(ProductFixture::class, as: 'product')]
    #[DataFixture(GuestCartFixture::class, as: 'cart')]
    #[DataFixture(AddProductToCartFixture::class, ['cart_id' => '$cart.id$', 'product_id' => '$product.id$'])]
    public function testEventThatCanNotBeEncodedAsJsonIsDropped(): void
    {
        $this->loadQuoteIntoSession()->getPayment()->setMethod('checkmo');
        $beginCheckout = $this->createMock(BeginCheckout::class);
        $beginCheckout->method('get')->willReturn(['event' => 'begin_checkout', 'ecommerce' => ['coupon' => "\xB1\x31"]]);

        $result = $this->createPlugin(['beginCheckout' => $beginCheckout])
            ->afterGetSectionData($this->getCheckoutSection(), []);

        self::assertNotFalse(json_encode($result));
        $eventNames = $this->getEventNames($result);
        self::assertNotContains('begin_checkout', $eventNames);
        self::assertContains('add_payment_info', $eventNames);
    }

    #[Config('googletagmanager2/settings/enabled', '1', ScopeInterface::SCOPE_STORE)]
    #[Config('googletagmanager2/settings/id', 'GTM-TEST', ScopeInterface::SCOPE_STORE)]
    #[DataFixture(ProductFixture::class, as: 'product')]
    #[DataFixture(GuestCartFixture::class, as: 'cart')]
    #[DataFixture(AddProductToCartFixture::class, ['cart_id' => '$cart.id$', 'product_id' => '$product.id$'])]
    public function testPaymentEventFollowsTheCurrentPaymentMethod(): void
    {
        $quote = $this->loadQuoteIntoSession();
        $plugin = $this->createPlugin([]);
        $checkout = $this->getCheckoutSection();

        foreach (['checkmo', 'banktransfer'] as $paymentMethod) {
            $quote->getPayment()->setMethod($paymentMethod);
            $events = $this->getEvents($plugin->afterGetSectionData($checkout, []));
            self::assertSame($paymentMethod, $events['add_payment_info']['ecommerce']['payment_type'] ?? null);
        }

        $quote->getPayment()->setMethod('');
        self::assertNotContains('add_payment_info', $this->getEventNames($plugin->afterGetSectionData($checkout, [])));
    }

    private function createPlugin(array $arguments): AppendGtmEventsToLokiCheckoutSectionPlugin
    {
        return Bootstrap::getObjectManager()->create(AppendGtmEventsToLokiCheckoutSectionPlugin::class, $arguments);
    }

    private function getCheckoutSection(): Checkout
    {
        return Bootstrap::getObjectManager()->get(Checkout::class);
    }

    private function getEvents(array $sectionData): array
    {
        return array_column(array_filter($sectionData['gtm_events'] ?? [], 'is_array'), null, 'event');
    }

    private function getEventNames(array $sectionData): array
    {
        return array_keys($this->getEvents($sectionData));
    }

    private function loadQuoteIntoSession(): Quote
    {
        $objectManager = Bootstrap::getObjectManager();
        $cart = DataFixtureStorageManager::getStorage()->get('cart');
        $quote = $objectManager->get(CartRepositoryInterface::class)->get((int)$cart->getId());
        self::assertInstanceOf(Quote::class, $quote);
        $objectManager->get(CheckoutSession::class)->replaceQuote($quote);

        return $quote;
    }
}
