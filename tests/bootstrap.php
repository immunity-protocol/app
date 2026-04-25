<?php

declare(strict_types=1);

define('ROOT_DIR', dirname(__DIR__));
require ROOT_DIR . '/vendor/autoload.php';

use Dotenv\Dotenv;
use Zephyrus\Core\Config\Configuration;
use Zephyrus\Core\Config\DatabaseConfig;
use Zephyrus\Data\Database;

Dotenv::createImmutable(ROOT_DIR)->safeLoad();

$config = Configuration::fromYamlFile(ROOT_DIR . '/config.yml');
if ($config->database === null) {
    fwrite(STDERR, "tests/bootstrap.php: no database section in config.yml\n");
    exit(1);
}

$testDbName = 'immunity_test';

// Create the test database if it does not already exist. We connect to the
// default 'postgres' admin DB to issue the CREATE.
$adminConfig = new DatabaseConfig(
    driver:   $config->database->driver,
    host:     $config->database->host,
    port:     $config->database->port,
    database: 'postgres',
    username: $config->database->username,
    password: $config->database->password,
    charset:  $config->database->charset,
);
$adminDb = Database::fromConfig($adminConfig);
$exists = (int) $adminDb->selectValue(
    "SELECT count(*) FROM pg_database WHERE datname = ?",
    [$testDbName]
);
if ($exists === 0) {
    $adminDb->pdo()->exec(sprintf('CREATE DATABASE %s', $testDbName));
}

// Apply any pending migrations to the test DB.
$testConfig = new DatabaseConfig(
    driver:   $config->database->driver,
    host:     $config->database->host,
    port:     $config->database->port,
    database: $testDbName,
    username: $config->database->username,
    password: $config->database->password,
    charset:  $config->database->charset,
);
$testDb = Database::fromConfig($testConfig);
$migrationsDir = ROOT_DIR . '/sql/migrations';
$files = glob($migrationsDir . '/*.sql') ?: [];
sort($files, SORT_STRING);

$tableExists = (int) $testDb->selectValue(
    "SELECT count(*) FROM information_schema.tables
     WHERE table_schema = 'public' AND table_name = 'schema_migrations'"
);
$applied = $tableExists
    ? array_flip(array_column($testDb->select("SELECT name FROM schema_migrations"), 'name'))
    : [];

foreach ($files as $file) {
    $name = basename($file);
    if (isset($applied[$name])) {
        continue;
    }
    $sql = file_get_contents($file);
    if ($sql === false || trim($sql) === '') {
        continue;
    }
    $pdo = $testDb->pdo();
    $pdo->beginTransaction();
    try {
        $pdo->exec($sql);
        $insert = $pdo->prepare("INSERT INTO schema_migrations (name) VALUES (?)");
        $insert->execute([$name]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

// Expose the test DB config to test cases via a global constant.
$GLOBALS['TEST_DATABASE_CONFIG'] = $testConfig;
