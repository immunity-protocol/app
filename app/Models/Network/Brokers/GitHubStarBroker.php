<?php

declare(strict_types=1);

namespace App\Models\Network\Brokers;

use App\Models\Core\Broker;
use stdClass;

class GitHubStarBroker extends Broker
{
    public function find(string $repo): ?stdClass
    {
        return $this->selectOne(
            "SELECT repo, stargazers_count, last_checked_at, last_success_at
               FROM network.github_repo_stat WHERE repo = ?",
            [$repo]
        );
    }

    /**
     * Upsert the cached row. last_checked_at is always set to now(); a
     * successful refresh also bumps stargazers_count + last_success_at.
     * On failure, pass null for both -- only the timestamp moves, throttling
     * the next refresh attempt.
     */
    public function upsert(string $repo, ?int $count, bool $success): void
    {
        if ($success && $count !== null) {
            $this->db->query(
                "INSERT INTO network.github_repo_stat (repo, stargazers_count, last_checked_at, last_success_at)
                 VALUES (?, ?, now(), now())
                 ON CONFLICT (repo) DO UPDATE
                    SET stargazers_count = EXCLUDED.stargazers_count,
                        last_checked_at  = EXCLUDED.last_checked_at,
                        last_success_at  = EXCLUDED.last_success_at",
                [$repo, $count]
            );
            return;
        }
        // Failure path: keep whatever count was there, just bump the throttle.
        $this->db->query(
            "INSERT INTO network.github_repo_stat (repo, stargazers_count, last_checked_at)
             VALUES (?, 0, now())
             ON CONFLICT (repo) DO UPDATE SET last_checked_at = now()",
            [$repo]
        );
    }
}
