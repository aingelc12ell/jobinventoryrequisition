<?php
declare(strict_types=1);

// ================================================================
// Seeder class
// ================================================================
class Seeder
{
    // ── Generated ID caches ──────────────────────────────────
    /** @var int[] */
    private array $personnelIds = [];
    /** @var int[] */
    private array $staffIds = [];
    /** @var int[] */
    private array $adminIds = [];
    /** @var array<int, array{id: int, name: string, unit: string, stock: int}> */
    private array $inventoryItems = [];
    /** @var int[] request IDs by target status */
    private array $requestIds = [];

    private string $passwordHash;
    private int $totalRequests;

    // ── Status distribution weights ──────────────────────────
    private const array STATUS_WEIGHTS = [
        'draft'     => 8,
        'submitted' => 12,
        'in_review' => 15,
        'approved'  => 25,
        'rejected'  => 10,
        'completed' => 25,
        'cancelled' => 5,
    ];

    // ── Data pools ───────────────────────────────────────────

    private const array FIRST_NAMES = [
        'James','Mary','Robert','Patricia','John','Jennifer','Michael','Linda',
        'David','Elizabeth','William','Barbara','Richard','Susan','Joseph','Jessica',
        'Thomas','Sarah','Daniel','Karen','Matthew','Lisa','Anthony','Nancy',
        'Mark','Betty','Steven','Dorothy','Andrew','Margaret',
    ];

    private const array LAST_NAMES = [
        'Smith','Johnson','Williams','Brown','Jones','Garcia','Miller','Davis',
        'Rodriguez','Martinez','Hernandez','Lopez','Gonzalez','Wilson','Anderson',
        'Thomas','Taylor','Moore','Jackson','Martin','Lee','Perez','Thompson',
        'White','Harris','Sanchez','Clark','Ramirez','Lewis','Robinson',
    ];

    private const array JOB_TITLES = [
        'Fix leaking faucet in Building A restroom',
        'Replace broken window in Office 203',
        'Install additional power outlets in Conference Room B',
        'Repair HVAC system in Server Room',
        'Repaint hallway walls on 3rd floor',
        'Fix elevator door alignment issue',
        'Install security camera at parking entrance',
        'Replace worn carpet in Reception area',
        'Repair broken door lock in Storage Room C',
        'Install new lighting fixtures in Warehouse',
        'Fix roof leak above 2nd floor stairwell',
        'Replace cracked tiles in cafeteria kitchen',
        'Repair malfunctioning fire alarm panel — Zone 3',
        'Install ADA-compliant handrails in lobby',
        'Fix water pressure issue in 4th floor restrooms',
        'Replace damaged ceiling panels in Hallway B',
        'Repair parking lot pothole near entrance',
        'Install emergency exit signs in new wing',
        'Fix squeaking doors on floor 5',
        'Replace burnt-out ballasts in open-plan office',
        'Patch drywall hole in Meeting Room 7',
        'Repair loading dock hydraulic lift',
        'Install keycard reader at side entrance',
        'Fix intermittent short circuit in Lab 2',
        'Reseal expansion joints in parking garage',
        'Install window blinds in south-facing offices',
        'Repair toilet flush mechanism — Men\'s floor 3',
        'Replace corroded drainage pipe in basement',
        'Install bike rack at east entrance',
        'Paint safety markings on warehouse floor',
    ];

    private const array JOB_DESCRIPTIONS = [
        'The issue has been reported by multiple employees. Please investigate and resolve as soon as possible.',
        'This has been an ongoing problem for the past two weeks. Previous temporary fixes have not held.',
        'Safety concern reported during the last facility inspection. Must be addressed before the next audit.',
        'Affecting daily operations and employee comfort. Requesting priority attention.',
        'Routine maintenance item identified during scheduled walkthrough.',
        'Tenant complaint received. Please schedule repair at earliest convenience.',
        'Part of the quarterly maintenance plan. Materials should be available in the supply room.',
        'This is a follow-up to work order from last month. Previous repair was insufficient.',
        'Accessibility compliance requirement. Must be completed within 30 days per regulation.',
        'Energy efficiency improvement project. Expected to reduce utility costs by approximately 15%.',
    ];

    private const array INVENTORY_TITLES = [
        'Office supplies restock for Marketing department',
        'Safety equipment order for Construction team',
        'IT equipment request for new hires — Q2',
        'Cleaning supplies restock for Building B',
        'Furniture request for new office expansion',
        'First aid kit replenishment — all floors',
        'Printer toner and paper for Finance department',
        'PPE restock for Maintenance crew',
        'Break room supplies restock — monthly',
        'Lab consumables order for R&D team',
        'Reception area supply refresh',
        'Warehouse packing material reorder',
        'Ergonomic accessories for remote workers',
        'Seasonal cleaning supply increase',
        'Conference room tech equipment refresh',
        'Stationery order for onboarding kits',
        'Janitorial supply restock — Building C',
        'Safety signage replacement order',
        'Kitchen appliance replacement — 3rd floor',
        'Filing and archival supply request',
    ];

    private const array INVENTORY_DESCRIPTIONS = [
        'Monthly restock to maintain standard inventory levels. Several items are below minimum threshold.',
        'Required for upcoming project starting next month. Please expedite if possible.',
        'Routine quarterly order. Quantities based on average consumption from last quarter.',
        'Urgent restock needed — current supplies will last approximately one week.',
        'New employee onboarding requires these items. Start date is in two weeks.',
        'Compliance requirement — these items must be available at all times per regulation.',
        'Replacing damaged or worn items identified during last inspection.',
        'Budget-approved expansion supplies. PO number will be provided upon approval.',
        'Preventive replenishment to avoid stockout before the holiday period.',
        'Standard operating supplies consumed during normal business operations.',
    ];

    private const array INVENTORY_CATALOG = [
        // [name, sku, category, unit, stock_min, stock_max, reorder_level, location]
        ['Copy Paper A4 (ream)',     'OFF-001', 'Office Supplies',    'ream',  20,  200, 30,  'Store Room A'],
        ['Ballpoint Pens (box/50)', 'OFF-002', 'Office Supplies',    'box',   10,  100, 15,  'Store Room A'],
        ['Stapler — Heavy Duty',    'OFF-003', 'Office Supplies',    'pcs',    5,   30,  8,  'Store Room A'],
        ['Sticky Notes 3x3 (pack)', 'OFF-004', 'Office Supplies',    'pack',  15,  120, 20,  'Store Room A'],
        ['Manila Folders (box/100)','OFF-005', 'Office Supplies',    'box',    5,   50, 10,  'Store Room A'],
        ['Whiteboard Markers (set)','OFF-006', 'Office Supplies',    'set',   10,   60, 12,  'Store Room A'],
        ['Binder Clips Assorted',   'OFF-007', 'Office Supplies',    'box',    8,   80, 10,  'Store Room A'],
        ['Printer Toner — Black',   'OFF-008', 'Office Supplies',    'pcs',    3,   20,  5,  'Store Room B'],

        ['USB-C Cable 2m',          'ELC-001', 'Electronics',        'pcs',    5,   50, 10,  'IT Closet'],
        ['Wireless Mouse',          'ELC-002', 'Electronics',        'pcs',    3,   25,  5,  'IT Closet'],
        ['Mechanical Keyboard',     'ELC-003', 'Electronics',        'pcs',    2,   15,  3,  'IT Closet'],
        ['24" Monitor',             'ELC-004', 'Electronics',        'pcs',    1,   10,  2,  'IT Closet'],
        ['USB Headset w/ Mic',      'ELC-005', 'Electronics',        'pcs',    3,   20,  5,  'IT Closet'],
        ['Surge Protector 6-outlet','ELC-006', 'Electronics',        'pcs',    5,   30,  8,  'IT Closet'],
        ['Webcam 1080p',            'ELC-007', 'Electronics',        'pcs',    2,   15,  3,  'IT Closet'],

        ['Ergonomic Office Chair',  'FUR-001', 'Furniture',          'pcs',    0,    8,  2,  'Warehouse'],
        ['Standing Desk 160cm',     'FUR-002', 'Furniture',          'pcs',    0,    5,  1,  'Warehouse'],
        ['3-Drawer Filing Cabinet', 'FUR-003', 'Furniture',          'pcs',    1,   10,  2,  'Warehouse'],
        ['Monitor Arm — Dual',      'FUR-004', 'Furniture',          'pcs',    2,   12,  3,  'Warehouse'],
        ['Whiteboard 120x90cm',     'FUR-005', 'Furniture',          'pcs',    1,    6,  2,  'Warehouse'],

        ['Hard Hat — ANSI Type I',  'SAF-001', 'Safety Equipment',   'pcs',   10,   60, 15,  'Safety Cage'],
        ['Safety Goggles',          'SAF-002', 'Safety Equipment',   'pcs',   10,   80, 20,  'Safety Cage'],
        ['Nitrile Gloves (box/100)','SAF-003', 'Safety Equipment',   'box',    5,   40, 10,  'Safety Cage'],
        ['First Aid Kit — Refill',  'SAF-004', 'Safety Equipment',   'kit',    3,   20,  5,  'Safety Cage'],
        ['Hi-Vis Safety Vest',      'SAF-005', 'Safety Equipment',   'pcs',    8,   50, 12,  'Safety Cage'],

        ['All-Purpose Cleaner (gal)','CLN-001','Cleaning Supplies',  'gal',    5,   30, 10,  'Janitor Room'],
        ['Trash Bags 55gal (roll)', 'CLN-002', 'Cleaning Supplies',  'roll',  10,   60, 15,  'Janitor Room'],
        ['Paper Towels (case)',     'CLN-003', 'Cleaning Supplies',  'case',   5,   40, 10,  'Janitor Room'],
        ['Hand Sanitizer 500ml',    'CLN-004', 'Cleaning Supplies',  'pcs',   10,   80, 20,  'Janitor Room'],
        ['Disinfectant Wipes (tub)','CLN-005', 'Cleaning Supplies',  'tub',    8,   50, 12,  'Janitor Room'],
    ];

    private const array STATUS_COMMENTS = [
        'submitted'  => [
            'Submitting for review.',
            'Please review at your earliest convenience.',
            'All details are in the description.',
            'Attached supporting documentation.',
            'Ready for staff review.',
        ],
        'in_review'  => [
            'Reviewing request details and checking availability.',
            'Assigned — will review within 24 hours.',
            'Checking budget allocation for this request.',
            'Verifying inventory levels before approval.',
            'Under review — may need additional information.',
        ],
        'approved'   => [
            'Approved — within budget allocation.',
            'All items in stock. Processing now.',
            'Approved. Work scheduled for next week.',
            'Approved with standard priority.',
            'Approved — forwarding to fulfillment.',
        ],
        'rejected'   => [
            'Insufficient justification provided.',
            'Budget for this category has been exhausted.',
            'Duplicate of existing request.',
            'Items are on back-order with no ETA.',
            'Request does not meet approval criteria.',
            'Please resubmit with supervisor approval.',
        ],
        'completed'  => [
            'Work completed and verified.',
            'All items delivered and confirmed.',
            'Task finished ahead of schedule.',
            'Completed — closing request.',
            'Final inspection passed.',
        ],
        'cancelled'  => [
            'No longer needed.',
            'Duplicate request — see related ticket.',
            'Requested by submitter.',
            'Superseded by a newer request.',
        ],
    ];

    // ── Context-aware message templates ──────────────────────
    // Placeholders: {title}, {type}, {priority}
    // Keyed by: [request_type][phase] => [[sender_role, template], ...]

    private const array CONV_THREADS_JOB = [
        'opening' => [
            ['personnel', 'Hi, I\'ve just submitted "{title}". Could you take a look when you have a moment?'],
            ['personnel', 'Hello — submitting this {priority}-priority job request: "{title}". Please review.'],
            ['personnel', 'This job request for "{title}" is ready for review. Let me know if more details are needed.'],
        ],
        'staff_ack' => [
            ['staff', 'Thanks, I\'ve received your request for "{title}". I\'ll review it shortly.'],
            ['staff', 'Got it — "{title}" is in my queue. I\'ll get back to you within 24 hours.'],
            ['staff', 'I see the {priority} request for "{title}". Let me check scheduling and get back to you.'],
        ],
        'clarification' => [
            ['staff', 'A quick question about "{title}" — can you confirm the exact location?'],
            ['personnel', 'Sure — it\'s on the 3rd floor, east wing. Let me know if you need anything else.'],
            ['staff', 'Could you provide more details on the scope of work for "{title}"?'],
            ['personnel', 'I\'ve updated the description with photos and measurements. Should cover it.'],
            ['staff', 'Before I can proceed with "{title}", I need to confirm the materials budget. Any PO number?'],
            ['personnel', 'I\'ll get the PO from my supervisor and send it over today.'],
        ],
        'in_progress' => [
            ['staff', '"{title}" has been assigned to our maintenance team. They\'ll start this week.'],
            ['personnel', 'Great, thanks for the update! The team will be glad to hear it.'],
            ['staff', 'Quick update — work on "{title}" is underway. Should be done within a few days.'],
            ['personnel', 'Appreciate the progress update. Is there anything you need from our side?'],
            ['staff', 'We hit a minor snag with "{title}" — waiting on a replacement part. Should arrive tomorrow.'],
            ['personnel', 'No problem, thanks for keeping us informed.'],
        ],
        'approved' => [
            ['staff', 'Good news — "{title}" has been approved. We\'ll schedule the work shortly.'],
            ['personnel', 'Excellent! When can we expect the crew to start?'],
            ['staff', '"{title}" is approved and queued. Estimated start: next Monday.'],
            ['personnel', 'Perfect, that works for our schedule. Thanks!'],
        ],
        'rejected' => [
            ['staff', 'Unfortunately, "{title}" cannot be approved right now due to budget constraints.'],
            ['personnel', 'I see. Is there anything I can adjust to get it reconsidered?'],
            ['staff', 'The request for "{title}" was declined — it falls outside the current maintenance scope.'],
            ['personnel', 'Understood. I\'ll discuss with my manager and may resubmit.'],
        ],
        'completed' => [
            ['staff', '"{title}" has been completed. Please verify everything is satisfactory.'],
            ['personnel', 'Just checked — looks great. Thanks for the quick turnaround!'],
            ['staff', 'All done with "{title}". The area has been cleaned up. Closing the request.'],
            ['personnel', 'Confirmed, everything is in order. Appreciate the help!'],
        ],
    ];

    private const array CONV_THREADS_INVENTORY = [
        'opening' => [
            ['personnel', 'Hi, I\'ve submitted an inventory request: "{title}". We\'re running low on a few items.'],
            ['personnel', 'Submitting "{title}" — {priority} priority. Several items are below reorder level.'],
            ['personnel', 'Please review "{title}". We need these supplies for ongoing operations.'],
        ],
        'staff_ack' => [
            ['staff', 'Received your inventory request "{title}". Let me check current stock levels.'],
            ['staff', 'Thanks — I\'ll review "{title}" and verify item availability today.'],
            ['staff', 'Got your request for "{title}". I\'ll cross-reference with our catalogue.'],
        ],
        'clarification' => [
            ['staff', 'Regarding "{title}" — are the quantities listed the minimum needed or the preferred amount?'],
            ['personnel', 'Those are the minimum quantities. If there\'s extra stock, we can certainly use more.'],
            ['staff', 'One item in "{title}" has been discontinued. Can I substitute with the newer model?'],
            ['personnel', 'Yes, any equivalent replacement works for us. Thanks for checking.'],
            ['staff', 'The brand you requested in "{title}" is out of stock. Shall I order a comparable alternative?'],
            ['personnel', 'Sure, as long as the specs are similar. We\'re flexible on the brand.'],
        ],
        'in_progress' => [
            ['staff', 'Processing "{title}" now. Most items are in stock and will be ready for pickup.'],
            ['personnel', 'Great, what\'s the expected delivery date?'],
            ['staff', 'Two items from "{title}" need to be ordered externally. ETA is about a week.'],
            ['personnel', 'That\'s fine. Can the in-stock items be sent ahead?'],
            ['staff', 'Absolutely — I\'ll prepare a partial shipment for "{title}" today.'],
            ['personnel', 'Perfect, that helps a lot. We need at least the basics right away.'],
        ],
        'approved' => [
            ['staff', '"{title}" has been approved. Items will be issued from the warehouse.'],
            ['personnel', 'Wonderful! Where should we pick them up?'],
            ['staff', 'Approved and ready — "{title}" items are at Store Room A. Pick up anytime.'],
            ['personnel', 'Thanks, I\'ll send someone over this afternoon.'],
        ],
        'rejected' => [
            ['staff', 'I\'m sorry, but "{title}" has been declined. We\'re over budget for this category this quarter.'],
            ['personnel', 'That\'s disappointing. Can we resubmit next quarter?'],
            ['staff', '"{title}" was rejected — the items are non-standard and require director approval.'],
            ['personnel', 'I\'ll get the director\'s sign-off and resubmit. Thanks for the heads-up.'],
        ],
        'completed' => [
            ['staff', 'All items from "{title}" have been delivered. Please confirm receipt.'],
            ['personnel', 'Everything received and accounted for. Thanks!'],
            ['staff', '"{title}" is fulfilled. Stock levels have been adjusted accordingly.'],
            ['personnel', 'Confirmed — all items match the request. Great service, thanks!'],
        ],
    ];
    private readonly string $password;

    public function __construct(
        private readonly PDO $pdo,
        private readonly int $jobCount,
        private readonly int $inventoryCount,
        private readonly int $usersPerRole,
        private readonly bool $doClean,
        string $password,
    ) {
        $this->password = $password;
        $this->passwordHash   = password_hash($password, PASSWORD_ARGON2ID);
        $this->totalRequests  = $this->jobCount + $this->inventoryCount;
    }

    // ── Public entry point ───────────────────────────────────
    public function run(): void
    {
        $start = hrtime(true);

        $this->banner();

        if ($this->doClean) {
            $this->clean();
        }

        $this->seedUsers();
        $this->seedInventory();
        $this->seedRequests();
        $this->seedMessages();

        $elapsed = round((hrtime(true) - $start) / 1e9, 2);

        $this->summary($elapsed);
    }

    // ── 0. Clean ─────────────────────────────────────────────
    private function clean(): void
    {
        $this->step('Cleaning existing data...');
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');

        $tables = [
            'message_notifications', 'messages', 'conversation_participants',
            'conversations', 'inventory_transactions', 'request_items',
            'request_status_history', 'attachments', 'requests',
            'inventory_items', 'audit_logs', 'two_factor_codes',
            'user_tokens', 'users', 'settings',
        ];

        foreach ($tables as $t) {
            $this->pdo->exec("TRUNCATE TABLE `{$t}`");
        }

        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

        // Re-seed default settings
        $this->pdo->exec("
            INSERT INTO settings (setting_key, setting_value) VALUES
                ('site_name', 'Job & Inventory Request System'),
                ('items_per_page', '15'),
                ('max_upload_size_mb', '10'),
                ('default_request_priority', 'medium'),
                ('notification_email_enabled', '1'),
                ('maintenance_mode', '0'),
                ('maintenance_message', 'The system is currently undergoing scheduled maintenance.')
        ");

        $this->ok('All tables truncated, default settings restored');
    }

    // ── 1. Users ─────────────────────────────────────────────
    private function seedUsers(): void
    {
        $this->step("Creating users ({$this->usersPerRole} per role)...");

        $stmt = $this->pdo->prepare(
            'INSERT INTO users (email, password_hash, full_name, role, is_active, email_verified_at, two_factor_enabled, last_login_at, created_at)
             VALUES (?, ?, ?, ?, 1, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)'
        );

        $roles = [
            'admin'     => 2,
            'staff'     => $this->usersPerRole,
            'personnel' => $this->usersPerRole,
        ];

        $counter = 0;
        foreach ($roles as $role => $count) {
            for ($i = 1; $i <= $count; $i++) {
                $counter++;
                $first = self::pick(self::FIRST_NAMES);
                $last  = self::pick(self::LAST_NAMES);
                $name  = "{$first} {$last}";
                $email = strtolower("{$role}.{$i}@jir.test");

                $createdAt  = $this->randomPastDate(120, 30);
                $verifiedAt = $this->addHours($createdAt, random_int(0, 6));
                $lastLogin  = random_int(0, 100) < 80
                    ? $this->addHours($verifiedAt, random_int(24, 2000))
                    : null;
                $twoFa = random_int(0, 100) < 20 ? 1 : 0;

                $stmt->execute([
                    $email, $this->passwordHash, $name, $role,
                    $verifiedAt, $twoFa, $lastLogin, $createdAt,
                ]);

                $id = (int) $this->pdo->lastInsertId();

                match ($role) {
                    'admin'     => $this->adminIds[]     = $id,
                    'staff'     => $this->staffIds[]     = $id,
                    'personnel' => $this->personnelIds[] = $id,
                };

                // Audit log
                $this->audit('user_registered', $id, 'user', $id, null, [
                    'email' => $email, 'role' => $role,
                ], $createdAt);
            }
            $this->ok("{$count} {$role} user(s)");
        }
    }

    // ── 2. Inventory catalogue ───────────────────────────────
    private function seedInventory(): void
    {
        $this->step('Seeding inventory catalogue (' . count(self::INVENTORY_CATALOG) . ' items)...');

        $stmt = $this->pdo->prepare(
            'INSERT INTO inventory_items (name, sku, category, description, unit, quantity_in_stock, reorder_level, location, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)'
        );

        $txStmt = $this->pdo->prepare(
            'INSERT INTO inventory_transactions (inventory_item_id, type, quantity, performed_by, notes, created_at)
             VALUES (?, "in", ?, ?, ?, ?)'
        );

        foreach (self::INVENTORY_CATALOG as $item) {
            [$name, $sku, $category, $unit, $stockMin, $stockMax, $reorder, $location] = $item;

            $stock     = random_int($stockMin, $stockMax);
            $createdAt = $this->randomPastDate(180, 90);

            $stmt->execute([
                $name, $sku, $category,
                "Standard {$category} item — {$name}.",
                $unit, $stock, $reorder, $location, $createdAt,
            ]);

            $id = (int) $this->pdo->lastInsertId();

            $this->inventoryItems[] = [
                'id' => $id, 'name' => $name, 'unit' => $unit, 'stock' => $stock,
            ];

            // Initial stock-in transaction
            $txStmt->execute([
                $id, $stock, self::pick($this->adminIds),
                'Initial stock entry', $createdAt,
            ]);
        }

        $this->ok(count(self::INVENTORY_CATALOG) . ' catalogue items with opening stock');
    }

    // ── 3. Requests ──────────────────────────────────────────
    private function seedRequests(): void
    {
        $this->step("Creating requests ({$this->jobCount} job + {$this->inventoryCount} inventory)...");

        // Build a bag of target statuses based on weights
        $statusBag = [];
        foreach (self::STATUS_WEIGHTS as $status => $weight) {
            for ($i = 0; $i < $weight; $i++) {
                $statusBag[] = $status;
            }
        }

        // ── Job requests ─────────────────────────────────────
        $jobCreated = 0;
        for ($i = 0; $i < $this->jobCount; $i++) {
            $target = self::pick($statusBag);
            $this->createRequest('job', $target);
            $jobCreated++;
        }
        $this->ok("{$jobCreated} job requests");

        // ── Inventory requests ───────────────────────────────
        $invCreated = 0;
        for ($i = 0; $i < $this->inventoryCount; $i++) {
            $target = self::pick($statusBag);
            $this->createRequest('inventory', $target);
            $invCreated++;
        }
        $this->ok("{$invCreated} inventory requests");

        // Status breakdown
        $breakdown = $this->pdo->query(
            'SELECT status, COUNT(*) AS c FROM requests GROUP BY status ORDER BY FIELD(status,
             "draft","submitted","in_review","approved","rejected","completed","cancelled")'
        )->fetchAll();
        foreach ($breakdown as $row) {
            $this->info("  {$row['status']}: {$row['c']}");
        }
    }

    /**
     * Create a single request and walk it through the status pipeline
     * up to the given $targetStatus.
     */
    private function createRequest(string $type, string $targetStatus): void
    {
        $submitter = self::pick($this->personnelIds);
        $priority  = self::pick(['low', 'low', 'medium', 'medium', 'medium', 'high', 'high', 'urgent']);
        $createdAt = $this->randomPastDate(90, 3);
        $dueDate   = random_int(0, 100) < 60
            ? date('Y-m-d', strtotime($createdAt) + random_int(3, 30) * 86400)
            : null;

        if ($type === 'job') {
            $title = self::pick(self::JOB_TITLES);
            $desc  = self::pick(self::JOB_DESCRIPTIONS);
        } else {
            $title = self::pick(self::INVENTORY_TITLES);
            $desc  = self::pick(self::INVENTORY_DESCRIPTIONS);
        }

        // ── Insert at draft ──────────────────────────────────
        $insertStmt = $this->pdo->prepare(
            'INSERT INTO requests (type, title, description, priority, status, submitted_by, due_date, created_at, updated_at)
             VALUES (?, ?, ?, ?, "draft", ?, ?, ?, ?)'
        );
        $insertStmt->execute([
            $type, $title, $desc, $priority, $submitter, $dueDate, $createdAt, $createdAt,
        ]);
        $requestId = (int) $this->pdo->lastInsertId();

        // ── For inventory requests: add line items ───────────
        if ($type === 'inventory') {
            $this->addRequestItems($requestId);
        }

        $this->audit('request_created', $submitter, 'request', $requestId,
            null, ['type' => $type, 'title' => $title, 'status' => 'draft'], $createdAt);

        if ($targetStatus === 'draft') {
            return;
        }

        // ── Transition: draft → submitted ────────────────────
        $ts = $this->addHours($createdAt, random_int(0, 24));
        $this->transitionStatus($requestId, 'draft', 'submitted', $submitter, $ts);

        if ($targetStatus === 'submitted') {
            return;
        }

        if ($targetStatus === 'cancelled') {
            $ts2 = $this->addHours($ts, random_int(1, 48));
            $this->transitionStatus($requestId, 'submitted', 'cancelled', $submitter, $ts2);
            return;
        }

        // ── Transition: submitted → in_review (assign staff) ─
        $staff = self::pick($this->staffIds);
        $ts2   = $this->addHours($ts, random_int(2, 48));

        $this->pdo->prepare('UPDATE requests SET assigned_to = ? WHERE id = ?')
            ->execute([$staff, $requestId]);

        $this->transitionStatus($requestId, 'submitted', 'in_review', $staff, $ts2);

        if ($targetStatus === 'in_review') {
            return;
        }

        // ── Transition: in_review → approved / rejected ──────
        $ts3 = $this->addHours($ts2, random_int(1, 36));

        if ($targetStatus === 'rejected') {
            $this->transitionStatus($requestId, 'in_review', 'rejected', $staff, $ts3);
            return;
        }

        // Approve
        $this->transitionStatus($requestId, 'in_review', 'approved', $staff, $ts3);

        // ── For inventory: create stock-out transactions ─────
        if ($type === 'inventory') {
            $this->processInventoryApproval($requestId, $staff, $ts3);
        }

        if ($targetStatus === 'approved') {
            return;
        }

        // ── Transition: approved → completed ─────────────────
        $ts4 = $this->addHours($ts3, random_int(24, 168));
        $this->transitionStatus($requestId, 'approved', 'completed', $staff, $ts4);
    }

    /**
     * Add 1-5 random line items from the inventory catalogue to a request.
     */
    private function addRequestItems(int $requestId): void
    {
        $count = random_int(1, 5);
        $used  = [];

        $stmt = $this->pdo->prepare(
            'INSERT INTO request_items (request_id, inventory_item_id, item_name, quantity, unit, notes)
             VALUES (?, ?, ?, ?, ?, ?)'
        );

        for ($i = 0; $i < $count; $i++) {
            // Pick a unique catalogue item
            $tries = 0;
            do {
                $item = self::pick($this->inventoryItems);
                $tries++;
            } while (in_array($item['id'], $used, true) && $tries < 20);

            $used[] = $item['id'];
            $qty    = random_int(1, 10);
            $notes  = random_int(0, 100) < 40
                ? 'Needed for ongoing operations.'
                : null;

            $stmt->execute([
                $requestId, $item['id'], $item['name'], $qty, $item['unit'], $notes,
            ]);
        }
    }

    /**
     * Create stock-out inventory transactions for an approved inventory request.
     */
    private function processInventoryApproval(int $requestId, int $staffId, string $approvedAt): void
    {
        $items = $this->pdo->prepare(
            'SELECT inventory_item_id, quantity FROM request_items WHERE request_id = ? AND inventory_item_id IS NOT NULL'
        );
        $items->execute([$requestId]);

        $txStmt = $this->pdo->prepare(
            'INSERT INTO inventory_transactions (inventory_item_id, type, quantity, performed_by, reference_type, reference_id, notes, created_at)
             VALUES (?, "out", ?, ?, "request", ?, ?, ?)'
        );

        $adjStmt = $this->pdo->prepare(
            'UPDATE inventory_items SET quantity_in_stock = GREATEST(quantity_in_stock - ?, 0) WHERE id = ?'
        );

        foreach ($items->fetchAll() as $row) {
            if ($row['inventory_item_id'] === null) {
                continue;
            }
            $qty = (int) $row['quantity'];
            $txStmt->execute([
                $row['inventory_item_id'], $qty, $staffId, $requestId,
                "Issued for request #{$requestId}", $approvedAt,
            ]);
            $adjStmt->execute([$qty, $row['inventory_item_id']]);
        }
    }

    /**
     * Update status, add history entry, add audit log, update timestamp.
     */
    private function transitionStatus(
        int $requestId, string $from, string $to, int $changedBy, string $ts
    ): void {
        $comment = self::pick(self::STATUS_COMMENTS[$to] ?? ['Status updated.']);

        $this->pdo->prepare('UPDATE requests SET status = ?, updated_at = ? WHERE id = ?')
            ->execute([$to, $ts, $requestId]);

        $this->pdo->prepare(
            'INSERT INTO request_status_history (request_id, old_status, new_status, changed_by, comment, created_at)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$requestId, $from, $to, $changedBy, $comment, $ts]);

        $this->audit("request_{$to}", $changedBy, 'request', $requestId,
            ['status' => $from], ['status' => $to], $ts);
    }

    // ── 4. Messages / Conversations (request-contextual) ────
    private function seedMessages(): void
    {
        $this->step('Creating request-linked conversations...');

        // Get non-draft requests with an assigned staff member and their
        // status history for timeline-aligned message placement.
        $rows = $this->pdo->query(
            'SELECT id, submitted_by, assigned_to, title, type, status, priority, created_at
             FROM requests
             WHERE assigned_to IS NOT NULL AND status != "draft"
             ORDER BY RAND()'
        )->fetchAll();

        // Pre-fetch status history keyed by request_id
        $allHistory = [];
        $histRows = $this->pdo->query(
            'SELECT request_id, old_status, new_status, created_at
             FROM request_status_history ORDER BY created_at ASC'
        )->fetchAll();
        foreach ($histRows as $h) {
            $allHistory[(int) $h['request_id']][] = $h;
        }

        // Create conversations for ~60% of eligible requests
        $eligible  = array_filter($rows, fn () => random_int(1, 100) <= 60);
        $convCount = 0;
        $msgCount  = 0;

        $convStmt = $this->pdo->prepare(
            'INSERT INTO conversations (subject, request_id, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?)'
        );
        $partStmt = $this->pdo->prepare(
            'INSERT IGNORE INTO conversation_participants (conversation_id, user_id, last_read_at) VALUES (?, ?, ?)'
        );
        $msgStmt = $this->pdo->prepare(
            'INSERT INTO messages (conversation_id, sender_id, body, created_at) VALUES (?, ?, ?, ?)'
        );

        foreach ($eligible as $req) {
            $requestId = (int) $req['id'];
            $personnel = (int) $req['submitted_by'];
            $staff     = (int) $req['assigned_to'];
            $title     = $req['title'];
            $type      = $req['type'];
            $status    = $req['status'];
            $priority  = $req['priority'];
            $history   = $allHistory[$requestId] ?? [];

            // Build a timeline of status timestamps
            $timeline = $this->buildTimeline($history, $req['created_at']);

            // Select the conversation thread pool by request type
            $pool = ($type === 'job') ? self::CONV_THREADS_JOB : self::CONV_THREADS_INVENTORY;

            // Build the message sequence based on how far the request progressed
            $messageSequence = $this->buildConversationThread($pool, $status, $timeline);

            if (empty($messageSequence)) {
                continue;
            }

            // Determine conversation start — slightly after the request was submitted
            $submitTs = $timeline['submitted'] ?? $req['created_at'];
            $convStartTs = $this->addHours($submitTs, random_int(0, 4));
            $typeLabel = ($type === 'job') ? 'Job' : 'Inventory';
            $subject = "RE: {$typeLabel} #{$requestId} — {$title}";

            $convStmt->execute([$subject, $requestId, $personnel, $convStartTs, $convStartTs]);
            $convId = (int) $this->pdo->lastInsertId();

            // Add participants
            $partStmt->execute([$convId, $personnel, null]);
            $partStmt->execute([$convId, $staff, null]);

            // Insert each message with context-replaced body
            $lastMsgTs = $convStartTs;
            foreach ($messageSequence as $msg) {
                $sender = ($msg['role'] === 'personnel') ? $personnel : $staff;
                $body   = str_replace(
                    ['{title}', '{type}', '{priority}'],
                    [$title, $type, $priority],
                    $msg['body']
                );

                // Space messages 1-12 hours apart, capped at the phase boundary
                $msgTs = $this->addHours($lastMsgTs, random_int(1, 12));
                if (isset($msg['before']) && strtotime($msgTs) > strtotime($msg['before'])) {
                    // Push back to just before the boundary
                    $msgTs = date('Y-m-d H:i:s', strtotime($msg['before']) - random_int(60, 3600));
                }
                // Don't go before the last message
                if (strtotime($msgTs) <= strtotime($lastMsgTs)) {
                    $msgTs = $this->addHours($lastMsgTs, 1);
                }

                $msgStmt->execute([$convId, $sender, $body, $msgTs]);
                $lastMsgTs = $msgTs;
                $msgCount++;
            }

            // Update conversation updated_at to last message time
            $this->pdo->prepare('UPDATE conversations SET updated_at = ? WHERE id = ?')
                ->execute([$lastMsgTs, $convId]);

            // Read-state simulation
            if (random_int(1, 100) <= 55) {
                // Both read
                $readTs = $this->addHours($lastMsgTs, random_int(0, 4));
                $this->pdo->prepare(
                    'UPDATE conversation_participants SET last_read_at = ? WHERE conversation_id = ?'
                )->execute([$readTs, $convId]);
            } elseif (random_int(1, 100) <= 50) {
                // Only submitter read (staff reply is unread by personnel)
                $this->pdo->prepare(
                    'UPDATE conversation_participants SET last_read_at = ? WHERE conversation_id = ? AND user_id = ?'
                )->execute([$this->addHours($lastMsgTs, 1), $convId, $personnel]);
            }
            // else: unread by both — creates realistic unread badges

            $convCount++;
        }

        $this->ok("{$convCount} conversations with {$msgCount} messages");
    }

    /**
     * Build a map of status → timestamp from the request's status history.
     * @return array<string, string>
     */
    private function buildTimeline(array $history, string $createdAt): array
    {
        $timeline = ['draft' => $createdAt];
        foreach ($history as $h) {
            $timeline[$h['new_status']] = $h['created_at'];
        }
        return $timeline;
    }

    /**
     * Build an ordered message sequence for a conversation based on the
     * request's final status and its status timeline.
     *
     * Each entry: ['role' => 'personnel'|'staff', 'body' => string, 'before' => ?string]
     */
    private function buildConversationThread(array $pool, string $finalStatus, array $timeline): array
    {
        $messages = [];

        // Phase ordering — which phases happen for each final status
        $phaseMap = [
            'submitted'  => ['opening', 'staff_ack'],
            'in_review'  => ['opening', 'staff_ack', 'clarification'],
            'approved'   => ['opening', 'staff_ack', 'clarification', 'approved'],
            'rejected'   => ['opening', 'staff_ack', 'clarification', 'rejected'],
            'completed'  => ['opening', 'staff_ack', 'in_progress', 'approved', 'completed'],
            'cancelled'  => ['opening', 'staff_ack'],
        ];

        $phases = $phaseMap[$finalStatus] ?? ['opening', 'staff_ack'];

        foreach ($phases as $phase) {
            $available = $pool[$phase] ?? [];
            if (empty($available)) {
                continue;
            }

            // Pick 1-2 message pairs from this phase
            $pairsToUse = ($phase === 'clarification' || $phase === 'in_progress')
                ? random_int(1, 2)
                : 1;

            // Shuffle to add variety
            $indices = array_keys($available);
            shuffle($indices);

            // Messages in the pool come in pairs (personnel, staff or staff, personnel)
            // Pick sequential pairs
            $picked = 0;
            $i = 0;
            while ($picked < $pairsToUse * 2 && $i < count($indices)) {
                $idx = $indices[$i];
                [$role, $body] = $available[$idx];

                // Determine phase boundary timestamp
                $before = null;
                if ($phase === 'opening' && isset($timeline['in_review'])) {
                    $before = $timeline['in_review'];
                } elseif ($phase === 'clarification' && isset($timeline['approved'])) {
                    $before = $timeline['approved'];
                } elseif ($phase === 'clarification' && isset($timeline['rejected'])) {
                    $before = $timeline['rejected'];
                }

                $messages[] = [
                    'role' => $role,
                    'body' => $body,
                    'before' => $before,
                ];
                $picked++;
                $i++;
            }
        }

        return $messages;
    }

    // ── Audit helper ─────────────────────────────────────────
    private function audit(
        string $action, int $userId, string $entityType, int $entityId,
        ?array $old, ?array $new, string $createdAt
    ): void {
        static $stmt = null;
        $stmt ??= $this->pdo->prepare(
            'INSERT INTO audit_logs (action, user_id, entity_type, entity_id, old_values, new_values, ip_address, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );

        $stmt->execute([
            $action, $userId, $entityType, $entityId,
            $old !== null ? json_encode($old) : null,
            $new !== null ? json_encode($new) : null,
            '127.0.0.1',
            $createdAt,
        ]);
    }

    // ── Date helpers ─────────────────────────────────────────

    /**
     * Random past datetime between $maxDaysAgo and $minDaysAgo.
     */
    private function randomPastDate(int $maxDaysAgo = 90, int $minDaysAgo = 0): string
    {
        $earliest = time() - ($maxDaysAgo * 86400);
        $latest   = time() - ($minDaysAgo * 86400);
        return date('Y-m-d H:i:s', random_int($earliest, $latest));
    }

    /**
     * Add a random number of hours to a datetime string.
     */
    private function addHours(string $datetime, int $hours): string
    {
        $ts = strtotime($datetime) + ($hours * 3600);
        // Don't exceed current time
        $ts = min($ts, time());
        return date('Y-m-d H:i:s', $ts);
    }

    // ── Array helpers ────────────────────────────────────────

    /**
     * Pick a random element from an array.
     * @template T
     * @param array<T> $arr
     * @return T
     */
    private static function pick(array $arr): mixed
    {
        return $arr[array_rand($arr)];
    }

    // ── Console output ───────────────────────────────────────

    private function banner(): void
    {
        echo "\n";
        echo "\033[36m================================================================\033[0m\n";
        echo "\033[36m  JIR — Sample Data Generator\033[0m\n";
        echo "\033[36m================================================================\033[0m\n";
        echo "  Job requests:        {$this->jobCount}\n";
        echo "  Inventory requests:  {$this->inventoryCount}\n";
        echo "  Users per role:      {$this->usersPerRole}\n";
        echo "  Clean first:         " . ($this->doClean ? 'yes' : 'no') . "\n\n";
    }

    private function step(string $msg): void
    {
        echo "\033[36m[STEP]\033[0m {$msg}\n";
    }

    private function ok(string $msg): void
    {
        echo "  \033[32m+\033[0m {$msg}\n";
    }

    private function info(string $msg): void
    {
        echo "  \033[37m{$msg}\033[0m\n";
    }

    private function summary(float $elapsed): void
    {
        // Counts
        $users    = $this->pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
        $requests = $this->pdo->query('SELECT COUNT(*) FROM requests')->fetchColumn();
        $items    = $this->pdo->query('SELECT COUNT(*) FROM inventory_items')->fetchColumn();
        $convos   = $this->pdo->query('SELECT COUNT(*) FROM conversations')->fetchColumn();
        $messages = $this->pdo->query('SELECT COUNT(*) FROM messages')->fetchColumn();
        $history  = $this->pdo->query('SELECT COUNT(*) FROM request_status_history')->fetchColumn();
        $txns     = $this->pdo->query('SELECT COUNT(*) FROM inventory_transactions')->fetchColumn();
        $audits   = $this->pdo->query('SELECT COUNT(*) FROM audit_logs')->fetchColumn();

        echo "\n";
        echo "\033[32m================================================================\033[0m\n";
        echo "\033[32m  Seeding Complete!  ({$elapsed}s)\033[0m\n";
        echo "\033[32m================================================================\033[0m\n";
        echo "\n";
        echo "  \033[1mRecords created\033[0m\n";
        echo "    Users:                  {$users}\n";
        echo "    Inventory items:        {$items}\n";
        echo "    Requests:               {$requests}\n";
        echo "    Status history entries: {$history}\n";
        echo "    Inventory transactions: {$txns}\n";
        echo "    Conversations:          {$convos}\n";
        echo "    Messages:               {$messages}\n";
        echo "    Audit log entries:      {$audits}\n";
        echo "\n";
        echo "  \033[1mTest credentials\033[0m\n";
        echo "    Password (all users): \033[36m{$this->password}\033[0m\n";
        echo "\n";
        echo "  \033[1mSample logins\033[0m\n";
        echo "    Admin:     \033[36madmin.1@jir.test\033[0m\n";
        echo "    Staff:     \033[36mstaff.1@jir.test\033[0m\n";
        echo "    Personnel: \033[36mpersonnel.1@jir.test\033[0m\n";
        echo "\n";
        echo "\033[32m================================================================\033[0m\n";
    }
}
