<?php

namespace App\Http\Middleware;

use App\Models\ContextApplication;
use App\Models\User;
use App\Services\Context\ContextCaller;
use Closure;
use Illuminate\Http\Request;

class AuthenticateContextApplication
{
    public function handle(Request $request, Closure $next)
    {
        $token = $request->bearerToken();
        abort_unless(is_string($token) && preg_match('/\Aomctx_[a-f0-9]{64}\z/', $token), 401);
        $app = ContextApplication::where('token_hash', hash('sha256', $token))->first();
        abort_unless($app, 401);
        $owner = User::findOrFail($app->owner_id);
        if ($app->revoked_at !== null || ! $app->expires_at->isFuture()) {
            app(\App\Services\Context\ContextAudit::class)->record(new ContextCaller($owner, $app->id),
                (string) \Illuminate\Support\Str::uuid(), 'context.authenticate', 'denied');
            abort(401);
        }
        $request->attributes->set('context_caller', new ContextCaller($owner, $app->id, $app->grant_revision));

        return $next($request);
    }
}
