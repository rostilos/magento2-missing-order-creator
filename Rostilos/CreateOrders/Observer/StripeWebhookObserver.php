<?php
namespace Rostilos\CreateOrders\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Psr\Log\LoggerInterface;
use Rostilos\CreateOrders\Model\Service\Creator;

class StripeWebhookObserver implements ObserverInterface
{
    private Creator $creator;
    private LoggerInterface $logger;

    public function __construct(
        Creator $creator,
        LoggerInterface $logger
    ) {
        $this->creator = $creator;
        $this->logger = $logger;
    }

    /**
     * Observer executed for provider-specific webhook events (e.g. Stripe).
     * Expects the event payload to be available as 'arrEvent' (array) or 'stdEvent' (stdClass).
     *
     * The event name wiring (which provider) is mapped in the events.xml that triggers this observer.
     * This observer currently assumes Stripe provider for the events declared in module's events.xml.
     *
     * @param Observer $observer
     * @throws \Exception|\StripeIntegration\Payments\Exception\RetryLaterException
     */
    public function execute(Observer $observer)
    {
        $arrEvent = $observer->getData('arrEvent') ?? null;
        $stdEvent = $observer->getData('stdEvent') ?? null;

        if (!$arrEvent && !$stdEvent) {
            $this->logger->warning("ProviderWebhookObserver called without event payload.");
            return;
        }

        $event = $arrEvent ?? json_decode(json_encode($stdEvent), true);

        try {
            // For now, the events.xml attached in this module points to Stripe events.
            // If you want to use the same observer for other providers, pass provider name via observer args or create provider-specific events.
            $provider = 'stripe';
            $this->creator->createFromProviderEvent($provider, $event);
        } catch (\Exception $e) {
            // Log full error for diagnostics
            $this->logger->error("CreateOrders provider observer error: " . $e->getMessage());
            $this->logger->debug($e->getTraceAsString());

            // If this is a transient retry exception (vendor class), rethrow so the webhooks helper maps it to 409.
            if (class_exists(\StripeIntegration\Payments\Exception\RetryLaterException::class)
                && $e instanceof \StripeIntegration\Payments\Exception\RetryLaterException) {
                throw $e;
            }

            // Otherwise do not rethrow to avoid breaking webhook processing; the creator saved a record with status=failed/pending.
        }
    }
}
