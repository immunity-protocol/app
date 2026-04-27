<?php

declare(strict_types=1);

namespace Tests\Unit\Controllers\Web\Antibody;

use App\Controllers\Web\Antibody\AntibodyFilters;
use Tests\TestCase;
use Zephyrus\Http\Request;

final class AntibodyFiltersTest extends TestCase
{
    private function buildRequest(array $query): Request
    {
        return new Request('GET', 'http://localhost/antibodies', [], $query);
    }

    public function testEmptyRequestDefaults(): void
    {
        $f = AntibodyFilters::fromRequest($this->buildRequest([]));
        self::assertSame([], $f->types);
        self::assertSame([], $f->statuses);
        self::assertSame([], $f->verdicts);
        self::assertNull($f->search);
        self::assertNull($f->range);
        self::assertNull($f->sevMin);
        self::assertNull($f->sevMax);
        self::assertNull($f->publisher);
        self::assertSame(30, $f->perPage);
        self::assertSame(1, $f->page);
        self::assertFalse($f->hasAnyFilter());
    }

    public function testValidEnumLists(): void
    {
        $f = AntibodyFilters::fromRequest($this->buildRequest([
            'type'    => ['address', 'bytecode'],
            'status'  => ['active'],
            'verdict' => ['malicious'],
        ]));
        self::assertSame(['address', 'bytecode'], $f->types);
        self::assertSame(['active'], $f->statuses);
        self::assertSame(['malicious'], $f->verdicts);
        self::assertTrue($f->hasAnyFilter());
    }

    public function testRejectsUnknownEnumValues(): void
    {
        $f = AntibodyFilters::fromRequest($this->buildRequest([
            'type'    => ['address', 'WAT', 'BYTECODE'],   // BYTECODE is upper-cased -> ok via lower
            'status'  => ['gibberish'],
            'verdict' => ['malicious', 'evil'],
        ]));
        self::assertSame(['address', 'bytecode'], $f->types);
        self::assertSame([], $f->statuses);
        self::assertSame(['malicious'], $f->verdicts);
    }

    public function testIgnoresDuplicates(): void
    {
        $f = AntibodyFilters::fromRequest($this->buildRequest([
            'type' => ['address', 'address', 'bytecode'],
        ]));
        self::assertSame(['address', 'bytecode'], $f->types);
    }

    public function testSeverityClamps(): void
    {
        $f = AntibodyFilters::fromRequest($this->buildRequest([
            'sev_min' => '-10',
            'sev_max' => '500',
        ]));
        self::assertSame(0, $f->sevMin);
        self::assertSame(100, $f->sevMax);
    }

    public function testRangeAllowlist(): void
    {
        $f = AntibodyFilters::fromRequest($this->buildRequest(['range' => '7d']));
        self::assertSame('7d', $f->range);

        $f = AntibodyFilters::fromRequest($this->buildRequest(['range' => 'forever']));
        self::assertNull($f->range);
    }

    public function testPerPageAllowlist(): void
    {
        $f = AntibodyFilters::fromRequest($this->buildRequest(['per_page' => '60']));
        self::assertSame(60, $f->perPage);

        $f = AntibodyFilters::fromRequest($this->buildRequest(['per_page' => '7']));
        self::assertSame(30, $f->perPage);
    }

    public function testPageMinClamps(): void
    {
        $f = AntibodyFilters::fromRequest($this->buildRequest(['page' => '-1']));
        self::assertSame(1, $f->page);
    }

    public function testQueryStringRoundtripDropsDefaults(): void
    {
        $f = AntibodyFilters::fromRequest($this->buildRequest([
            'type' => ['address'],
            'q'    => 'foo',
            'page' => '3',
        ]));
        $qs = $f->toQueryString();
        self::assertStringContainsString('type%5B0%5D=address', $qs);
        self::assertStringContainsString('q=foo', $qs);
        self::assertStringContainsString('page=3', $qs);
        self::assertStringNotContainsString('per_page=', $qs);  // default dropped
    }

    public function testQueryStringOverrideSwapsField(): void
    {
        $f = AntibodyFilters::fromRequest($this->buildRequest([
            'type' => ['address'],
            'page' => '3',
        ]));
        $qs = $f->toQueryString(['page' => 5]);
        self::assertStringContainsString('page=5', $qs);
        self::assertStringNotContainsString('page=3', $qs);
    }

    public function testQueryStringOverrideNullClearsField(): void
    {
        $f = AntibodyFilters::fromRequest($this->buildRequest(['type' => ['address']]));
        $qs = $f->toQueryString(['type' => null]);
        self::assertStringNotContainsString('type', $qs);
    }
}
