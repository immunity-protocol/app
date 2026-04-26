<?php

declare(strict_types=1);

/**
 * Drop, recreate, and initialize a database from sql/0-init-database.sql.
 *
 * Usage:
 *     php bin/init-database.php                # rebuilds the configured DB
 *     php bin/init-database.php --target=test  # rebuilds immunity_test
 *
 * No migrations: the SQL files in sql/ are the source of truth and the DB
 * is rebuilt from scratch on each run. Mock data is loaded by a separate
 * command (php bin/seed.php) once Batch 4 lands.
 */

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
    fwrite(STDERR, "ERROR: no database section in config.yml\n");
    exit(1);
}

$target = 'main';
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--target=')) {
        $target = substr($arg, strlen('--target='));
    }
}
$dbName = $target === 'test' ? 'immunity_test' : $config->database->database;

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

echo "Dropping $dbName ...\n";
$adminPdo->exec(sprintf('DROP DATABASE IF EXISTS %s WITH (FORCE)', $dbName));

echo "Creating $dbName ...\n";
$adminPdo->exec(sprintf('CREATE DATABASE %s', $dbName));

$targetConfig = new DatabaseConfig(
    driver:   $config->database->driver,
    host:     $config->database->host,
    port:     $config->database->port,
    database: $dbName,
    username: $config->database->username,
    password: $config->database->password,
    charset:  $config->database->charset,
);
$targetPdo = Db::fromConfig($targetConfig)->pdo();

echo "Loading sql/0-init-database.sql ...\n";
$sql = SqlLoader::load(ROOT_DIR . '/sql/0-init-database.sql');
$targetPdo->exec($sql);

echo "Done.\n";
