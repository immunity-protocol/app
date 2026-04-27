<?php

declare(strict_types=1);

namespace App\Models\Mirror;

/**
 * Process-local cache of decoded AntibodyPublished args, keyed by keccakId hex.
 *
 * The relayer needs both the antibody envelope (from AntibodyPublished) and the
 * type-specific dispatch key (from the auxiliary event: AddressBlocked etc) to
 * build a mirror tx. Both events fire in the same publish() tx, in source-code
 * order, so the auxiliary handler always runs after AntibodyPublished within
 * the same EventPoller tick.
 *
 * The buffer is in-memory only; on indexer restart the EventPoller replays
 * unprocessed blocks and AntibodyPublished re-stashes before the auxiliary
 * event re-fires. No persistence needed.
 */
final class MirrorEnvelopeBuffer
{
    /** @var array<string, array<string, mixed>> */
    private array $envelopes = [];

    public function stash(string $keccakIdHex, array $envelope): void
    {
        $this->envelopes[self::normalize($keccakIdHex)] = $envelope;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function take(string $keccakIdHex): ?array
    {
        $key = self::normalize($keccakIdHex);
        if (!array_key_exists($key, $this->envelopes)) {
            return null;
        }
        $env = $this->envelopes[$key];
        unset($this->envelopes[$key]);
        return $env;
    }

    public function size(): int
    {
        return count($this->envelopes);
    }

    private static function normalize(string $hex): string
    {
        if (str_starts_with($hex, '0x') || str_starts_with($hex, '0X')) {
            $hex = substr($hex, 2);
        }
        return strtolower($hex);
    }
}
