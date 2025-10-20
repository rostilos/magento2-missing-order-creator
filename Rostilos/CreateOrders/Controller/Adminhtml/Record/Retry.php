<?php
namespace Rostilos\CreateOrders\Controller\Adminhtml\Record;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\RedirectFactory;
use Rostilos\CreateOrders\Model\CreatedRecordFactory;
use Rostilos\CreateOrders\Model\Service\Creator;
use Psr\Log\LoggerInterface;

class Retry extends Action
{
    const ADMIN_RESOURCE = 'Rostilos_CreateOrders::retry';

    protected $resultRedirectFactory;
    private $createdRecordFactory;
    private $creator;
    private $logger;

    public function __construct(
        Context $context,
        RedirectFactory $resultRedirectFactory,
        CreatedRecordFactory $createdRecordFactory,
        Creator $creator,
        LoggerInterface $logger
    ) {
        parent::__construct($context);
        $this->resultRedirectFactory = $resultRedirectFactory;
        $this->createdRecordFactory = $createdRecordFactory;
        $this->creator = $creator;
        $this->logger = $logger;
    }

    public function execute()
    {
        $result = $this->resultRedirectFactory->create();
        $id = (int)$this->getRequest()->getParam('entity_id');

        if (!$id) {
            $this->messageManager->addErrorMessage(__('No record specified to retry.'));
            return $result->setPath('*/*/');
        }

        try {
            $record = $this->createdRecordFactory->create()->load($id);
            if (!$record || !$record->getId()) {
                $this->messageManager->addErrorMessage(__('Record not found.'));
                return $result->setPath('*/*/');
            }

            // Re-dispatch to creator using provider and raw meta (if available)
            $meta = $record->getData('meta');
            $raw = null;
            if ($meta) {
                $decoded = json_decode($meta, true);
                $raw = $decoded['original_meta'] ?? $decoded ?? null;
            }

            if (!$raw) {
                $this->messageManager->addErrorMessage(__('No raw payload available to retry.'));
                return $result->setPath('*/*/');
            }

            // Call creator
            $provider = $record->getData('provider') ?? 'stripe';
            $this->creator->createFromProviderEvent($provider, (array)$raw);

            $this->messageManager->addSuccessMessage(__('Retry requested. Check record status.'));
        } catch (\Exception $e) {
            $this->logger->error("CreateOrders retry failed: " . $e->getMessage());
            $this->messageManager->addErrorMessage(__('Retry failed: %1', $e->getMessage()));
        }

        return $result->setPath('*/*/');
    }
}
