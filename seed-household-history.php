<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

echo "========================================================\n";
echo " RoomieSync - Seeding Rich Historical & Active Data\n";
echo "========================================================\n\n";

$db = database_connection();
$db->autocommit(false);

try {
    $householdId = 1;
    $members = [1, 3, 7, 8]; // Prince (1), Jonats (3), John Doe (7), Sofia Ramos (8)

    // 1. UPDATE EXISTING BILL 9 TO PAID
    $db->query("UPDATE billsTb SET status = 'Paid' WHERE bill_id = 9 AND household_id = 1");

    // 2. INSERT PAYMENTS FOR EXISTING PAID BILL SHARES THAT HAVE NO PAYMENT RECORD
    echo "[1/7] Seeding payments for existing paid bills...\n";
    $paidSharesStmt = $db->query("
        SELECT bs.bill_share_id, bs.share_amount, b.due_date, b.bill_name, bs.user_id
        FROM bill_sharesTb bs
        INNER JOIN billsTb b ON b.bill_id = bs.bill_id
        LEFT JOIN paymentsTb p ON p.bill_share_id = bs.bill_share_id
        WHERE b.household_id = 1
          AND LOWER(bs.payment_status) = 'paid'
          AND p.payment_id IS NULL
    ");

    $payInsert = $db->prepare("
        INSERT INTO paymentsTb (bill_share_id, amount, payment_date, status, notes)
        VALUES (?, ?, ?, 'Paid', ?)
    ");

    $existingPaymentsCount = 0;
    while ($row = $paidSharesStmt->fetch_assoc()) {
        $shareId = (int) $row['bill_share_id'];
        $amount = (float) $row['share_amount'];
        $dueDate = (string) $row['due_date'];
        
        // Pick a payment date 1-3 days before or on due date
        $payTimestamp = strtotime($dueDate . ' -' . rand(0, 3) . ' days');
        if ($payTimestamp > time()) {
            $payTimestamp = time() - rand(3600, 86400);
        }
        $paymentDate = date('Y-m-d', $payTimestamp);

        $notesList = [
            'Paid via GCash app',
            'Bank transfer via BPI Online',
            'Transferred through Maya',
            'Settled in cash with Prince',
            'Paid on time via mobile banking',
            'GCash QR payment transfer'
        ];
        $note = $notesList[array_rand($notesList)];

        $payInsert->bind_param('idss', $shareId, $amount, $paymentDate, $note);
        $payInsert->execute();
        $existingPaymentsCount++;
    }
    echo "  -> Created {$existingPaymentsCount} payment records for previously unsettled paid shares.\n";

    // 3. SEED ADDITIONAL BILLS (AUGUST, OCTOBER SPECIALS, NOVEMBER UPCOMING)
    echo "\n[2/7] Seeding additional historical and upcoming bills...\n";
    $newBills = [
        [
            'category_id' => 4, // Rent
            'name' => 'Apartment Monthly Rent (August 2026)',
            'amount' => 18000.00,
            'due_date' => '2026-08-05',
            'status' => 'Paid',
            'created_at' => '2026-08-01 09:00:00',
            'all_paid' => true,
        ],
        [
            'category_id' => 1, // Electricity
            'name' => 'Meralco Electric Power Bill (August 2026)',
            'amount' => 4320.00,
            'due_date' => '2026-08-28',
            'status' => 'Paid',
            'created_at' => '2026-08-15 11:30:00',
            'all_paid' => true,
        ],
        [
            'category_id' => 3, // Internet
            'name' => 'PLDT Home Fibr Fiber 100Mbps (August 2026)',
            'amount' => 1699.00,
            'due_date' => '2026-08-20',
            'status' => 'Paid',
            'created_at' => '2026-08-05 10:00:00',
            'all_paid' => true,
        ],
        [
            'category_id' => 2, // Water
            'name' => 'Manila Water Utility (August 2026)',
            'amount' => 790.00,
            'due_date' => '2026-08-18',
            'status' => 'Paid',
            'created_at' => '2026-08-08 14:00:00',
            'all_paid' => true,
        ],
        [
            'category_id' => 2, // Maintenance / dues
            'name' => 'Apartment Bi-Monthly Pest Control Service (October 2026)',
            'amount' => 1200.00,
            'due_date' => '2026-10-22',
            'status' => 'Paid',
            'created_at' => '2026-10-15 08:30:00',
            'all_paid' => true,
        ],
        [
            'category_id' => 2, // Water
            'name' => 'Drinking Water 5-Gallon Monthly Supply Package (October 2026)',
            'amount' => 600.00,
            'due_date' => '2026-10-12',
            'status' => 'Paid',
            'created_at' => '2026-10-02 10:00:00',
            'all_paid' => true,
        ],
        [
            'category_id' => 3, // Internet
            'name' => 'PLDT Home Fibr Fiber 100Mbps (November 2026)',
            'amount' => 1699.00,
            'due_date' => '2026-11-20',
            'status' => 'Unpaid',
            'created_at' => '2026-10-26 10:00:00',
            'all_paid' => false,
        ],
        [
            'category_id' => 2, // Water
            'name' => 'Manila Water Utility (November 2026)',
            'amount' => 920.00,
            'due_date' => '2026-11-18',
            'status' => 'Unpaid',
            'created_at' => '2026-10-26 11:00:00',
            'all_paid' => false,
        ],
        [
            'category_id' => 1, // Electricity
            'name' => 'Meralco Electric Power Bill (November 2026)',
            'amount' => 4750.00,
            'due_date' => '2026-11-28',
            'status' => 'Unpaid',
            'created_at' => '2026-10-26 14:00:00',
            'all_paid' => false,
        ],
    ];

    $billInsert = $db->prepare("
        INSERT INTO billsTb (household_id, category_id, bill_name, amount, due_date, status, created_by, created_at)
        VALUES (1, ?, ?, ?, ?, ?, 1, ?)
    ");
    $shareInsert = $db->prepare("
        INSERT INTO bill_sharesTb (bill_id, user_id, share_amount, payment_status)
        VALUES (?, ?, ?, ?)
    ");

    $addedBillsCount = 0;
    foreach ($newBills as $b) {
        // Check if bill already exists
        $check = $db->prepare("SELECT bill_id FROM billsTb WHERE household_id = 1 AND bill_name = ?");
        $check->bind_param('s', $b['name']);
        $check->execute();
        if ($check->get_result()->fetch_assoc()) {
            continue;
        }

        $billInsert->bind_param('isdsss', $b['category_id'], $b['name'], $b['amount'], $b['due_date'], $b['status'], $b['created_at']);
        $billInsert->execute();
        $newBillId = $db->insert_id;
        $addedBillsCount++;

        $splitAmount = round($b['amount'] / count($members), 2);
        // Correct rounding difference on first share
        $firstShare = $b['amount'] - ($splitAmount * (count($members) - 1));

        foreach ($members as $idx => $mId) {
            $shareVal = ($idx === 0) ? $firstShare : $splitAmount;
            $payStatus = $b['all_paid'] ? 'Paid' : 'Unpaid';

            $shareInsert->bind_param('iids', $newBillId, $mId, $shareVal, $payStatus);
            $shareInsert->execute();
            $newShareId = $db->insert_id;

            if ($b['all_paid']) {
                $payTimestamp = strtotime($b['due_date'] . ' -' . rand(0, 2) . ' days');
                $paymentDate = date('Y-m-d', $payTimestamp);
                $note = 'Paid on time via online transfer';
                $payInsert->bind_param('idss', $newShareId, $shareVal, $paymentDate, $note);
                $payInsert->execute();
            }
        }
    }
    echo "  -> Added {$addedBillsCount} new bills with member split shares and payments.\n";

    // 4. POPULATE EXPENSES_SHARESTB FOR EXISTING 20 EXPENSES
    echo "\n[3/7] Seeding expense shares for existing expenses...\n";
    $existingExpStmt = $db->query("
        SELECT e.expense_id, e.amount, e.expense_date, e.paid_by
        FROM expenseTb e
        LEFT JOIN expenses_sharesTb s ON s.expense_id = e.expense_id
        WHERE e.household_id = 1
        GROUP BY e.expense_id
        HAVING COUNT(s.share_id) = 0
    ");

    $expShareInsert = $db->prepare("
        INSERT INTO expenses_sharesTb (expense_id, user_id, share_amount, is_paid)
        VALUES (?, ?, ?, ?)
    ");

    $existingExpSharesCreated = 0;
    while ($exp = $existingExpStmt->fetch_assoc()) {
        $expId = (int) $exp['expense_id'];
        $amount = (float) $exp['amount'];
        $expDate = (string) $exp['expense_date'];
        $payerId = (int) $exp['paid_by'];

        $shareVal = round($amount / count($members), 2);
        $firstShare = $amount - ($shareVal * (count($members) - 1));

        foreach ($members as $idx => $mId) {
            $val = ($idx === 0) ? $firstShare : $shareVal;
            // Payer is always paid. Older dates (> 3 days ago) are paid, very recent have 1-2 unpaid
            if ($mId === $payerId) {
                $isPaid = 1;
            } elseif ($expDate < '2026-10-18') {
                $isPaid = 1;
            } else {
                $isPaid = (rand(1, 10) > 4) ? 1 : 0;
            }

            $expShareInsert->bind_param('iidi', $expId, $mId, $val, $isPaid);
            $expShareInsert->execute();
            $existingExpSharesCreated++;
        }
    }
    echo "  -> Added {$existingExpSharesCreated} split shares for existing expenses.\n";

    // 5. SEED 22 MORE REALISTIC EXPENSES ACROSS AUGUST, SEPTEMBER, OCTOBER
    echo "\n[4/7] Seeding additional shared expenses and roommate splits...\n";
    $additionalExpenses = [
        [
            'category_id' => 3, // Household Supplies
            'title' => 'Solane LPG Cooking Gas 11kg Tank Refill',
            'amount' => 1050.00,
            'paid_by' => 1,
            'expense_date' => '2026-10-24',
            'description' => 'Replaced empty kitchen cooking gas cylinder with safety regulator check.',
            'created_at' => '2026-10-24 14:20:00'
        ],
        [
            'category_id' => 1, // Grocery
            'title' => 'Landers Wholesale 25kg Sinandomeng Rice Sack',
            'amount' => 1450.00,
            'paid_by' => 3,
            'expense_date' => '2026-10-22',
            'description' => 'Shared pantry whole grain rice supply for the household.',
            'created_at' => '2026-10-22 17:00:00'
        ],
        [
            'category_id' => 3, // Household Supplies
            'title' => 'Ariel Liquid Detergent & Downy Bulk Refill Pouches',
            'amount' => 780.00,
            'paid_by' => 8,
            'expense_date' => '2026-10-19',
            'description' => 'Laundry supplies restock: antibacterial liquid detergent and fabric softener.',
            'created_at' => '2026-10-19 11:30:00'
        ],
        [
            'category_id' => 1, // Grocery
            'title' => 'Sunday Brunch Cookout (Pancakes, Bacon, Eggs & Juice)',
            'amount' => 920.00,
            'paid_by' => 7,
            'expense_date' => '2026-10-18',
            'description' => 'Communal weekend breakfast ingredients for all roommates.',
            'created_at' => '2026-10-18 10:15:00'
        ],
        [
            'category_id' => 3, // Household Supplies
            'title' => 'Ace Hardware Multi-Bit Screwdriver & Pliers Toolset',
            'amount' => 640.00,
            'paid_by' => 1,
            'expense_date' => '2026-10-17',
            'description' => 'Household toolset for furniture assembly and quick home repairs.',
            'created_at' => '2026-10-17 15:45:00'
        ],
        [
            'category_id' => 3, // Household Supplies
            'title' => 'Joy Antibacterial Dishwashing Liquid Refill Pack (3x 800ml)',
            'amount' => 295.00,
            'paid_by' => 3,
            'expense_date' => '2026-10-13',
            'description' => 'Kitchen dishwashing liquid triple pouch bundle.',
            'created_at' => '2026-10-13 18:00:00'
        ],
        [
            'category_id' => 3, // Household Supplies
            'title' => 'Baygon Multi-Insect Killer Citrus Aerosol Twin Pack',
            'amount' => 360.00,
            'paid_by' => 8,
            'expense_date' => '2026-10-11',
            'description' => 'Mosquito and insect barrier spray for balcony and kitchen.',
            'created_at' => '2026-10-11 13:20:00'
        ],
        [
            'category_id' => 1, // Grocery
            'title' => 'Pantry Aromatics (Garlic, Red Onions, Ginger & Potatoes)',
            'amount' => 420.00,
            'paid_by' => 7,
            'expense_date' => '2026-10-09',
            'description' => 'Fresh aromatics and root crops from public market vendor.',
            'created_at' => '2026-10-09 09:30:00'
        ],
        [
            'category_id' => 1, // Grocery
            'title' => 'Drinking Water 5-Gallon Refills (5 jugs delivered)',
            'amount' => 250.00,
            'paid_by' => 1,
            'expense_date' => '2026-10-07',
            'description' => 'Door-to-door purified water delivery for water dispenser.',
            'created_at' => '2026-10-07 14:00:00'
        ],
        [
            'category_id' => 3, // Household Supplies
            'title' => 'Glade Automatic Air Freshener Lavender Refills (Pack of 2)',
            'amount' => 480.00,
            'paid_by' => 8,
            'expense_date' => '2026-10-03',
            'description' => 'Living room and corridor automated fragrance spray refills.',
            'created_at' => '2026-10-03 16:30:00'
        ],
        [
            'category_id' => 2, // Dining Out
            'title' => 'Jollibee 8-pc Chickenjoy & Jolly Spaghetti Group Meal',
            'amount' => 1120.00,
            'paid_by' => 3,
            'expense_date' => '2026-10-01',
            'description' => 'First of the month celebration dinner after apartment general cleaning.',
            'created_at' => '2026-10-01 19:45:00'
        ],
        [
            'category_id' => 3, // Household Supplies
            'title' => 'Philips Warm White 9W LED Bulbs (Pack of 4)',
            'amount' => 380.00,
            'paid_by' => 1,
            'expense_date' => '2026-09-29',
            'description' => 'Replaced burned out bulbs in dining fixture and hallway socket.',
            'created_at' => '2026-09-29 16:00:00'
        ],
        [
            'category_id' => 1, // Grocery
            'title' => 'Robinson\'s Supermarket Meat & Poultry Stockup',
            'amount' => 1850.00,
            'paid_by' => 8,
            'expense_date' => '2026-09-26',
            'description' => 'Chicken breast, lean ground pork, beef cuts, and frozen mixed veg.',
            'created_at' => '2026-09-26 15:30:00'
        ],
        [
            'category_id' => 3, // Household Supplies
            'title' => 'Scotch-Brite Heavy Duty Scrub Sponges & Wipes (Pack of 6)',
            'amount' => 210.00,
            'paid_by' => 7,
            'expense_date' => '2026-09-23',
            'description' => 'Non-scratch dish sponges and yellow microfiber kitchen wipes.',
            'created_at' => '2026-09-23 11:15:00'
        ],
        [
            'category_id' => 1, // Grocery
            'title' => 'Communal Coffee Beans 1kg (Sagada Arabica Medium Roast)',
            'amount' => 620.00,
            'paid_by' => 1,
            'expense_date' => '2026-09-20',
            'description' => 'Shared whole bean bag for kitchen French press and drip brewer.',
            'created_at' => '2026-09-20 08:30:00'
        ],
        [
            'category_id' => 4, // Transportation
            'title' => 'Shared GrabCar Transport to Wholesale Club',
            'amount' => 320.00,
            'paid_by' => 3,
            'expense_date' => '2026-09-17',
            'description' => 'Shared fare for roommates transporting bulk laundry and pantry boxes.',
            'created_at' => '2026-09-17 18:20:00'
        ],
        [
            'category_id' => 6, // Healthcare
            'title' => 'First Aid Alcohol, Cotton Balls & Band-Aids Restock',
            'amount' => 195.00,
            'paid_by' => 8,
            'expense_date' => '2026-09-14',
            'description' => '70% Isopropyl alcohol, sterile gauze pads, adhesive bandages.',
            'created_at' => '2026-09-14 12:40:00'
        ],
        [
            'category_id' => 2, // Dining Out
            'title' => 'Shakey\'s Monster Meal Deal Pizza & Chicken for Game Night',
            'amount' => 1580.00,
            'paid_by' => 7,
            'expense_date' => '2026-09-08',
            'description' => 'Shared pizza, mojo potatoes, and iced tea for roommates board game night.',
            'created_at' => '2026-09-08 20:00:00'
        ],
        [
            'category_id' => 3, // Household Supplies
            'title' => 'Living Room 6-Gang Surge Protector Extension Cord',
            'amount' => 499.00,
            'paid_by' => 1,
            'expense_date' => '2026-09-03',
            'description' => 'Individual switch surge-protected extension cord for entertainment console.',
            'created_at' => '2026-09-03 14:10:00'
        ],
        [
            'category_id' => 3, // Household Supplies
            'title' => 'Anti-Slip Bathroom Floor Mats & Microfiber Towels',
            'amount' => 530.00,
            'paid_by' => 8,
            'expense_date' => '2026-08-31',
            'description' => 'Quick-dry diatomite bath mat and replacement microfiber hand towels.',
            'created_at' => '2026-08-31 17:30:00'
        ],
        [
            'category_id' => 3, // Household Supplies
            'title' => 'Apartment Welcome Doormats (Main Entry & Kitchen Access)',
            'amount' => 280.00,
            'paid_by' => 3,
            'expense_date' => '2026-08-27',
            'description' => 'Heavy duty coir welcome mat and rubber backed kitchen runner.',
            'created_at' => '2026-08-27 15:00:00'
        ],
        [
            'category_id' => 1, // Grocery
            'title' => 'Puregold Cooking Condiments, Salt, Peppercorns & Vinegar',
            'amount' => 385.00,
            'paid_by' => 7,
            'expense_date' => '2026-08-26',
            'description' => 'Datu Puti vinegar, soy sauce, iodized salt, whole black pepper.',
            'created_at' => '2026-08-26 16:45:00'
        ]
    ];

    $newExpInsert = $db->prepare("
        INSERT INTO expenseTb (household_id, category_id, title, amount, paid_by, expense_date, description, created_at)
        VALUES (1, ?, ?, ?, ?, ?, ?, ?)
    ");

    $addedExpensesCount = 0;
    foreach ($additionalExpenses as $ae) {
        $check = $db->prepare("SELECT expense_id FROM expenseTb WHERE household_id = 1 AND title = ?");
        $check->bind_param('s', $ae['title']);
        $check->execute();
        if ($check->get_result()->fetch_assoc()) {
            continue;
        }

        $newExpInsert->bind_param(
            'isdisss',
            $ae['category_id'],
            $ae['title'],
            $ae['amount'],
            $ae['paid_by'],
            $ae['expense_date'],
            $ae['description'],
            $ae['created_at']
        );
        $newExpInsert->execute();
        $newExpId = $db->insert_id;
        $addedExpensesCount++;

        $shareVal = round($ae['amount'] / count($members), 2);
        $firstShare = $ae['amount'] - ($shareVal * (count($members) - 1));

        foreach ($members as $idx => $mId) {
            $val = ($idx === 0) ? $firstShare : $shareVal;
            if ($mId === $ae['paid_by'] || $ae['expense_date'] < '2026-10-18') {
                $isPaid = 1;
            } else {
                $isPaid = (rand(1, 10) > 4) ? 1 : 0;
            }

            $expShareInsert->bind_param('iidi', $newExpId, $mId, $val, $isPaid);
            $expShareInsert->execute();
        }
    }
    echo "  -> Added {$addedExpensesCount} new expenses with all roommate shares linked.\n";

    // 6. CHORE ROTATION & RICH ASSIGNMENT HISTORY
    echo "\n[5/7] Seeding chore rotations and comprehensive assignment history...\n";
    // Add 3 more chores to household
    $newChores = [
        [
            'category_id' => 2, // Cleaning
            'name' => 'Balcony Plants Watering & Patio Sweep',
            'desc' => 'Water potted plants, remove dry leaves, and sweep tile flooring of the balcony.',
            'freq' => 'Weekly'
        ],
        [
            'category_id' => 1, // Kitchen
            'name' => 'Kitchen Range Hood Degreasing & Stovetop Scrub',
            'desc' => 'Wash aluminum grease filters with warm soapy water and scrub burner plates.',
            'freq' => 'Bi-weekly'
        ],
        [
            'category_id' => 2, // Cleaning
            'name' => 'Shoe Cabinet & Entryway Sanitizing',
            'desc' => 'Straighten shoes on the entryway rack, disinfect door handles, and vacuum floor mat.',
            'freq' => 'Weekly'
        ],
    ];

    $choreInsert = $db->prepare("
        INSERT INTO choresTb (household_id, category_id, chore_name, description, frequency, assignment_type, created_by, created_at)
        VALUES (1, ?, ?, ?, ?, 'Rotation', 1, '2026-09-24 10:00:00')
    ");

    foreach ($newChores as $nc) {
        $check = $db->prepare("SELECT chore_id FROM choresTb WHERE household_id = 1 AND chore_name = ?");
        $check->bind_param('s', $nc['name']);
        $check->execute();
        if (!$check->get_result()->fetch_assoc()) {
            $choreInsert->bind_param('isss', $nc['category_id'], $nc['name'], $nc['desc'], $nc['freq']);
            $choreInsert->execute();
        }
    }

    // Ensure chore_rotationTb is populated for all household chores
    $allChoresQuery = $db->query("SELECT chore_id FROM choresTb WHERE household_id = 1");
    $rotInsert = $db->prepare("
        INSERT INTO chore_rotationTb (chore_id, user_id, rotation_order)
        VALUES (?, ?, ?)
    ");

    $rotationsConfigured = 0;
    while ($c = $allChoresQuery->fetch_assoc()) {
        $cId = (int) $c['chore_id'];
        $checkRot = $db->query("SELECT COUNT(*) FROM chore_rotationTb WHERE chore_id = {$cId}")->fetch_row()[0];
        if ($checkRot == 0) {
            foreach ($members as $orderIdx => $uId) {
                $orderNum = $orderIdx + 1;
                $rotInsert->bind_param('iii', $cId, $uId, $orderNum);
                $rotInsert->execute();
                $rotationsConfigured++;
            }
        }
    }
    echo "  -> Configured {$rotationsConfigured} rotation entries across household chores.\n";

    // Add historical chore assignments across September and October
    $assignInsert = $db->prepare("
        INSERT INTO chore_assignmentsTb (chore_id, user_id, due_date, status, completed_at)
        VALUES (?, ?, ?, ?, ?)
    ");

    $choreIdList = [];
    $cq = $db->query("SELECT chore_id FROM choresTb WHERE household_id = 1");
    while ($r = $cq->fetch_assoc()) {
        $choreIdList[] = (int) $r['chore_id'];
    }

    // Historical completed dates in September and October
    $historicalChores = [
        ['chore_idx' => 0, 'user' => 1, 'due' => '2026-09-25', 'status' => 'Completed', 'done' => '2026-09-25 18:30:00'],
        ['chore_idx' => 1, 'user' => 3, 'due' => '2026-09-26', 'status' => 'Completed', 'done' => '2026-09-26 19:15:00'],
        ['chore_idx' => 2, 'user' => 7, 'due' => '2026-09-27', 'status' => 'Completed', 'done' => '2026-09-27 16:45:00'],
        ['chore_idx' => 3, 'user' => 8, 'due' => '2026-09-28', 'status' => 'Completed', 'done' => '2026-09-28 20:00:00'],
        ['chore_idx' => 4, 'user' => 1, 'due' => '2026-09-29', 'status' => 'Completed', 'done' => '2026-09-29 17:20:00'],
        ['chore_idx' => 5, 'user' => 3, 'due' => '2026-09-30', 'status' => 'Completed', 'done' => '2026-09-30 21:00:00'],
        ['chore_idx' => 0, 'user' => 7, 'due' => '2026-10-01', 'status' => 'Completed', 'done' => '2026-10-01 19:30:00'],
        ['chore_idx' => 1, 'user' => 8, 'due' => '2026-10-03', 'status' => 'Completed', 'done' => '2026-10-03 17:00:00'],
        ['chore_idx' => 2, 'user' => 1, 'due' => '2026-10-05', 'status' => 'Completed', 'done' => '2026-10-05 18:10:00'],
        ['chore_idx' => 3, 'user' => 3, 'due' => '2026-10-06', 'status' => 'Completed', 'done' => '2026-10-06 19:40:00'],
        ['chore_idx' => 4, 'user' => 7, 'due' => '2026-10-08', 'status' => 'Completed', 'done' => '2026-10-08 16:15:00'],
        ['chore_idx' => 5, 'user' => 8, 'due' => '2026-10-10', 'status' => 'Completed', 'done' => '2026-10-10 15:30:00'],
        ['chore_idx' => 6, 'user' => 1, 'due' => '2026-10-12', 'status' => 'Completed', 'done' => '2026-10-12 18:00:00'],
        ['chore_idx' => 0, 'user' => 3, 'due' => '2026-10-14', 'status' => 'Completed', 'done' => '2026-10-14 20:30:00'],
        ['chore_idx' => 1, 'user' => 7, 'due' => '2026-10-16', 'status' => 'Completed', 'done' => '2026-10-16 17:45:00'],
        ['chore_idx' => 2, 'user' => 8, 'due' => '2026-10-18', 'status' => 'Completed', 'done' => '2026-10-18 16:50:00'],
        ['chore_idx' => 3, 'user' => 1, 'due' => '2026-10-20', 'status' => 'Completed', 'done' => '2026-10-20 19:10:00'],
        ['chore_idx' => 4, 'user' => 3, 'due' => '2026-10-22', 'status' => 'Completed', 'done' => '2026-10-22 17:30:00'],
        ['chore_idx' => 5, 'user' => 7, 'due' => '2026-10-24', 'status' => 'Completed', 'done' => '2026-10-24 15:00:00'],
        ['chore_idx' => 6, 'user' => 8, 'due' => '2026-10-25', 'status' => 'Completed', 'done' => '2026-10-25 18:40:00'],
        // Active and upcoming assignments
        ['chore_idx' => 0, 'user' => 1, 'due' => '2026-10-27', 'status' => 'Overdue', 'done' => null],
        ['chore_idx' => 1, 'user' => 3, 'due' => '2026-10-28', 'status' => 'Pending', 'done' => null],
        ['chore_idx' => 2, 'user' => 7, 'due' => '2026-10-29', 'status' => 'Pending', 'done' => null],
        ['chore_idx' => 3, 'user' => 8, 'due' => '2026-10-30', 'status' => 'Pending', 'done' => null],
        ['chore_idx' => 4, 'user' => 1, 'due' => '2026-10-31', 'status' => 'Pending', 'done' => null],
        ['chore_idx' => 5, 'user' => 3, 'due' => '2026-11-01', 'status' => 'Pending', 'done' => null],
        ['chore_idx' => 6, 'user' => 7, 'due' => '2026-11-02', 'status' => 'Pending', 'done' => null],
    ];

    $addedAssignmentsCount = 0;
    foreach ($historicalChores as $hc) {
        $cIndex = $hc['chore_idx'] % count($choreIdList);
        $realChoreId = $choreIdList[$cIndex];
        $uId = $hc['user'];
        $dDate = $hc['due'];

        // Avoid exact duplicate assignment
        $check = $db->prepare("
            SELECT assignment_id FROM chore_assignmentsTb
            WHERE chore_id = ? AND user_id = ? AND due_date = ?
        ");
        $check->bind_param('iis', $realChoreId, $uId, $dDate);
        $check->execute();
        if ($check->get_result()->fetch_assoc()) {
            continue;
        }

        $assignInsert->bind_param('iisss', $realChoreId, $uId, $dDate, $hc['status'], $hc['done']);
        $assignInsert->execute();
        $addedAssignmentsCount++;
    }
    echo "  -> Added {$addedAssignmentsCount} historical completed & active chore assignments.\n";

    // 7. SEED MAINTENANCE TICKETS
    echo "\n[6/7] Seeding realistic maintenance tickets and logs...\n";
    $maintenanceTickets = [
        [
            'reported_by' => 3,
            'type' => 'Electrical',
            'desc' => 'Hallway fluorescent ceiling fixture flickers intermittently, likely faulty starter or ballast.',
            'date' => '2026-10-12 11:20:00',
            'status' => 'Resolved'
        ],
        [
            'reported_by' => 8,
            'type' => 'Plumbing',
            'desc' => 'Shower floor drain draining slowly; hair catcher cleared but main pipe needed liquid clog remover.',
            'date' => '2026-10-08 16:40:00',
            'status' => 'Resolved'
        ],
        [
            'reported_by' => 7,
            'type' => 'Appliance',
            'desc' => 'Washing machine water inlet connection hose rubber gasket was seeping droplets during wash cycle.',
            'date' => '2026-10-15 14:00:00',
            'status' => 'Resolved'
        ],
        [
            'reported_by' => 1,
            'type' => 'Carpentry',
            'desc' => 'Kitchen upper corner cabinet door hinge screws stripped from particle board, cabinet sagging slightly.',
            'date' => '2026-10-23 09:15:00',
            'status' => 'In Progress'
        ],
        [
            'reported_by' => 8,
            'type' => 'Appliance',
            'desc' => 'Kitchen range hood recirculating charcoal filter needs replacement due to cooking odor buildup.',
            'date' => '2026-10-25 18:30:00',
            'status' => 'Open'
        ],
        [
            'reported_by' => 7,
            'type' => 'Carpentry',
            'desc' => 'Bedroom window mesh screen corner came unglued from aluminum frame during monsoon gusts.',
            'date' => '2026-09-18 10:10:00',
            'status' => 'Resolved'
        ],
        [
            'reported_by' => 3,
            'type' => 'Plumbing',
            'desc' => 'Bathroom shower water heater unit thermostat knob feels loose when adjusting between warm and hot.',
            'date' => '2026-10-27 19:45:00',
            'status' => 'Open'
        ],
    ];

    $maintInsert = $db->prepare("
        INSERT INTO maintenanceTb (household_id, reported_by, problem_type, problem_desc, reported_at, status)
        VALUES (1, ?, ?, ?, ?, ?)
    ");

    $addedMaintCount = 0;
    foreach ($maintenanceTickets as $mt) {
        $check = $db->prepare("SELECT problem_id FROM maintenanceTb WHERE household_id = 1 AND problem_desc = ?");
        $check->bind_param('s', $mt['desc']);
        $check->execute();
        if ($check->get_result()->fetch_assoc()) {
            continue;
        }

        $maintInsert->bind_param('issss', $mt['reported_by'], $mt['type'], $mt['desc'], $mt['date'], $mt['status']);
        $maintInsert->execute();
        $addedMaintCount++;
    }
    echo "  -> Added {$addedMaintCount} maintenance tickets.\n";

    // 8. SEED ENGAGING ANNOUNCEMENTS
    echo "\n[7/7] Seeding household bulletin announcements...\n";
    $announcements = [
        [
            'user' => 1,
            'title' => 'Building Admin Notice: Mandatory Fire Alarm Testing Friday (10 AM - 11 AM)',
            'content' => "Building administration sent an advisory that technicians will be testing the corridor fire alarms and smoke detectors this Friday between 10:00 AM and 11:00 AM. Alarms will sound in short intermittent bursts. No need to evacuate unless instructed by building marshals.",
            'date' => '2026-10-25 15:30:00'
        ],
        [
            'user' => 8,
            'title' => 'Friendly Reminder: University Midterms Study Week - Quiet Hours After 9 PM',
            'content' => "Hey roommates! Midterms start next Monday for most of us. Requesting everyone to observe quiet resting and study hours starting 9:00 PM on weeknights. Feel free to use the dining table as a shared study desk—just keep headphone volume on check. Good luck with exams!",
            'date' => '2026-10-23 11:00:00'
        ],
        [
            'user' => 3,
            'title' => 'Kitchen Pantry Update: New Spice Racks & Labeled Storage Bins Installed',
            'content' => "I installed the two-tier acrylic spice rack beside the stovetop and labeled the pantry shelf containers (Salt, White Sugar, Ground Pepper, Pasta, Cereal). Please return all condiments and seasonings to their designated slots after meal prep so counters stay clutter-free!",
            'date' => '2026-10-16 16:45:00'
        ],
        [
            'user' => 7,
            'title' => 'Found Item: Black Wireless Earbuds Charging Case on Living Room Sofa',
            'content' => "Found a matte black Bluetooth earbuds charging case tucked between the middle cushions of the grey couch. Placed it safely on top of the TV media console. Claim whenever you are in the living room!",
            'date' => '2026-10-10 13:15:00'
        ],
        [
            'user' => 1,
            'title' => 'Barangay Eco-Waste Advisory: Strict Segregation of Biodegradable & Recyclables',
            'content' => "The barangay waste collector issued a reminder that mixed rubbish bags will be rejected starting this month. Blue bin = Clean paper, cardboard, plastic bottles, aluminum cans. Green bin = Food scraps, coffee grounds, biodegradable waste. Black bin = Non-recyclable sanitary items. Thank you all for cooperating!",
            'date' => '2026-09-28 09:00:00'
        ]
    ];

    $annInsert = $db->prepare("
        INSERT INTO announceTb (household_id, created_by, title, content, created_at)
        VALUES (1, ?, ?, ?, ?)
    ");

    $addedAnnCount = 0;
    foreach ($announcements as $an) {
        $check = $db->prepare("SELECT announcement_id FROM announceTb WHERE household_id = 1 AND title = ?");
        $check->bind_param('s', $an['title']);
        $check->execute();
        if ($check->get_result()->fetch_assoc()) {
            continue;
        }

        $annInsert->bind_param('isss', $an['user'], $an['title'], $an['content'], $an['date']);
        $annInsert->execute();
        $addedAnnCount++;
    }
    echo "  -> Added {$addedAnnCount} household announcements.\n";

    $db->commit();
    echo "\n========================================================\n";
    echo " SUCCESS: All household records committed successfully!\n";
    echo "========================================================\n";

} catch (Throwable $e) {
    $db->rollback();
    echo "\n[ERROR] Transaction failed and rolled back:\n" . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
    exit(1);
}
