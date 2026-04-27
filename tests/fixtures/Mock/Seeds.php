<?php

declare(strict_types=1);

namespace Tests\Fixtures\Mock;

/**
 * Reproducibility helpers for the mock generator.
 *
 * Every randomized call across the factories should go through `Seeds::int()`,
 * `Seeds::float()`, `Seeds::pick()`, etc., after a single `Seeds::reset()` at
 * the top of the orchestrator. Reseeding the same value produces the same
 * generated dataset, which keeps demos stable across rebuilds.
 */
final class Seeds
{
    public const DEFAULT_SEED = 0xCAFEBEE;

    public static function reset(int $seed = self::DEFAULT_SEED): void
    {
        mt_srand($seed);
    }

    public static function int(int $min, int $max): int
    {
        return mt_rand($min, $max);
    }

    public static function float(): float
    {
        return mt_rand() / mt_getrandmax();
    }

    /**
     * Return true with probability $p (0.0 - 1.0).
     */
    public static function chance(float $p): bool
    {
        return self::float() < $p;
    }

    /**
     * Pick one element from a non-empty list.
     *
     * @template T
     * @param T[] $items
     * @return T
     */
    public static function pick(array $items): mixed
    {
        return $items[self::int(0, count($items) - 1)];
    }

    /**
     * Pick one key from a weight map. Higher weight = higher chance.
     *
     * @param array<string, float|int> $weights
     */
    public static function weighted(array $weights): string
    {
        $sum = array_sum($weights);
        $r = self::float() * $sum;
        $acc = 0.0;
        foreach ($weights as $key => $weight) {
            $acc += $weight;
            if ($r < $acc) {
                return (string) $key;
            }
        }
        return (string) array_key_last($weights);
    }

    /**
     * Approximate beta-distributed value in [$min, $max] skewed toward $mode.
     * Uses two uniform draws averaged toward the mode for a quick concentration.
     */
    public static function skewed(float $min, float $max, float $mode): float
    {
        $bias = ($mode - $min) / max(0.001, $max - $min);
        $u = self::float();
        $v = self::float();
        $skew = $u * $bias + $v * (1 - $bias);
        return $min + $skew * ($max - $min);
    }

    /**
     * Approximate log-normal sample. Useful for value-at-risk distributions
     * where most values cluster low but a few are very large.
     */
    public static function logNormal(float $median, float $sigma): float
    {
        // Box-Muller for normal, then exponentiate.
        $u1 = max(1e-9, self::float());
        $u2 = self::float();
        $z = sqrt(-2 * log($u1)) * cos(2 * M_PI * $u2);
        return $median * exp($sigma * $z);
    }
}
