<?php

declare(strict_types=1);

namespace App\Controllers\Web;

use App\Models\Demo\PlaygroundSession;
use Zephyrus\Http\Request;
use Zephyrus\Http\Response;
use Zephyrus\Routing\Attribute\Get;
use Zephyrus\Routing\Attribute\Post;

/**
 * The /playground page is the interactive demo surface. Single access tier:
 *   - judge: PLAYGROUND_PASSWORD unlocks the page + all endpoints (publish,
 *     inject, check-address, fleet pause/resume).
 *
 * Auth is session-cookie based. The page itself self-handles the gate so it
 * can render a login form on miss instead of returning JSON 401 (which is
 * what the playground middleware does for the API endpoints). The old admin
 * tier (destructive ops, kill-node) was removed.
 */
final class PlaygroundController extends Controller
{
    #[Get('/playground')]
    public function index(): Response
    {
        if (!PlaygroundSession::hasJudge()) {
            return $this->render('playground/login', [
                'tier'  => PlaygroundSession::TIER_JUDGE,
                'error' => session('playground_login_error'),
            ]);
        }

        return $this->render('playground/index', [
            'tier' => PlaygroundSession::tier(),
        ]);
    }

    #[Post('/playground/login')]
    public function login(Request $request): Response
    {
        $expected = (string) ($_ENV['PLAYGROUND_PASSWORD'] ?? getenv('PLAYGROUND_PASSWORD') ?: '');
        $submitted = (string) $request->body()->get('password', '');

        if ($expected === '' || !hash_equals($expected, $submitted)) {
            session(['playground_login_error' => 'Wrong password.']);
            return Response::redirect('/playground');
        }

        session(['playground_login_error' => null]);
        PlaygroundSession::grant(PlaygroundSession::TIER_JUDGE);
        return Response::redirect('/playground');
    }

    #[Post('/playground/logout')]
    public function logout(): Response
    {
        PlaygroundSession::revoke();
        return Response::redirect('/playground');
    }
}
