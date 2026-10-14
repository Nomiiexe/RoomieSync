<?php
declare(strict_types=1);

require_once __DIR__ . '/bills.php';

/**
 * Seeds default household expense categories if none exist for the household.
 */
function expense_seed_default_categories_if_empty(PDO $connection, int $householdId): void
{
    $check = $connection->prepare(
        'SELECT 1 FROM expense_categoriesTb WHERE household_id = :household_id LIMIT 1'
    );
    $check->execute(['household_id' => $householdId]);
    if ($check->fetchColumn()) {
        return;
    }

    $defaults = [
        'Grocery' => 'Food, groceries, and daily supermarket essentials',
        'Dining Out' => 'Restaurants, takeout, delivery, and dining meals',
        'Household Supplies' => 'Cleaning items, toiletries, paper goods, and home supplies',
        'Transportation' => 'Fuel, parking, public transit fares, tolls, and rideshares',
        'Entertainment' => 'Movies, games, outings, and leisure activities',
        'Healthcare' => 'Medications, first aid, clinic visits, and medical items',
        'Personal' => 'Personal care, grooming, and miscellaneous individual items',
        'Other' => 'General household and miscellaneous shared spending',
    ];

    $insert = $connection->prepare(
        'INSERT INTO expense_categoriesTb (household_id, category_name, description)
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

/**
 * Retrieves all expense categories for the current household, seeding defaults if needed.
 */
function expense_get_categories(PDO $connection, int $householdId): array
{
    expense_seed_default_categories_if_empty($connection, $householdId);

    $query = $connection->prepare(
        'SELECT expense_category_id, category_name, description
         FROM expense_categoriesTb
         WHERE household_id = :household_id
         ORDER BY category_name ASC'
    );
    $query->execute(['household_id' => $householdId]);
    return $query->fetchAll();
}

/**
 * Validates whether a category ID belongs to the current household.
 */
function expense_category_is_valid(PDO $connection, int $householdId, int $categoryId): bool
{
    $query = $connection->prepare(
        'SELECT expense_category_id
         FROM expense_categoriesTb
         WHERE expense_category_id = :category_id AND household_id = :household_id
         LIMIT 1'
    );
    $query->execute(['category_id' => $categoryId, 'household_id' => $householdId]);
    return (bool) $query->fetchColumn();
}

/**
 * Validates whether a user ID is an active member of the current household.
 */
function expense_member_is_valid(PDO $connection, int $householdId, int $userId): bool
{
    return bill_member_is_valid($connection, $householdId, $userId);
}

/**
 * Flash message helper for expense actions.
 */
function expense_set_flash_and_redirect(string $message, string $target = '../bills.php?view=expenses'): never
{
    $_SESSION['flash_message'] = ['message' => $message];
    redirect_to($target);
}

/**
 * Sets the payment status of all member shares for an expense (Admin function).
 */
function expense_set_status(PDO $connection, int $expenseId, int $householdId, int $isPaid): bool
{
    $verify = $connection->prepare(
        'SELECT 1 FROM expenseTb WHERE expense_id = :expense_id AND household_id = :household_id LIMIT 1'
    );
    $verify->execute(['expense_id' => $expenseId, 'household_id' => $householdId]);
    if (!$verify->fetchColumn()) {
        return false;
    }

    $connection->beginTransaction();
    try {
        $update = $connection->prepare(
            'UPDATE expenses_sharesTb SET is_paid = :is_paid WHERE expense_id = :expense_id'
        );
        $update->execute([
            'is_paid' => $isPaid === 1 ? 1 : 0,
            'expense_id' => $expenseId,
        ]);

        $connection->commit();
        return true;
    } catch (Throwable $e) {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }
        error_log('RoomieSync expense_set_status error: ' . $e->getMessage());
        return false;
    }
}

/**
 * Sets the payment status of an individual member's expense share.
 */
function expense_set_share_status(PDO $connection, int $shareId, int $householdId, int $isPaid): bool
{
    $query = $connection->prepare(
        'SELECT s.share_id
         FROM expenses_sharesTb AS s
         INNER JOIN expenseTb AS e ON e.expense_id = s.expense_id
         WHERE s.share_id = :share_id AND e.household_id = :household_id
         LIMIT 1'
    );
    $query->execute(['share_id' => $shareId, 'household_id' => $householdId]);
    if (!$query->fetchColumn()) {
        return false;
    }

    $update = $connection->prepare(
        'UPDATE expenses_sharesTb SET is_paid = :is_paid WHERE share_id = :share_id'
    );
    return $update->execute([
        'is_paid' => $isPaid === 1 ? 1 : 0,
        'share_id' => $shareId,
    ]);
}
