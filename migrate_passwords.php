<?php
/**
 * One-time migration script to hash all existing plain-text passwords.
 * Run once via browser or CLI, then delete this file.
 *
 * Usage: php migrate_passwords.php
 *    or: visit /migrate_passwords.php in browser
 */
require_once 'config/database.php';

$stmt = db()->query("SELECT id, password FROM tbl_users");
$users = $stmt->fetchAll();

$migrated = 0;
$skipped = 0;

foreach ($users as $user) {
    // Skip if already a bcrypt hash (starts with $2y$)
    if (str_starts_with($user['password'], '$2y$')) {
        $skipped++;
        continue;
    }

    $hash = password_hash($user['password'], PASSWORD_BCRYPT);
    $update = db()->prepare("UPDATE tbl_users SET password = ? WHERE id = ?");
    $update->execute([$hash, $user['id']]);
    $migrated++;
}

echo "Password migration complete.\n";
echo "Migrated: $migrated\n";
echo "Skipped (already hashed): $skipped\n";
echo "\n⚠ DELETE THIS FILE after running it.\n";
