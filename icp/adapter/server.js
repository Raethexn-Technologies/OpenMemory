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
const idlFactory = ({ IDL }) => {
  const StoreRequest = IDL.Record({
    user_id:    IDL.Text,
    session_id: IDL.Text,
    content:    IDL.Text,
    metadata:   IDL.Opt(IDL.Text),
  });

  const MemoryResponse = IDL.Record({
    id:         IDL.Text,
    user_id:    IDL.Text,
    session_id: IDL.Text,
    content:    IDL.Text,
    timestamp:  IDL.Int,
    metadata:   IDL.Opt(IDL.Text),
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
app.post('/store', async (req, res) => {
  const { user_id, session_id, content, metadata } = req.body;

  if (MOCK_MODE) {
    const id = `${user_id}:${Date.now()}`;
    mockStore.push({ id, user_id, session_id, content, timestamp: Date.now(), metadata: metadata || null });
    return res.json({ id });
  }

  try {
    const actor = await getActor();
    const id = await actor.store_memory({ user_id, session_id, content, metadata: metadata ? [metadata] : [] });
    res.json({ id });
  } catch (err) {
    console.error('store_memory error:', err);
    res.status(500).json({ error: err.message });
  }
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
  return {
    id:         r.id,
    user_id:    r.user_id,
    session_id: r.session_id,
    content:    r.content,
    timestamp:  Number(r.timestamp),
    metadata:   r.metadata?.[0] ?? null,
  };
}

app.listen(PORT, () => {
  console.log(`OMA ICP Adapter :${PORT} [mock=${MOCK_MODE}] [dfx=${DFX_HOST}]`);
});
