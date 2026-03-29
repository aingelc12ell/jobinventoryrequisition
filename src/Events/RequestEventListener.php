<?php

declare(strict_types=1);

namespace App\Events;

use App\Helpers\EmailHelper;
use App\Repositories\UserRepository;
use Psr\Log\LoggerInterface;

/**
 * Handles side-effects for request lifecycle events
 * (submissions, status changes, assignments) by logging
 * and sending notification emails.
 */
class RequestEventListener
{
    public function __construct(
        private readonly EmailHelper $emailHelper,
        private readonly UserRepository $userRepo,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Handle the "request.submitted" event.
     *
     * Logs the submission and notifies all staff users via email.
     *
     * @param array $payload Must contain 'request' and 'user' keys.
     */
    public function onRequestSubmitted(array $payload): void
    {
        $request = $payload['request'];
        $user    = $payload['user'];

        $this->logger->info('Request submitted', [
            'request_id' => $request['id'],
            'title'      => $request['title'],
            'type'       => $request['type'],
            'user_id'    => $user['id'],
        ]);

        // Notify all staff members
        $staffResult = $this->userRepo->findAll(['role' => 'staff']);
        $staffUsers  = $staffResult['data'] ?? [];

        $subject = "New request submitted: {$request['title']}";
        $body    = "<p>A new <strong>{$request['type']}</strong> request has been submitted.</p>"
            . "<p><strong>Title:</strong> {$request['title']}</p>"
            . "<p><strong>Priority:</strong> " . ($request['priority'] ?? 'medium') . "</p>"
            . "<p><strong>Submitted by:</strong> {$user['full_name']}</p>";

        foreach ($staffUsers as $staff) {
            $this->sendNotificationEmail(
                $staff['email'],
                $staff['full_name'],
                $subject,
                $body
            );
        }
    }

    /**
     * Handle the "request.status_changed" event.
     *
     * Logs the change and notifies the request submitter via email.
     *
     * @param array $payload Must contain 'request', 'old_status', 'new_status', and 'changed_by' keys.
     */
    public function onStatusChanged(array $payload): void
    {
        $request   = $payload['request'];
        $oldStatus = $payload['old_status'];
        $newStatus = $payload['new_status'];
        $changedBy = $payload['changed_by'];

        $this->logger->info('Request status changed', [
            'request_id' => $request['id'],
            'old_status' => $oldStatus,
            'new_status' => $newStatus,
            'changed_by' => $changedBy,
        ]);

        // Notify the submitter if the request has one
        if (!empty($request['submitted_by'])) {
            $submitter = $this->userRepo->findById((int) $request['submitted_by']);

            if ($submitter) {
                $subject = "Your request '{$request['title']}' status changed to {$newStatus}";
                $body    = "<p>The status of your request <strong>{$request['title']}</strong> "
                    . "has been changed from <em>{$oldStatus}</em> to <em>{$newStatus}</em>.</p>";

                $this->sendNotificationEmail(
                    $submitter['email'],
                    $submitter['full_name'],
                    $subject,
                    $body
                );
            }
        }
    }

    /**
     * Handle the "request.assigned" event.
     *
     * Notifies the assigned staff member via email.
     *
     * @param array $payload Must contain 'request' and 'assigned_to' keys.
     */
    public function onRequestAssigned(array $payload): void
    {
        $request      = $payload['request'];
        $assignedToId = $payload['assigned_to']['id'] ?? null;

        if (!$assignedToId) {
            return;
        }

        $this->logger->info('Request assigned', [
            'request_id'  => $request['id'],
            'assigned_to' => $assignedToId,
        ]);

        $assignedUser = $this->userRepo->findById((int) $assignedToId);

        if (!$assignedUser) {
            return;
        }

        $subject = "You have been assigned request: {$request['title']}";
        $body    = "<p>You have been assigned to the <strong>{$request['type']}</strong> request "
            . "<strong>{$request['title']}</strong>.</p>"
            . "<p><strong>Priority:</strong> " . ($request['priority'] ?? 'medium') . "</p>";

        $this->sendNotificationEmail(
            $assignedUser['email'],
            $assignedUser['full_name'],
            $subject,
            $body
        );
    }

    /**
     * Send a notification email via the EmailHelper.
     */
    private function sendNotificationEmail(string $toEmail, string $toName, string $subject, string $message): void
    {
        $this->emailHelper->sendNotification($toEmail, $toName, $subject, $message);
    }
}
