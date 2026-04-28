<?php

declare(strict_types=1);

namespace App\Controllers\Web;

use App\Models\Demo\PlaygroundSession;
use Zephyrus\Http\Request;
use Zephyrus\Http\Response;
use Zephyrus\Routing\Attribute\Get;
use Zephyrus\Routing\Attribute\Post;

/**
 * The /playground page is the interactive demo surface. Two access tiers:
 *   - judge: PLAYGROUND_PASSWORD unlocks the page + Section 1/2 endpoints.
 *   - admin: ADMIN_PASSWORD additionally unlocks Section 3 + destructive ops.
 *
 * Auth is session-cookie based. The page itself self-handles the gate so it
 * can render a login form on miss instead of returning JSON 401 (which is
 * what the playground/admin middlewares do for the API endpoints).
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

    #[Post('/playground/admin-login')]
    public function adminLogin(Request $request): Response
    {
        if (!PlaygroundSession::hasJudge()) {
            return Response::redirect('/playground');
        }

        $expected = (string) ($_ENV['ADMIN_PASSWORD'] ?? getenv('ADMIN_PASSWORD') ?: '');
        $submitted = (string) $request->body()->get('password', '');

        if ($expected === '' || !hash_equals($expected, $submitted)) {
            session(['playground_admin_error' => 'Wrong admin password.']);
            return Response::redirect('/playground');
        }

        session(['playground_admin_error' => null]);
        PlaygroundSession::grant(PlaygroundSession::TIER_ADMIN);
        return Response::redirect('/playground');
    }

    #[Post('/playground/logout')]
    public function logout(): Response
    {
        PlaygroundSession::revoke();
        return Response::redirect('/playground');
    }
}
