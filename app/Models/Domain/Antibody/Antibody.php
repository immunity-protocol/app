<?php

declare(strict_types=1);

namespace App\Models\Domain\Antibody;

use stdClass;

final readonly class Antibody
{
    public function __construct(
        public int $id,
        public string $keccakId,
        public string $immId,
        public string $type,
        public ?string $flavor,
        public string $verdict,
        public int $confidence,
        public int $severity,
        public string $status,
        public mixed $primaryMatcher,
        public mixed $secondaryMatchers,
        public string $contextHash,
        public string $evidenceCid,
        public ?string $embeddingHash,
        public ?string $embeddingCid,
        public string $stakeLockUntil,
        public ?string $expiresAt,
        public string $publisher,
        public ?string $publisherEns,
        public string $stakeAmount,
        public string $attestation,
        public ?string $seedSource,
        public ?string $redactedReasoning,
        public string $createdAt,
        public string $updatedAt,
    ) {
    }

    public static function fromRow(stdClass $row): self
    {
        return new self(
            id:                (int) $row->id,
            keccakId:          self::bytea($row->keccak_id),
            immId:             $row->imm_id,
            type:              $row->type,
            flavor:            $row->flavor,
            verdict:           $row->verdict,
            confidence:        (int) $row->confidence,
            severity:          (int) $row->severity,
            status:            $row->status,
            primaryMatcher:    $row->primary_matcher,
            secondaryMatchers: $row->secondary_matchers,
            contextHash:       self::bytea($row->context_hash),
            evidenceCid:       self::bytea($row->evidence_cid),
            embeddingHash:     self::bytea($row->embedding_hash),
            embeddingCid:      self::bytea($row->embedding_cid),
            stakeLockUntil:    $row->stake_lock_until,
            expiresAt:         $row->expires_at,
            publisher:         self::bytea($row->publisher),
            publisherEns:      $row->publisher_ens,
            stakeAmount:       (string) $row->stake_amount,
            attestation:       self::bytea($row->attestation),
            seedSource:        $row->seed_source,
            redactedReasoning: $row->redacted_reasoning,
            createdAt:         $row->created_at,
            updatedAt:         $row->updated_at,
        );
    }

    /**
     * Read a Postgres bytea cell into a string. Zephyrus type-conversion does
     * not fire for resources, so we coerce here at the entity boundary.
     */
    private static function bytea(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (is_resource($value)) {
            $contents = stream_get_contents($value);
            return $contents === false ? '' : $contents;
        }
        return (string) $value;
    }
}
