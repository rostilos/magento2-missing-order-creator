<?php
namespace Rostilos\CreateOrders\Model\Service;

use Rostilos\CreateOrders\Model\CreatedRecord;
use Rostilos\CreateOrders\Model\CreatedRecordFactory;
use Rostilos\CreateOrders\Model\ResourceModel\CreatedRecord as CreatedRecordResource;
use Rostilos\CreateOrders\Model\ResourceModel\CreatedRecord\CollectionFactory as CreatedRecordCollectionFactory;
use Magento\Quote\Model\QuoteManagement;
use Magento\Quote\Model\QuoteFactory;
use Rostilos\CreateOrders\Model\Adapter\Pool as AdapterPool;
use Psr\Log\LoggerInterface;
use StripeIntegration\Payments\Exception\RetryLaterException;
use Magento\Quote\Model\ResourceModel\Quote as QuoteResource;

/**
 * Generic Creator service: accepts normalized provider events (via adapter) and
 * attempts to create Magento orders when possible. Records attempts in createorders_records.
 *
 * Throws \StripeIntegration\Payments\Exception\RetryLaterException when a transient DB error is detected,
 * if that class exists in the system. This allows existing webhook helpers (e.g. StripeIntegration) to map to HTTP 409.
 */
class Creator
{
    const RESERVED_ORDER_FIELD = 'reserved_order_id';
    private CreatedRecordFactory $createdRecordFactory;
    private CreatedRecordResource $createdRecordResource;
    private QuoteManagement $quoteManagement;
    private QuoteFactory $quoteFactory;
    private AdapterPool $adapterPool;
    private LoggerInterface $logger;
    private $orderFactory;
    private QuoteResource $quoteResource;

    public function __construct(
        CreatedRecordFactory $createdRecordFactory,
        CreatedRecordResource $createdRecordResource,
        QuoteManagement $quoteManagement,
        QuoteFactory $quoteFactory,
        AdapterPool $adapterPool,
        \Magento\Sales\Model\OrderFactory $orderFactory,
        LoggerInterface $logger,
        QuoteResource $quoteResource
    ) {
        $this->createdRecordFactory = $createdRecordFactory;
        $this->createdRecordResource = $createdRecordResource;
        $this->quoteManagement = $quoteManagement;
        $this->quoteFactory = $quoteFactory;
        $this->adapterPool = $adapterPool;
        $this->orderFactory = $orderFactory;
        $this->logger = $logger;
        $this->quoteResource = $quoteResource;
    }

    /**
     * Main entry point: accepts provider key (e.g. 'stripe') and raw event payload (array).
     *
     * @param string $provider
     * @param array $rawEvent
     * @return CreatedRecord
     * @throws \Exception|RetryLaterException
     */
    public function createFromProviderEvent(string $provider, array $rawEvent)
    {
        $adapter = $this->adapterPool->getAdapter($provider);
        if (!$adapter) {
            throw new \Exception("No adapter registered for provider '{$provider}'");
        }

        $data = $adapter->parseEvent($rawEvent);

        // Normalize provider in returned data
        $data['provider'] = $provider;

        // Create tracking record
        $record = $this->createdRecordFactory->create();
        $record->setData('provider', $data['provider'] ?? $provider);
        $record->setData('event_id', $data['event_id'] ?? null);
        $record->setData('payment_intent_id', $data['payment_intent_id'] ?? null);
        $record->setData('charge_id', $data['charge_id'] ?? null);
        $record->setData('order_increment_id', $data['order_increment_id'] ?? null);
        $record->setData('quote_id', isset($data['quote_id']) ? (int)$data['quote_id'] : null);
        $record->setData('status', 'pending');
        $record->setData('meta', json_encode($data['meta'] ?? $rawEvent));

        try {
            $this->createdRecordResource->save($record);
        } catch (\Exception $e) {
            // Log but continue; persistence failure shouldn't stop webhook flow
            $this->logger->error("CreateOrders: failed to persist created record: " . $e->getMessage());
            $record->setData('meta', json_encode(['_save_error' => $e->getMessage(), 'original_meta' => $data['meta'] ?? $rawEvent]));
        }

        // If order_increment_id already present, try to load the existing order and mark created if it exists.
        if (!empty($data['order_increment_id'])) {
            $incrementId = (string)$data['order_increment_id'];
            $order = null;
            try {
                $order = $this->orderFactory->create()->loadByIncrementId($incrementId);
            } catch (\Exception $e) {
                $this->logger->error("CreateOrders: failed to load order by increment id {$incrementId}: " . $e->getMessage());
            }

            if ($order && $order->getId()) {
                $record->setData('order_increment_id', $incrementId);
                $record->setData('status', 'created');
                try {
                    $this->createdRecordResource->save($record);
                } catch (\Exception $e) {
                    $this->logger->error("CreateOrders: failed to update record status: " . $e->getMessage());
                }
                return $record;
            }
        }

        $quoteId = isset($data['quote_id']) ? (int)$data['quote_id'] : null;
        if (!$quoteId && !empty($data['order_increment_id'])) {
            $reservedOrderId = (string)$data['order_increment_id'];
            try {
                $quote = $this->quoteFactory->create();
                $this->quoteResource->load($quote, $reservedOrderId, self::RESERVED_ORDER_FIELD);
                if ($quote && $quote->getId()) {
                    $quoteId = (int)$quote->getId();
                    // Set quote_id in the record for tracking purposes
                    $record->setData('quote_id', $quoteId);
                    $this->logger->info("CreateOrders: Found quote ID {$quoteId} using reserved_order_id {$reservedOrderId}.");
                }
            } catch (\Exception $e) {
                $this->logger->error("CreateOrders: failed to find quote by reserved_order_id {$reservedOrderId}: " . $e->getMessage());
                // Non-critical error, continue to next step (will fail if no quote ID)
            }
        }
        // If quote_id provided (either from $data or found via reserved_order_id), attempt to recreate order from quote
        if ($quoteId) { // Changed check to use $quoteId variable
            try {
                if (!isset($quote) || !$quote || !$quote->getId()) {
                    $quote = $this->quoteFactory->create()->load($quoteId);
                }

                if (!$quote || !$quote->getId()) {
                    $record->setData('status', 'failed');
                    $record->setData('meta', json_encode(['reason' => 'quote_not_found', 'quote_id' => $quoteId]));
                    try { $this->createdRecordResource->save($record); } catch (\Exception $ignore) {}
                    return $record;
                }

                // Attempt to submit the quote and create an order
                $order = $this->quoteManagement->submit($quote);
                if ($order && $order->getIncrementId()) {
                    $record->setData('order_increment_id', $order->getIncrementId());
                    $record->setData('status', 'created');
                    $meta = json_decode((string)$record->getData('meta'), true) ?: [];
                    $meta['created_by'] = 'createorders_service';
                    $record->setData('meta', json_encode($meta));
                    $this->createdRecordResource->save($record);
                } else {
                    $record->setData('status', 'failed');
                    $record->setData('meta', json_encode(['reason' => 'submit_no_order_returned']));
                    $this->createdRecordResource->save($record);
                }
            } catch (\Exception $e) {
                if ($this->isTransientDbError($e)) {
                    $record->setData('status', 'pending');
                    $record->setData('meta', json_encode(['_transient_error' => $e->getMessage()]));
                    try { $this->createdRecordResource->save($record); } catch (\Exception $ignore) {}
                    // Reuse vendor RetryLaterException when present
                    if (class_exists(RetryLaterException::class)) {
                        throw new RetryLaterException($e->getMessage());
                    }
                    // Fallback to generic \Exception if vendor exception not available
                    throw $e;
                }

                $record->setData('status', 'failed');
                $record->setData('meta', json_encode(['_error' => $e->getMessage()]));
                try { $this->createdRecordResource->save($record); } catch (\Exception $ignore) {}
            }
        }

        return $record;
    }

    /**
     * Basic transient DB error heuristic (MySQL)
     *
     * @param \Exception $e
     * @return bool
     */
    private function isTransientDbError(\Exception $e): bool
    {
        $msg = $e->getMessage();
        $code = (int)$e->getCode();

        if (stripos($msg, 'deadlock') !== false) return true;
        if (stripos($msg, 'Lock wait timeout') !== false) return true;
        if (stripos($msg, 'SQLSTATE[40001]') !== false) return true;
        if ($code === 1213 || $code === 1205) return true;

        return false;
    }
}
