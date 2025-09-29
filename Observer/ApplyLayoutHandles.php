<?php declare(strict_types=1);

namespace Yireo\GoogleTagManager2LokiCheckout\Observer;

use Magento\Framework\App\RequestInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\View\LayoutInterface;

class ApplyLayoutHandles implements ObserverInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly LayoutInterface $layout,
    ) {
    }

    public function execute(Observer $observer): void
    {

        $this->layout->getUpdate()->addHandle('hyva_default');

        if ($this->request->getModuleName() !== 'checkout') {
            return;
        }

        $this->layout->getUpdate()->addHandle('hyva_default');
    }
}
