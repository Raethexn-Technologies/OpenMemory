/**
 * OMA ICP Adapter
 *
 * Express bridge between the Laravel app and the ICP memory canister.
 * Laravel calls this adapter (port 3100) via HTTP JSON.
 * This adapter calls the ICP canister via @dfinity/agent (Candid).
 *
 * Environment variables:
 *   PORT             — adapter listen port (default: 3100)
 *   ICP_MOCK         — "false" to use real canister, anything else = mock mode
 *   ICP_CANISTER_ID  — deployed canister ID (required when ICP_MOCK=false)
 *   ICP_DFX_HOST     — dfx replica URL (default: http://localhost:4943)
 *                      In Docker, set this to the dfx host reachable from the adapter container.
 *                      Separate from ICP_CANISTER_ENDPOINT which is the Laravel→adapter URL.
 */

const express = require('express');
const { HttpAgent, Actor } = require('@dfinity/agent');
const { IDL } = require('@dfinity/candid');

const app = express();
app.use(express.json());

const PORT        = process.env.PORT || 3100;
const CANISTER_ID = process.env.ICP_CANISTER_ID || '';
const DFX_HOST    = process.env.ICP_DFX_HOST || 'http://localhost:4943';
const MOCK_MODE   = process.env.ICP_MOCK !== 'false';

// ─── In-memory mock store ──────────────────────────────────────────
const mockStore = [];

// ─── Candid IDL for the memory canister ───────────────────────────
//
// NOTE: store_memory is a shared (update) call — the canister uses msg.caller
// as the user_id. This adapter is used by Laravel for READS only in live mode.
// WRITES in live mode come from the browser (signed with the user's Ed25519 key).
// The adapter's /store endpoint is only meaningful in mock mode.
//
const idlFactory = ({ IDL }) => {
  const MemoryType = IDL.Variant({
    Public:    IDL.Null,
    Private:   IDL.Null,
    Sensitive: IDL.Null,
  });

  // user_id is absent — the canister derives it from msg.caller.
  const StoreRequest = IDL.Record({
    session_id:  IDL.Text,
    content:     IDL.Text,
    metadata:    IDL.Opt(IDL.Text),
    memory_type: IDL.Opt(MemoryType),
  });

  const MemoryResponse = IDL.Record({
    id:          IDL.Text,
    user_id:     IDL.Text,
    session_id:  IDL.Text,
    content:     IDL.Text,
    timestamp:   IDL.Int,
    metadata:    IDL.Opt(IDL.Text),
    memory_type: MemoryType,
  });

  return IDL.Service({
    store_memory:            IDL.Func([StoreRequest], [IDL.Text], []),
    get_memories:            IDL.Func([IDL.Text], [IDL.Vec(MemoryResponse)], ['query']),
    get_memories_by_session: IDL.Func([IDL.Text], [IDL.Vec(MemoryResponse)], ['query']),
    list_recent_memories:    IDL.Func([IDL.Nat], [IDL.Vec(MemoryResponse)], ['query']),
    health:                  IDL.Func([], [IDL.Record({ status: IDL.Text, count: IDL.Nat })], ['query']),
  });
};

// ─── ICP Actor factory ─────────────────────────────────────────────
async function getActor() {
  const agent = new HttpAgent({ host: DFX_HOST });
  if (DFX_HOST.includes('localhost')) {
    await agent.fetchRootKey().catch(console.warn);
  }
  return Actor.createActor(idlFactory, { agent, canisterId: CANISTER_ID });
}

// ─── Routes ────────────────────────────────────────────────────────

// POST /store
// Mock mode only: Laravel calls this to persist memories when no canister is available.
// In live mode, the browser writes directly to the canister (browser-signed via @dfinity/agent).
// The user_id field here is the browser-derived principal, not a server-generated ID.
app.post('/store', async (req, res) => {
  const { user_id, session_id, content, metadata, memory_type } = req.body;

  if (MOCK_MODE) {
    const id = `${user_id}:${Date.now()}`;
    mockStore.push({
      id, user_id, session_id, content,
      timestamp:   Date.now(),
      metadata:    metadata || null,
      memory_type: memory_type || 'public',
    });
    return res.json({ id });
  }

  // Live mode: this endpoint must NOT be called.
  // In live mode all writes come from the browser (browser-signed, msg.caller = user's principal).
  // Routing writes through this adapter in live mode would drop memory_type entirely,
  // causing the canister to default to #Public — silently publishing private/sensitive records.
  // Fail loudly so this is never silently triggered.
  console.error('[OMA adapter] POST /store called in live mode — rejecting. Writes must come from the browser.');
  return res.status(400).json({
    error: 'Live mode writes must originate from the browser (browser-signed via @dfinity/agent). '
      + 'Routing writes through this adapter drops memory_type and would publish all records as Public.',
  });
});

// GET /memories/:userId
app.get('/memories/:userId', async (req, res) => {
  const { userId } = req.params;

  if (MOCK_MODE) {
    return res.json({ memories: mockStore.filter(m => m.user_id === userId) });
  }

  try {
    const actor = await getActor();
    res.json({ memories: (await actor.get_memories(userId)).map(formatRecord) });
  } catch (err) {
    console.error('get_memories error:', err);
    res.status(500).json({ error: err.message });
  }
});

// GET /memories/session/:sessionId
app.get('/memories/session/:sessionId', async (req, res) => {
  const { sessionId } = req.params;

  if (MOCK_MODE) {
    return res.json({ memories: mockStore.filter(m => m.session_id === sessionId) });
  }

  try {
    const actor = await getActor();
    res.json({ memories: (await actor.get_memories_by_session(sessionId)).map(formatRecord) });
  } catch (err) {
    console.error('get_memories_by_session error:', err);
    res.status(500).json({ error: err.message });
  }
});

// GET /memories/recent?limit=20
app.get('/memories/recent', async (req, res) => {
  const limit = parseInt(req.query.limit || '20', 10);

  if (MOCK_MODE) {
    return res.json({ memories: mockStore.slice(-limit) });
  }

  try {
    const actor = await getActor();
    res.json({ memories: (await actor.list_recent_memories(BigInt(limit))).map(formatRecord) });
  } catch (err) {
    console.error('list_recent_memories error:', err);
    res.status(500).json({ error: err.message });
  }
});

// GET /health — returns adapter status + canister record count
app.get('/health', async (req, res) => {
  if (MOCK_MODE) {
    return res.json({ status: 'ok', mock: true, count: mockStore.length, canister_id: '' });
  }

  try {
    const actor = await getActor();
    const result = await actor.health();
    res.json({
      status:      result.status,
      mock:        false,
      count:       Number(result.count),
      canister_id: CANISTER_ID,
    });
  } catch (err) {
    res.status(503).json({ status: 'error', error: err.message, mock: false, canister_id: CANISTER_ID });
  }
});

// ─── Helpers ───────────────────────────────────────────────────────
function formatRecord(r) {
  // memory_type is a Candid variant: { Public: null } | { Private: null } | { Sensitive: null }
  const memType = r.memory_type ? Object.keys(r.memory_type)[0].toLowerCase() : 'public';
  return {
    id:          r.id,
    user_id:     r.user_id,
    session_id:  r.session_id,
    content:     r.content,
    timestamp:   Number(r.timestamp),
    metadata:    r.metadata?.[0] ?? null,
    memory_type: memType,
  };
}

app.listen(PORT, () => {
  console.log(`OMA ICP Adapter :${PORT} [mock=${MOCK_MODE}] [dfx=${DFX_HOST}]`);
});
