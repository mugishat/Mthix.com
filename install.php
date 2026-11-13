<?php
declare(strict_types=1);

/**
 * Creates the database and loads demo data.
 *   php cli/install.php            fresh database + demo catalogue + demo orders
 *   php cli/install.php --empty    fresh database with only the platform admin, categories and commission rule
 */
require __DIR__ . '/../app/bootstrap.php';
require ROOT . '/database/seed.php';

use App\Core\Db;

if (cfg('env') !== 'dev' && !in_array('--force', $argv, true)) {
    fwrite(STDERR, "Refusing to wipe a non-dev database. Pass --force if you really mean it.\n");
    exit(1);
}

$path = (string)cfg('db_path');
foreach ([$path, $path . '-wal', $path . '-shm'] as $f) {
    if (is_file($f)) {
        unlink($f);
    }
}
Db::reset();
seed_install_schema();

$ids = seed_base();
if (!in_array('--empty', $argv, true)) {
    seed_catalog($ids);
    seed_demo_orders($ids);
}

echo "Database ready at $path\n\n";
echo "Demo accounts (password for all: " . DEMO_PASSWORD . ")\n";
foreach (Db::all("SELECT u.email, GROUP_CONCAT(r.role) AS roles FROM users u JOIN user_roles r ON r.user_id = u.id GROUP BY u.id ORDER BY u.id") as $u) {
    printf("  %-24s %s\n", $u['email'], $u['roles']);
}
echo "\nChange these passwords before putting anything real on this system.\n";
