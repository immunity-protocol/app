<?php

declare(strict_types=1);

namespace Tests\Unit\Models\Mirror;

use App\Models\Mirror\MirrorEnvelopeBuffer;
use Tests\TestCase;

final class MirrorEnvelopeBufferTest extends TestCase
{
    public function testStashAndTakeRoundTrips(): void
    {
        $buf = new MirrorEnvelopeBuffer();
        $buf->stash('0xAA', ['publisher' => '0x1234']);

        self::assertSame(1, $buf->size());
        $env = $buf->take('0xaa');
        self::assertIsArray($env);
        self::assertSame('0x1234', $env['publisher']);
        self::assertSame(0, $buf->size(), 'take() must remove the entry');
    }

    public function testTakeNormalizesCaseAndPrefix(): void
    {
        $buf = new MirrorEnvelopeBuffer();
        $buf->stash('0xABCDEF', ['v' => 1]);

        self::assertNotNull($buf->take('abcdef'));
    }

    public function testTakeMissingReturnsNull(): void
    {
        $buf = new MirrorEnvelopeBuffer();
        self::assertNull($buf->take('0xdead'));
    }

    public function testStashOverwritesExistingKey(): void
    {
        $buf = new MirrorEnvelopeBuffer();
        $buf->stash('0xff', ['v' => 1]);
        $buf->stash('0xff', ['v' => 2]);
        self::assertSame(1, $buf->size());

        $env = $buf->take('0xff');
        self::assertSame(2, $env['v']);
    }
}
