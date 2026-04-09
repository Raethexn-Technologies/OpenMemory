<?php

namespace App\Http\Controllers;

use App\Models\MemoryNode;
use App\Services\DocumentIngestionService;
use App\Services\IcpMemoryService;
use Illuminate\Http\Request;

class DocumentController extends Controller
{
    public function __construct(
        private readonly DocumentIngestionService $ingestion,
        private readonly IcpMemoryService $icp,
    ) {}

    /**
     * List all ingested document anchor nodes for the current user.
     *
     * Filters on source='document_anchor' rather than type='document' because
     * the LLM can classify chunk nodes as type='document' too. The source field
     * is the reliable anchor marker - it is set explicitly by DocumentIngestionService
     * and is never assigned by GraphExtractionService.
     */
    public function index(Request $request)
    {
        $userId = session()->get('chat_user_id');

        if (! $userId) {
            return response()->json(['error' => 'No user identity. Please refresh.'], 422);
        }

        $documents = MemoryNode::where('user_id', $userId)
            ->where('source', 'document_anchor')
            ->whereNull('consolidated_at')
            ->orderByDesc('created_at')
            ->get(['id', 'label', 'sensitivity', 'created_at'])
            ->map(fn ($n) => [
                'id'          => $n->id,
                'label'       => $n->label,
                'sensitivity' => $n->sensitivity,
                'created_at'  => $n->created_at?->toIso8601String(),
            ]);

        return response()->json(['documents' => $documents]);
    }

    /**
     * Ingest a document into the memory graph.
     *
     * Accepts either a plain-text/markdown file upload or a raw text paste.
     *
     * Sensitivity defaults to 'public'. This is intentional: private and sensitive
     * nodes are excluded from graph-guided retrieval in MemoryGraphService::findContextSeeds()
     * and retrieveContext(), so a private document would appear in the graph explorer
     * but the assistant would never use it as LLM context. If the user wants the
     * document to affect chat responses, it must be public. If they want graph-only
     * storage without LLM exposure, they can pass sensitivity=private explicitly.
     *
     * ICP write (mock mode only): the document anchor content is stored in the ICP
     * canister after successful ingestion, matching the mock-mode public write path
     * in ChatController. In live ICP mode, canister writes for document ingest require
     * browser-side signing, which is not yet wired - only graph storage runs. This
     * means external MCP clients operating in live mode will not see ingested documents
     * until that path is implemented.
     *
     * PDF is not supported at this time. Add smalot/pdfparser and extend the
     * mimes rule to 'txt,md,pdf' once the dependency is installed.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'title'       => 'required|string|max:200',
            'text'        => 'nullable|string|max:500000',
            'file'        => 'nullable|file|mimes:txt,md|max:10240', // 10 MB
            'sensitivity' => 'nullable|in:public,private,sensitive',
        ]);

        if (empty($validated['text']) && ! $request->hasFile('file')) {
            return response()->json(['error' => 'Provide either text or a file upload.'], 422);
        }

        $userId = session()->get('chat_user_id');

        if (! $userId) {
            return response()->json(['error' => 'No user identity. Please refresh.'], 422);
        }

        $text = $request->hasFile('file')
            ? $request->file('file')->get()
            : $validated['text'];

        $sensitivity = $validated['sensitivity'] ?? 'public';

        $result = $this->ingestion->ingest(
            userId:      $userId,
            title:       $validated['title'],
            text:        $text,
            sensitivity: $sensitivity,
        );

        // Mirror the mock-mode public write path from ChatController:
        // store the anchor node content in the ICP canister so MCP clients
        // see the ingested document alongside chat-derived memories.
        // Private and sensitive documents are not auto-written; the user would
        // need to explicitly approve those writes (not yet implemented for ingestion).
        if ($this->icp->isMockMode() && $sensitivity === 'public') {
            $sessionId = session()->get('chat_session_id', 'document_ingest');
            $this->icp->storeMemory(
                userId:     $userId,
                sessionId:  $sessionId,
                content:    "Document ingested: {$validated['title']}. " . mb_substr($text, 0, 200),
                metadata:   json_encode([
                    'source'           => 'document_ingest',
                    'document_node_id' => $result['document_node_id'],
                ]),
                memoryType: 'public',
            );
        }

        return response()->json($result, 201);
    }
}
