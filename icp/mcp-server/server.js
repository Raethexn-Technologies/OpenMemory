/**
 * OpenMemoryAgent — MCP Server
 *
 * Exposes the ICP memory canister as a Model Context Protocol (MCP) resource.
 * Any MCP-compatible agent (Claude Desktop, Claude Code, Claude Code CLI,
 * Gemini CLI, Codex CLI, etc.) can read and write memory records via this server.
 *
 * READ path: public HTTP endpoint — no auth required. Private/Sensitive records
 * are never returned here; the canister enforces that server-side.
 *
 * WRITE path: store_memory tool. Two modes:
 *   Mock mode  — POSTs to OMA_MOCK_URL/mcp/store (Laravel dev server).
 *                Requires OMA_MOCK_URL and OMA_API_KEY.
 *   Live mode  — Signs canister calls with the Ed25519 identity loaded from
 *                OMA_IDENTITY_FILE (default: ~/.config/openmemorymcp/identity.json).
 *                Run `node setup-identity.js` once to create the key file.
 *
 * Write scope (WRITE_SCOPE env var):
 *   "public"          — only public writes allowed (default)
 *   "public,private"  — public and private writes allowed
 *   "none"            — writes disabled (read-only mode)
 *   Sensitive writes are always blocked at this layer regardless of scope.
 *
 * Configuration (env vars):
 *   ICP_CANISTER_ID   — deployed canister ID (required for live mode reads)
 *   ICP_HOST          — canister HTTP host (default: https://ic0.app)
 *   USER_PRINCIPAL    — default principal for read tools (optional)
 *   OMA_MOCK_URL      — base URL of the Laravel app for mock-mode writes
 *   OMA_API_KEY       — shared secret sent as X-OMA-API-Key header
 *   WRITE_SCOPE       — comma-separated allowed sensitivity levels (default: public)
 *   OMA_IDENTITY_FILE — path to Ed25519 identity JSON (default: ~/.config/openmemorymcp/identity.json)
 *   OMA_USER_ID       — user_id to tag writes with (required for mock-mode writes)
 *
 * Claude Code / Gemini / Codex config example (~/.claude/claude_desktop_config.json):
 *   {
 *     "mcpServers": {
 *       "openMemory": {
 *         "command": "node",
 *         "args": ["/absolute/path/to/icp/mcp-server/server.js"],
 *         "env": {
 *           "ICP_CANISTER_ID": "<your-canister-id>",
 *           "OMA_MOCK_URL": "http://localhost:8080",
 *           "OMA_API_KEY": "<your-mcp-api-key>",
 *           "OMA_USER_ID": "<your-user-id>",
 *           "WRITE_SCOPE": "public,private"
 *         }
 *       }
 *     }
 *   }
 */

import { McpServer, ResourceTemplate } from '@modelcontextprotocol/sdk/server/mcp.js';
import { StdioServerTransport } from '@modelcontextprotocol/sdk/server/stdio.js';
import { z } from 'zod';
import { loadIdentity } from './identity.js';

const CANISTER_ID    = process.env.ICP_CANISTER_ID || '';
const HOST           = (process.env.ICP_HOST || 'https://ic0.app').replace(/\/$/, '');
const USER_PRINCIPAL = process.env.USER_PRINCIPAL || '';
const IS_LOCAL       = HOST.includes('localhost') || HOST.includes('127.0.0.1');

// Write path configuration
const OMA_MOCK_URL   = (process.env.OMA_MOCK_URL || '').replace(/\/$/, '');
const OMA_API_KEY    = process.env.OMA_API_KEY || '';
const OMA_USER_ID    = process.env.OMA_USER_ID || '';
const WRITE_SCOPE    = (process.env.WRITE_SCOPE || 'public').toLowerCase().split(',').map(s => s.trim());
const WRITES_ENABLED = !WRITE_SCOPE.includes('none');
const ALLOW_PRIVATE  = WRITE_SCOPE.includes('private');

// Load identity once at startup for live-mode writes.
const identityResult = loadIdentity();

const server = new McpServer({
  name:    'OpenMemoryAgent',
  version: '0.1.0',
});

// ─── Helpers ────────────────────────────────────────────────────────

function canisterUrl(path) {
  if (IS_LOCAL) {
    // dfx serves HTTP via query param locally
    return `${HOST}${path}?canisterId=${CANISTER_ID}`;
  }
  return `https://${CANISTER_ID}.ic0.app${path}`;
}

async function fetchMemories(principal) {
  if (!CANISTER_ID) {
    throw new Error('ICP_CANISTER_ID is not set. Configure the MCP server with your canister ID.');
  }
  if (!principal) {
    throw new Error('No principal specified. Pass a principal in the resource URI or set USER_PRINCIPAL.');
  }

  const url = canisterUrl(`/memory/${encodeURIComponent(principal)}`);
  const res  = await fetch(url);

  if (!res.ok) {
    throw new Error(`Canister returned ${res.status}: ${await res.text()}`);
  }

  return res.json();
}

function formatMemories(memories, principal) {
  if (!Array.isArray(memories) || memories.length === 0) {
    return `No public memories found for principal: ${principal}`;
  }

  const lines = memories.map((m) => {
    const date = m.timestamp > 1e12
      ? new Date(m.timestamp / 1e6).toLocaleDateString()
      : new Date(m.timestamp).toLocaleDateString();
    return `[${date}] ${m.content}`;
  });

  return [
    `Memory records for ${principal}`,
    `Source: ${canisterUrl(`/memory/${principal}`)}`,
    `Total: ${memories.length} public record(s)`,
    '',
    ...lines,
  ].join('\n');
}

// ─── Resource: user's public memory ──────────────────────────────

// URI pattern: memory://<principal>
// The principal is the ICP identity of the user whose public memories you want.
server.resource(
  'user-memory',
  new ResourceTemplate('memory://{principal}', { list: undefined }),
  async (uri, { principal }) => {
    const memories = await fetchMemories(principal);
    return {
      contents: [{
        uri:      uri.href,
        mimeType: 'text/plain',
        text:     formatMemories(memories, principal),
      }],
    };
  }
);

// ─── Tool: read memories for a principal ─────────────────────────
//
// Agents can call this tool to retrieve memory records for any principal.
// Only Public records are returned — Private/Sensitive are canister-enforced
// and never appear in the HTTP endpoint this server reads from.
//
server.tool(
  'get_memories',
  'Retrieve public memory records for an ICP principal from the OpenMemoryAgent canister.',
  {
    principal: z.string().optional().describe(
      'ICP principal to look up. Defaults to USER_PRINCIPAL env var if not specified.'
    ),
  },
  async ({ principal: reqPrincipal }) => {
    const principal = reqPrincipal || USER_PRINCIPAL;
    const memories  = await fetchMemories(principal);

    if (!Array.isArray(memories) || memories.length === 0) {
      return {
        content: [{ type: 'text', text: `No public memories found for ${principal}.` }],
      };
    }

    return {
      content: [{
        type: 'text',
        text: formatMemories(memories, principal),
      }],
    };
  }
);

// ─── Tool: canister health ────────────────────────────────────────

server.tool(
  'canister_health',
  'Check the health and record count of the OpenMemoryAgent ICP canister.',
  {},
  async () => {
    if (!CANISTER_ID) {
      return { content: [{ type: 'text', text: 'ICP_CANISTER_ID not configured.' }] };
    }
    const url = canisterUrl('/memory');
    const res  = await fetch(url);
    const data = await res.json();
    return {
      content: [{
        type: 'text',
        text: [
          `Canister: ${CANISTER_ID}`,
          `Status:   ${data.status ?? 'unknown'}`,
          `Public records:  ${data.public_count ?? '?'}`,
          `Total records:   ${data.total_count ?? '?'}`,
          `Endpoint: ${canisterUrl('/memory')}`,
        ].join('\n'),
      }],
    };
  }
);

// ─── Tool: store a memory ─────────────────────────────────────────
//
// CLI tools (Claude Code, Gemini, Codex) call this to persist a fact about
// the user. The tool dispatches to either the Laravel mock endpoint or the
// live ICP canister depending on whether OMA_MOCK_URL is set.
//
// Sensitive writes are always blocked at this layer. Private writes require
// WRITE_SCOPE to include "private".
//
server.tool(
  'store_memory',
  'Save a memory about the user to OpenMemoryAgent. Only call this when the user explicitly asks you to remember something, or when a fact is clearly important and durable enough to be worth storing.',
  {
    content: z.string().min(1).max(2000).describe(
      'The memory to store. Write as a clear, self-contained sentence or short paragraph. Third person is fine: "User prefers X." Do not include filler or conversational fragments.'
    ),
    sensitivity: z.enum(['public', 'private']).default('public').describe(
      'public — can be shared across sessions and tools. private — stored but only injected for the owning user. Sensitive memories are not accepted via this tool.'
    ),
    context: z.string().optional().describe(
      'Optional short note about why this memory is being stored (e.g. "User just mentioned this while debugging").'
    ),
  },
  async ({ content, sensitivity = 'public', context }) => {
    if (!WRITES_ENABLED) {
      return { content: [{ type: 'text', text: 'Write access is disabled (WRITE_SCOPE=none). No memory was stored.' }] };
    }

    if (sensitivity === 'private' && !ALLOW_PRIVATE) {
      return { content: [{ type: 'text', text: 'Private writes are not allowed with the current WRITE_SCOPE. Set WRITE_SCOPE=public,private to enable them.' }] };
    }

    // Mock mode: POST to Laravel /mcp/store endpoint.
    if (OMA_MOCK_URL) {
      if (!OMA_API_KEY) {
        return { content: [{ type: 'text', text: 'OMA_API_KEY is not set. Cannot authenticate with the Laravel mock endpoint.' }] };
      }
      if (!OMA_USER_ID) {
        return { content: [{ type: 'text', text: 'OMA_USER_ID is not set. Cannot store memory without a user identity.' }] };
      }

      let response;
      try {
        response = await fetch(`${OMA_MOCK_URL}/mcp/store`, {
          method:  'POST',
          headers: {
            'Content-Type':  'application/json',
            'X-OMA-API-Key': OMA_API_KEY,
          },
          body: JSON.stringify({
            content,
            sensitivity,
            user_id: OMA_USER_ID,
            context: context || null,
          }),
        });
      } catch (err) {
        return { content: [{ type: 'text', text: `Failed to reach OMA_MOCK_URL (${OMA_MOCK_URL}): ${err.message}` }] };
      }

      if (!response.ok) {
        const body = await response.text().catch(() => '');
        return { content: [{ type: 'text', text: `Store failed (HTTP ${response.status}): ${body}` }] };
      }

      const data = await response.json().catch(() => ({}));
      return {
        content: [{
          type: 'text',
          text: `Memory stored (mock mode). ID: ${data.id ?? 'unknown'} | Sensitivity: ${sensitivity}`,
        }],
      };
    }

    // Live mode: sign canister call with loaded identity.
    if (!identityResult) {
      return {
        content: [{
          type: 'text',
          text: 'No identity file found. Run `node setup-identity.js` in icp/mcp-server/ to create one, then restart the MCP server.',
        }],
      };
    }

    if (!CANISTER_ID) {
      return { content: [{ type: 'text', text: 'ICP_CANISTER_ID is not set. Cannot store memory in live mode.' }] };
    }

    // Dynamic import to keep @dfinity/agent out of the startup path when using mock mode.
    const { HttpAgent } = await import('@dfinity/agent');
    const { IDL } = await import('@dfinity/candid');

    const agent = await HttpAgent.create({
      identity: identityResult.identity,
      host:     HOST,
    });

    if (IS_LOCAL) {
      await agent.fetchRootKey();
    }

    // Candid Opt<T>: present = [value], absent = [].
    const sensitivityArg = sensitivity === 'private'
      ? [{ Private: null }]
      : [{ Public: null }];

    try {
      // The canister's store method takes (content: Text, memory_type: Opt<MemoryType>).
      // Call via raw update call with Candid encoding.
      const result = await agent.call(CANISTER_ID, {
        methodName: 'store',
        arg: IDL.encode(
          [IDL.Text, IDL.Opt(IDL.Variant({ Public: IDL.Null, Private: IDL.Null }))],
          [content, sensitivityArg]
        ),
      });

      return {
        content: [{
          type: 'text',
          text: `Memory stored (live ICP mode). Principal: ${identityResult.principal} | Sensitivity: ${sensitivity}`,
        }],
      };
    } catch (err) {
      return { content: [{ type: 'text', text: `Canister call failed: ${err.message}` }] };
    }
  }
);

// ─── Start ───────────────────────────────────────────────────────

const transport = new StdioServerTransport();
await server.connect(transport);

if (CANISTER_ID) {
  console.error(`[OMA MCP] Canister: ${CANISTER_ID}`);
  console.error(`[OMA MCP] Host:     ${HOST}`);
  if (USER_PRINCIPAL) console.error(`[OMA MCP] Default principal: ${USER_PRINCIPAL}`);
} else {
  console.error('[OMA MCP] WARNING: ICP_CANISTER_ID not set — read tool calls will fail until configured.');
}

// Write path status
if (!WRITES_ENABLED) {
  console.error('[OMA MCP] Write path: disabled (WRITE_SCOPE=none)');
} else if (OMA_MOCK_URL) {
  console.error(`[OMA MCP] Write path: mock → ${OMA_MOCK_URL}/mcp/store`);
  console.error(`[OMA MCP] Write scope: ${WRITE_SCOPE.join(', ')}`);
  if (!OMA_API_KEY)  console.error('[OMA MCP] WARNING: OMA_API_KEY not set — store_memory will fail');
  if (!OMA_USER_ID)  console.error('[OMA MCP] WARNING: OMA_USER_ID not set — store_memory will fail');
} else if (identityResult) {
  console.error(`[OMA MCP] Write path: live ICP | Principal: ${identityResult.principal}`);
  console.error(`[OMA MCP] Write scope: ${WRITE_SCOPE.join(', ')}`);
} else {
  console.error('[OMA MCP] Write path: no identity file found. Run `node setup-identity.js` to enable writes.');
}
