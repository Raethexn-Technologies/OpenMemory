<?php

namespace App\Http\Controllers;

use App\Models\SourceConnection;
use App\Models\SourceResource;
use App\Services\Context\ContextInput;
use App\Services\Context\ContextSources;
use App\Services\GitHub\GitHubConnections;
use App\Services\GitHub\GitHubFailure;
use Illuminate\Http\Request;
use Inertia\Inertia;

class GitHubConnectionController extends Controller
{
    public function __construct(private readonly GitHubConnections $connections) {}

    public function page()
    {
        return Inertia::render('Sources/GitHub');
    }

    public function index(Request $request)
    {
        $connection = SourceConnection::where('owner_id', $request->user('web')->id)->where('provider', 'github')->first();

        return response()->json([
            'connection' => $connection?->summary(),
            'resources' => $connection ? SourceResource::where('connection_id', $connection->id)->orderBy('reference')
                ->get(['id', 'external_id', 'reference', 'selected'])->toArray() : [],
            'capabilities' => ContextSources::DEFINITIONS['github']['capabilities'],
        ]);
    }

    public function store(Request $request)
    {
        return $this->safe(fn () => response()->json(['connection' => $this->connections->connect(
            $request->user('web'), ContextInput::request($request),
        )->summary()], 201));
    }

    public function repositories(Request $request, string $connectionId)
    {
        return $this->safe(fn () => response()->json($this->connections->discover(
            $request->user('web'), $connectionId, ContextInput::request($request),
        )));
    }

    public function update(Request $request, string $connectionId)
    {
        return $this->safe(function () use ($request, $connectionId) {
            $this->connections->select($request->user('web'), $connectionId, ContextInput::request($request));

            return response()->noContent();
        });
    }

    public function destroy(Request $request, string $connectionId)
    {
        ContextInput::validate(ContextInput::request($request), []);
        $this->connections->disconnect($request->user('web'), $connectionId);

        return response()->noContent();
    }

    private function safe(\Closure $action)
    {
        try {
            return $action();
        } catch (GitHubFailure $failure) {
            return response()->json(['status' => $failure->outcome, 'retry_at' => $failure->retryAt], 422);
        }
    }
}
