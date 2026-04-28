<?php

declare(strict_types=1);

namespace App\Models\Demo;

/**
 * Tier-based session helper for the /playground page.
 *
 * Two access tiers, both backed by a single session key holding the highest
 * granted tier:
 *
 *   - 'judge' : page + Section-1/2 endpoints. Password shared with hackathon
 *               judges and the demo booth.
 *   - 'admin' : adds Section 3 + the destructive endpoints (kill agents, manual
 *               command insertion, scenario triggers).
 *
 * Admin implies judge - `hasJudge()` returns true for admin sessions too.
 */
final class PlaygroundSession
{
    public const TIER_JUDGE = 'judge';
    public const TIER_ADMIN = 'admin';

    private const SESSION_KEY = 'playground_tier';

    public static function grant(string $tier): void
    {
        if ($tier !== self::TIER_JUDGE && $tier !== self::TIER_ADMIN) {
            throw new \InvalidArgumentException("unknown tier: $tier");
        }
        // Don't downgrade an existing admin session to judge.
        if ($tier === self::TIER_JUDGE && self::hasAdmin()) {
            return;
        }
        session([self::SESSION_KEY => $tier]);
    }

    public static function revoke(): void
    {
        session([self::SESSION_KEY => null]);
    }

    public static function tier(): ?string
    {
        $tier = session(self::SESSION_KEY);
        return is_string($tier) ? $tier : null;
    }

    public static function hasJudge(): bool
    {
        $tier = self::tier();
        return $tier === self::TIER_JUDGE || $tier === self::TIER_ADMIN;
    }

    public static function hasAdmin(): bool
    {
        return self::tier() === self::TIER_ADMIN;
    }
}
