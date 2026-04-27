<?php

declare(strict_types=1);

/**
 * Demo / dev script: stamp a USD value-at-risk on the check_event matching
 * a given chain tx_hash, and propagate to event.block_event for the same tx.
 *
 * Usage:
 *     docker compose exec api php bin/record-value-protected.php <tx_hash> <usd>
 *
 * Example:
 *     docker compose exec api php bin/record-value-protected.php 0xabc... 12345.67
 *
 * Use the HTTP path (POST /v1/internal/value-protected) for SDK integration;
 * this script is for hands-on demo seeding when the SDK telemetry isn't
 * wired yet.
 */

define('ROOT_DIR', dirname(__DIR__));
require ROOT_DIR . '/vendor/autoload.php';

use App\Models\Core\Db;
use App\Models\Event\Brokers\CheckEventBroker;
use Dotenv\Dotenv;
use Zephyrus\Core\Config\Configuration;

if ($argc < 3) {
    fwrite(STDERR, "usage: php bin/record-value-protected.php <tx_hash> <usd>\n");
    exit(1);
}

$txHash = (string) $argv[1];
$usd = (string) $argv[2];

if (!preg_match('/^0x[0-9a-fA-F]{64}$/', $txHash)) {
    fwrite(STDERR, "ERROR: tx_hash must be 0x-prefixed 32-byte hex\n");
    exit(1);
}
if (!preg_match('/^\d+(\.\d+)?$/', $usd)) {
    fwrite(STDERR, "ERROR: usd must be a non-negative decimal number\n");
    exit(1);
}

Dotenv::createImmutable(ROOT_DIR)->safeLoad();
Db::applyDatabaseUrl();
$config = Configuration::fromYamlFile(ROOT_DIR . '/config.yml');
if ($config->database === null) {
    fwrite(STDERR, "ERROR: no database section in config.yml\n");
    exit(1);
}
$db = Db::fromConfig($config->database);
Db::setCurrent($db);

$broker = new CheckEventBroker();
$updated = $broker->setValueAtRisk($txHash, $usd);

if ($updated === 0) {
    fwrite(STDOUT, "no check_event found for $txHash; report queued (no-op)\n");
    exit(0);
}

fwrite(STDOUT, "updated $updated check_event row(s); value_protected_usd mirrored to event.block_event\n");
exit(0);
