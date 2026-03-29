<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\AuditRepository;
use App\Repositories\UserRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;

final class ProfileController
{
    public function __construct(
        private readonly Twig $view,
        private readonly UserRepository $userRepo,
        private readonly AuditRepository $auditRepo,
    ) {
    }

    /**
     * GET /profile — View own profile (all authenticated roles).
     */
    public function show(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $currentUser = $request->getAttribute('user');
        $profileUser = $this->userRepo->findById((int) $currentUser['id']);

        if ($profileUser === null) {
            return $this->redirect($response, '/dashboard');
        }

        // All users see their own audit logs
        $logsResult = $this->auditRepo->findAll(['user_id' => (int) $profileUser['id']], 20, 0);

        return $this->view->render($response, 'profile/show.twig', [
            'profile_user' => $profileUser,
            'is_own_profile' => true,
            'show_logs' => true,
            'logs' => $logsResult['data'],
        ]);
    }

    /**
     * GET /profile/{id} — View another user's profile.
     *
     * Admin  → redirects to /admin/users/{id}/edit
     * Staff  → view-only profile (logs only if viewing self)
     */
    public function viewUser(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $currentUser = $request->getAttribute('user');
        $targetId = (int) $args['id'];

        // If viewing own profile, redirect to /profile
        if ($targetId === (int) $currentUser['id']) {
            return $this->redirect($response, '/profile');
        }

        // Admin → redirect to edit page
        if ($currentUser['role'] === 'admin') {
            return $this->redirect($response, '/admin/users/' . $targetId . '/edit');
        }

        $profileUser = $this->userRepo->findById($targetId);

        if ($profileUser === null) {
            $_SESSION['flash']['error'][] = 'User not found.';

            return $this->redirect($response, '/dashboard');
        }

        return $this->view->render($response, 'profile/show.twig', [
            'profile_user' => $profileUser,
            'is_own_profile' => false,
            'show_logs' => false,
            'logs' => [],
        ]);
    }

    private function redirect(ResponseInterface $response, string $url): ResponseInterface
    {
        return $response->withHeader('Location', $url)->withStatus(302);
    }
}
