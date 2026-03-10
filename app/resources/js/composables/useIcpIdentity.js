/**
 * useIcpIdentity
 *
 * Generates and persists an Ed25519 key pair in the browser's localStorage.
 * The derived ICP principal becomes the user's identity — created in the browser,
 * never generated or stored by the server.
 *
 * In live ICP mode the principal is also the canister-level user_id: msg.caller
 * on store_memory() is this principal, cryptographically enforced by the canister.
 *
 * Key storage: localStorage['oma_icp_identity_v1']
 * Key format:  Ed25519KeyIdentity JSON (public key + private key bytes)
 *
 * Upgrading to Internet Identity in a future version only requires swapping the
 * identity returned here — the actor and write flow are unchanged.
 */

import { Ed25519KeyIdentity } from '@dfinity/identity';

const STORAGE_KEY = 'oma_icp_identity_v1';

let _cached = null;

export function useIcpIdentity() {
  if (_cached) return _cached;

  let identity;

  const stored = localStorage.getItem(STORAGE_KEY);
  if (stored) {
    try {
      identity = Ed25519KeyIdentity.fromJSON(stored);
    } catch {
      // Corrupted — generate fresh
      identity = Ed25519KeyIdentity.generate();
    }
  } else {
    identity = Ed25519KeyIdentity.generate();
  }

  // Persist (no-op if already stored and unchanged)
  localStorage.setItem(STORAGE_KEY, JSON.stringify(identity.toJSON()));

  const principal = identity.getPrincipal().toText();

  _cached = { identity, principal };
  return _cached;
}

/**
 * Wipe the identity from localStorage and reset the module cache.
 * The next call to useIcpIdentity() will generate a new key pair.
 * Use this only if the user explicitly wants to reset their identity.
 */
export function clearIcpIdentity() {
  localStorage.removeItem(STORAGE_KEY);
  _cached = null;
}
