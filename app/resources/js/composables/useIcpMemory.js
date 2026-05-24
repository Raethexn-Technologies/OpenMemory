/**
 * useIcpMemory
 *
 * Browser-side ICP actor for reading and writing memory records directly to the canister.
 *
 * The `identity` argument is any object implementing the @dfinity/agent Identity
 * interface — typically the DelegationIdentity returned by `AuthClient.getIdentity()`
 * after the user has signed in with Internet Identity. AnonymousIdentity is also
 * accepted but the canister will reject writes from anonymous principals.
 *
 * storeMemory  — signs a write with the supplied identity; msg.caller on the canister
 *                equals the user's principal. Called after the server returns a memory
 *                summary and the user approves it (private/sensitive) or auto-signs it
 *                (public, live mode).
 *
 * getMyMemories — authenticated read; returns { ok: true, records: [...] } with all records
 *                 the owner can see (public + private + sensitive), or { ok: false, error }
 *                 on failure. Never collapses errors into an empty array.
 *                 The canister enforces msg.caller == user_id before returning non-public records.
 *                 Anonymous callers (server adapter, MCP) only get public.
 *
 * In mock mode the server handles writes; this composable is not used for public memories.
 * For approved private/sensitive memories in mock mode, the browser POSTs to /chat/store-memory.
 */

import { HttpAgent, Actor } from '@dfinity/agent';
import { IDL } from '@dfinity/candid';

// Candid IDL matching the deployed Motoko canister.
// store_memory has no user_id — the canister uses msg.caller.
// memory_type is a variant: { Public: null } | { Private: null } | { Sensitive: null }
const idlFactory = ({ IDL }) => {
  const MemoryType = IDL.Variant({
    Public:    IDL.Null,
    Private:   IDL.Null,
    Sensitive: IDL.Null,
  });

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
    store_memory:         IDL.Func([StoreRequest], [IDL.Text], []),
    get_memories:         IDL.Func([IDL.Text], [IDL.Vec(MemoryResponse)], ['query']),
    list_recent_memories: IDL.Func([IDL.Nat], [IDL.Vec(MemoryResponse)], ['query']),
    health:               IDL.Func([], [IDL.Record({ status: IDL.Text, count: IDL.Nat })], ['query']),
  });
};

// Convert a string type name to the Candid variant expected by the canister.
function toMemoryTypeVariant(type) {
  if (type === 'private')   return { Private:   null };
  if (type === 'sensitive') return { Sensitive: null };
  return { Public: null };
}

// Normalise a Candid MemoryType variant ({ Public: null } etc.) to a plain string.
function fromMemoryTypeVariant(variant) {
  if (!variant) return 'public';
  const key = Object.keys(variant)[0];
  return key ? key.toLowerCase() : 'public';
}

export function useIcpMemory({ identity, canisterId, host }) {
  if (!canisterId) {
    console.warn('[useIcpMemory] No canisterId — live reads/writes disabled.');
    return {
      storeMemory:   async () => null,
      getMyMemories: async () => ({ ok: true, records: [] }),
    };
  }

  async function getActor() {
    const agent = new HttpAgent({ identity, host });

    // fetchRootKey is only needed for local dfx replicas, not mainnet.
    if (host.includes('localhost') || host.includes('127.0.0.1')) {
      await agent.fetchRootKey().catch((e) =>
        console.warn('[useIcpMemory] fetchRootKey failed (replica may not be running):', e.message)
      );
    }

    return Actor.createActor(idlFactory, { agent, canisterId });
  }

  /**
   * Write a memory record to the canister, signed by the user's browser identity.
   * msg.caller on the canister will equal identity.getPrincipal().
   *
   * @param {object} params
   * @param {string} params.sessionId
   * @param {string} params.content
   * @param {string|null} params.metadata  — optional JSON string
   * @param {'public'|'private'|'sensitive'} [params.type='public']
   * @returns {Promise<string|null>} stored record ID or null on error
   */
  async function storeMemory({ sessionId, content, metadata = null, type = 'public' }) {
    try {
      const actor = await getActor();
      const id = await actor.store_memory({
        session_id:  sessionId,
        content,
        metadata:    metadata ? [metadata] : [],
        memory_type: [toMemoryTypeVariant(type)],
      });
      return id;
    } catch (err) {
      console.error('[useIcpMemory] storeMemory failed:', err);
      return null;
    }
  }

  /**
   * Read all memory records the owner can see (public + private + sensitive).
   *
   * This call is authenticated — the actor is created with the user's Ed25519 identity,
   * so msg.caller on the canister equals the user's principal. The canister returns all
   * records where user_id == msg.caller, including private and sensitive ones.
   *
   * Anonymous callers (server adapter, MCP server) only receive public records.
   * This is the distinction between owner-authenticated recall and agent recall.
   *
   * @param {string} principal  — the user's ICP principal (text form)
   * @returns {Promise<{ ok: true, records: Array } | { ok: false, error: string }>}
   */
  async function getMyMemories(principal) {
    try {
      const actor = await getActor();
      const records = await actor.get_memories(principal);
      return {
        ok: true,
        records: records.map((r) => ({
          id:          r.id,
          user_id:     r.user_id,
          session_id:  r.session_id,
          content:     r.content,
          timestamp:   Number(r.timestamp),
          metadata:    r.metadata?.[0] ?? null,
          memory_type: fromMemoryTypeVariant(r.memory_type),
        })),
      };
    } catch (err) {
      console.error('[useIcpMemory] getMyMemories failed:', err);
      return { ok: false, error: err.message ?? String(err) };
    }
  }

  return { storeMemory, getMyMemories };
}
