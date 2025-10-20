<?php
namespace Rostilos\CreateOrders\Api;

interface AdapterInterface
{
    /**
     * Parse a provider webhook event and return normalized data used by the creation service.
     * Expected return keys (as available):
     *  - provider (string)
     *  - event_id
     *  - payment_intent_id
     *  - charge_id
     *  - order_increment_id
     *  - quote_id
     *  - meta (array)
     *
     * @param array $event
     * @return array
     */
    public function parseEvent(array $event): array;
}
