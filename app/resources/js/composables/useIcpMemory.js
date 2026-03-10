/**
 * useIcpMemory
 *
 * Browser-side ICP actor for writing memory records directly to the canister.
 * Called after the server returns a memory_summary — the browser signs the write
 * with the user's Ed25519 identity so msg.caller on the canister equals the user's
 * principal. The server cannot write under this principal in live mode.
 *
 * Only used when icp.mode === 'icp' (live mode). In mock mode the server writes
 * to the file cache and this composable is not called.
 */

import { HttpAgent, Actor } from '@dfinity/agent';
import { IDL } from '@dfinity/candid';

// Candid IDL matching the deployed Motoko canister.
// store_memory has no user_id field — the canister uses msg.caller.
const idlFactory = ({ IDL }) => {
  const StoreRequest = IDL.Record({
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
    store_memory:         IDL.Func([StoreRequest], [IDL.Text], []),
    get_memories:         IDL.Func([IDL.Text], [IDL.Vec(MemoryResponse)], ['query']),
    list_recent_memories: IDL.Func([IDL.Nat], [IDL.Vec(MemoryResponse)], ['query']),
    health:               IDL.Func([], [IDL.Record({ status: IDL.Text, count: IDL.Nat })], ['query']),
  });
};

export function useIcpMemory({ identity, canisterId, host }) {
  if (!canisterId) {
    console.warn('[useIcpMemory] No canisterId — live writes disabled.');
    return { storeMemory: async () => null };
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
   * @param {string} params.content   — the memory summary text
   * @param {string|null} params.metadata — optional JSON string
   * @returns {Promise<string|null>} the stored record ID, or null on error
   */
  async function storeMemory({ sessionId, content, metadata = null }) {
    try {
      const actor = await getActor();
      const id = await actor.store_memory({
        session_id: sessionId,
        content,
        metadata: metadata ? [metadata] : [],
      });
      return id;
    } catch (err) {
      console.error('[useIcpMemory] storeMemory failed:', err);
      return null;
    }
  }

  return { storeMemory };
}
