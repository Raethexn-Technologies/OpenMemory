<?php

namespace App\Http\Controllers;

use App\Services\IcpMemoryService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class MemoryController extends Controller
{
    public function __construct(
        private readonly IcpMemoryService $icp,
    ) {}

    /**
     * Memory inspector dashboard.
     */
    public function index(): Response
    {
        $recent = $this->icp->listRecentMemories(50);

        return Inertia::render('Memory/Index', [
            'memories'    => $recent,
            'icp_mode'    => $this->icp->mode(),
            'canister_id' => $this->icp->canisterId(),
        ]);
    }

    /**
     * Refresh memories for the inspector — returns all recent records.
     */
    public function refresh(Request $request)
    {
        $memories = $this->icp->listRecentMemories(50);

        return response()->json([
            'memories'    => $memories,
            'icp_mode'    => $this->icp->mode(),
            'canister_id' => $this->icp->canisterId(),
        ]);
    }
}
