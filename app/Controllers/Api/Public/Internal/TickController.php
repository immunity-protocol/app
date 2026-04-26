<?php

declare(strict_types=1);

namespace App\Controllers\Api\Public\Internal;

use App\Models\Core\Db;
use App\Models\Mock\Ticker;
use Zephyrus\Core\App;
use Zephyrus\Http\Response;
use Zephyrus\Routing\Attribute\Post;

final class TickController extends Controller
{
    #[Post('/internal/tick')]
    public function tick(): Response
    {
        $config = App::getConfiguration()?->database;
        if ($config === null) {
            return Response::json(['error' => 'no database configured'], 500);
        }
        $db = Db::fromConfig($config);
        $tally = (new Ticker($db))->run();
        return Response::json($tally);
    }
}
