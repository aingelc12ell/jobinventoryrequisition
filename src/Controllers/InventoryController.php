<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\InventoryRepository;
use App\Services\InventoryService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Slim\Views\Twig;

final class InventoryController
{
    public function __construct(
        private readonly Twig $view,
        private readonly InventoryService $inventoryService,
        private readonly InventoryRepository $inventoryRepo,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * GET /staff/inventory — Inventory listing with filters.
     */
    public function index(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $queryParams = $request->getQueryParams();

        $page = max(1, (int) ($queryParams['page'] ?? 1));
        $limit = 20;
        $offset = ($page - 1) * $limit;

        $filters = array_filter([
            'category' => $queryParams['category'] ?? null,
            'search' => $queryParams['search'] ?? null,
            'low_stock' => isset($queryParams['low_stock']) ? true : null,
        ]);

        $result = $this->inventoryRepo->findAll($filters, $limit, $offset);
        $totalPages = (int) ceil($result['total'] / $limit);

        $categories = $this->inventoryService->getCategories();
        $lowStockAlerts = $this->inventoryService->getLowStockAlerts();
        $lowStockCount = count($lowStockAlerts);

        return $this->view->render($response, 'staff/inventory/index.twig', [
            'items' => $result['data'],
            'current_page' => $page,
            'total_pages' => $totalPages,
            'filters' => $filters,
            'categories' => $categories,
            'low_stock_count' => $lowStockCount,
        ]);
    }

    /**
     * GET /staff/inventory/new — Show create inventory item form.
     */
    public function create(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $categories = $this->inventoryService->getCategories();

        $oldInput = $_SESSION['old_input'] ?? [];
        unset($_SESSION['old_input']);

        return $this->view->render($response, 'staff/inventory/form.twig', [
            'mode' => 'create',
            'old' => $oldInput,
            'categories' => $categories,
        ]);
    }

    /**
     * POST /staff/inventory — Store a new inventory item.
     */
    public function store(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $request->getAttribute('user');
        $data = (array) $request->getParsedBody();

        $itemData = [
            'name' => trim((string) ($data['name'] ?? '')),
            'sku' => trim((string) ($data['sku'] ?? '')),
            'category' => trim((string) ($data['category'] ?? '')),
            'description' => trim((string) ($data['description'] ?? '')),
            'unit' => trim((string) ($data['unit'] ?? '')),
            'quantity_in_stock' => (int) ($data['quantity_in_stock'] ?? 0),
            'reorder_level' => (int) ($data['reorder_level'] ?? 0),
            'location' => trim((string) ($data['location'] ?? '')),
        ];

        try {
            $item = $this->inventoryService->createItem($itemData, $user['id']);

            $this->flash('success', 'Inventory item created successfully.');

            return $this->redirect($response, '/staff/inventory/' . $item['id']);
        } catch (\RuntimeException $e) {
            $this->logger->warning('Inventory item creation failed', [
                'user_id' => $user['id'],
                'error' => $e->getMessage(),
            ]);

            $this->flash('error', $e->getMessage());
            $_SESSION['old_input'] = $itemData;

            return $this->redirect($response, '/staff/inventory/new');
        }
    }

    /**
     * GET /staff/inventory/{id} — Show inventory item details.
     */
    public function show(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id = (int) $args['id'];

        try {
            $item = $this->inventoryService->getItemWithHistory($id);
        } catch (\RuntimeException $e) {
            $this->flash('error', $e->getMessage());

            return $this->redirect($response, '/staff/inventory');
        }

        return $this->view->render($response, 'staff/inventory/show.twig', [
            'item' => $item,
        ]);
    }

    /**
     * GET /staff/inventory/{id}/edit — Show edit inventory item form.
     */
    public function edit(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id = (int) $args['id'];

        try {
            $item = $this->inventoryService->getItemWithHistory($id);
        } catch (\RuntimeException $e) {
            $this->flash('error', $e->getMessage());

            return $this->redirect($response, '/staff/inventory');
        }

        $categories = $this->inventoryService->getCategories();

        $oldInput = $_SESSION['old_input'] ?? [];
        unset($_SESSION['old_input']);

        return $this->view->render($response, 'staff/inventory/form.twig', [
            'mode' => 'edit',
            'item' => $item,
            'old' => $oldInput,
            'categories' => $categories,
        ]);
    }

    /**
     * PUT /staff/inventory/{id} — Update an inventory item.
     */
    public function update(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $request->getAttribute('user');
        $id = (int) $args['id'];
        $data = (array) $request->getParsedBody();

        $itemData = [
            'name' => trim((string) ($data['name'] ?? '')),
            'sku' => trim((string) ($data['sku'] ?? '')),
            'category' => trim((string) ($data['category'] ?? '')),
            'description' => trim((string) ($data['description'] ?? '')),
            'unit' => trim((string) ($data['unit'] ?? '')),
            'quantity_in_stock' => (int) ($data['quantity_in_stock'] ?? 0),
            'reorder_level' => (int) ($data['reorder_level'] ?? 0),
            'location' => trim((string) ($data['location'] ?? '')),
        ];

        try {
            $this->inventoryService->updateItem($id, $itemData, $user['id']);

            $this->flash('success', 'Inventory item updated successfully.');

            return $this->redirect($response, '/staff/inventory/' . $id);
        } catch (\RuntimeException $e) {
            $this->logger->warning('Inventory item update failed', [
                'item_id' => $id,
                'user_id' => $user['id'],
                'error' => $e->getMessage(),
            ]);

            $this->flash('error', $e->getMessage());
            $_SESSION['old_input'] = $itemData;

            return $this->redirect($response, '/staff/inventory/' . $id . '/edit');
        }
    }

    /**
     * POST /staff/inventory/{id}/adjust — Adjust stock quantity.
     */
    public function adjust(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $request->getAttribute('user');
        $id = (int) $args['id'];
        $data = (array) $request->getParsedBody();

        $type = trim((string) ($data['type'] ?? ''));
        $quantity = (int) ($data['quantity'] ?? 0);
        $notes = trim((string) ($data['notes'] ?? ''));

        try {
            $this->inventoryService->adjustStock(
                $id,
                $type,
                $quantity,
                $user['id'],
                $notes !== '' ? $notes : null,
            );

            $this->flash('success', 'Stock updated successfully.');

            return $this->redirect($response, '/staff/inventory/' . $id);
        } catch (\RuntimeException $e) {
            $this->logger->warning('Stock adjustment failed', [
                'item_id' => $id,
                'user_id' => $user['id'],
                'type' => $type,
                'quantity' => $quantity,
                'error' => $e->getMessage(),
            ]);

            $this->flash('error', $e->getMessage());

            return $this->redirect($response, '/staff/inventory/' . $id);
        }
    }

    /**
     * GET /staff/inventory/batch — Show batch operations form.
     */
    public function batchForm(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $categories = $this->inventoryService->getCategories();

        return $this->view->render($response, 'staff/inventory/batch.twig', [
            'categories' => $categories,
        ]);
    }

    /**
     * POST /staff/inventory/batch/stock-in — Process batch stock-in adjustments.
     */
    public function batchStockIn(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $request->getAttribute('user');
        $data = (array) $request->getParsedBody();
        $items = $data['items'] ?? [];

        if (empty($items) || !is_array($items)) {
            $this->flash('error', 'No items provided for batch stock-in.');

            return $this->redirect($response, '/staff/inventory/batch');
        }

        // Filter out completely empty rows
        $items = array_filter($items, fn(array $row) => !empty($row['item_id']) || !empty($row['quantity']));

        if (empty($items)) {
            $this->flash('error', 'No valid items provided.');

            return $this->redirect($response, '/staff/inventory/batch');
        }

        $result = $this->inventoryService->batchStockIn(array_values($items), $user['id']);

        if ($result['success'] > 0) {
            $this->flash('success', "{$result['success']} item(s) restocked successfully.");
        }

        foreach ($result['errors'] as $error) {
            $this->flash('error', $error);
        }

        $this->logger->info('Batch stock-in processed', [
            'user_id' => $user['id'],
            'success' => $result['success'],
            'errors' => count($result['errors']),
        ]);

        return $this->redirect($response, '/staff/inventory/batch');
    }

    /**
     * POST /staff/inventory/batch/add — Process batch add new items.
     */
    public function batchAdd(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $request->getAttribute('user');
        $data = (array) $request->getParsedBody();
        $items = $data['items'] ?? [];

        if (empty($items) || !is_array($items)) {
            $this->flash('error', 'No items provided for batch creation.');

            return $this->redirect($response, '/staff/inventory/batch');
        }

        // Filter out completely empty rows
        $items = array_filter($items, fn(array $row) => !empty(trim((string) ($row['name'] ?? ''))));

        if (empty($items)) {
            $this->flash('error', 'No valid items provided.');

            return $this->redirect($response, '/staff/inventory/batch');
        }

        $result = $this->inventoryService->batchCreateItems(array_values($items), $user['id']);

        if ($result['success'] > 0) {
            $this->flash('success', "{$result['success']} item(s) created successfully.");
        }

        foreach ($result['errors'] as $error) {
            $this->flash('error', $error);
        }

        $this->logger->info('Batch add processed', [
            'user_id' => $user['id'],
            'success' => $result['success'],
            'errors' => count($result['errors']),
        ]);

        return $this->redirect($response, '/staff/inventory');
    }

    /**
     * GET /api/inventory/search — AJAX autocomplete search.
     */
    public function search(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $queryParams = $request->getQueryParams();
        $q = trim((string) ($queryParams['q'] ?? ''));

        $results = $this->inventoryService->searchItems($q);

        $response = $response->withHeader('Content-Type', 'application/json');
        $response->getBody()->write(json_encode($results, JSON_THROW_ON_ERROR));

        return $response;
    }

    /**
     * Redirect helper.
     */
    private function redirect(ResponseInterface $response, string $url, int $status = 302): ResponseInterface
    {
        return $response->withHeader('Location', $url)->withStatus($status);
    }

    /**
     * Flash message helper.
     */
    private function flash(string $type, string $message): void
    {
        $_SESSION['flash'][$type][] = $message;
    }
}
