<?php

declare(strict_types=1);

namespace App\Models\Mirror;

use App\Models\Core\MirrorChainConfig;
use App\Models\Mirror\Entities\PendingJob;
use RuntimeException;

/**
 * proc_open wrapper around scripts/mirror-send.mjs.
 *
 * Writes the request envelope to the helper's STDIN (NOT argv) so the relayer
 * private key never appears in `ps` listings. Reads a single JSON line from
 * stdout describing the outcome.
 */
class MirrorBridge
{
    public function __construct(
        private readonly string $scriptPath,
        private readonly int $timeoutSeconds = 90,
        private readonly string $nodeBinary = 'node',
    ) {
    }

    /**
     * Submit a single mirror job. Returns a structured outcome:
     *   ['ok' => true,  'txHash' => '0x...', 'blockNumber' => int|null]
     *   ['ok' => true,  'alreadyAbsent' => true]                              // unmirror no-op
     *   ['ok' => false, 'error' => string, 'code' => string, 'permanent' => bool, 'txHash' => ?]
     *
     * @return array<string, mixed>
     */
    public function send(MirrorChainConfig $chain, PendingJob $job, string $privateKey, array $envelope): array
    {
        $payload = $job->payloadArray();

        $request = [
            'chainId'       => $chain->chainId,
            'rpcUrl'        => $chain->rpcUrl,
            'mirrorAddress' => $chain->mirrorAddress,
            'privateKey'    => $privateKey,
            'jobType'       => $job->job_type,
            'antibody'      => $envelope,
            'timeoutMs'     => max(15_000, ($this->timeoutSeconds - 5) * 1000),
        ];
        if ($job->job_type === PendingJob::TYPE_MIRROR) {
            $request['auxiliaryKey'] = (string) ($payload['auxiliary_key'] ?? str_repeat('0', 64));
        } elseif ($job->job_type === PendingJob::TYPE_MIRROR_ADDRESS) {
            $request['target'] = (string) ($payload['target'] ?? '');
        } elseif ($job->job_type === PendingJob::TYPE_UNMIRROR) {
            $request['keccakId'] = '0x' . self::asHex($job->keccak_id);
        }

        return $this->invoke($request);
    }

    /**
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    private function invoke(array $request): array
    {
        $cmd = [$this->nodeBinary, $this->scriptPath];
        $env = [
            'PATH' => getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin',
            'NODE_NO_WARNINGS' => '1',
        ];
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $proc = proc_open($cmd, $descriptors, $pipes, dirname($this->scriptPath), $env);
        if (!is_resource($proc)) {
            throw new RuntimeException('MirrorBridge: failed to spawn node process');
        }

        // Send the request via stdin so the private key is not visible in argv.
        fwrite($pipes[0], json_encode($request, JSON_UNESCAPED_SLASHES));
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $deadline = microtime(true) + $this->timeoutSeconds;
        while (true) {
            $stdout .= (string) stream_get_contents($pipes[1]);
            $stderr .= (string) stream_get_contents($pipes[2]);
            $status = proc_get_status($proc);
            if (!$status['running']) {
                $stdout .= (string) stream_get_contents($pipes[1]);
                $stderr .= (string) stream_get_contents($pipes[2]);
                break;
            }
            if (microtime(true) > $deadline) {
                proc_terminate($proc, 9);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($proc);
                return [
                    'ok' => false,
                    'error' => 'mirror-send.mjs timeout after ' . $this->timeoutSeconds . 's',
                    'code' => 'BRIDGE_TIMEOUT',
                    'permanent' => false,
                ];
            }
            usleep(50000);
        }

        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);

        $line = trim($stdout);
        if ($line === '') {
            return [
                'ok' => false,
                'error' => 'mirror-send.mjs produced no stdout' . ($stderr === '' ? '' : ' (stderr: ' . substr(trim($stderr), 0, 200) . ')'),
                'code' => 'BRIDGE_EMPTY',
                'permanent' => false,
            ];
        }
        // Take the LAST non-empty line; any preamble (warnings, debug) is discarded.
        $lines = preg_split('/\r?\n/', $line) ?: [];
        $lastLine = '';
        foreach (array_reverse($lines) as $candidate) {
            $candidate = trim($candidate);
            if ($candidate !== '') {
                $lastLine = $candidate;
                break;
            }
        }

        $decoded = json_decode($lastLine, true);
        if (!is_array($decoded)) {
            return [
                'ok' => false,
                'error' => 'mirror-send.mjs returned non-JSON: ' . substr($lastLine, 0, 200),
                'code' => 'BRIDGE_BAD_JSON',
                'permanent' => false,
            ];
        }
        return $decoded;
    }

    private static function asHex(string $bytea): string
    {
        // Broker-sanitized bytea arrives as raw bytes; serialize as lowercase hex.
        return bin2hex($bytea);
    }
}
