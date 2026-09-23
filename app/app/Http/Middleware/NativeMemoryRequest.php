<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class NativeMemoryRequest
{
    public function handle(Request $request, Closure $next): Response
    {
        // Do not let form redirects flash private import bodies into sessions.
        $request->headers->set('Accept', 'application/json');
        abort_if(strlen($request->getContent()) > 10 * 1024 * 1024, 413);
        if (! in_array($request->method(), ['GET', 'HEAD'], true)) {
            abort_unless($request->isJson(), 415);
        }

        $response = $next($request);
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }
}
