<?php

declare(strict_types=1);

namespace App\Models\Gateway\Storage;

use Lighthouse\LighthouseService;
use RuntimeException;

/**
 * Minimal Lighthouse/IPFS write helper for the gateway. Ported from CodeQuill's
 * LighthouseStorage MINUS the local-Kubo pin (`pinLocalFromFile`) — v1 has no
 * IPFS sidecar on the low-spec machine. Reads are keyless from Lighthouse's
 * public gateway (the SDK reads from `lighthouseGateway`).
 *
 * Holds ONLY the Lighthouse API key (a fly secret). Never decrypts, never signs.
 */
final class LighthouseStore
{
    private LighthouseService $service;

    public function __construct(LighthouseService|string|null $service = null)
    {
        if ($service instanceof LighthouseService) {
            $this->service = $service;
            return;
        }
        $apiKey = $service ?? (string) ($_ENV['LIGHTHOUSE_API_KEY'] ?? getenv('LIGHTHOUSE_API_KEY') ?: '');
        if ($apiKey === '') {
            throw new RuntimeException('LIGHTHOUSE_API_KEY is not configured');
        }
        $this->service = new LighthouseService($apiKey);
    }

    /**
     * Pin a string of content to Lighthouse and return its CID. Writes to a
     * temp file (the package uploads from a path), uploads, then removes the
     * temp file in all cases.
     */
    public function store(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'gw-evidence-');
        if ($path === false) {
            throw new RuntimeException('failed to allocate temp file for upload');
        }
        try {
            if (file_put_contents($path, $content) === false) {
                throw new RuntimeException('failed to write temp file for upload');
            }
            return $this->service->uploadFile($path);
        } finally {
            @unlink($path);
        }
    }

    /** Public keyless read URL for a CID (round-trip verification / debugging). */
    public static function gatewayUrl(string $cid): string
    {
        return LighthouseService::getFileUrl($cid);
    }
}
