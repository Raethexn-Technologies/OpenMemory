<?php

namespace App\Http\Controllers;

use App\Models\ContextApplication;
use App\Services\Context\ContextApplications;
use App\Services\Context\ContextInput;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class ContextApplicationController extends Controller
{
    public function __construct(private readonly ContextApplications $applications) {}

    public function page()
    {
        return Inertia::render('Applications/Index');
    }

    public function index(Request $request)
    {
        return response()->json(['applications' => ContextApplication::where('owner_id', $request->user('web')->id)
            ->orderBy('id')->get()->map->summary()]);
    }

    public function store(Request $request)
    {
        return response()->json($this->applications->create($request->user('web'), ContextInput::request($request)), 201);
    }

    public function update(Request $request, string $applicationId)
    {
        return response()->json(['application' => $this->applications->change(
            $request->user('web'), $applicationId, ContextInput::request($request),
        )]);
    }

    public function destroy(Request $request, string $applicationId)
    {
        ContextInput::validate(ContextInput::request($request), []);
        $this->applications->revoke($request->user('web'), $applicationId);

        return response()->noContent();
    }

    public function events(Request $request)
    {
        $rows = DB::table('context_access_events')->where('owner_id', $request->user('web')->id)
            ->orderByDesc('created_at')->orderBy('id')->limit(100)->get();
        $events = $rows->map(fn ($row) => [
            'request_id' => $row->id, 'application_id' => $row->application_id,
            'operation' => $row->operation, 'outcome' => $row->outcome,
            'sources' => json_decode($row->sources, true),
            'fragment_count' => $row->fragment_count, 'duration_ms' => $row->duration_ms,
            'created_at' => $row->created_at,
        ]);

        return response()->json(['events' => $events, 'coverage' => 'latest_100_retained_events']);
    }
}
