<?php

declare(strict_types=1);

namespace App\Models\Indexer\Workers;

use App\Models\Indexer\Brokers\StateBroker;
use App\Models\Indexer\Chain\EventDecoder;
use App\Models\Indexer\Chain\JsonRpcClient;
use App\Models\Indexer\Entities\State;
use Throwable;

/**
 * Reads new contract logs since `indexer.state.last_processed_block` for the
 * given chain and dispatches each decoded event to the matching handler.
 * One eth_getLogs call per tick.
 *
 * Stops at `head - confirmations` to avoid reorg risk near the chain tip.
 * If the gap is larger than `chunkSize`, only that chunk is processed this
 * tick; the next tick continues from the new last_processed_block.
 *
 * Generic over the contract: the handler map (`array<string, callable>`)
 * is built per chain by the bootstrap. Same class drives the 0G Registry
 * pollers and the per-chain Mirror pollers.
 */
class EventPoller
{
    /** @var array<string, callable(array):bool> */
    private array $dispatch;

    /**
     * @param array<string, callable(array):bool> $handlers
     */
    public function __construct(
        private readonly JsonRpcClient $rpc,
        private readonly EventDecoder $decoder,
        private readonly StateBroker $state,
        private readonly int $chainId,
        private readonly string $contractAddress,
        array $handlers,
        private readonly int $confirmations = 2,
        private readonly int $chunkSize = 5000,
    ) {
        $this->dispatch = $handlers;
    }

    public function chainId(): int
    {
        return $this->chainId;
    }

    /**
     * Process one chunk of new blocks. Returns the number of events handled.
     */
    public function tick(): int
    {
        $head = $this->rpc->blockNumber();
        $safeHead = max(0, $head - $this->confirmations);

        $row = $this->state->find($this->chainId);
        $lastProcessed = $row !== null ? (int) $row->last_processed_block : 0;
        $mode = $row !== null ? (string) $row->mode : State::MODE_LIVE;

        if ($lastProcessed >= $safeHead) {
            $this->state->touch($this->chainId);
            return 0;
        }

        $fromBlock = $lastProcessed + 1;
        $toBlock = min($safeHead, $fromBlock + $this->chunkSize - 1);

        $logs = $this->rpc->getLogs([
            'fromBlock' => JsonRpcClient::intToHex($fromBlock),
            'toBlock'   => JsonRpcClient::intToHex($toBlock),
            'address'   => $this->contractAddress,
        ]);

        $handled = 0;
        foreach ($logs as $log) {
            $decoded = $this->decoder->decode($log);
            if ($decoded === null) {
                continue;
            }
            $name = $decoded['event'];
            $fn = $this->dispatch[$name] ?? null;
            if ($fn === null) {
                continue;
            }
            try {
                if ($fn($decoded)) {
                    $handled++;
                }
            } catch (Throwable $e) {
                // Skip the row but advance the cursor; a transient handler
                // bug should not block the whole indexer. Real errors land
                // in stderr where the supervisor surfaces them.
                fwrite(STDERR, "[EventPoller chain=$this->chainId] handler '$name' failed at block " . $decoded['blockNumber']
                    . " logIndex " . $decoded['logIndex'] . ': ' . $e->getMessage() . PHP_EOL);
            }
        }

        $newMode = ($toBlock >= $safeHead) ? State::MODE_LIVE : State::MODE_BACKFILLING;
        $this->state->advance($this->chainId, $toBlock, $newMode);
        return $handled;
    }
}
