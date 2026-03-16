<?php

namespace App\Http\Controllers;

use App\Services\GraphExtractionService;
use App\Services\IcpMemoryService;
use App\Services\MemoryGraphService;
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
        $content     = $validated['content'];
        $sensitivity = $validated['sensitivity'];

        // Store in ICP mock cache. Session ID is synthesized from the user ID
        // and current timestamp since CLI tools have no browser session.
        $sessionId = 'mcp-' . $userId . '-' . now()->timestamp;
        $icpId = $this->icp->storeMemory($userId, $sessionId, $content, $validated['context'] ?? null, $sensitivity);

        // Extract graph node metadata and wire into the memory graph.
        $extracted = $this->graphExtractor->extract($content, $sensitivity);
        if ($extracted !== null) {
            $this->graph->storeNode($userId, $content, $extracted, $sessionId);
        }

        return response()->json([
            'id'          => $icpId,
            'user_id'     => $userId,
            'sensitivity' => $sensitivity,
        ], 201);
    }
}
