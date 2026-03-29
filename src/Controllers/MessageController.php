<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\MessageService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

class MessageController
{
    public function __construct(
        private readonly Twig $view,
        private readonly MessageService $messageService,
    ) {}

    /**
     * GET /messages — Inbox listing.
     */
    public function index(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        $params = $request->getQueryParams();

        $page = max(1, (int) ($params['page'] ?? 1));
        $limit = 20;
        $offset = ($page - 1) * $limit;

        $inbox = $this->messageService->getInbox((int) $user['id'], $limit, $offset);

        return $this->view->render($response, 'messages/inbox.twig', [
            'conversations' => $inbox['data'],
            'total' => $inbox['total'],
            'page' => $page,
            'limit' => $limit,
            'total_pages' => (int) ceil($inbox['total'] / $limit),
        ]);
    }

    /**
     * GET /messages/new — New conversation form.
     */
    public function create(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        $params = $request->getQueryParams();

        $recipients = $this->messageService->getEligibleRecipients(
            (int) $user['id'],
            $user['role']
        );

        // Pre-fill from request link
        $requestId = isset($params['request_id']) ? (int) $params['request_id'] : null;
        $subject = $params['subject'] ?? '';

        return $this->view->render($response, 'messages/new.twig', [
            'recipients' => $recipients,
            'prefill_request_id' => $requestId,
            'prefill_subject' => $subject,
        ]);
    }

    /**
     * POST /messages — Store a new conversation.
     */
    public function store(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        $data = (array) $request->getParsedBody();

        $recipientIds = [];
        if (!empty($data['recipients'])) {
            if (is_array($data['recipients'])) {
                $recipientIds = array_map('intval', $data['recipients']);
            } else {
                $recipientIds = [(int) $data['recipients']];
            }
        }

        $requestId = !empty($data['request_id']) ? (int) $data['request_id'] : null;

        try {
            $conversation = $this->messageService->startConversation(
                $data['subject'] ?? '',
                $data['body'] ?? '',
                (int) $user['id'],
                $recipientIds,
                $requestId
            );

            $this->flash('success', 'Conversation started successfully.');

            return $this->redirect($response, '/messages/' . $conversation['id']);
        } catch (\RuntimeException $e) {
            $this->flash('danger', $e->getMessage());
            $_SESSION['old_input'] = $data;

            return $this->redirect($response, '/messages/new');
        }
    }

    /**
     * GET /messages/{id} — View a conversation.
     */
    public function show(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        $conversationId = (int) $args['id'];

        try {
            $conversation = $this->messageService->getConversation(
                $conversationId,
                (int) $user['id']
            );

            // Invalidate unread cache — reading a conversation changes the count
            unset($_SESSION['unread_count'], $_SESSION['unread_count_at']);

            return $this->view->render($response, 'messages/show.twig', [
                'conversation' => $conversation,
            ]);
        } catch (\RuntimeException $e) {
            $this->flash('danger', $e->getMessage());

            return $this->redirect($response, '/messages');
        }
    }

    /**
     * POST /messages/{id}/reply — Reply to a conversation.
     */
    public function reply(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        $conversationId = (int) $args['id'];
        $data = (array) $request->getParsedBody();

        try {
            $this->messageService->reply(
                $conversationId,
                (int) $user['id'],
                $data['body'] ?? ''
            );

            $this->flash('success', 'Reply sent.');

            return $this->redirect($response, '/messages/' . $conversationId);
        } catch (\RuntimeException $e) {
            $this->flash('danger', $e->getMessage());

            return $this->redirect($response, '/messages/' . $conversationId);
        }
    }

    /**
     * POST /messages/{id}/archive — Archive a conversation.
     */
    public function archive(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        $conversationId = (int) $args['id'];

        try {
            $this->messageService->archiveConversation($conversationId, (int) $user['id']);
            $this->flash('success', 'Conversation archived.');
        } catch (\RuntimeException $e) {
            $this->flash('danger', $e->getMessage());
        }

        return $this->redirect($response, '/messages');
    }

    /**
     * GET /api/messages/unread — AJAX endpoint for unread count.
     */
    public function unreadCount(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        $count = $this->messageService->getUnreadCount((int) $user['id']);

        // Update session cache so the next page load skips the DB query
        $_SESSION['unread_count'] = $count;
        $_SESSION['unread_count_at'] = time();

        $response->getBody()->write(json_encode(['unread_count' => $count], JSON_THROW_ON_ERROR));

        return $response->withHeader('Content-Type', 'application/json');
    }

    private function flash(string $type, string $message): void
    {
        $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
    }

    private function redirect(Response $response, string $url): Response
    {
        return $response->withHeader('Location', $url)->withStatus(302);
    }
}
