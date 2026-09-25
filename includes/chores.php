<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/auth.php';
require_once __DIR__ . '/db.php';

function chore_context(PDO $connection, int $userId, int $householdId): ?array
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

function chore_is_admin(PDO $connection, int $userId, int $householdId): bool
{
    $context = chore_context($connection, $userId, $householdId);
    return $context !== null && strcasecmp((string) $context['role'], 'Admin') === 0;
}

function chore_valid_date(mixed $value): ?string
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

function chore_member_is_valid(PDO $connection, int $householdId, int $userId): bool
{
    $query = $connection->prepare(
        'SELECT hm.user_id
         FROM householdmembersTb AS hm
         INNER JOIN userTb AS u ON u.user_id = hm.user_id
         WHERE hm.household_id = :household_id
           AND hm.user_id = :user_id
           AND LOWER(u.status) = \'active\'
         LIMIT 1'
    );
    $query->execute(['household_id' => $householdId, 'user_id' => $userId]);
    return (bool) $query->fetchColumn();
}

function chore_category_is_valid(PDO $connection, int $householdId, int $categoryId): bool
{
    $query = $connection->prepare(
        'SELECT chore_category_id
         FROM chore_categoriesTb
         WHERE chore_category_id = :category_id AND household_id = :household_id
         LIMIT 1'
    );
    $query->execute(['category_id' => $categoryId, 'household_id' => $householdId]);
    return (bool) $query->fetchColumn();
}

function chore_frequency_is_valid(string $frequency): bool
{
    return in_array($frequency, ['One-time', 'Daily', 'Weekly'], true);
}

function chore_member_ids(mixed $value): array
{
    if (!is_array($value)) {
        return [];
    }

    $ids = [];
    foreach ($value as $memberId) {
        if ((is_int($memberId) || is_string($memberId)) && ctype_digit((string) $memberId) && (int) $memberId > 0) {
            $ids[] = (int) $memberId;
        }
    }

    return array_values(array_unique($ids));
}

function chore_status_label(?string $status, ?string $dueDate): string
{
    if (strcasecmp((string) $status, 'completed') === 0) {
        return 'Completed';
    }

    if ($dueDate !== null && $dueDate !== '') {
        $today = new DateTimeImmutable('today');
        $due = new DateTimeImmutable($dueDate);
        if ($due < $today) {
            return 'Overdue';
        }
        if ($due <= $today->modify('+3 days')) {
            return 'Due Soon';
        }
    }

    return 'Pending';
}

function chore_status_class(string $status): string
{
    return match (strtolower($status)) {
        'completed' => 'rs-chore-status-completed',
        'due soon' => 'rs-chore-status-due-soon',
        'overdue' => 'rs-chore-status-overdue',
        default => 'rs-chore-status-pending',
    };
}

function chore_display_date(?string $date): string
{
    if ($date === null || $date === '') {
        return 'Unassigned';
    }

    return date('M j, Y', strtotime($date));
}

function chore_next_due_date(string $frequency, string $currentDueDate): ?string
{
    $date = new DateTimeImmutable($currentDueDate);
    $frequency = strtolower(trim($frequency));

    if ($frequency === 'one-time') {
        return null;
    }

    if (str_contains($frequency, 'daily')) {
        return $date->modify('+1 day')->format('Y-m-d');
    }
    if (str_contains($frequency, 'biweekly') || str_contains($frequency, 'bi-weekly')) {
        return $date->modify('+14 days')->format('Y-m-d');
    }
    if (str_contains($frequency, 'weekly')) {
        return $date->modify('+7 days')->format('Y-m-d');
    }
    if (str_contains($frequency, 'monthly')) {
        return $date->modify('+1 month')->format('Y-m-d');
    }

    return $date->modify('+7 days')->format('Y-m-d');
}

function chore_set_flash_and_redirect(string $message, string $target = '../chores.php'): never
{
    $_SESSION['flash_message'] = ['message' => $message];
    redirect_to($target);
}
