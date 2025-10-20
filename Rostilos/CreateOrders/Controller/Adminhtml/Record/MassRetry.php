<?php
namespace Rostilos\CreateOrders\Controller\Adminhtml\Record;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\RedirectFactory;
use Rostilos\CreateOrders\Model\CreatedRecordFactory;
use Rostilos\CreateOrders\Model\Service\Creator;
use Psr\Log\LoggerInterface;

class MassRetry extends Action
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
        $request = $this->getRequest();

        // massaction sends 'selected' => [id,...] or 'entity_id' param depending on UI configuration
        $selected = $request->getParam('selected');
        if (empty($selected) || !is_array($selected)) {
            // Try common alternative
            $selected = $request->getParam('entity_id');
            if (!is_array($selected)) {
                $this->messageManager->addErrorMessage(__('No records selected.'));
                return $result->setPath('*/*/');
            }
        }

        $success = 0;
        $failed = 0;

        foreach ($selected as $id) {
            try {
                $record = $this->createdRecordFactory->create()->load((int)$id);
                if (!$record || !$record->getId()) {
                    $failed++;
                    continue;
                }

                $meta = $record->getData('meta');
                $raw = null;
                if ($meta) {
                    $decoded = json_decode($meta, true);
                    $raw = $decoded['original_meta'] ?? $decoded ?? null;
                }

                if (!$raw) {
                    $failed++;
                    continue;
                }

                $provider = $record->getData('provider') ?? 'stripe';
                $this->creator->createFromProviderEvent($provider, (array)$raw);
                $success++;
            } catch (\Exception $e) {
                $this->logger->error("CreateOrders mass retry failed for id {$id}: " . $e->getMessage());
                $failed++;
            }
        }

        if ($success) {
            $this->messageManager->addSuccessMessage(__("%1 records were retried.", $success));
        }
        if ($failed) {
            $this->messageManager->addErrorMessage(__("%1 records failed to retry or had no payload.", $failed));
        }

        return $result->setPath('*/*/');
    }
}
