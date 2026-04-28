<?php

declare(strict_types=1);

namespace App\Models\Network\Services;

use App\Models\Network\Brokers\GitHubStarBroker;

/**
 * Lazily-refreshed GitHub star count cache. The web tier reads this on every
 * page render; if the cached row is older than REFRESH_INTERVAL_SECONDS we
 * try one live API call (with a tight timeout) before returning. The result
 * is upserted into network.github_repo_stat so the next request hits cache.
 *
 * Failure modes are silent: a flaky GitHub API just keeps the previous count
 * around and bumps last_checked_at to throttle the next attempt.
 */
final class GitHubStarService
{
    private const REFRESH_INTERVAL_SECONDS = 900;   // 15 min
    private const HTTP_TIMEOUT_SECONDS     = 3;

    /**
     * Per-request memoization so multiple template fragments asking for the
     * same repo do not trigger multiple DB round-trips.
     *
     * @var array<string, int>
     */
    private static array $perRequestCache = [];

    private GitHubStarBroker $broker;

    public function __construct(?GitHubStarBroker $broker = null)
    {
        $this->broker = $broker ?? new GitHubStarBroker();
    }

    /** Convenience facade for Latte / view code: GitHubStarService::current('owner/repo'). */
    public static function current(string $repo): int
    {
        if (isset(self::$perRequestCache[$repo])) {
            return self::$perRequestCache[$repo];
        }
        return self::$perRequestCache[$repo] = (new self())->getCount($repo);
    }

    public function getCount(string $repo): int
    {
        $row = $this->broker->find($repo);
        $stale = $row === null
            || strtotime((string) $row->last_checked_at) < (time() - self::REFRESH_INTERVAL_SECONDS);

        if ($stale) {
            $fresh = $this->fetchFromGitHub($repo);
            if ($fresh !== null) {
                $this->broker->upsert($repo, $fresh, true);
                return $fresh;
            }
            $this->broker->upsert($repo, null, false);
        }

        return $row !== null ? (int) $row->stargazers_count : 0;
    }

    private function fetchFromGitHub(string $repo): ?int
    {
        $url = 'https://api.github.com/repos/' . $repo;
        $ctx = stream_context_create([
            'http' => [
                'method'        => 'GET',
                'timeout'       => self::HTTP_TIMEOUT_SECONDS,
                'ignore_errors' => true,
                'header'        => "Accept: application/vnd.github+json\r\n"
                                 . "User-Agent: immunity-protocol-web\r\n",
            ],
        ]);
        $body = @file_get_contents($url, false, $ctx);
        if ($body === false) {
            return null;
        }
        $data = json_decode($body, true);
        if (!is_array($data) || !isset($data['stargazers_count'])) {
            return null;
        }
        $count = (int) $data['stargazers_count'];
        return $count >= 0 ? $count : null;
    }
}
