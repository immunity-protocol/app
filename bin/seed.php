<?php

declare(strict_types=1);

/**
 * Seed the local immunity database with mock data.
 *
 * Usage:
 *     php bin/seed.php                  # seed only (errors on existing data)
 *     php bin/seed.php --reset          # truncate first, then seed
 *     php bin/seed.php --reset --small  # tiny dataset (fast, for smoke testing)
 */

define('ROOT_DIR', dirname(__DIR__));
require ROOT_DIR . '/vendor/autoload.php';

use App\Models\Core\Db;
use Tests\Fixtures\Mock\Orchestrator;
use Tests\Fixtures\Mock\Seeds;
use Dotenv\Dotenv;
use Zephyrus\Core\Config\Configuration;

Dotenv::createImmutable(ROOT_DIR)->safeLoad();
Db::applyDatabaseUrl();
$config = Configuration::fromYamlFile(ROOT_DIR . '/config.yml');
if ($config->database === null) {
    fwrite(STDERR, "ERROR: no database section in config.yml\n");
    exit(1);
}

$reset = in_array('--reset', $argv, true);
$small = in_array('--small', $argv, true);

$db = Db::fromConfig($config->database);
$orchestrator = new Orchestrator(
    db: $db,
    seed: Seeds::DEFAULT_SEED,
    entryCount: $small ? 30 : 350,
    checkEventCount: $small ? 1000 : 50_000,
    publisherCount: $small ? 15 : 80,
    heartbeatCount: $small ? 10 : 60,
);

if ($reset) {
    echo "Truncating domain tables ...\n";
    $orchestrator->reset();
}

echo "Seeding mock data ", $small ? "(small) " : "", "...\n";
$start = microtime(true);
$tally = $orchestrator->seed();
$elapsed = microtime(true) - $start;

foreach ($tally as $key => $count) {
    echo sprintf("  %-16s %d\n", $key, $count);
}
echo sprintf("Done in %.1fs.\n", $elapsed);
