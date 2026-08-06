<?php

namespace App\Http\Controllers;

use App\Services\GraphExtractionService;
use App\Services\IcpMemoryService;
use App\Services\MemoryGraphService;
use App\Services\RedactionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Receives write requests from the MCP server (icp/mcp-server/server.js).
 *
 * This endpoint is only used in mock mode — when OMA_MOCK_URL points to this
 * Laravel app and the MCP server routes writes here instead of directly to the
 * ICP canister. In live ICP mode the MCP server signs canister calls directly
 * using the Ed25519 identity and this controller is not involved.
 *
 * Authentication is via X-OMA-API-Key header checked against MCP_API_KEY in .env.
 * CSRF is exempted in bootstrap/app.php — Node.js sends no CSRF token.
 *
 * On success:
 *   1. Stores the memory in ICP mock cache via IcpMemoryService.
 *   2. Extracts graph metadata via GraphExtractionService.
 *   3. Creates a MemoryNode and wires edges via MemoryGraphService.
 *
 * The MCP server pre-validates content — no MemorabilityService or
 * MemorySummarizationService is needed here. The content is already the
 * summarized fact the AI tool chose to persist.
 */
class McpController extends Controller
{
    public function __construct(
        private IcpMemoryService $icp,
        private GraphExtractionService $graphExtractor,
        private MemoryGraphService $graph,
        private RedactionService $redactor,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $prepared = $this->prepareRequest($request);
        if ($prepared instanceof JsonResponse) {
            return $prepared;
        }

        // Store in ICP mock cache. Session ID is synthesized from the user ID
        // and current timestamp since CLI tools have no browser session.
        $sessionId = 'mcp-' . $prepared['user_id'] . '-' . now()->timestamp;
        $icpId = $this->icp->storeMemory($prepared['user_id'], $sessionId, $prepared['content'], $prepared['metadata'], $prepared['sensitivity']);

        $this->syncPreparedRecord($prepared, $sessionId);

        return response()->json([
            'id'          => $icpId,
            'user_id'     => $prepared['user_id'],
            'sensitivity' => $prepared['sensitivity'],
            'redaction'   => $prepared['redaction'],
        ], 201);
    }

    /**
     * Redact and classify a memory before the MCP client signs a live canister write.
     */
    public function prepare(Request $request): JsonResponse
    {
        $prepared = $this->prepareRequest($request);

        return $prepared instanceof JsonResponse ? $prepared : response()->json($prepared);
    }

    /**
     * Index a record after the MCP client confirms a live canister write.
     */
    public function sync(Request $request): JsonResponse
    {
        $prepared = $this->prepareRequest($request);
        if ($prepared instanceof JsonResponse) {
            return $prepared;
        }

        $validated = $request->validate([
            'session_id' => ['required', 'string', 'max:255'],
            'canister_id' => ['nullable', 'string', 'max:255'],
        ]);

        $this->syncPreparedRecord($prepared, $validated['session_id']);

        return response()->json([
            'user_id' => $prepared['user_id'],
            'sensitivity' => $prepared['sensitivity'],
            'redaction' => $prepared['redaction'],
        ], 201);
    }

    /**
     * Return only public, query-matching graph records. There is intentionally
     * no recency fallback because unrelated memories are not useful MCP context.
     */
    public function search(Request $request): JsonResponse
    {
        if (! $this->isAuthorized($request)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $validated = $request->validate([
            'user_id' => ['required', 'string', 'max:255'],
            'query' => ['required', 'string', 'min:1', 'max:500'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:20'],
        ]);

        $safeQuery = $this->redactor->redact($validated['query'], $validated['user_id'])->text;

        return response()->json([
            'records' => $this->graph->searchPublic($validated['user_id'], $safeQuery, $validated['limit'] ?? 8),
            'query_redacted' => $safeQuery !== $validated['query'],
        ]);
    }

    /**
     * @return array{user_id: string, content: string, sensitivity: string, metadata: string|null, redaction: array<string, mixed>}|JsonResponse
     */
    private function prepareRequest(Request $request): array|JsonResponse
    {
        if (! $this->isAuthorized($request)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $validated = $request->validate([
            'content' => ['required', 'string', 'min:1', 'max:2000'],
            'sensitivity' => ['required', 'string', 'in:public,private'],
            'user_id' => ['required', 'string', 'max:255'],
            'context' => ['nullable', 'string', 'max:500'],
        ]);

        $redaction = $this->redactor->redact($validated['content'], $validated['user_id']);
        $sensitivity = $this->redactor->enforceSensitivity($validated['sensitivity'], $redaction);
        if ($sensitivity === 'sensitive') {
            $sensitivity = 'private';
        }

        $metadata = $validated['context'] ?? null;
        if ($redaction->applied()) {
            $metadata = json_encode([
                'context' => $metadata,
                'redaction' => $redaction->toMetadata(),
            ], JSON_THROW_ON_ERROR);
        }

        return [
            'user_id' => $validated['user_id'],
            'content' => $redaction->text,
            'sensitivity' => $sensitivity,
            'metadata' => $metadata,
            'redaction' => $redaction->applied() ? $redaction->toMetadata() : ['applied' => false],
        ];
    }

    private function isAuthorized(Request $request): bool
    {
        $expectedKey = config('services.mcp.api_key', '');

        return ! empty($expectedKey) && hash_equals($expectedKey, (string) $request->header('X-OMA-API-Key'));
    }

    /**
     * @param  array{user_id: string, content: string, sensitivity: string, metadata: string|null, redaction: array<string, mixed>}  $prepared
     */
    private function syncPreparedRecord(array $prepared, string $sessionId): void
    {
        $extracted = $this->graphExtractor->extract($prepared['content'], $prepared['sensitivity']);
        if ($extracted !== null) {
            $this->graph->storeNode(
                $prepared['user_id'],
                $prepared['content'],
                $this->sanitizeExtractedMetadata($extracted, $prepared['user_id']),
                $sessionId,
                'mcp',
                $prepared['metadata'] ? ['mcp' => json_decode($prepared['metadata'], true)] : [],
            );
        }
    }

    /**
     * @param  array<string, mixed>  $extracted
     * @return array<string, mixed>
     */
    private function sanitizeExtractedMetadata(array $extracted, string $userId): array
    {
        if (isset($extracted['label']) && is_string($extracted['label'])) {
            $extracted['label'] = $this->redactor->redact($extracted['label'], $userId)->text;
        }

        foreach (['tags', 'people', 'projects'] as $field) {
            $values = $extracted[$field] ?? [];
            if (! is_array($values)) {
                $extracted[$field] = [];
                continue;
            }

            $redactedValues = array_map(
                fn ($value) => is_string($value) ? trim($this->redactor->redact($value, $userId)->text) : '',
                $values,
            );

            $extracted[$field] = array_values(array_unique(array_filter(
                $redactedValues,
                static fn (string $value) => $value !== '' && ! str_contains($value, '['),
            )));
        }

        return $extracted;
    }
}
