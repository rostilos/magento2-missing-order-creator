<?php
namespace Rostilos\CreateOrders\Model;

use Magento\Framework\Model\AbstractModel;

class CreatedRecord extends AbstractModel
{
    protected function _construct()
    {
        $this->_init(\Rostilos\CreateOrders\Model\ResourceModel\CreatedRecord::class);
    }

    public function markCreated(string $incrementId)
    {
        $this->setData('order_increment_id', $incrementId);
        $this->setData('status', 'created');
        return $this->save();
    }

    public function markFailed($meta = [])
    {
        $this->setData('status', 'failed');
        $this->setData('meta', is_array($meta) ? json_encode($meta) : $meta);
        return $this->save();
    }

    public function markPending($meta = [])
    {
        $this->setData('status', 'pending');
        if (!empty($meta)) {
            $this->setData('meta', is_array($meta) ? json_encode($meta) : $meta);
        }
        return $this->save();
    }
}
