<?php
namespace Rostilos\CreateOrders\Model\Adapter;

use Rostilos\CreateOrders\Api\AdapterInterface;

class StripeAdapter implements AdapterInterface
{
    public function parseEvent(array $event): array
    {
        $data = [
            'provider' => 'stripe',
            'event_id' => $event['id'] ?? null,
            'meta' => $event
        ];

        $object = $event['data']['object'] ?? [];

        // Payment Intent / Checkout Session / Charge handling
        if (!empty($object['payment_intent']))
        {
            $data['payment_intent_id'] = $object['payment_intent'];
        }

        if (!empty($object['id']) && ($object['object'] == 'charge' || $object['object'] == 'payment_intent'))
        {
            if ($object['object'] == 'charge')
            {
                $data['charge_id'] = $object['id'];
                if (!empty($object['payment_intent'])) $data['payment_intent_id'] = $object['payment_intent'];
            }
            else if ($object['object'] == 'payment_intent')
            {
                $data['payment_intent_id'] = $object['id'];
                if (!empty($object['charges']['data'][0]['id'])) $data['charge_id'] = $object['charges']['data'][0]['id'];
            }
        }

        // Extract order increment stored in metadata (common key used by stripe integration)
        if (!empty($object['metadata']['Order #']))
            $data['order_increment_id'] = (string)$object['metadata']['Order #'];

        // Try to get quote id from metadata if available
        if (!empty($object['metadata']['Quote ID']))
            $data['quote_id'] = (int)$object['metadata']['Quote ID'];

        // If checkout.session, try to map
        if ($object['object'] == 'checkout.session') {
            if (!empty($object['metadata']['Order #'])) {
                $data['order_increment_id'] = (string)$object['metadata']['Order #'];
            }
            if (!empty($object['metadata']['Quote ID'])) {
                $data['quote_id'] = (int)$object['metadata']['Quote ID'];
            }
        }

        return $data;
    }
}
