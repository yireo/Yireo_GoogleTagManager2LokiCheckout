<?php

declare(strict_types=1);

namespace Yireo\GoogleTagManager2LokiCheckout\Test\Integration;

use PHPUnit\Framework\TestCase;
use Yireo\IntegrationTestHelper\Test\Integration\Traits\AssertModuleIsEnabled;
use Yireo\IntegrationTestHelper\Test\Integration\Traits\AssertModuleIsRegistered;

final class ModuleTest extends TestCase
{
    use AssertModuleIsEnabled;
    use AssertModuleIsRegistered;

    public function testRequiredModulesAreRegisteredAndEnabled(): void
    {
        foreach ([
            'LokiCheckout_Core',
            'Magento_Checkout',
            'Magento_Store',
            'Yireo_GoogleTagManager2',
            'Yireo_GoogleTagManager2LokiCheckout',
        ] as $moduleName) {
            $this->assertModuleIsRegistered($moduleName);
            $this->assertModuleIsEnabled($moduleName);
        }
    }
}
