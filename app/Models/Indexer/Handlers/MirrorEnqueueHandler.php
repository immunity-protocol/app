<?php

declare(strict_types=1);

namespace App\Models\Indexer\Handlers;

use App\Models\Core\MirrorNetworkRegistry;
use App\Models\Mirror\Brokers\PendingJobsBroker;
use App\Models\Mirror\MirrorEnvelopeBuffer;

/**
 * Drains the in-memory envelope buffer (stashed by AntibodyPublishedHandler)
 * when one of the auxiliary events fires:
 *
 *   AddressBlocked       -> enqueue 'mirror_address' jobs (target = event.target)
 *   CallPatternBlocked   -> enqueue 'mirror' jobs (auxiliary_key = event.selector padded)
 *   BytecodeBlocked      -> enqueue 'mirror' jobs (auxiliary_key = event.bytecodeHash)
 *   GraphTaintAdded      -> enqueue 'mirror' jobs (auxiliary_key = event.taintSetId)
 *   SemanticPatternAdded -> enqueue 'mirror' jobs (auxiliary_key = bytes32(0))
 *
 * One row per configured Mirror chain. Idempotent on
 * (keccak_id, target_chain_id, job_type) - safe to replay.
 *
 * If the buffer is empty (publish event was missed or skipped), the handler
 * silently returns false; the audit handler still records the on-chain event
 * for visibility.
 */
class MirrorEnqueueHandler
{
    private const ZERO_BYTES32 = '0x0000000000000000000000000000000000000000000000000000000000000000';

    public function __construct(
        private readonly PendingJobsBroker $jobs,
        private readonly MirrorNetworkRegistry $networks,
        private readonly MirrorEnvelopeBuffer $buffer,
    ) {
    }

    /**
     * @param array{event:string,args:array<string,mixed>,blockNumber:int,txHash:string,logIndex:int,address:string} $decoded
     */
    public function handle(array $decoded): bool
    {
        $name = $decoded['event'];
        $args = $decoded['args'];
        $keccakIdHex = (string) ($args['keccakId'] ?? '');
        if ($keccakIdHex === '') {
            return false;
        }

        $envelope = $this->buffer->take($keccakIdHex);
        if ($envelope === null) {
            // No matching publish in this process; replays will refill the
            // buffer. Don't enqueue with a stale or missing envelope.
            return false;
        }

        $chains = $this->networks->all();
        if (empty($chains)) {
            return false;
        }

        $enqueued = 0;
        foreach ($chains as $chain) {
            $chainId = $chain->chainId;
            switch ($name) {
                case 'AddressBlocked':
                    $target = (string) ($args['target'] ?? '');
                    if ($target === '') {
                        break;
                    }
                    $this->jobs->enqueueMirrorAddress($keccakIdHex, $chainId, $envelope, $target);
                    $enqueued++;
                    break;
                case 'CallPatternBlocked':
                    $selector = (string) ($args['selector'] ?? '');
                    $envelope['auxiliary_key'] = self::padBytes32($selector);
                    $this->jobs->enqueueMirror($keccakIdHex, $chainId, $envelope);
                    $enqueued++;
                    break;
                case 'BytecodeBlocked':
                    $envelope['auxiliary_key'] = (string) ($args['bytecodeHash'] ?? self::ZERO_BYTES32);
                    $this->jobs->enqueueMirror($keccakIdHex, $chainId, $envelope);
                    $enqueued++;
                    break;
                case 'GraphTaintAdded':
                    $envelope['auxiliary_key'] = (string) ($args['taintSetId'] ?? self::ZERO_BYTES32);
                    $this->jobs->enqueueMirror($keccakIdHex, $chainId, $envelope);
                    $enqueued++;
                    break;
                case 'SemanticPatternAdded':
                    $envelope['auxiliary_key'] = self::ZERO_BYTES32;
                    $this->jobs->enqueueMirror($keccakIdHex, $chainId, $envelope);
                    $enqueued++;
                    break;
                default:
                    break;
            }
        }
        return $enqueued > 0;
    }

    /**
     * Right-pad a hex value (e.g., bytes4 selector "0x12345678") to bytes32.
     */
    private static function padBytes32(string $hex): string
    {
        if (str_starts_with($hex, '0x') || str_starts_with($hex, '0X')) {
            $hex = substr($hex, 2);
        }
        $hex = strtolower($hex);
        if ($hex === '') {
            return self::ZERO_BYTES32;
        }
        return '0x' . str_pad($hex, 64, '0', STR_PAD_RIGHT);
    }
}
