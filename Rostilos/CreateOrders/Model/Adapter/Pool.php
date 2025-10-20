<?php
namespace Rostilos\CreateOrders\Model\Adapter;

use Magento\Framework\ObjectManagerInterface;
use Rostilos\CreateOrders\Api\AdapterInterface;

class Pool
{
    /**
     * @var array
     *  Example: ['stripe' => \Rostilos\CreateOrders\Model\Adapter\StripeAdapter::class]
     */
    private $adaptersConfig;

    /**
     * @var ObjectManagerInterface
     */
    private $om;

    public function __construct(
        ObjectManagerInterface $om,
        array $adapters = []
    ) {
        $this->om = $om;
        $this->adaptersConfig = $adapters;
    }

    /**
     * Return an AdapterInterface instance for the given provider key.
     *
     * @param string $provider
     * @return AdapterInterface|null
     */
    public function getAdapter(string $provider): ?AdapterInterface
    {
        $provider = trim(strtolower($provider));
        if (empty($provider)) {
            return null;
        }

        if (!isset($this->adaptersConfig[$provider])) {
            return null;
        }

        $class = $this->adaptersConfig[$provider];
        $instance = $this->om->create($class);

        if ($instance instanceof AdapterInterface) {
            return $instance;
        }

        return null;
    }

    /**
     * Return entire adapters map
     * @return array
     */
    public function getAdaptersMap(): array
    {
        return $this->adaptersConfig;
    }
}
