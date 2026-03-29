<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\RequestRepository;
use App\Repositories\UserRepository;
use App\Services\RequestService;
use App\Services\RequestValidator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Slim\Views\Twig;

final class StaffController
{
    public function __construct(
        private readonly Twig $view,
        private readonly RequestService $requestService,
        private readonly RequestRepository $requestRepo,
        private readonly UserRepository $userRepo,
        private readonly RequestValidator $validator,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * GET /staff/requests — Staff request queue.
     */
    public function requests(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $queryParams = $request->getQueryParams();

        $page = max(1, (int) ($queryParams['page'] ?? 1));
        $limit = 20;
        $offset = ($page - 1) * $limit;

        $filters = array_filter([
            'type' => $queryParams['type'] ?? null,
            'status' => $queryParams['status'] ?? null,
            'priority' => $queryParams['priority'] ?? null,
            'search' => $queryParams['search'] ?? null,
            'assigned_to' => $queryParams['assigned_to'] ?? null,
        ]);

        $result = $this->requestRepo->findAll($filters, $limit, $offset);
        $totalPages = (int) ceil($result['total'] / $limit);

        $statusCounts = $this->requestRepo->countByStatus();

        $staffResult = $this->userRepo->findAll(['role' => 'staff'], 100, 0);
        $staffList = $staffResult['data'];

        return $this->view->render($response, 'staff/requests/index.twig', [
            'requests' => $result['data'],
            'current_page' => $page,
            'total_pages' => $totalPages,
            'filters' => $filters,
            'status_counts' => $statusCounts,
            'staff_list' => $staffList,
        ]);
    }

    /**
     * GET /staff/requests/{id} — Staff view of a single request.
     */
    public function showRequest(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $request->getAttribute('user');
        $id = (int) $args['id'];

        try {
            $requestData = $this->requestService->getRequestForUser($id, $user['id'], $user['role']);
        } catch (\RuntimeException $e) {
            $this->flash('error', $e->getMessage());

            return $this->redirect($response, '/staff/requests');
        }

        $allowedTransitions = $this->validator->getAllowedTransitions($requestData['status']);

        $staffResult = $this->userRepo->findAll(['role' => 'staff'], 100, 0);
        $staffList = $staffResult['data'];

        return $this->view->render($response, 'staff/requests/show.twig', [
            'request' => $requestData,
            'allowed_transitions' => $allowedTransitions,
            'staff_list' => $staffList,
            'user' => $user,
        ]);
    }

    /**
     * POST /staff/requests/{id}/status — Change request status.
     */
    public function updateStatus(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $request->getAttribute('user');
        $id = (int) $args['id'];
        $data = (array) $request->getParsedBody();

        $status = trim((string) ($data['status'] ?? ''));
        $comment = trim((string) ($data['comment'] ?? ''));

        try {
            $this->requestService->changeStatus(
                $id,
                $status,
                $user['id'],
                $comment !== '' ? $comment : null,
            );

            $this->flash('success', "Request status updated to {$status}.");

            return $this->redirect($response, '/staff/requests/' . $id);
        } catch (\RuntimeException $e) {
            $this->logger->warning('Status update failed', [
                'request_id' => $id,
                'user_id' => $user['id'],
                'target_status' => $status,
                'error' => $e->getMessage(),
            ]);

            $this->flash('error', $e->getMessage());

            return $this->redirect($response, '/staff/requests/' . $id);
        }
    }

    /**
     * POST /staff/requests/{id}/assign — Assign request to staff.
     */
    public function assignRequest(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $request->getAttribute('user');
        $id = (int) $args['id'];
        $data = (array) $request->getParsedBody();

        $assignedTo = (int) ($data['assigned_to'] ?? 0);

        try {
            $this->requestService->assignRequest($id, $assignedTo, $user['id']);

            $this->flash('success', 'Request assigned successfully.');

            return $this->redirect($response, '/staff/requests/' . $id);
        } catch (\RuntimeException $e) {
            $this->logger->warning('Request assignment failed', [
                'request_id' => $id,
                'user_id' => $user['id'],
                'assigned_to' => $assignedTo,
                'error' => $e->getMessage(),
            ]);

            $this->flash('error', $e->getMessage());

            return $this->redirect($response, '/staff/requests/' . $id);
        }
    }

    /**
     * GET /staff/requests/export — Export requests as CSV.
     */
    public function exportCsv(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $params = $request->getQueryParams();
        $filters = array_filter([
            'type' => $params['type'] ?? null,
            'status' => $params['status'] ?? null,
            'priority' => $params['priority'] ?? null,
            'search' => $params['search'] ?? null,
            'assigned_to' => $params['assigned_to'] ?? null,
        ]);

        $result = $this->requestRepo->findAll($filters, 10000, 0);

        $output = fopen('php://temp', 'r+');
        fputcsv($output, ['ID', 'Title', 'Type', 'Status', 'Priority', 'Submitter', 'Assigned To', 'Created At', 'Due Date']);

        foreach ($result['data'] as $row) {
            fputcsv($output, [
                $row['id'],
                $row['title'],
                $row['type'],
                $row['status'],
                $row['priority'],
                $row['submitter_name'] ?? '',
                $row['assigned_to'] ?? '',
                $row['created_at'],
                $row['due_date'] ?? '',
            ]);
        }

        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);

        $response->getBody()->write($csv);

        $filename = 'requests_export_' . date('Y-m-d') . '.csv';

        return $response
            ->withHeader('Content-Type', 'text/csv')
            ->withHeader('Content-Disposition', "attachment; filename=\"{$filename}\"");
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
