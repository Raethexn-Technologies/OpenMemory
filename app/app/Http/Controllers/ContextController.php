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

        return response()->json($this->resolver->resolve($caller, $input)->toArray());
    }
}
