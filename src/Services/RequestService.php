<?php

declare(strict_types=1);

namespace App\Services;

use App\Events\EventDispatcher;
use App\Repositories\AuditRepository;
use App\Repositories\RequestRepository;
use RuntimeException;

/**
 * Orchestrates request lifecycle operations: creation, submission,
 * editing, status changes, assignment, and cancellation.
 *
 * Delegates validation, persistence, auditing, and event dispatch
 * to their respective collaborators.
 */
class RequestService
{
    public function __construct(
        private readonly RequestRepository $requestRepo,
        private readonly RequestValidator $validator,
        private readonly EventDispatcher $eventDispatcher,
        private readonly AuditRepository $auditRepo,
        private readonly ?InventoryService $inventoryService = null,
    ) {}

    /**
     * Create a new request.
     *
     * @param  array $data   The request data.
     * @param  int   $userId The ID of the submitting user.
     * @return array The created request with full details.
     * @throws RuntimeException If validation fails.
     */
    public function createRequest(array $data, int $userId): array
    {
        $errors = $this->validator->validate($data);

        if (!empty($errors)) {
            throw new RuntimeException(implode(' ', $errors));
        }

        $data['submitted_by'] = $userId;
        $data['status']       = $data['status'] ?? 'draft';

        $requestId = $this->requestRepo->create($data);

        // Add inventory items if applicable
        if (($data['type'] ?? '') === 'inventory' && !empty($data['items'])) {
            foreach ($data['items'] as $item) {
                $this->requestRepo->addItem($requestId, $item);
            }
        }

        $this->auditRepo->log(
            'request.created',
            $userId,
            'request',
            $requestId,
            null,
            $data
        );

        return $this->requestRepo->findByIdWithDetails($requestId);
    }

    /**
     * Submit a draft request for review.
     *
     * @param  int   $requestId The request ID.
     * @param  int   $userId    The ID of the user submitting.
     * @return array The updated request.
     * @throws RuntimeException If the request is not found, not owned, or transition is invalid.
     */
    public function submitRequest(int $requestId, int $userId): array
    {
        $request = $this->fetchAndVerifyOwnership($requestId, $userId);

        if (!$this->validator->validateStatusChange($request['status'], 'submitted')) {
            $allowed = implode(', ', $this->validator->getAllowedTransitions($request['status']));
            throw new RuntimeException(
                "Cannot transition from '{$request['status']}' to 'submitted'. Allowed: {$allowed}."
            );
        }

        $oldStatus = $request['status'];
        $this->requestRepo->updateStatus($requestId, 'submitted');
        $this->requestRepo->addStatusHistory($requestId, $oldStatus, 'submitted', $userId);

        $updatedRequest = $this->requestRepo->findByIdWithDetails($requestId);

        $this->eventDispatcher->dispatch('request.submitted', [
            'request' => $updatedRequest,
            'user'    => ['id' => $userId, 'full_name' => $updatedRequest['submitter_name'] ?? 'Unknown'],
        ]);

        $this->auditRepo->log(
            'request.submitted',
            $userId,
            'request',
            $requestId,
            ['status' => $oldStatus],
            ['status' => 'submitted']
        );

        return $updatedRequest;
    }

    /**
     * Update a draft request.
     *
     * Only requests in 'draft' status may be edited.
     *
     * @param  int   $requestId The request ID.
     * @param  array $data      The fields to update.
     * @param  int   $userId    The ID of the editing user.
     * @return array The updated request.
     * @throws RuntimeException If not found, not owned, not a draft, or validation fails.
     */
    public function updateRequest(int $requestId, array $data, int $userId): array
    {
        $request = $this->fetchAndVerifyOwnership($requestId, $userId);

        if ($request['status'] !== 'draft') {
            throw new RuntimeException('Only requests in draft status can be edited.');
        }

        $errors = $this->validator->validate($data, true);

        if (!empty($errors)) {
            throw new RuntimeException(implode(' ', $errors));
        }

        $this->requestRepo->update($requestId, $data);

        // Replace inventory items if provided
        if ($request['type'] === 'inventory' && isset($data['items'])) {
            $this->requestRepo->deleteItems($requestId);

            foreach ($data['items'] as $item) {
                $this->requestRepo->addItem($requestId, $item);
            }
        }

        $this->auditRepo->log(
            'request.updated',
            $userId,
            'request',
            $requestId,
            null,
            $data
        );

        return $this->requestRepo->findByIdWithDetails($requestId);
    }

    /**
     * Cancel a request.
     *
     * The submitter can cancel their own request if the transition is valid.
     *
     * @param  int         $requestId The request ID.
     * @param  int         $userId    The ID of the cancelling user.
     * @param  string|null $comment   Optional cancellation reason.
     * @return array The updated request.
     * @throws RuntimeException If not found, not owned, or transition is invalid.
     */
    public function cancelRequest(int $requestId, int $userId, ?string $comment = null): array
    {
        $request = $this->fetchAndVerifyOwnership($requestId, $userId);

        if (!$this->validator->validateStatusChange($request['status'], 'cancelled')) {
            throw new RuntimeException(
                "Cannot cancel a request with status '{$request['status']}'."
            );
        }

        $oldStatus = $request['status'];
        $this->requestRepo->updateStatus($requestId, 'cancelled');
        $this->requestRepo->addStatusHistory($requestId, $oldStatus, 'cancelled', $userId, $comment);

        $updatedRequest = $this->requestRepo->findByIdWithDetails($requestId);

        $this->eventDispatcher->dispatch('request.status_changed', [
            'request'    => $updatedRequest,
            'old_status' => $oldStatus,
            'new_status' => 'cancelled',
            'changed_by' => $userId,
        ]);

        $this->auditRepo->log(
            'request.cancelled',
            $userId,
            'request',
            $requestId,
            ['status' => $oldStatus],
            ['status' => 'cancelled']
        );

        return $updatedRequest;
    }

    /**
     * Change the status of a request (staff action).
     *
     * @param  int         $requestId The request ID.
     * @param  string      $newStatus The desired new status.
     * @param  int         $staffId   The ID of the staff member making the change.
     * @param  string|null $comment   Optional comment for the status change.
     * @return array The updated request.
     * @throws RuntimeException If not found or transition is invalid.
     */
    public function changeStatus(int $requestId, string $newStatus, int $staffId, ?string $comment = null): array
    {
        $request = $this->requestRepo->findById($requestId);

        if ($request === null) {
            throw new RuntimeException('Request not found.');
        }

        if (!$this->validator->validateStatusChange($request['status'], $newStatus)) {
            $allowed = implode(', ', $this->validator->getAllowedTransitions($request['status']));
            throw new RuntimeException(
                "Cannot transition from '{$request['status']}' to '{$newStatus}'. Allowed: {$allowed}."
            );
        }

        $oldStatus = $request['status'];
        $this->requestRepo->updateStatus($requestId, $newStatus);
        $this->requestRepo->addStatusHistory($requestId, $oldStatus, $newStatus, $staffId, $comment);

        $updatedRequest = $this->requestRepo->findByIdWithDetails($requestId);

        // Auto-deduct inventory when an inventory request is approved
        if ($newStatus === 'approved'
            && $updatedRequest['type'] === 'inventory'
            && $this->inventoryService !== null
            && !empty($updatedRequest['items'])
        ) {
            $this->inventoryService->deductForRequest(
                $requestId,
                $updatedRequest['items'],
                $staffId,
            );
        }

        $this->eventDispatcher->dispatch('request.status_changed', [
            'request'    => $updatedRequest,
            'old_status' => $oldStatus,
            'new_status' => $newStatus,
            'changed_by' => $staffId,
        ]);

        $this->auditRepo->log(
            'request.status_changed',
            $staffId,
            'request',
            $requestId,
            ['status' => $oldStatus],
            ['status' => $newStatus]
        );

        return $updatedRequest;
    }

    /**
     * Assign a request to a staff member.
     *
     * Automatically transitions 'submitted' requests to 'in_review'.
     *
     * @param  int $requestId     The request ID.
     * @param  int $assignToUserId The user ID to assign.
     * @param  int $staffId       The staff member performing the assignment.
     * @return array The updated request.
     * @throws RuntimeException If the request is not found.
     */
    public function assignRequest(int $requestId, int $assignToUserId, int $staffId): array
    {
        $request = $this->requestRepo->findById($requestId);

        if ($request === null) {
            throw new RuntimeException('Request not found.');
        }

        $this->requestRepo->assignTo($requestId, $assignToUserId);

        // Auto-transition submitted requests to in_review
        if ($request['status'] === 'submitted') {
            $this->requestRepo->updateStatus($requestId, 'in_review');
            $this->requestRepo->addStatusHistory(
                $requestId,
                'submitted',
                'in_review',
                $staffId,
                "Assigned to user #{$assignToUserId}"
            );
        }

        $updatedRequest = $this->requestRepo->findByIdWithDetails($requestId);

        $this->eventDispatcher->dispatch('request.assigned', [
            'request'     => $updatedRequest,
            'assigned_to' => ['id' => $assignToUserId],
        ]);

        $this->auditRepo->log(
            'request.assigned',
            $staffId,
            'request',
            $requestId,
            ['assigned_to' => $request['assigned_to'] ?? null],
            ['assigned_to' => $assignToUserId]
        );

        return $updatedRequest;
    }

    /**
     * Retrieve a request for a given user, enforcing ownership for personnel.
     *
     * Staff and admin roles may view any request. Personnel may only view
     * their own submissions.
     *
     * @param  int    $requestId The request ID.
     * @param  int    $userId    The requesting user's ID.
     * @param  string $userRole  The requesting user's role.
     * @return array The request with full details.
     * @throws RuntimeException If the request is not found or access is denied.
     */
    public function getRequestForUser(int $requestId, int $userId, string $userRole): array
    {
        $request = $this->requestRepo->findByIdWithDetails($requestId);

        if ($request === null) {
            throw new RuntimeException('Request not found.');
        }

        if ($userRole === 'personnel' && (int) $request['submitted_by'] !== $userId) {
            throw new RuntimeException('Access denied. You can only view your own requests.');
        }

        return $request;
    }

    /**
     * Fetch a request by ID and verify that the given user owns it.
     *
     * @param  int $requestId The request ID.
     * @param  int $userId    The expected owner's user ID.
     * @return array The request record.
     * @throws RuntimeException If the request is not found or the user is not the owner.
     */
    private function fetchAndVerifyOwnership(int $requestId, int $userId): array
    {
        $request = $this->requestRepo->findById($requestId);

        if ($request === null) {
            throw new RuntimeException('Request not found.');
        }

        if ((int) $request['submitted_by'] !== $userId) {
            throw new RuntimeException('Access denied. You do not own this request.');
        }

        return $request;
    }
}
