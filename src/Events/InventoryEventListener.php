<?php

declare(strict_types=1);

namespace App\Events;

use App\Helpers\EmailHelper;
use App\Repositories\UserRepository;
use Psr\Log\LoggerInterface;

/**
 * Handles side-effects for inventory domain events by logging
 * warnings and sending notification emails to staff users.
 */
class InventoryEventListener
{
    public function __construct(
        private readonly EmailHelper $emailHelper,
        private readonly UserRepository $userRepo,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Handle the "inventory.low_stock" event.
     *
     * Logs a warning and emails all staff users with the item name,
     * current stock level, and reorder threshold.
     *
     * @param array $payload Must contain an 'item' key with the inventory item record.
     */
    public function onLowStock(array $payload): void
    {
        $item = $payload['item'];

        $this->logger->warning('Low stock alert', [
            'item_id'          => $item['id'],
            'item_name'        => $item['name'],
            'quantity_in_stock' => $item['quantity_in_stock'],
            'reorder_level'    => $item['reorder_level'],
        ]);

        // Notify all staff members
        $staffResult = $this->userRepo->findAll(['role' => 'staff']);
        $staffUsers  = $staffResult['data'] ?? [];

        $subject = "Low stock alert: {$item['name']}";
        $body    = "<p>The following inventory item has reached its reorder threshold:</p>"
            . "<p><strong>Item:</strong> {$item['name']}</p>"
            . "<p><strong>Current stock:</strong> {$item['quantity_in_stock']}</p>"
            . "<p><strong>Reorder level:</strong> {$item['reorder_level']}</p>"
            . "<p>Please arrange restocking as soon as possible.</p>";

        foreach ($staffUsers as $staff) {
            $this->emailHelper->sendNotification(
                $staff['email'],
                $staff['full_name'],
                $subject,
                $body
            );
        }
    }
}
