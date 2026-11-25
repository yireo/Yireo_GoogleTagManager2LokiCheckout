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
use Yireo\GoogleTagManager2\DataLayer\Event\AddPaymentInfo;
use Yireo\GoogleTagManager2\DataLayer\Event\AddPaymentInfoFactory;
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
    public function testDisabledGtmLeavesSectionDataUnchanged(): void
    {
        $objectManager = Bootstrap::getObjectManager();
        $sectionData = ['customer_email' => 'guest@example.com'];
        $plugin = $objectManager->get(AppendGtmEventsToLokiCheckoutSectionPlugin::class);

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
    public function testBeginCheckoutFailureIsLoggedAndDoesNotSuppressPaymentEvent(): void
    {
        $this->loadQuoteIntoSession()->getPayment()->setMethod('checkmo');
        $exceptionMessage = 'Could not calculate the begin checkout event';
        $beginCheckout = $this->createMock(BeginCheckout::class);
        $beginCheckout->expects(self::once())
            ->method('get')
            ->willThrowException(new RuntimeException($exceptionMessage));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning')->with(self::stringContains($exceptionMessage));

        $objectManager = Bootstrap::getObjectManager();
        $plugin = $objectManager->create(
            AppendGtmEventsToLokiCheckoutSectionPlugin::class,
            ['beginCheckout' => $beginCheckout, 'logger' => $logger]
        );
        $result = $plugin->afterGetSectionData(
            $objectManager->get(Checkout::class),
            ['customer_email' => 'guest@example.com']
        );

        self::assertSame('guest@example.com', $result['customer_email']);
        self::assertContains(false, $result['gtm_events']);
        $eventNames = array_column(array_filter($result['gtm_events'], 'is_array'), 'event');
        self::assertNotContains('begin_checkout', $eventNames);
        self::assertContains('add_payment_info', $eventNames);
    }

    public function testPaymentEventFactoryCreatesFreshInstances(): void
    {
        $factory = Bootstrap::getObjectManager()->get(AddPaymentInfoFactory::class);
        $firstEvent = $factory->create();
        $secondEvent = $factory->create();

        self::assertInstanceOf(AddPaymentInfo::class, $firstEvent);
        self::assertInstanceOf(AddPaymentInfo::class, $secondEvent);
        self::assertNotSame($firstEvent, $secondEvent);
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
