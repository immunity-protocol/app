<?php

declare(strict_types=1);

define('ROOT_DIR', dirname(__DIR__));
require ROOT_DIR . '/vendor/autoload.php';

use App\Models\Core\Db;
use App\Models\Core\SqlLoader;
use Dotenv\Dotenv;
use Zephyrus\Core\Config\Configuration;
use Zephyrus\Core\Config\DatabaseConfig;

Dotenv::createImmutable(ROOT_DIR)->safeLoad();
Db::applyDatabaseUrl();

$config = Configuration::fromYamlFile(ROOT_DIR . '/config.yml');
if ($config->database === null) {
    fwrite(STDERR, "tests/bootstrap.php: no database section in config.yml\n");
    exit(1);
}

$testDbName = 'immunity_test';

// Drop and rebuild the test database from sql/0-init-database.sql each
// session. Schema is the source of truth; there is no migration history.
$adminConfig = new DatabaseConfig(
    driver:   $config->database->driver,
    host:     $config->database->host,
    port:     $config->database->port,
    database: 'postgres',
    username: $config->database->username,
    password: $config->database->password,
    charset:  $config->database->charset,
);
$adminPdo = Db::fromConfig($adminConfig)->pdo();
$adminPdo->exec(sprintf('DROP DATABASE IF EXISTS %s WITH (FORCE)', $testDbName));
$adminPdo->exec(sprintf('CREATE DATABASE %s', $testDbName));

$testConfig = new DatabaseConfig(
    driver:   $config->database->driver,
    host:     $config->database->host,
    port:     $config->database->port,
    database: $testDbName,
    username: $config->database->username,
    password: $config->database->password,
    charset:  $config->database->charset,
);
$testPdo = Db::fromConfig($testConfig)->pdo();
$testPdo->exec(SqlLoader::load(ROOT_DIR . '/sql/0-init-database.sql'));

// Expose the test DB config to test cases.
$GLOBALS['TEST_DATABASE_CONFIG'] = $testConfig;
