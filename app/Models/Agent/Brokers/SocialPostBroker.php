<?php

declare(strict_types=1);

namespace App\Models\Agent\Brokers;

use App\Models\Core\Broker;
use stdClass;

/**
 * The fake agent social network feed (agent.social_post). Trader agents post
 * benign chatter; wolves plant poisoned content. Read newest-first with a
 * keyset cursor on id so the /feed page can infinite-scroll like the dashboard.
 */
class SocialPostBroker extends Broker
{
    /** @return array<int, array<string, mixed>> */
    public function findRecent(int $limit): array
    {
        return array_map([self::class, 'map'], $this->select(
            "SELECT * FROM agent.social_post ORDER BY id DESC LIMIT ?",
            [$limit]
        ));
    }

    /** @return array<int, array<string, mixed>> */
    public function findOlder(int $beforeId, int $limit): array
    {
        return array_map([self::class, 'map'], $this->select(
            "SELECT * FROM agent.social_post WHERE id < ? ORDER BY id DESC LIMIT ?",
            [$beforeId, $limit]
        ));
    }

    /** Rows strictly newer than $sinceId (live prepend). @return array<int, array<string, mixed>> */
    public function findSince(int $sinceId, int $limit): array
    {
        return array_map([self::class, 'map'], $this->select(
            "SELECT * FROM agent.social_post WHERE id > ? ORDER BY id DESC LIMIT ?",
            [$sinceId, $limit]
        ));
    }

    public function countAll(): int
    {
        return (int) $this->selectValue("SELECT count(*) FROM agent.social_post");
    }

    public function countMalicious(): int
    {
        return (int) $this->selectValue("SELECT count(*) FROM agent.social_post WHERE is_malicious");
    }

    /** Insert one post; returns the new id. @param array<string,mixed> $p */
    public function insert(array $p): int
    {
        $row = $this->selectOne(
            "INSERT INTO agent.social_post
                 (author_address, author_label, author_ens, author_kind, source, content, is_malicious, family, flavor)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?) RETURNING id",
            [
                strtolower((string) $p['author_address']), (string) $p['author_label'],
                $p['author_ens'] ?? null, (string) ($p['author_kind'] ?? 'trader'),
                (string) ($p['source'] ?? 'web'), (string) $p['content'],
                !empty($p['is_malicious']) ? 't' : 'f', $p['family'] ?? null, $p['flavor'] ?? null,
            ]
        );
        return $row !== null ? (int) $row->id : 0;
    }

    /** @return array<string, mixed> */
    private static function map(stdClass $r): array
    {
        return [
            'id'             => (int) $r->id,
            'author_address' => (string) $r->author_address,
            'author_label'   => (string) $r->author_label,
            'author_ens'     => $r->author_ens !== null ? (string) $r->author_ens : null,
            'author_kind'    => (string) $r->author_kind,
            'source'         => (string) $r->source,
            'content'        => (string) $r->content,
            'is_malicious'   => (bool) $r->is_malicious,
            'family'         => $r->family !== null ? (string) $r->family : null,
            'flavor'         => $r->flavor !== null ? (string) $r->flavor : null,
            'posted_at'      => (string) $r->posted_at,
        ];
    }
}
