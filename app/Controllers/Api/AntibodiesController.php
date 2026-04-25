<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Models\Antibody\Services\EntryService;
use Zephyrus\Http\Request;
use Zephyrus\Http\Response;
use Zephyrus\Routing\Attribute\Get;

final class AntibodiesController extends Controller
{
    private EntryService $entries;

    #[Get('/api/antibodies')]
    public function index(Request $request): Response
    {
        try {
        $type = $this->normalize($request->query('type'));
        $status = $this->normalize($request->query('status'));
        $search = $this->normalize($request->query('q'));
        $limit = max(1, min(100, (int) ($request->query('limit') ?? 30)));
        $beforeId = $request->query('before_id');
        $beforeId = $beforeId === null ? null : (int) $beforeId;

        $this->entries ??= new EntryService();
        $items = $this->entries->findFiltered($type, $status, $search, $limit, $beforeId);
        $total = $this->entries->countFiltered($type, $status, $search);

        return Response::json([
            'count'    => count($items),
            'total'    => $total,
            'next_cursor' => $items === [] ? null : end($items)->id,
            'items'    => $items,
        ])->withHeader('Cache-Control', 'public, max-age=10, stale-while-revalidate=20');
        } catch (\Throwable $e) {
            return Response::json([
                'error' => $e->getMessage(),
                'file'  => $e->getFile() . ':' . $e->getLine(),
            ], 500);
        }
    }

    private function normalize(mixed $v): ?string
    {
        if (!is_string($v)) {
            return null;
        }
        $v = trim($v);
        return $v === '' ? null : $v;
    }
}
