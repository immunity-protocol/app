<?php

declare(strict_types=1);

namespace App\Controllers\Web\Antibody;

use Zephyrus\Http\Request;

/**
 * Parsed query-string state for the /antibodies explorer page.
 *
 * Immutable. Built by `fromRequest()` (clamps numerics, validates enum values
 * against the allow-lists) so the controller / template can trust the values.
 */
final readonly class AntibodyFilters
{
    public const ALLOWED_TYPES   = ['address', 'call_pattern', 'bytecode', 'graph', 'semantic'];
    public const ALLOWED_STATUS  = ['active', 'challenged', 'expired', 'slashed'];
    public const ALLOWED_VERDICT = ['malicious', 'suspicious'];
    public const ALLOWED_RANGES  = ['24h', '7d', '30d', '90d', 'all'];
    public const ALLOWED_PER_PAGE = [30, 60, 100, 200];

    /**
     * @param array<int, string> $types
     * @param array<int, string> $statuses
     * @param array<int, string> $verdicts
     */
    public function __construct(
        public array $types = [],
        public array $statuses = [],
        public array $verdicts = [],
        public ?string $search = null,
        public ?string $range = null,
        public ?int $sevMin = null,
        public ?int $sevMax = null,
        public ?string $publisher = null,
        public int $perPage = 30,
        public int $page = 1,
    ) {
    }

    public static function fromRequest(Request $request): self
    {
        return new self(
            types:    self::filterEnumList($request->query('type'), self::ALLOWED_TYPES),
            statuses: self::filterEnumList($request->query('status'), self::ALLOWED_STATUS),
            verdicts: self::filterEnumList($request->query('verdict'), self::ALLOWED_VERDICT),
            search:   self::sanitizeString($request->query('q')),
            range:    self::sanitizeRange($request->query('range')),
            sevMin:   self::sanitizeSeverity($request->query('sev_min')),
            sevMax:   self::sanitizeSeverity($request->query('sev_max')),
            publisher: self::sanitizeString($request->query('publisher')),
            perPage:  self::sanitizePerPage($request->query('per_page')),
            page:     max(1, (int) ($request->query('page') ?? 1)),
        );
    }

    /**
     * Build a query string with this filter set, optionally overriding fields.
     * Used by the template to generate page links and filter-toggle hrefs that
     * preserve the rest of the active filter state.
     *
     * @param array<string, mixed> $overrides
     */
    public function toQueryString(array $overrides = []): string
    {
        $data = [
            'type'      => $this->types,
            'status'    => $this->statuses,
            'verdict'   => $this->verdicts,
            'q'         => $this->search,
            'range'     => $this->range,
            'sev_min'   => $this->sevMin,
            'sev_max'   => $this->sevMax,
            'publisher' => $this->publisher,
            'per_page'  => $this->perPage === 30 ? null : $this->perPage,
            'page'      => $this->page === 1 ? null : $this->page,
        ];
        foreach ($overrides as $k => $v) {
            $data[$k] = $v;
        }
        // Drop nulls and empty arrays so the URL stays compact.
        $data = array_filter($data, fn ($v) => $v !== null && $v !== '' && $v !== []);
        return http_build_query($data);
    }

    public function hasAnyFilter(): bool
    {
        return $this->types !== []
            || $this->statuses !== []
            || $this->verdicts !== []
            || ($this->search !== null && $this->search !== '')
            || ($this->range !== null && $this->range !== 'all')
            || $this->sevMin !== null
            || $this->sevMax !== null
            || ($this->publisher !== null && $this->publisher !== '');
    }

    /**
     * @param array<int, string> $allowed
     * @return array<int, string>
     */
    private static function filterEnumList(mixed $raw, array $allowed): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $allowedSet = array_flip($allowed);
        $out = [];
        foreach ($raw as $v) {
            if (!is_string($v)) {
                continue;
            }
            $v = strtolower(trim($v));
            if (isset($allowedSet[$v]) && !in_array($v, $out, true)) {
                $out[] = $v;
            }
        }
        return $out;
    }

    private static function sanitizeString(mixed $raw): ?string
    {
        if (!is_string($raw)) {
            return null;
        }
        $raw = trim($raw);
        return $raw === '' ? null : $raw;
    }

    private static function sanitizeRange(mixed $raw): ?string
    {
        $v = self::sanitizeString($raw);
        if ($v === null) {
            return null;
        }
        return in_array($v, self::ALLOWED_RANGES, true) ? $v : null;
    }

    private static function sanitizeSeverity(mixed $raw): ?int
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        if (!is_numeric($raw)) {
            return null;
        }
        return max(0, min(100, (int) $raw));
    }

    private static function sanitizePerPage(mixed $raw): int
    {
        if (!is_numeric($raw)) {
            return 30;
        }
        $n = (int) $raw;
        return in_array($n, self::ALLOWED_PER_PAGE, true) ? $n : 30;
    }
}
