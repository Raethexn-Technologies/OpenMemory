<?php

namespace App\Exceptions;

use Illuminate\Foundation\Exceptions\Handler;
use Illuminate\Support\Facades\Log;
use Throwable;

class SafeExceptionHandler extends Handler
{
    protected $dontFlash = [
        'current_password', 'password', 'password_confirmation', 'token',
        'text', 'content', 'message', 'question', 'file', 'context', 'title',
        'native_memories', 'external_references', 'metadata', 'memory_metadata', 'query', 'principal', 'access_token', 'refresh_token', 'client_secret',
    ];

    public function report(Throwable $e)
    {
        if ($this->shouldReport($e)) {
            Log::error('application_failure', ['error_category' => 'unhandled_exception']);
        }
    }

    public function render($request, Throwable $e)
    {
        // Never render framework debug pages containing SQL bindings, request
        // bodies, credentials, source paths, or previous provider exceptions.
        $debug = config('app.debug');
        config(['app.debug' => false]);
        try {
            if ($e instanceof \Illuminate\Validation\ValidationException
                || $e instanceof \Illuminate\Auth\AuthenticationException) {
                return parent::render($request, $e);
            }
            $e = $this->prepareException($e);
            $status = $e instanceof \Illuminate\Auth\Access\AuthorizationException
                ? 403
                : ($e instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface
                    ? $e->getStatusCode() : 500);

            return response()->json(['error' => $status < 500 ? 'Request denied.' : 'Operation failed.'], $status);
        } finally {
            config(['app.debug' => $debug]);
        }
    }

    public function renderForConsole($output, Throwable $e)
    {
        $output->writeln('<error>Operation failed. See sanitized application diagnostics.</error>');
    }
}
