<?php

namespace App\Http\Controllers;

use App\Services\Context\ContextCaller;
use App\Services\Context\ContextInput;
use App\Services\Context\ContextRequest;
use App\Services\Context\ContextResolver;
use Illuminate\Http\Request;

class ContextController extends Controller
{
    public function __construct(private readonly ContextResolver $resolver) {}

    public function owner(Request $request)
    {
        return $this->resolve($request, new ContextCaller($request->user('web')));
    }

    public function application(Request $request)
    {
        $caller = $request->attributes->get('context_caller');
        abort_unless($caller instanceof ContextCaller && $caller->applicationId !== null, 401);

        return $this->resolve($request, $caller);
    }

    private function resolve(Request $request, ContextCaller $caller)
    {
        $input = ContextRequest::fromArray(ContextInput::request($request));

        $bundle = $this->resolver->resolve($caller, $input)->toArray();

        return response()->json($bundle)->withHeaders(app(\App\Services\Context\ContextMetrics::class)->headers());
    }

    public function permissions(Request $request)
    {
        $caller = $request->attributes->get('context_caller');
        abort_unless($caller instanceof ContextCaller && $caller->applicationId !== null, 401);
        $policy = app(\App\Services\Context\ContextPolicy::class);
        $application = $policy->application($caller);
        abort_unless($application && $policy->allows($caller, 'context.resolve'), 403);
        $sources = [];
        foreach (\App\Services\Context\ContextSources::DEFINITIONS as $source => $definition) {
            $sources[$source] = ['retrieval' => $policy->retrieve($caller, $source), 'disclosure' => $policy->disclose($caller, $source)];
        }
        app(\App\Services\Context\ContextAudit::class)->record($caller, (string) \Illuminate\Support\Str::uuid(), 'context.permissions', 'allowed');
        $application = $policy->application($caller);
        abort_unless($application, 403);

        return response()->json(['version' => 'context-permissions-v1', 'grant_revision' => $application->grant_revision,
            'expires_at' => $application->expires_at->toIso8601String(), 'sources' => $sources,
            'model_disclosure' => $application->model_disclosure]);
    }
}
