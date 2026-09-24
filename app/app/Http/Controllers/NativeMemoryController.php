<?php

namespace App\Http\Controllers;

use App\Services\NativeMemory\MemoryInput;
use App\Services\NativeMemory\NativeMemoryService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use JsonException;

class NativeMemoryController extends Controller
{
    public function __construct(private readonly NativeMemoryService $memories) {}

    public function page()
    {
        return Inertia::render('NativeMemory/Index');
    }

    private function input(Request $request): array
    {
        // Read the original JSON so global string trimming cannot mutate an export.
        try {
            $shape = json_decode($request->getContent(), false, 32, JSON_THROW_ON_ERROR);
            if (! $shape instanceof \stdClass) {
                MemoryInput::invalid();
            }
            foreach (['native_memories', 'external_references'] as $field) {
                if (property_exists($shape, $field) && ! is_array($shape->{$field})) {
                    MemoryInput::invalid();
                }
            }
            foreach ($shape->native_memories ?? [] as $record) {
                if (! $record instanceof \stdClass) {
                    MemoryInput::invalid();
                }
            }
            $input = json_decode($request->getContent(), true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            MemoryInput::invalid();
        }
        if (! is_array($input) || array_is_list($input)) {
            MemoryInput::invalid();
        }

        return $input;
    }

    public function index(Request $request)
    {
        $filters = MemoryInput::validate($request->query(), [
            'state' => 'sometimes|in:active,archived,superseded,all',
            'after' => 'sometimes|nullable|uuid',
            'limit' => 'sometimes|integer|min:1|max:1000',
        ]);

        return response()->json($this->memories->listing($request->user('web'), $filters));
    }

    public function search(Request $request)
    {
        return response()->json($this->memories->listing($request->user('web'), $this->input($request)));
    }

    public function store(Request $request)
    {
        return response()->json(['data' => $this->memories->create($request->user('web'), $this->input($request))->portable()], 201);
    }

    public function show(Request $request, string $memoryId)
    {
        return response()->json(['data' => $this->memories->get($request->user('web'), $memoryId)->portable()]);
    }

    public function update(Request $request, string $memoryId)
    {
        return response()->json(['data' => $this->memories->update($request->user('web'), $memoryId, $this->input($request))->portable()]);
    }

    public function supersede(Request $request, string $memoryId)
    {
        return response()->json(['data' => $this->memories->supersede($request->user('web'), $memoryId, $this->input($request))->portable()], 201);
    }

    public function destroy(Request $request, string $memoryId)
    {
        $this->memories->delete($request->user('web'), $memoryId, $this->input($request));

        return response()->noContent();
    }

    public function export(Request $request)
    {
        return response()->json($this->memories->export($request->user('web'), $request->query()))
            ->header('Content-Disposition', 'attachment; filename="openmemory-export-v1.json"');
    }

    public function import(Request $request)
    {
        return response()->json($this->memories->import($request->user('web'), $this->input($request)));
    }
}
