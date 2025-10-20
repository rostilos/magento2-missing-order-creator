<?php
namespace Rostilos\CreateOrders\Model\ResourceModel\CreatedRecord;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

class Collection extends AbstractCollection
{
    protected function _construct()
    {
        $this->_init(
            \Rostilos\CreateOrders\Model\CreatedRecord::class,
            \Rostilos\CreateOrders\Model\ResourceModel\CreatedRecord::class
        );
    }
}
