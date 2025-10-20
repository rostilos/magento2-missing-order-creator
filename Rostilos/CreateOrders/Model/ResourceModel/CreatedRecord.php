<?php
namespace Rostilos\CreateOrders\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class CreatedRecord extends AbstractDb
{
    protected function _construct()
    {
        $this->_init('createorders_records', 'entity_id');
    }
}
