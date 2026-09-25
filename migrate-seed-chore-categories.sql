-- Seed the standard chore categories for every existing household.
-- The application loads these records dynamically from chore_categoriesTb.

USE roomiesync;

INSERT INTO chore_categoriesTb (household_id, category_name, description)
SELECT h.household_id, defaults.category_name, NULL
FROM householdTb AS h
CROSS JOIN (
  SELECT 'Kitchen' AS category_name
  UNION ALL SELECT 'Cleaning'
  UNION ALL SELECT 'Laundry'
  UNION ALL SELECT 'Trash'
) AS defaults
WHERE NOT EXISTS (
  SELECT 1
  FROM chore_categoriesTb AS existing
  WHERE existing.household_id = h.household_id
    AND LOWER(existing.category_name) = LOWER(defaults.category_name)
);
