<?php

declare(strict_types=1);

namespace App\Models\Core;

use RuntimeException;

/**
 * Loads config/mirror-network.json and exposes per-chain Mirror config.
 *
 * The JSON file is mirrored from immunity-contracts-mirror/network.json
 * (re-sync after Mirror redeploys). Chains with mirror == null are skipped
 * - they are scaffolding for future deployments.
 *
 * Placeholders of the form "${ENV_VAR}" in any string field are expanded
 * against getenv() at load time. Missing env vars become empty strings;
 * the relayer logs a warning and skips jobs for that chain rather than
 * fail-fast (failure to mirror to one chain must not block other chains).
 */
final class MirrorNetworkRegistry
{
    /** @var array<int, MirrorChainConfig> */
    private array $chains;

    /**
     * @param array<int, MirrorChainConfig> $chains
     */
    private function __construct(array $chains)
    {
        $this->chains = $chains;
    }

    public static function fromFile(string $path): self
    {
        if (!is_file($path)) {
            throw new RuntimeException("mirror network registry file not found: $path");
        }
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new RuntimeException("could not read mirror network registry: $path");
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new RuntimeException("mirror network registry is not valid JSON: $path");
        }

        $chains = [];
        foreach ($decoded as $chainIdStr => $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $mirror = $entry['mirror'] ?? null;
            $deployBlock = $entry['deployBlock'] ?? null;
            if ($mirror === null || $deployBlock === null) {
                continue; // Mirror not yet deployed on this chain.
            }
            $chainId = (int) $chainIdStr;
            $chains[$chainId] = new MirrorChainConfig(
                chainId:              $chainId,
                name:                 (string) ($entry['name'] ?? "chain-$chainId"),
                rpcUrl:               self::expandEnv((string) ($entry['rpcUrl'] ?? '')),
                mirrorAddress:        strtolower((string) $mirror),
                deployBlock:          (int) $deployBlock,
                relayerPrivateKeyEnv: (string) ($entry['relayerPrivateKeyEnv'] ?? ''),
                pollIntervalMs:       isset($entry['pollIntervalMs']) ? (int) $entry['pollIntervalMs'] : null,
            );
        }

        return new self($chains);
    }

    public static function default(): self
    {
        $configured = getenv('MIRROR_NETWORK_FILE');
        $path = ($configured !== false && $configured !== '')
            ? $configured
            : dirname(__DIR__, 3) . '/config/mirror-network.json';
        return self::fromFile($path);
    }

    /** @return array<int, MirrorChainConfig> */
    public function all(): array
    {
        return $this->chains;
    }

    public function get(int $chainId): ?MirrorChainConfig
    {
        return $this->chains[$chainId] ?? null;
    }

    private static function expandEnv(string $value): string
    {
        return preg_replace_callback(
            '/\$\{([A-Z0-9_]+)\}/',
            static function (array $m): string {
                $env = getenv($m[1]);
                return $env === false ? '' : $env;
            },
            $value
        ) ?? $value;
    }
}
