<?php

declare(strict_types=1);

namespace App\Models\Gateway\Brokers;

use App\Models\Core\Broker;

/**
 * The gateway's upload ledger (`gateway.upload`). Doubles as:
 *   - the nonce/replay store (UNIQUE nonce),
 *   - the per-publisher rate-limit source (row count over a trailing window),
 *   - an audit log of every accepted write.
 *
 * publisher/nonce are bytea, written via Postgres `\x` hex literals (the
 * convention used across the indexer brokers).
 */
final class GatewayUploadBroker extends Broker
{
    /** Fast pre-check: has this nonce already been accepted? */
    public function nonceSeen(string $nonceHex): bool
    {
        $row = $this->selectOne(
            "SELECT 1 AS hit FROM gateway.upload WHERE nonce = ? LIMIT 1",
            ['\\x' . self::cleanHex($nonceHex)],
        );
        return $row !== null;
    }

    /** Count a publisher's accepted uploads within the trailing window. */
    public function countRecentUploads(string $publisherHex, int $windowSeconds): int
    {
        return (int) $this->selectValue(
            "SELECT count(*) FROM gateway.upload
              WHERE publisher = ?
                AND created_at > now() - (? || ' seconds')::interval",
            ['\\x' . self::cleanHex($publisherHex), $windowSeconds],
        );
    }

    /**
     * Record an accepted upload. Returns the new row id, or null when the nonce
     * was concurrently inserted (replay race — caller rejects). This is the
     * authoritative replay guard (the UNIQUE nonce constraint).
     */
    public function record(string $publisherHex, string $nonceHex, string $evidenceCid, ?string $contextCid): ?int
    {
        $row = $this->selectOne(
            "INSERT INTO gateway.upload (publisher, nonce, evidence_cid, context_cid)
             VALUES (?, ?, ?, ?)
             ON CONFLICT (nonce) DO NOTHING
             RETURNING id",
            ['\\x' . self::cleanHex($publisherHex), '\\x' . self::cleanHex($nonceHex), $evidenceCid, $contextCid],
        );
        return $row !== null ? (int) $row->id : null;
    }

    private static function cleanHex(string $hex): string
    {
        if (str_starts_with($hex, '0x') || str_starts_with($hex, '0X')) {
            $hex = substr($hex, 2);
        }
        return strtolower($hex);
    }
}
