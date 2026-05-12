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
        // Authenticate the MCP server before processing anything.
        $expectedKey = config('services.mcp.api_key', '');
        if (empty($expectedKey) || $request->header('X-OMA-API-Key') !== $expectedKey) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $validated = $request->validate([
            'content'     => ['required', 'string', 'min:1', 'max:2000'],
            'sensitivity' => ['required', 'string', 'in:public,private'],
            'user_id'     => ['required', 'string', 'max:255'],
            'context'     => ['nullable', 'string', 'max:500'],
        ]);

        $userId      = $validated['user_id'];
        $redaction   = $this->redactor->redact($validated['content'], $userId);
        $content     = $redaction->text;
        $sensitivity = $this->redactor->enforceSensitivity($validated['sensitivity'], $redaction);

        // The MCP write surface intentionally accepts only public/private. If a
        // floor category is detected, store the redacted memory as private rather
        // than rejecting useful context or allowing it to remain public.
        if ($sensitivity === 'sensitive') {
            $sensitivity = 'private';
        }

        $metadata = $validated['context'] ?? null;
        if ($redaction->applied()) {
            $metadata = json_encode([
                'context' => $metadata,
                'redaction' => $redaction->toMetadata(),
            ]);
        }

        // Store in ICP mock cache. Session ID is synthesized from the user ID
        // and current timestamp since CLI tools have no browser session.
        $sessionId = 'mcp-' . $userId . '-' . now()->timestamp;
        $icpId = $this->icp->storeMemory($userId, $sessionId, $content, $metadata, $sensitivity);

        // Extract graph node metadata and wire into the memory graph.
        $extracted = $this->graphExtractor->extract($content, $sensitivity);
        if ($extracted !== null) {
            $this->graph->storeNode($userId, $content, $this->sanitizeExtractedMetadata($extracted, $userId), $sessionId);
        }

        return response()->json([
            'id'          => $icpId,
            'user_id'     => $userId,
            'sensitivity' => $sensitivity,
            'redaction'   => $redaction->applied() ? $redaction->toMetadata() : ['applied' => false],
        ], 201);
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
