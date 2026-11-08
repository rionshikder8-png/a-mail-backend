<?php
declare(strict_types=1);

/**
 * Installs the schema and reference data, then reports what it did. CLI only.
 *   php database/install.php
 * Safe to re-run: every statement is idempotent (CREATE TABLE IF NOT EXISTS / upserts).
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once dirname(__DIR__) . '/config/database.php';

$pdo = db();
foreach (['schema.sql', 'seed.sql'] as $file) {
    $sql = (string)file_get_contents(__DIR__ . '/' . $file);
    // strip comments, split on statement terminators
    $sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;
    $n = 0;
    foreach (array_filter(array_map('trim', explode(";\n", $sql))) as $stmt) {
        $pdo->exec($stmt);
        $n++;
    }
    fwrite(STDOUT, "$file: $n statements applied\n");
}
$migDir = __DIR__ . '/migrations';
$pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (name VARCHAR(190) PRIMARY KEY, applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB');
$done = array_column($pdo->query('SELECT name FROM schema_migrations')->fetchAll(), 'name');
$files = glob($migDir . '/*.sql') ?: [];
sort($files);
foreach ($files as $f) {
    $name = basename($f);
    if (in_array($name, $done, true)) {
        continue;
    }
    $pdo->exec((string)file_get_contents($f));
    $pdo->prepare('INSERT INTO schema_migrations (name) VALUES (?)')->execute([$name]);
    fwrite(STDOUT, "migration applied: $name\n");
}
fwrite(STDOUT, "Done. Create your first administrator with: php database/create-admin.php you@example.com \"Your Name\" super_admin\n");
