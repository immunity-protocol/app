<?php

declare(strict_types=1);

namespace App\Models\Gateway\Storage;

/**
 * A content-addressed store for evidence artifacts: pin a string of content and
 * return its CID. Implemented by LighthouseStore; stubbed in tests.
 */
interface EvidenceStore
{
    public function store(string $content): string;
}
