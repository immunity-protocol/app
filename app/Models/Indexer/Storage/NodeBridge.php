<?php

declare(strict_types=1);

namespace App\Models\Indexer\Storage;

use RuntimeException;

/**
 * proc_open wrapper around scripts/og-download.mjs.
 *
 * Returns the parsed envelope JSON, or throws a RuntimeException on transport
 * or parse failures. The caller (HydrationWorker) translates exceptions into
 * queue-row state transitions (backoff vs failed).
 */
class NodeBridge
{
    public function __construct(
        private readonly string $scriptPath,
        private readonly string $storageIndexerUrl,
        private readonly int $timeoutSeconds = 60,
        private readonly string $nodeBinary = 'node',
    ) {
    }

    /**
     * @return array<string, mixed>|null  null when rootHash is empty (0x000...).
     */
    public function downloadEnvelope(string $rootHashHex): ?array
    {
        $rootHashHex = strtolower(trim($rootHashHex));
        if (!preg_match('/^0x[0-9a-f]{64}$/', $rootHashHex)) {
            throw new RuntimeException("NodeBridge: invalid rootHash '$rootHashHex'");
        }

        $cmd = [$this->nodeBinary, $this->scriptPath, $rootHashHex];
        $env = ['OG_STORAGE_INDEXER' => $this->storageIndexerUrl, 'PATH' => getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin'];
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $proc = proc_open($cmd, $descriptors, $pipes, null, $env);
        if (!is_resource($proc)) {
            throw new RuntimeException('NodeBridge: failed to spawn node process');
        }
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
                throw new RuntimeException('NodeBridge: timeout after ' . $this->timeoutSeconds . 's');
            }
            usleep(50000);
        }

        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);

        if ($exit !== 0) {
            $msg = trim($stderr) !== '' ? trim($stderr) : 'exit code ' . $exit;
            throw new RuntimeException("NodeBridge failure: $msg");
        }

        $line = trim($stdout);
        if ($line === '') {
            throw new RuntimeException('NodeBridge: empty stdout');
        }
        $decoded = json_decode($line, true);
        if (!is_array($decoded) || empty($decoded['ok'])) {
            throw new RuntimeException('NodeBridge: malformed stdout: ' . substr($line, 0, 200));
        }
        if (!empty($decoded['empty'])) {
            return null;
        }
        if (!isset($decoded['payload']) || !is_array($decoded['payload'])) {
            throw new RuntimeException('NodeBridge: missing payload');
        }
        return $decoded['payload'];
    }
}
