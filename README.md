CreateOrders — Generic "create order from webhook" module (how-to)

Overview
--------
CreateOrders is a payment-provider-agnostic Magento module that:
- normalizes payment-provider webhook payloads via pluggable adapters,
- records each incoming event to a DB table (createorders_records),
- when possible, attempts to recreate a Magento order from a stored quote (quote->submit),
- marks the record status (pending, created, failed) and stores raw metadata for diagnostics.

This module ships with an example Stripe adapter and an adapter pool. 
It throws vendor RetryLaterException (if available) when a transient DB error is detected so existing webhook handlers (e.g. StripeIntegration_Payments) can return HTTP 409 and allow the provider to retry.

Install & enable
----------------
From Magento root:
1) Copy code into app/code/Rostilos/CreateOrders (already done if using this repo).
2) Enable and upgrade:
   php bin/magento module:enable Rostilos_CreateOrders
   php bin/magento setup:upgrade
   php bin/magento cache:flush


How the flow works
------------------
1) A provider webhook is received by your existing webhook endpoint.
2) If that endpoint dispatches a Magento event (e.g., stripe_payments_webhook_charge_succeeded), CreateOrders' observer (ProviderWebhookObserver) will get called (example wiring included, see etc/events.xml).
3) Observer passes the raw event to Creator::createFromProviderEvent($provider, $rawEvent).
4) Creator:
   - uses adapter pool to obtain provider-specific adapter and parse/normalize the payload,
   - inserts a tracking record in createorders_records (status=pending),
   - if an order increment is already present: mark as created,
   - if a quote_id is present: attempt quote->submit() to create the order:
       - on success: mark created and store order increment,
       - on non-transient failure: mark failed (meta contains error),
       - on transient DB errors (deadlock / lock wait): save record as pending and throw RetryLaterException (if vendor class exists) so webhook handler can return HTTP 409.

Extending for a new provider (summary)
--------------------------------------
1) Create an adapter that implements Rostilos\CreateOrders\Api\AdapterInterface::parseEvent(array $event): array and return normalized keys:
   - provider, event_id, payment_intent_id, charge_id, order_increment_id, quote_id, meta.
2) Register the adapter in the pool via etc/di.xml:
   <type name="Rostilos\CreateOrders\Model\Adapter\Pool">
     <arguments>
       <argument name="adapters" xsi:type="array">
         <item name="your_provider_key" xsi:type="string">Vendor\Module\Adapter\YourAdapter</item>
       </argument>
     </arguments>
   </type>
3) Wire your provider's webhook event to the observer in Rostilos/CreateOrders/etc/events.xml or ensure your provider's webhook dispatcher calls the Creator service directly:
   $creator->createFromProviderEvent('your_provider_key', $payload);

Stripe testing (example)
------------------------
- Use Stripe CLI:
  stripe listen --forward-to https://your-store/stripe/webhooks
  stripe trigger payment_intent.succeeded

- Observe a new record in createorders_records and check status:
  SELECT * FROM createorders_records WHERE provider = 'stripe' ORDER BY created_at DESC LIMIT 5;

Troubleshooting / logs
----------------------
- Check createorders_records.meta for raw payload and error info.
- Magento logs: var/log/system.log and var/log/exception.log
- For DB deadlocks / lock wait timeouts: inspect DB error message (MySQL errno 1213 or 1205) and the Magento exception stack trace (Creator stores meta). Transient errors may cause a RetryLaterException to be thrown (mapped to 409).
