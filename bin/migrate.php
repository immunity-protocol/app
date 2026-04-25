<?php

declare(strict_types=1);

/**
 * Apply outstanding sql/migrations/*.sql files.
 *
 * Each migration runs inside a transaction. Applied migrations are recorded
 * in the schema_migrations table; reruns skip them. The first migration
 * (0001_schema_migrations.sql) bootstraps the tracking table itself.
 */

define('ROOT_DIR', dirname(__DIR__));
require ROOT_DIR . '/vendor/autoload.php';

use Dotenv\Dotenv;
use Zephyrus\Core\Config\Configuration;
use Zephyrus\Data\Database;
use Zephyrus\Mailer\MailerConfig;
use Zephyrus\Rendering\RenderConfig;

Dotenv::createImmutable(ROOT_DIR)->safeLoad();

$config = Configuration::fromYamlFile(ROOT_DIR . '/config.yml', [
    'render' => RenderConfig::class,
    'mailer' => MailerConfig::class,
]);
if ($config->database === null) {
    fwrite(STDERR, "ERROR: no database section in config.yml\n");
    exit(1);
}

$db = Database::fromConfig($config->database);
$migrationsDir = ROOT_DIR . '/sql/migrations';
$files = glob($migrationsDir . '/*.sql') ?: [];
sort($files, SORT_STRING);

$tableExists = (int) $db->selectValue(
    "SELECT count(*) FROM information_schema.tables
     WHERE table_schema = 'public' AND table_name = 'schema_migrations'"
);
$applied = $tableExists
    ? array_column($db->select("SELECT name FROM schema_migrations"), 'name')
    : [];
$applied = array_flip($applied);

$ranAny = false;
foreach ($files as $file) {
    $name = basename($file);
    if (isset($applied[$name])) {
        continue;
    }
    echo "Applying $name ...\n";
    $sql = file_get_contents($file);
    if ($sql === false || trim($sql) === '') {
        fwrite(STDERR, "  skipped (empty)\n");
        continue;
    }
    $db->transaction(function () use ($db, $sql, $name) {
        $db->query($sql);
        $db->query("INSERT INTO schema_migrations (name) VALUES (?)", [$name]);
    });
    $ranAny = true;
}

if (!$ranAny) {
    echo "No pending migrations.\n";
}
