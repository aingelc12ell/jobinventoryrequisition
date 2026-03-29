<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\MessageRepository;
use App\Repositories\RequestRepository;
use App\Repositories\UserRepository;
use App\Repositories\InventoryRepository;
use App\Repositories\AuditRepository;
use PDO;

/**
 * Aggregation service for dashboard analytics.
 *
 * Combines related statistics into fewer, larger SQL queries to reduce
 * round-trip overhead. Lightweight result caching prevents duplicate
 * work when the same data is needed in multiple dashboard sections.
 */
class DashboardService
{
    /** @var array<string, mixed> Per-request result cache */
    private array $resultCache = [];

    public function __construct(
        private readonly PDO $db,
        private readonly RequestRepository $requestRepo,
        private readonly UserRepository $userRepo,
        private readonly InventoryRepository $inventoryRepo,
        private readonly MessageRepository $messageRepo,
        private readonly AuditRepository $auditRepo,
    ) {}

    // ── Personnel Dashboard ──────────────────────────────────────────

    /**
     * Get dashboard data for a personnel user.
     */
    public function getPersonnelDashboard(int $userId): array
    {
        return [
            'request_counts' => $this->requestRepo->countByStatus($userId),
            'recent_requests' => $this->getRecentRequests($userId, 5),
            'unread_messages' => $this->messageRepo->countUnread($userId),
            'recent_activity' => $this->getRecentActivity($userId, 10),
        ];
    }

    // ── Staff Dashboard ──────────────────────────────────────────────

    /**
     * Get dashboard data for a staff user.
     *
     * Combines priority, type, and status counts into a single query.
     */
    public function getStaffDashboard(int $staffId): array
    {
        $statusCounts = $this->cached('status_counts', fn() => $this->requestRepo->countByStatus());
        $summary = $this->cached('request_summary', fn() => $this->getRequestSummary());

        return [
            'status_counts' => $statusCounts,
            'pending_count' => ($statusCounts['submitted'] ?? 0) + ($statusCounts['in_review'] ?? 0),
            'requests_by_priority' => $summary['by_priority'],
            'requests_by_type' => $summary['by_type'],
            'recent_submissions' => $this->getRecentSubmissions(5),
            'my_assigned' => $this->getAssignedRequests($staffId, 5),
            'workload_by_assignee' => $this->cached('workload', fn() => $this->getWorkloadByAssignee()),
            'inventory_alerts' => $this->inventoryRepo->getLowStockItems(),
            'avg_turnaround' => $this->cached('turnaround', fn() => $this->getAverageTurnaround()),
            'unread_messages' => $this->messageRepo->countUnread($staffId),
            'recent_activity' => $this->getRecentActivity(null, 15),
        ];
    }

    // ── Admin Dashboard ──────────────────────────────────────────────

    /**
     * Get dashboard data for an admin user.
     */
    public function getAdminDashboard(int $adminId): array
    {
        $statusCounts = $this->cached('status_counts', fn() => $this->requestRepo->countByStatus());
        $summary = $this->cached('request_summary', fn() => $this->getRequestSummary());

        return [
            'status_counts' => $statusCounts,
            'total_requests' => array_sum($statusCounts),
            'user_counts' => $this->getUserCountsByRole(),
            'requests_by_priority' => $summary['by_priority'],
            'requests_by_type' => $summary['by_type'],
            'request_volume' => $this->getRequestVolumeOverTime(),
            'recent_submissions' => $this->getRecentSubmissions(10),
            'workload_by_assignee' => $this->cached('workload', fn() => $this->getWorkloadByAssignee()),
            'inventory_alerts' => $this->inventoryRepo->getLowStockItems(),
            'avg_turnaround' => $this->cached('turnaround', fn() => $this->getAverageTurnaround()),
            'unread_messages' => $this->messageRepo->countUnread($adminId),
            'recent_activity' => $this->getRecentActivity(null, 20),
        ];
    }

    // ── API Stats (for Chart.js AJAX) ────────────────────────────────

    /**
     * Get stats data formatted for chart rendering.
     */
    public function getChartData(string $userRole, int $userId): array
    {
        $data = [];

        if ($userRole === 'personnel') {
            $data['requests_by_status'] = $this->requestRepo->countByStatus($userId);
        } else {
            $data['requests_by_status'] = $this->requestRepo->countByStatus();
            $summary = $this->cached('request_summary', fn() => $this->getRequestSummary());
            $data['requests_by_priority'] = $summary['by_priority'];
            $data['requests_by_type'] = $summary['by_type'];
            $data['request_volume'] = $this->getRequestVolumeOverTime();

            if ($userRole === 'admin') {
                $data['user_counts'] = $this->getUserCountsByRole();
            }
        }

        return $data;
    }

    // ── Combined Aggregation Queries ─────────────────────────────────

    /**
     * Combine priority + type counts into a single query (saves 1 round-trip).
     *
     * @return array{by_priority: array, by_type: array}
     */
    private function getRequestSummary(): array
    {
        $stmt = $this->db->query(
            "SELECT priority, type, COUNT(*) AS cnt
             FROM requests
             WHERE status NOT IN ('cancelled')
             GROUP BY priority, type"
        );

        $byPriority = [];
        $byType = [];

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $p = $row['priority'];
            $t = $row['type'];
            $c = (int) $row['cnt'];

            $byPriority[$p] = ($byPriority[$p] ?? 0) + $c;
            $byType[$t] = ($byType[$t] ?? 0) + $c;
        }

        return ['by_priority' => $byPriority, 'by_type' => $byType];
    }

    /**
     * Get recent requests for a specific user.
     */
    private function getRecentRequests(int $userId, int $limit): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, title, type, status, priority, created_at
             FROM requests
             WHERE submitted_by = ?
             ORDER BY created_at DESC
             LIMIT ?'
        );
        $stmt->bindValue(1, $userId, PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get recently submitted requests (across all users).
     */
    private function getRecentSubmissions(int $limit): array
    {
        $stmt = $this->db->prepare(
            'SELECT r.id, r.title, r.type, r.status, r.priority, r.created_at,
                    u.full_name AS submitter_name
             FROM requests r
             LEFT JOIN users u ON r.submitted_by = u.id
             ORDER BY r.created_at DESC
             LIMIT ?'
        );
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get requests assigned to a specific staff member.
     */
    private function getAssignedRequests(int $staffId, int $limit): array
    {
        $stmt = $this->db->prepare(
            'SELECT r.id, r.title, r.type, r.status, r.priority, r.created_at,
                    u.full_name AS submitter_name
             FROM requests r
             LEFT JOIN users u ON r.submitted_by = u.id
             WHERE r.assigned_to = ? AND r.status NOT IN (\'completed\', \'cancelled\')
             ORDER BY FIELD(r.priority, \'urgent\', \'high\', \'medium\', \'low\'), r.created_at DESC
             LIMIT ?'
        );
        $stmt->bindValue(1, $staffId, PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Count users grouped by role.
     */
    private function getUserCountsByRole(): array
    {
        $stmt = $this->db->query(
            'SELECT role, COUNT(*) AS count FROM users WHERE is_active = 1 GROUP BY role'
        );

        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[$row['role']] = (int) $row['count'];
        }

        return $result;
    }

    /**
     * Get request volume over the last 30 days (grouped by date).
     */
    private function getRequestVolumeOverTime(): array
    {
        $stmt = $this->db->query(
            'SELECT DATE(created_at) AS date, COUNT(*) AS count
             FROM requests
             WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
             GROUP BY DATE(created_at)
             ORDER BY date ASC'
        );

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Count open requests per assignee (workload distribution).
     */
    private function getWorkloadByAssignee(): array
    {
        $stmt = $this->db->query(
            'SELECT u.id, u.full_name, COUNT(r.id) AS count
             FROM requests r
             JOIN users u ON r.assigned_to = u.id
             WHERE r.status IN (\'submitted\', \'in_review\', \'approved\')
             GROUP BY r.assigned_to, u.full_name
             ORDER BY count DESC'
        );

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Calculate average turnaround time (submitted -> completed) in hours.
     */
    private function getAverageTurnaround(): ?float
    {
        $stmt = $this->db->query(
            'SELECT AVG(TIMESTAMPDIFF(HOUR, r.created_at, rsh.created_at)) AS avg_hours
             FROM requests r
             JOIN request_status_history rsh ON r.id = rsh.request_id AND rsh.new_status = \'completed\'
             WHERE r.status = \'completed\''
        );

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row && $row['avg_hours'] !== null ? round((float) $row['avg_hours'], 1) : null;
    }

    /**
     * Get recent activity from audit logs with user names.
     */
    private function getRecentActivity(?int $userId, int $limit): array
    {
        $where = '';
        $params = [];

        if ($userId !== null) {
            $where = 'WHERE a.user_id = ?';
            $params[] = $userId;
        }

        $stmt = $this->db->prepare(
            "SELECT a.action, a.entity_type, a.entity_id, a.created_at,
                    u.full_name AS user_name
             FROM audit_logs a
             LEFT JOIN users u ON a.user_id = u.id
             {$where}
             ORDER BY a.created_at DESC
             LIMIT ?"
        );

        $paramIndex = 1;
        foreach ($params as $param) {
            $stmt->bindValue($paramIndex++, $param, PDO::PARAM_INT);
        }
        $stmt->bindValue($paramIndex, $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ── Internal Cache Helper ────────────────────────────────────────

    /**
     * Return a cached result or compute and cache it.
     */
    private function cached(string $key, callable $compute): mixed
    {
        if (!array_key_exists($key, $this->resultCache)) {
            $this->resultCache[$key] = $compute();
        }

        return $this->resultCache[$key];
    }
}
