<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/expenses.php';

function bill_context(PDO $connection, int $userId, int $householdId): ?array
{
    $query = $connection->prepare(
        'SELECT h.household_id, h.household_name, hm.role
         FROM householdmembersTb AS hm
         INNER JOIN householdTb AS h ON h.household_id = hm.household_id
         WHERE hm.household_id = :household_id AND hm.user_id = :user_id
         LIMIT 1'
    );
    $query->execute(['household_id' => $householdId, 'user_id' => $userId]);
    $context = $query->fetch();
    return is_array($context) ? $context : null;
}

function bill_is_admin(PDO $connection, int $userId, int $householdId): bool
{
    $context = bill_context($connection, $userId, $householdId);
    return $context !== null && strcasecmp((string) $context['role'], 'Admin') === 0;
}

function bill_seed_default_categories_if_empty(PDO $connection, int $householdId): void
{
    $check = $connection->prepare(
        'SELECT 1 FROM bill_categoriesTb WHERE household_id = :household_id LIMIT 1'
    );
    $check->execute(['household_id' => $householdId]);
    if ($check->fetchColumn()) {
        return;
    }

    $defaults = [
        'Electricity' => 'Electricity and power utility',
        'Water' => 'Water and sewer service',
        'Internet' => 'Broadband and Wi-Fi subscription',
        'Rent' => 'Monthly house or apartment rent',
    ];

    $insert = $connection->prepare(
        'INSERT INTO bill_categoriesTb (household_id, category_name, description)
         VALUES (:household_id, :category_name, :description)'
    );

    foreach ($defaults as $name => $desc) {
        $insert->execute([
            'household_id' => $householdId,
            'category_name' => $name,
            'description' => $desc,
        ]);
    }
}

function bill_get_categories(PDO $connection, int $householdId): array
{
    bill_seed_default_categories_if_empty($connection, $householdId);

    $query = $connection->prepare(
        'SELECT bill_category_id, category_name, description
         FROM bill_categoriesTb
         WHERE household_id = :household_id
         ORDER BY category_name ASC'
    );
    $query->execute(['household_id' => $householdId]);
    return $query->fetchAll();
}

function bill_get_active_members(PDO $connection, int $householdId): array
{
    $query = $connection->prepare(
        "SELECT u.user_id, u.full_name, u.username, hm.role
         FROM householdmembersTb AS hm
         INNER JOIN userTb AS u ON u.user_id = hm.user_id
         WHERE hm.household_id = :household_id
           AND LOWER(u.status) = 'active'
         ORDER BY u.full_name ASC"
    );
    $query->execute(['household_id' => $householdId]);
    return $query->fetchAll();
}

function bill_valid_date(mixed $value): ?string
{
    if (!is_string($value) || !preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $value)) {
        return null;
    }

    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    $errors = DateTimeImmutable::getLastErrors();
    if ($date === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
        return null;
    }

    return $date->format('Y-m-d') === $value ? $value : null;
}

function bill_category_is_valid(PDO $connection, int $householdId, int $categoryId): bool
{
    $query = $connection->prepare(
        'SELECT bill_category_id
         FROM bill_categoriesTb
         WHERE bill_category_id = :category_id AND household_id = :household_id
         LIMIT 1'
    );
    $query->execute(['category_id' => $categoryId, 'household_id' => $householdId]);
    return (bool) $query->fetchColumn();
}

function bill_member_is_valid(PDO $connection, int $householdId, int $userId): bool
{
    $query = $connection->prepare(
        "SELECT hm.user_id
         FROM householdmembersTb AS hm
         INNER JOIN userTb AS u ON u.user_id = hm.user_id
         WHERE hm.household_id = :household_id
           AND hm.user_id = :user_id
           AND LOWER(u.status) = 'active'
         LIMIT 1"
    );
    $query->execute(['household_id' => $householdId, 'user_id' => $userId]);
    return (bool) $query->fetchColumn();
}

function bill_sync_status(PDO $connection, int $billId, int $householdId): void
{
    // Retrieve all shares for this bill
    $query = $connection->prepare(
        'SELECT share_amount, payment_status
         FROM bill_sharesTb
         WHERE bill_id = :bill_id'
    );
    $query->execute(['bill_id' => $billId]);
    $shares = $query->fetchAll();

    if (empty($shares)) {
        return;
    }

    $allPaid = true;
    foreach ($shares as $share) {
        if (strcasecmp((string) $share['payment_status'], 'paid') !== 0) {
            $allPaid = false;
            break;
        }
    }

    $newStatus = $allPaid ? 'Paid' : 'Unpaid';

    $update = $connection->prepare(
        'UPDATE billsTb
         SET status = :status
         WHERE bill_id = :bill_id AND household_id = :household_id'
    );
    $update->execute([
        'status' => $newStatus,
        'bill_id' => $billId,
        'household_id' => $householdId,
    ]);
}

function bill_display_status(string $status, ?string $dueDate): array
{
    $normStatus = strtolower(trim($status));
    if ($normStatus === 'paid') {
        return ['label' => 'Paid', 'badge' => 'rs-bill-status-paid'];
    }

    if ($dueDate !== null && $dueDate !== '') {
        $today = new DateTimeImmutable('today');
        $due = new DateTimeImmutable($dueDate);
        $diffDays = (int) $today->diff($due)->format('%r%a');

        if ($diffDays < 0) {
            return ['label' => 'Overdue', 'badge' => 'rs-bill-status-overdue'];
        }
        if ($diffDays <= 3) {
            return ['label' => 'Due Soon', 'badge' => 'rs-bill-status-due-soon'];
        }
    }

    return ['label' => 'Unpaid', 'badge' => 'rs-bill-status-unpaid'];
}

function bill_set_flash_and_redirect(string $message, string $target = '../bills.php'): never
{
    $_SESSION['flash_message'] = ['message' => $message];
    redirect_to($target);
}

/**
 * Sets the payment status of an entire bill and all its shares (Admin function).
 */
function bill_set_status(PDO $connection, int $billId, int $householdId, string $status): bool
{
    $normStatus = (strcasecmp($status, 'paid') === 0) ? 'Paid' : 'Unpaid';

    $verify = $connection->prepare(
        'SELECT 1 FROM billsTb WHERE bill_id = :bill_id AND household_id = :household_id LIMIT 1'
    );
    $verify->execute(['bill_id' => $billId, 'household_id' => $householdId]);
    if (!$verify->fetchColumn()) {
        return false;
    }

    $connection->beginTransaction();
    try {
        $updateBill = $connection->prepare(
            'UPDATE billsTb SET status = :status WHERE bill_id = :bill_id AND household_id = :household_id'
        );
        $updateBill->execute([
            'status' => $normStatus,
            'bill_id' => $billId,
            'household_id' => $householdId,
        ]);

        $updateShares = $connection->prepare(
            'UPDATE bill_sharesTb SET payment_status = :status WHERE bill_id = :bill_id'
        );
        $updateShares->execute([
            'status' => $normStatus,
            'bill_id' => $billId,
        ]);

        if ($normStatus === 'Paid') {
            $getShares = $connection->prepare('SELECT bill_share_id, share_amount FROM bill_sharesTb WHERE bill_id = :bill_id');
            $getShares->execute(['bill_id' => $billId]);
            $shRows = $getShares->fetchAll();
            $checkP = $connection->prepare('SELECT payment_id FROM paymentsTb WHERE bill_share_id = :share_id LIMIT 1');
            $insP = $connection->prepare("INSERT INTO paymentsTb (bill_share_id, amount, payment_date, status) VALUES (:share_id, :amount, CURDATE(), 'Paid')");
            foreach ($shRows as $sh) {
                $checkP->execute(['share_id' => $sh['bill_share_id']]);
                if (!$checkP->fetchColumn()) {
                    $insP->execute(['share_id' => $sh['bill_share_id'], 'amount' => (float) $sh['share_amount']]);
                }
            }
        } else {
            $delP = $connection->prepare('DELETE p FROM paymentsTb p INNER JOIN bill_sharesTb bs ON bs.bill_share_id = p.bill_share_id WHERE bs.bill_id = :bill_id');
            $delP->execute(['bill_id' => $billId]);
        }

        $connection->commit();
        return true;
    } catch (Throwable $e) {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }
        error_log('RoomieSync bill_set_status error: ' . $e->getMessage());
        return false;
    }
}

/**
 * Sets the payment status of an individual member's bill share.
 */
function bill_set_share_status(PDO $connection, int $shareId, int $householdId, string $status): bool
{
    $normStatus = (strcasecmp($status, 'paid') === 0) ? 'Paid' : 'Unpaid';

    $query = $connection->prepare(
        'SELECT s.bill_id
         FROM bill_sharesTb AS s
         INNER JOIN billsTb AS b ON b.bill_id = s.bill_id
         WHERE s.bill_share_id = :share_id AND b.household_id = :household_id
         LIMIT 1'
    );
    $query->execute(['share_id' => $shareId, 'household_id' => $householdId]);
    $billId = $query->fetchColumn();
    if (!$billId) {
        return false;
    }

    $update = $connection->prepare(
        'UPDATE bill_sharesTb SET payment_status = :status WHERE bill_share_id = :share_id'
    );
    $update->execute(['status' => $normStatus, 'share_id' => $shareId]);

    if ($normStatus === 'Paid') {
        $checkP = $connection->prepare('SELECT payment_id FROM paymentsTb WHERE bill_share_id = :share_id LIMIT 1');
        $checkP->execute(['share_id' => $shareId]);
        if (!$checkP->fetchColumn()) {
            $amtQ = $connection->prepare('SELECT share_amount FROM bill_sharesTb WHERE bill_share_id = :share_id LIMIT 1');
            $amtQ->execute(['share_id' => $shareId]);
            $amt = (float) ($amtQ->fetchColumn() ?: 0);
            $insP = $connection->prepare(
                "INSERT INTO paymentsTb (bill_share_id, amount, payment_date, status) VALUES (:share_id, :amount, CURDATE(), 'Paid')"
            );
            $insP->execute(['share_id' => $shareId, 'amount' => $amt]);
        }
    } else {
        $delP = $connection->prepare('DELETE FROM paymentsTb WHERE bill_share_id = :share_id');
        $delP->execute(['share_id' => $shareId]);
    }

    // Recalculate and synchronize overall bill status
    bill_sync_status($connection, (int) $billId, $householdId);

    return true;
}
