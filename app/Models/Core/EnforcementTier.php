<?php

declare(strict_types=1);

namespace App\Models\Core;

/**
 * The one read-side derivation of an antibody's enforcement tier, matching the
 * SDK rule exactly: an antibody is hard-block-eligible iff it has reached K
 * corroborating publishers OR it is seeded (genesis). Otherwise it is advisory.
 *
 * Protected targets can never be hard-blocked — a flag against a protected
 * address is capped at advisory regardless of corroboration. Callers pass
 * `protected: true` when the matcher resolves to a protected-set member.
 *
 * Never key enforcement on `status == active` alone. Both the antibody list and
 * detail views derive their tier through this single helper.
 */
final class EnforcementTier
{
    public const string HARD_BLOCK = 'hard-block';
    public const string ADVISORY   = 'advisory';

    /**
     * @param int  $corroborationCount the antibody's corroboration_count
     * @param bool $isSeeded           the antibody's is_seeded flag
     * @param int  $k                  the deployed corroborationK threshold
     * @param bool $protected          true when the target is on the protected set (advisory cap)
     */
    public static function derive(int $corroborationCount, bool $isSeeded, int $k, bool $protected = false): string
    {
        if ($protected) {
            return self::ADVISORY;
        }
        return ($corroborationCount >= $k || $isSeeded) ? self::HARD_BLOCK : self::ADVISORY;
    }

    public static function isHardBlock(int $corroborationCount, bool $isSeeded, int $k, bool $protected = false): bool
    {
        return self::derive($corroborationCount, $isSeeded, $k, $protected) === self::HARD_BLOCK;
    }
}
