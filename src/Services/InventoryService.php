<?php

declare(strict_types=1);

namespace App\Services;

use App\Events\EventDispatcher;
use App\Repositories\AuditRepository;
use App\Repositories\InventoryRepository;
use RuntimeException;

/**
 * Manages inventory catalog items, stock adjustments, and
 * deductions triggered by approved inventory requests.
 *
 * Delegates persistence to InventoryRepository, records audit
 * trails via AuditRepository, and dispatches domain events
 * (e.g. low-stock alerts) through the EventDispatcher.
 */
class InventoryService
{
    public function __construct(
        private readonly InventoryRepository $inventoryRepo,
        private readonly EventDispatcher $eventDispatcher,
        private readonly AuditRepository $auditRepo,
    ) {}

    /**
     * Create a new inventory catalog item.
     *
     * Validates required fields, enforces SKU uniqueness, persists
     * the item, records an initial stock transaction when applicable,
     * and logs the creation to the audit trail.
     *
     * @param  array $data   Item data (name, unit, sku, quantity_in_stock, reorder_level, category, etc.).
     * @param  int   $userId The ID of the user creating the item.
     * @return array The newly created item record.
     * @throws RuntimeException If validation fails or the SKU is already in use.
     */
    public function createItem(array $data, int $userId): array
    {
        $this->validateItemData($data);

        // SKU uniqueness check
        if (!empty($data['sku'])) {
            $existing = $this->inventoryRepo->findBySku($data['sku']);

            if ($existing !== null) {
                throw new RuntimeException('An item with this SKU already exists.');
            }
        }

        $itemId = $this->inventoryRepo->create($data);

        // Record initial stock transaction when stock is provided
        $initialStock = (int) ($data['quantity_in_stock'] ?? 0);

        if ($initialStock > 0) {
            $this->inventoryRepo->addTransaction(
                $itemId,
                'in',
                $initialStock,
                $userId,
                'initial',
                null,
                'Initial'
            );
        }

        $this->auditRepo->log(
            'inventory.item_created',
            $userId,
            'inventory_item',
            $itemId,
            null,
            $data
        );

        return $this->inventoryRepo->findById($itemId);
    }

    /**
     * Update an existing inventory catalog item.
     *
     * Fetches the current record, enforces SKU uniqueness when
     * changed, persists updates, and logs old/new values.
     *
     * @param  int   $id     The item ID to update.
     * @param  array $data   Fields to update.
     * @param  int   $userId The ID of the user performing the update.
     * @return array The updated item record.
     * @throws RuntimeException If the item is not found or SKU conflicts.
     */
    public function updateItem(int $id, array $data, int $userId): array
    {
        $item = $this->inventoryRepo->findById($id);

        if ($item === null) {
            throw new RuntimeException('Inventory item not found.');
        }

        // SKU uniqueness check when SKU is being changed
        if (isset($data['sku']) && $data['sku'] !== ($item['sku'] ?? null)) {
            $existing = $this->inventoryRepo->findBySku($data['sku']);

            if ($existing !== null && (int) $existing['id'] !== $id) {
                throw new RuntimeException('An item with this SKU already exists.');
            }
        }

        $this->inventoryRepo->update($id, $data);

        $this->auditRepo->log(
            'inventory.item_updated',
            $userId,
            'inventory_item',
            $id,
            $item,
            $data
        );

        return $this->inventoryRepo->findById($id);
    }

    /**
     * Adjust the stock level of an inventory item.
     *
     * Supports three adjustment types:
     *  - "in":         Adds the given quantity to current stock.
     *  - "out":        Subtracts the given quantity (fails if insufficient).
     *  - "adjustment": Sets stock to the exact quantity provided.
     *
     * After the adjustment, a low-stock event is dispatched when the
     * resulting quantity falls at or below the item's reorder level.
     *
     * @param  int         $itemId   The inventory item ID.
     * @param  string      $type     Adjustment type: "in", "out", or "adjustment".
     * @param  int         $quantity The quantity to apply.
     * @param  int         $userId   The user performing the adjustment.
     * @param  string|null $notes    Optional notes for the transaction record.
     * @return array The updated item record.
     * @throws RuntimeException If the item is not found, type is invalid, or stock is insufficient.
     */
    public function adjustStock(int $itemId, string $type, int $quantity, int $userId, ?string $notes = null): array
    {
        $item = $this->inventoryRepo->findById($itemId);

        if ($item === null) {
            throw new RuntimeException('Inventory item not found.');
        }

        if (!in_array($type, ['in', 'out', 'adjustment'], true)) {
            throw new RuntimeException("Invalid adjustment type '{$type}'. Must be 'in', 'out', or 'adjustment'.");
        }

        if ($quantity <= 0) {
            throw new RuntimeException('Quantity must be greater than zero.');
        }

        $currentStock = (int) $item['quantity_in_stock'];

        if ($type === 'in') {
            $delta = $quantity;
            $this->inventoryRepo->adjustStock($itemId, $delta);
        } elseif ($type === 'out') {
            if ($quantity > $currentStock) {
                throw new RuntimeException(
                    "Insufficient stock. Available: {$currentStock}, requested: {$quantity}."
                );
            }

            $delta = -$quantity;
            $this->inventoryRepo->adjustStock($itemId, $delta);
        } else {
            // adjustment — set stock to the exact value
            $delta = $quantity - $currentStock;
            $this->inventoryRepo->updateStock($itemId, $quantity);
        }

        // Record the transaction
        /*$this->inventoryRepo->addTransaction([
            'inventory_item_id' => $itemId,
            'type'              => $type,
            'quantity'          => $quantity,
            'reference_type'    => $notes ? 'manual' : null,
            'notes'             => $notes,
            'created_by'        => $userId,
        ]);*/
        $this->inventoryRepo->addTransaction(
            $itemId,
            $type,
            $quantity,
            $userId,
            $notes ? 'manual' : null,
            null,
            $notes,
        );

        $updatedItem = $this->inventoryRepo->findById($itemId);

        // Dispatch low-stock alert when at or below reorder level
        $reorderLevel = (int) ($updatedItem['reorder_level'] ?? 0);

        if ($reorderLevel > 0 && (int) $updatedItem['quantity_in_stock'] <= $reorderLevel) {
            $this->eventDispatcher->dispatch('inventory.low_stock', [
                'item' => $updatedItem,
            ]);
        }

        $this->auditRepo->log(
            'inventory.stock_adjusted',
            $userId,
            'inventory_item',
            $itemId,
            ['quantity_in_stock' => $currentStock],
            ['quantity_in_stock' => (int) $updatedItem['quantity_in_stock'], 'type' => $type, 'quantity' => $quantity]
        );

        return $updatedItem;
    }

    /**
     * Deduct inventory for an approved request.
     *
     * Iterates over the request's items and subtracts the requested
     * quantity from each linked catalog item. Uses partial fulfillment:
     * if a single deduction fails the remaining items are still processed.
     *
     * @param  int   $requestId The originating request ID.
     * @param  array $items     List of request item arrays, each optionally containing
     *                          'inventory_item_id' and 'quantity'.
     * @param  int   $userId    The user who approved the request.
     */
    public function deductForRequest(int $requestId, array $items, int $userId): void
    {
        foreach ($items as $item) {
            if (empty($item['inventory_item_id'])) {
                continue;
            }

            try {
                $catalogItem = $this->inventoryRepo->findById((int) $item['inventory_item_id']);

                if ($catalogItem === null) {
                    continue;
                }

                $quantity    = (int) ($item['quantity'] ?? 0);
                $currentStock = (int) $catalogItem['quantity_in_stock'];

                if ($quantity <= 0) {
                    continue;
                }

                // Deduct stock
                if ($quantity > $currentStock) {
                    throw new RuntimeException(
                        "Insufficient stock for item '{$catalogItem['name']}'. "
                        . "Available: {$currentStock}, requested: {$quantity}."
                    );
                }

                $this->inventoryRepo->adjustStock((int) $catalogItem['id'], -$quantity);

                // Record transaction
                /*$this->inventoryRepo->addTransaction([
                    'inventory_item_id' => (int) $catalogItem['id'],
                    'type'              => 'out',
                    'quantity'          => $quantity,
                    'reference_type'    => 'request',
                    'reference_id'      => $requestId,
                    'notes'             => "Deducted for request #{$requestId}",
                    'created_by'        => $userId,
                ]);*/
                $this->inventoryRepo->addTransaction(
                    (int) $catalogItem['id'],
                    'out',
                    $quantity,
                    $userId,
                    'request',
                    $requestId,
                    "Deducted for request #{$requestId}",
                );

                // Check low stock after deduction
                $updatedItem  = $this->inventoryRepo->findById((int) $catalogItem['id']);
                $reorderLevel = (int) ($updatedItem['reorder_level'] ?? 0);

                if ($reorderLevel > 0 && (int) $updatedItem['quantity_in_stock'] <= $reorderLevel) {
                    $this->eventDispatcher->dispatch('inventory.low_stock', [
                        'item' => $updatedItem,
                    ]);
                }
            } catch (RuntimeException $e) {
                // Log error but continue with remaining items (partial fulfillment)
                error_log("InventoryService::deductForRequest — request #{$requestId}: " . $e->getMessage());
            }
        }
    }

    /**
     * Batch stock-in adjustment for multiple existing inventory items.
     *
     * Each entry must contain 'item_id' and 'quantity'; 'notes' is optional.
     * Returns a summary with 'success' count, 'errors' array, and 'results'.
     *
     * @param  array $items  List of arrays with keys: item_id, quantity, notes.
     * @param  int   $userId The user performing the adjustments.
     * @return array Summary: ['success' => int, 'errors' => string[], 'results' => array[]]
     */
    public function batchStockIn(array $items, int $userId): array
    {
        $success = 0;
        $errors = [];
        $results = [];

        foreach ($items as $index => $entry) {
            $itemId   = (int) ($entry['item_id'] ?? 0);
            $quantity = (int) ($entry['quantity'] ?? 0);
            $notes    = trim((string) ($entry['notes'] ?? ''));
            $row      = $index + 1;

            if ($itemId <= 0) {
                $errors[] = "Row {$row}: No item selected.";
                continue;
            }

            if ($quantity <= 0) {
                $errors[] = "Row {$row}: Quantity must be greater than zero.";
                continue;
            }

            try {
                $result = $this->adjustStock($itemId, 'in', $quantity, $userId, $notes !== '' ? $notes : null);
                $results[] = $result;
                $success++;
            } catch (RuntimeException $e) {
                $errors[] = "Row {$row}: " . $e->getMessage();
            }
        }

        return ['success' => $success, 'errors' => $errors, 'results' => $results];
    }

    /**
     * Batch create multiple new inventory items.
     *
     * Each entry follows the same validation as createItem().
     * Returns a summary with 'success' count, 'errors' array, and 'created' items.
     *
     * @param  array $items  List of item data arrays.
     * @param  int   $userId The user creating the items.
     * @return array Summary: ['success' => int, 'errors' => string[], 'created' => array[]]
     */
    public function batchCreateItems(array $items, int $userId): array
    {
        $success = 0;
        $errors = [];
        $created = [];

        foreach ($items as $index => $entry) {
            $row = $index + 1;

            $itemData = [
                'name'             => trim((string) ($entry['name'] ?? '')),
                'sku'              => trim((string) ($entry['sku'] ?? '')),
                'category'         => trim((string) ($entry['category'] ?? '')),
                'description'      => trim((string) ($entry['description'] ?? '')),
                'unit'             => trim((string) ($entry['unit'] ?? '')),
                'quantity_in_stock' => (int) ($entry['quantity_in_stock'] ?? 0),
                'reorder_level'    => (int) ($entry['reorder_level'] ?? 0),
                'location'         => trim((string) ($entry['location'] ?? '')),
            ];

            try {
                $item = $this->createItem($itemData, $userId);
                $created[] = $item;
                $success++;
            } catch (RuntimeException $e) {
                $errors[] = "Row {$row} ({$itemData['name']}): " . $e->getMessage();
            }
        }

        return ['success' => $success, 'errors' => $errors, 'created' => $created];
    }

    /**
     * Restock an inventory item (convenience wrapper for adjustStock with type "in").
     *
     * @param  int         $itemId   The inventory item ID.
     * @param  int         $quantity The quantity to add.
     * @param  int         $userId   The user performing the restock.
     * @param  string|null $notes    Optional notes.
     * @return array The updated item record.
     * @throws RuntimeException If the item is not found or quantity is invalid.
     */
    public function restockItem(int $itemId, int $quantity, int $userId, ?string $notes = null): array
    {
        return $this->adjustStock($itemId, 'in', $quantity, $userId, $notes);
    }

    /**
     * Retrieve an inventory item along with its recent transaction history.
     *
     * @param  int $id               The inventory item ID.
     * @param  int $transactionLimit Maximum number of transactions to include.
     * @return array The item record with an added 'transactions' key.
     * @throws RuntimeException If the item is not found.
     */
    public function getItemWithHistory(int $id, int $transactionLimit = 50): array
    {
        $item = $this->inventoryRepo->findById($id);

        if ($item === null) {
            throw new RuntimeException('Inventory item not found.');
        }

        $item['transactions'] = $this->inventoryRepo->getTransactions($id, $transactionLimit);

        return $item;
    }

    /**
     * Retrieve all inventory items whose stock is at or below their reorder level.
     *
     * @return array List of low-stock item records.
     */
    public function getLowStockAlerts(): array
    {
        return $this->inventoryRepo->getLowStockItems();
    }

    /**
     * Retrieve all distinct inventory categories.
     *
     * @return array List of category strings.
     */
    public function getCategories(): array
    {
        return $this->inventoryRepo->getCategories();
    }

    /**
     * Search inventory items by name.
     *
     * @param  string $term The search term.
     * @return array Matching item records.
     */
    public function searchItems(string $term): array
    {
        return $this->inventoryRepo->searchByName($term);
    }

    /**
     * Validate required fields and constraints for inventory item data.
     *
     * @param  array $data The item data to validate.
     * @throws RuntimeException If any validation rule is violated.
     */
    private function validateItemData(array $data): void
    {
        $errors = [];

        if (empty($data['name']) || mb_strlen(trim($data['name'])) < 2) {
            $errors[] = 'Item name is required and must be at least 2 characters.';
        }

        if (empty($data['unit'])) {
            $errors[] = 'Unit is required.';
        }

        if (isset($data['quantity_in_stock']) && (int) $data['quantity_in_stock'] < 0) {
            $errors[] = 'Quantity in stock must be zero or greater.';
        }

        if (isset($data['reorder_level']) && (int) $data['reorder_level'] < 0) {
            $errors[] = 'Reorder level must be zero or greater.';
        }

        if (!empty($errors)) {
            throw new RuntimeException(implode(' ', $errors));
        }
    }
}
