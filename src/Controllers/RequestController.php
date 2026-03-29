<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\AttachmentRepository;
use App\Repositories\RequestRepository;
use App\Services\FileUploadService;
use App\Services\RequestService;
use App\Services\RequestValidator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Slim\Views\Twig;

final class RequestController
{
    public function __construct(
        private readonly Twig $view,
        private readonly RequestService $requestService,
        private readonly RequestRepository $requestRepo,
        private readonly FileUploadService $fileUploadService,
        private readonly RequestValidator $validator,
        private readonly AttachmentRepository $attachmentRepo,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * GET /requests — Personnel's "My Requests" list.
     */
    public function index(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $request->getAttribute('user');
        $queryParams = $request->getQueryParams();

        $page = max(1, (int) ($queryParams['page'] ?? 1));
        $limit = 20;
        $offset = ($page - 1) * $limit;

        $filters = array_filter([
            'type' => $queryParams['type'] ?? null,
            'status' => $queryParams['status'] ?? null,
            'priority' => $queryParams['priority'] ?? null,
            'search' => $queryParams['search'] ?? null,
        ]);

        $result = $this->requestRepo->findBySubmitter($user['id'], $filters, $limit, $offset);
        $totalPages = (int) ceil($result['total'] / $limit);

        return $this->view->render($response, 'personnel/requests/index.twig', [
            'requests' => $result['data'],
            'current_page' => $page,
            'total_pages' => $totalPages,
            'filters' => $filters,
            'user' => $user,
        ]);
    }

    /**
     * GET /requests/new — Show the request creation form.
     */
    public function create(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $oldInput = $_SESSION['old_input'] ?? [];
        unset($_SESSION['old_input']);

        return $this->view->render($response, 'personnel/requests/form.twig', [
            'mode' => 'create',
            'old' => $oldInput,
        ]);
    }

    /**
     * POST /requests — Create a new request.
     */
    public function store(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $request->getAttribute('user');
        $data = (array) $request->getParsedBody();

        $type = trim((string) ($data['type'] ?? ''));
        $title = trim((string) ($data['title'] ?? ''));
        $description = trim((string) ($data['description'] ?? ''));
        $priority = trim((string) ($data['priority'] ?? 'medium'));
        $dueDate = trim((string) ($data['due_date'] ?? ''));
        $submitAction = trim((string) ($data['submit_action'] ?? 'save_draft'));

        $requestData = [
            'type' => $type,
            'title' => $title,
            'description' => $description,
            'priority' => $priority,
            'due_date' => $dueDate !== '' ? $dueDate : null,
        ];

        // Parse items for inventory requests
        if ($type === 'inventory' && isset($data['items']) && is_array($data['items'])) {
            $requestData['items'] = [];
            foreach ($data['items'] as $item) {
                $requestData['items'][] = [
                    'item_name' => trim((string) ($item['item_name'] ?? '')),
                    'quantity' => (int) ($item['quantity'] ?? 0),
                    'unit' => trim((string) ($item['unit'] ?? '')),
                    'notes' => trim((string) ($item['notes'] ?? '')),
                ];
            }
        }

        try {
            $createdRequest = $this->requestService->createRequest($requestData, $user['id']);
            $createdId = (int) $createdRequest['id'];

            if ($submitAction === 'submit') {
                $this->requestService->submitRequest($createdId, $user['id']);
            }

            // Handle file uploads
            $uploadedFiles = $request->getUploadedFiles();
            $files = $uploadedFiles['attachments'] ?? [];

            if (!is_array($files)) {
                $files = [$files];
            }

            foreach ($files as $file) {
                if ($file->getError() === UPLOAD_ERR_OK) {
                    $this->fileUploadService->upload($file, $createdId, $user['id']);
                }
            }

            $this->flash('success', $submitAction === 'submit'
                ? 'Request submitted successfully.'
                : 'Request saved as draft.');

            return $this->redirect($response, '/requests/' . $createdId);
        } catch (\RuntimeException $e) {
            $this->logger->warning('Request creation failed', [
                'user_id' => $user['id'],
                'error' => $e->getMessage(),
            ]);

            $this->flash('error', $e->getMessage());

            $_SESSION['old_input'] = $requestData;

            return $this->redirect($response, '/requests/new');
        }
    }

    /**
     * GET /requests/{id} — Request detail page.
     */
    public function show(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $request->getAttribute('user');
        $id = (int) $args['id'];

        try {
            $requestData = $this->requestService->getRequestForUser($id, $user['id'], $user['role']);
        } catch (\RuntimeException $e) {
            $this->flash('error', $e->getMessage());

            return $this->redirect($response, '/requests');
        }

        $allowedTransitions = $this->validator->getAllowedTransitions($requestData['status']);

        return $this->view->render($response, 'personnel/requests/show.twig', [
            'request' => $requestData,
            'allowed_transitions' => $allowedTransitions,
            'user' => $user,
        ]);
    }

    /**
     * GET /requests/{id}/edit — Edit form for draft requests.
     */
    public function edit(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $request->getAttribute('user');
        $id = (int) $args['id'];

        try {
            $requestData = $this->requestService->getRequestForUser($id, $user['id'], $user['role']);
        } catch (\RuntimeException $e) {
            $this->flash('error', $e->getMessage());

            return $this->redirect($response, '/requests');
        }

        if ($requestData['status'] !== 'draft') {
            $this->flash('error', 'Only draft requests can be edited.');

            return $this->redirect($response, '/requests/' . $id);
        }

        $oldInput = $_SESSION['old_input'] ?? [];
        unset($_SESSION['old_input']);

        return $this->view->render($response, 'personnel/requests/form.twig', [
            'mode' => 'edit',
            'request' => $requestData,
            'old' => $oldInput,
        ]);
    }

    /**
     * PUT /requests/{id} — Update a draft request.
     */
    public function update(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $request->getAttribute('user');
        $id = (int) $args['id'];
        $data = (array) $request->getParsedBody();

        $title = trim((string) ($data['title'] ?? ''));
        $description = trim((string) ($data['description'] ?? ''));
        $priority = trim((string) ($data['priority'] ?? 'medium'));
        $dueDate = trim((string) ($data['due_date'] ?? ''));
        $type = trim((string) ($data['type'] ?? ''));

        $requestData = [
            'title' => $title,
            'description' => $description,
            'priority' => $priority,
            'due_date' => $dueDate !== '' ? $dueDate : null,
        ];

        // Parse items for inventory requests
        if ($type === 'inventory' && isset($data['items']) && is_array($data['items'])) {
            $requestData['items'] = [];
            foreach ($data['items'] as $item) {
                $requestData['items'][] = [
                    'item_name' => trim((string) ($item['item_name'] ?? '')),
                    'quantity' => (int) ($item['quantity'] ?? 0),
                    'unit' => trim((string) ($item['unit'] ?? '')),
                    'notes' => trim((string) ($item['notes'] ?? '')),
                ];
            }
        }

        try {
            $this->requestService->updateRequest($id, $requestData, $user['id']);

            // Handle any new file uploads
            $uploadedFiles = $request->getUploadedFiles();
            $files = $uploadedFiles['attachments'] ?? [];

            if (!is_array($files)) {
                $files = [$files];
            }

            foreach ($files as $file) {
                if ($file->getError() === UPLOAD_ERR_OK) {
                    $this->fileUploadService->upload($file, $id, $user['id']);
                }
            }

            $this->flash('success', 'Request updated successfully.');

            return $this->redirect($response, '/requests/' . $id);
        } catch (\RuntimeException $e) {
            $this->logger->warning('Request update failed', [
                'request_id' => $id,
                'user_id' => $user['id'],
                'error' => $e->getMessage(),
            ]);

            $this->flash('error', $e->getMessage());

            $_SESSION['old_input'] = $requestData;

            return $this->redirect($response, '/requests/' . $id . '/edit');
        }
    }

    /**
     * POST /requests/{id}/cancel — Cancel a request.
     */
    public function cancel(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $request->getAttribute('user');
        $id = (int) $args['id'];
        $data = (array) $request->getParsedBody();
        $comment = trim((string) ($data['comment'] ?? ''));

        try {
            $this->requestService->cancelRequest($id, $user['id'], $comment !== '' ? $comment : null);

            $this->flash('success', 'Request cancelled successfully.');

            return $this->redirect($response, '/requests');
        } catch (\RuntimeException $e) {
            $this->logger->warning('Request cancellation failed', [
                'request_id' => $id,
                'user_id' => $user['id'],
                'error' => $e->getMessage(),
            ]);

            $this->flash('error', $e->getMessage());

            return $this->redirect($response, '/requests/' . $id);
        }
    }

    /**
     * POST /requests/{id}/submit — Submit a draft request.
     */
    public function submit(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $request->getAttribute('user');
        $id = (int) $args['id'];

        try {
            $this->requestService->submitRequest($id, $user['id']);
            $this->flash('success', 'Request submitted successfully.');
        } catch (\RuntimeException $e) {
            $this->flash('error', $e->getMessage());
        }

        return $this->redirect($response, '/requests/' . $id);
    }

    /**
     * GET /attachments/{id}/download — Download an attachment.
     */
    public function downloadAttachment(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id = (int) $args['id'];
        $attachment = $this->attachmentRepo->findById($id);

        if ($attachment === null || !file_exists($attachment['file_path'])) {
            $this->flash('error', 'Attachment not found.');

            return $this->redirect($response, '/requests');
        }

        $stream = fopen($attachment['file_path'], 'rb');
        $body = new \Slim\Psr7\Stream($stream);

        return $response
            ->withHeader('Content-Type', $attachment['mime_type'])
            ->withHeader('Content-Disposition', 'attachment; filename="' . $attachment['file_name'] . '"')
            ->withHeader('Content-Length', (string) $attachment['file_size'])
            ->withBody($body);
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
