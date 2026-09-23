<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetAuthenticatedOwner
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user('web');
        abort_unless($user, 401);
        $ownerKey = $user->corpusOwnerKey();
        $context = $user->id.':'.$ownerKey;

        if ($request->session()->get('authenticated_owner_context') !== $context) {
            // Legacy transcripts cannot establish authenticated ownership.
            $request->session()->forget(['chat_session_id', 'chat_user_id', 'identity_source']);
        }

        $request->session()->put([
            'authenticated_owner_context' => $context,
            'chat_user_id' => $ownerKey,
            'identity_source' => 'openmemory',
        ]);

        $response = $next($request);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
