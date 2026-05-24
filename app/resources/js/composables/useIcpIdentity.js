/**
 * useIcpIdentity
 *
 * Internet Identity adapter for the browser. Replaces the previous
 * Ed25519-in-localStorage implementation. The principal is held by the
 * Internet Identity canister and reached through an AuthClient delegation.
 *
 * State is module-singleton so any component that calls useIcpIdentity()
 * shares the same reactive identity. The composable returns refs so the
 * UI reacts to login, logout, and delegation expiry automatically.
 *
 * Lifecycle:
 *   1. init({ providerUrl }) — construct the AuthClient and restore a
 *      prior session if one exists. Call once on app mount.
 *   2. login({ providerUrl }) — opens the II window. Resolves once the
 *      user has authenticated or cancelled.
 *   3. logout() — clears the delegation and returns to AnonymousIdentity.
 *
 * Until login completes, identity is an AnonymousIdentity and writes to
 * the memory canister will be rejected by msg.caller checks. This is
 * intentional: live ICP writes require a signed-in principal.
 *
 * Legacy Ed25519 principals stored in localStorage under 'oma_icp_identity_v1'
 * by the previous version do not migrate. The first II login establishes a
 * fresh principal; old browser-key memories remain visible only to the old
 * principal. A migration path is future work — see VISION.md.
 */

import { ref, computed } from 'vue';
import { AuthClient } from '@dfinity/auth-client';
import { AnonymousIdentity } from '@dfinity/agent';

const LEGACY_STORAGE_KEY = 'oma_icp_identity_v1';

let _state = null;
let _initPromise = null;

function buildState() {
    return {
        authClient: ref(null),
        identity:   ref(new AnonymousIdentity()),
        isAuthenticated: ref(false),
        isReady:    ref(false),
        // Set when AuthClient.create() or isAuthenticated() rejects. When this
        // is non-null the chat UI should treat II as unavailable but still
        // unblock input — the canister still rejects anonymous writes, so live
        // writes remain blocked, but the user can at least read the page.
        initError:  ref(null),
    };
}

function ensureState() {
    if (!_state) _state = buildState();
    return _state;
}

async function initIdentity() {
    const state = ensureState();
    if (state.isReady.value) return;
    if (_initPromise) return _initPromise;

    _initPromise = (async () => {
        try {
            const client = await AuthClient.create({
                // Disable the built-in idle timeout. Delegation expiry from the
                // II canister is the authoritative session lifetime; the idle
                // logout would otherwise sign users out while the tab is open.
                idleOptions: {
                    disableIdle: true,
                    disableDefaultIdleCallback: true,
                },
            });
            state.authClient.value = client;
            if (await client.isAuthenticated()) {
                state.identity.value = client.getIdentity();
                state.isAuthenticated.value = true;
            }
            state.initError.value = null;
        } catch (err) {
            // Surface the failure to the UI but always flip isReady so the chat
            // can render. The canister still rejects anonymous writes, so live
            // memory storage stays blocked until the user can sign in.
            state.initError.value = err?.message ?? String(err);
            console.error('[useIcpIdentity] init failed:', err);
        } finally {
            state.isReady.value = true;
        }
    })();

    try {
        await _initPromise;
    } finally {
        _initPromise = null;
    }
}

async function loginIdentity({ providerUrl } = {}) {
    if (!providerUrl) {
        console.warn('[useIcpIdentity] No II provider URL configured — login disabled.');
        return false;
    }
    const state = ensureState();
    await initIdentity();
    const client = state.authClient.value;

    return new Promise((resolve) => {
        client.login({
            identityProvider: providerUrl,
            onSuccess: () => {
                state.identity.value = client.getIdentity();
                state.isAuthenticated.value = true;
                resolve(true);
            },
            onError: (err) => {
                console.error('[useIcpIdentity] login failed:', err);
                resolve(false);
            },
        });
    });
}

async function logoutIdentity() {
    const state = ensureState();
    if (state.authClient.value) {
        await state.authClient.value.logout();
    }
    state.identity.value = new AnonymousIdentity();
    state.isAuthenticated.value = false;
}

export function useIcpIdentity() {
    const state = ensureState();
    return {
        identity:        state.identity,
        isAuthenticated: state.isAuthenticated,
        isReady:         state.isReady,
        initError:       state.initError,
        principal:       computed(() => state.identity.value.getPrincipal().toText()),
        init:   (opts) => initIdentity(opts),
        login:  (opts) => loginIdentity(opts),
        logout: () => logoutIdentity(),
    };
}

/**
 * Legacy helper kept for callers that wanted to wipe the old browser key.
 * With II, logging out is the equivalent — but legacy Ed25519 material in
 * localStorage from earlier versions is also removed so it cannot be
 * reconstructed accidentally.
 */
export async function clearIcpIdentity() {
    try {
        localStorage.removeItem(LEGACY_STORAGE_KEY);
    } catch {
        // localStorage unavailable (SSR, private mode) — ignore.
    }
    await logoutIdentity();
}

// Exposed only for tests — resets the module singleton between cases.
export function _resetIdentityForTests() {
    _state = null;
    _initPromise = null;
}
