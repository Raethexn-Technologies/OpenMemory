import { mount, flushPromises } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import axios from 'axios';
import { router } from '@inertiajs/vue3';
import ChatIndex from './Index.vue';
import { _setMockAuth, _setMockReady, _setMockInitError } from '@/composables/useIcpIdentity';

const mocks = vi.hoisted(() => ({
    storeMemory: vi.fn(),
    getMyMemories: vi.fn(),
}));

vi.mock('axios', () => ({
    default: {
        post: vi.fn(),
    },
}));

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({
        url: '/chat',
        props: {
            icp: {
                mode: 'mock',
            },
        },
    }),
    router: {
        post:  vi.fn(),
        visit: vi.fn(),
    },
}));

vi.mock('@/composables/useIcpIdentity', async () => {
    const { ref, computed } = await import('vue');
    const isAuthenticated = ref(true);
    const isReady = ref(true);
    const principalRef = ref('test-principal');
    const initError = ref(null);
    return {
        useIcpIdentity: () => ({
            identity: computed(() => ({
                getPrincipal: () => ({ toText: () => principalRef.value }),
            })),
            principal: principalRef,
            isAuthenticated,
            isReady,
            initError,
            init:   vi.fn().mockResolvedValue(undefined),
            login:  vi.fn().mockResolvedValue(true),
            logout: vi.fn().mockResolvedValue(undefined),
        }),
        _setMockAuth:      (v) => { isAuthenticated.value = v; },
        _setMockReady:     (v) => { isReady.value = v; },
        _setMockInitError: (v) => { initError.value = v; },
    };
});

vi.mock('@/composables/useIcpMemory', () => ({
    useIcpMemory: () => ({
        storeMemory: mocks.storeMemory,
        getMyMemories: mocks.getMyMemories,
    }),
}));

function mountChat(overrides = {}) {
    return mount(ChatIndex, {
        props: {
            session_id: 'session-1',
            user_id: 'test-principal',
            identity_source: 'browser',
            messages: [],
            llm_provider: 'mock',
            icp_mode: 'mock',
            canister_id: '',
            browser_host: 'http://localhost:4943',
            ii_provider_url: '',
            ...overrides,
        },
        global: {
            stubs: {
                AppLayout: { template: '<div><slot /></div>' },
                AmbientGraph: {
                    template: '<div data-test="ambient-graph"></div>',
                    methods: {
                        pulse() {},
                    },
                },
            },
        },
    });
}

function buttonByText(wrapper, text) {
    return wrapper.findAll('button').find((button) => button.text().includes(text));
}

async function sendMessage(wrapper, text) {
    await wrapper.find('input').setValue(text);
    await buttonByText(wrapper, 'Send').trigger('click');
    await flushPromises();
}

describe('Chat redaction UI', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        _setMockAuth(true);
        _setMockReady(true);
        mocks.getMyMemories.mockResolvedValue({ ok: true, records: [] });
    });

    it('replaces the visible user turn with the redacted server copy', async () => {
        axios.post.mockResolvedValueOnce({
            data: {
                message: 'I can remember the safe version.',
                redacted_message: 'My card is [PAYMENT_CARD].',
                memory: 'User mentioned a payment card without storing the number.',
                memory_type: 'sensitive',
                memory_metadata: '{"source":"chat"}',
            },
        });

        const wrapper = mountChat();
        await sendMessage(wrapper, 'My card is 4111111111111111.');

        expect(wrapper.text()).toContain('My card is [PAYMENT_CARD].');
        expect(wrapper.text()).not.toContain('4111111111111111');
        expect(wrapper.text()).toContain('Sensitive memory');
    });

    it('stores approved sensitive mock memories with the returned effective type', async () => {
        axios.post
            .mockResolvedValueOnce({
                data: {
                    message: 'Stored after review.',
                    redacted_message: 'My card is [PAYMENT_CARD].',
                    memory: 'User mentioned a payment card without storing the number.',
                    memory_type: 'sensitive',
                    memory_metadata: '{"source":"chat"}',
                },
            })
            .mockResolvedValueOnce({
                data: {
                    id: 'mock-memory-1',
                    memory_type: 'sensitive',
                },
            });

        const wrapper = mountChat();
        await sendMessage(wrapper, 'My card is 4111111111111111.');

        await buttonByText(wrapper, 'Sign & store').trigger('click');
        await flushPromises();

        expect(axios.post).toHaveBeenNthCalledWith(2, '/chat/store-memory', {
            content: 'User mentioned a payment card without storing the number.',
            memory_type: 'sensitive',
            metadata: '{"source":"chat"}',
        });
        expect(wrapper.text()).toContain('Stored (mock):');
        expect(wrapper.text()).toContain('sensitive');
        expect(wrapper.text()).not.toContain('Sensitive memory');
    });

    it('syncs live browser writes with the backend effective memory type', async () => {
        mocks.storeMemory.mockResolvedValueOnce('canister-memory-1');
        axios.post
            .mockResolvedValueOnce({
                data: {
                    message: 'Safe to store publicly.',
                    redacted_message: 'I like distributed systems.',
                    memory: 'User likes distributed systems.',
                    memory_type: 'public',
                    memory_metadata: '{"source":"chat"}',
                },
            })
            .mockResolvedValueOnce({
                data: {
                    ok: true,
                    memory_type: 'public',
                },
            });

        const wrapper = mountChat({
            icp_mode: 'icp',
            canister_id: 'aaaaa-aa',
            ii_provider_url: 'https://identity.ic0.app',
        });
        await flushPromises();

        await sendMessage(wrapper, 'I like distributed systems.');

        expect(mocks.storeMemory).toHaveBeenCalledWith({
            sessionId: 'session-1',
            content: 'User likes distributed systems.',
            type: 'public',
            metadata: '{"source":"chat"}',
        });
        expect(axios.post).toHaveBeenNthCalledWith(2, '/chat/sync-graph-memory', {
            content: 'User likes distributed systems.',
            memory_type: 'public',
        });
        expect(wrapper.text()).toContain('Written to ICP (browser-signed):');
        expect(wrapper.text()).toContain('public');
    });
});

describe('Chat Internet Identity lifecycle', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        _setMockAuth(true);
        _setMockReady(true);
        _setMockInitError(null);
        mocks.getMyMemories.mockResolvedValue({ ok: true, records: [] });
    });

    it('shows the signed-in badge and Sign out control when authenticated', async () => {
        _setMockAuth(true);
        const wrapper = mountChat({
            icp_mode: 'icp',
            canister_id: 'aaaaa-aa',
            ii_provider_url: 'https://identity.ic0.app',
        });
        await flushPromises();

        expect(wrapper.text()).toContain('Internet Identity');
        expect(buttonByText(wrapper, 'Sign out')).toBeTruthy();
        expect(buttonByText(wrapper, 'Sign in')).toBeUndefined();
    });

    it('shows Sign in and a warning when ICP is live but user is signed out', async () => {
        _setMockAuth(false);
        const wrapper = mountChat({
            icp_mode: 'icp',
            canister_id: 'aaaaa-aa',
            ii_provider_url: 'https://identity.ic0.app',
        });
        await flushPromises();

        expect(buttonByText(wrapper, 'Sign in')).toBeTruthy();
        expect(buttonByText(wrapper, 'Sign out')).toBeUndefined();
        expect(wrapper.text()).toContain('Sign in to write memories to ICP');
        expect(wrapper.text()).toContain('Signed out');
    });

    it('reports II as unconfigured when no provider URL is set in live mode', async () => {
        _setMockAuth(false);
        const wrapper = mountChat({
            icp_mode: 'icp',
            canister_id: 'aaaaa-aa',
            ii_provider_url: '',
        });
        await flushPromises();

        expect(wrapper.text()).toContain('Internet Identity is not configured');
        expect(buttonByText(wrapper, 'Sign in')).toBeUndefined();
    });

    it('omits the principal from /chat/send when signed out', async () => {
        _setMockAuth(false);
        axios.post.mockResolvedValueOnce({
            data: {
                message: 'OK',
                redacted_message: 'hello',
                memory: null,
            },
        });

        const wrapper = mountChat({
            ii_provider_url: 'https://identity.ic0.app',
        });
        await flushPromises();

        await sendMessage(wrapper, 'hello');

        expect(axios.post).toHaveBeenNthCalledWith(1, '/chat/send', {
            message: 'hello',
            principal: null,
        });
    });

    it('blocks live public writes when signed out without calling the canister', async () => {
        _setMockAuth(false);
        axios.post.mockResolvedValueOnce({
            data: {
                message: 'Logged in or not, I heard you.',
                redacted_message: 'I like distributed systems.',
                memory: 'User likes distributed systems.',
                memory_type: 'public',
                memory_metadata: '{"source":"chat"}',
            },
        });

        const wrapper = mountChat({
            icp_mode: 'icp',
            canister_id: 'aaaaa-aa',
            ii_provider_url: 'https://identity.ic0.app',
        });
        await flushPromises();

        await sendMessage(wrapper, 'I like distributed systems.');

        expect(mocks.storeMemory).not.toHaveBeenCalled();
        expect(wrapper.text()).toContain('Memory not stored');
        expect(wrapper.text()).toContain('Sign in with Internet Identity');
    });

    it('calls /chat/identity-logout when the user signs out', async () => {
        _setMockAuth(true);
        axios.post.mockResolvedValueOnce({ data: { ok: true } });

        const wrapper = mountChat({
            icp_mode: 'icp',
            canister_id: 'aaaaa-aa',
            ii_provider_url: 'https://identity.ic0.app',
        });
        await flushPromises();

        await buttonByText(wrapper, 'Sign out').trigger('click');
        await flushPromises();

        expect(axios.post).toHaveBeenCalledWith('/chat/identity-logout');
        expect(router.visit).toHaveBeenCalledWith('/chat', { preserveScroll: false, replace: true });
    });

    it('surfaces an error and does not navigate when /chat/identity-logout fails', async () => {
        _setMockAuth(true);
        axios.post.mockRejectedValueOnce(new Error('network'));

        const wrapper = mountChat({
            icp_mode: 'icp',
            canister_id: 'aaaaa-aa',
            ii_provider_url: 'https://identity.ic0.app',
        });
        await flushPromises();

        await buttonByText(wrapper, 'Sign out').trigger('click');
        await flushPromises();

        expect(router.visit).not.toHaveBeenCalled();
        expect(wrapper.text()).toContain('Sign out failed');
        // The Sign out button is still present (delegation not cleared) so the
        // user can retry from the same control.
        expect(buttonByText(wrapper, 'Sign out')).toBeTruthy();
    });

    it('blocks send while II readiness is pending in live mode', async () => {
        _setMockReady(false);
        _setMockAuth(false);

        const wrapper = mountChat({
            icp_mode: 'icp',
            canister_id: 'aaaaa-aa',
            ii_provider_url: 'https://identity.ic0.app',
        });
        await flushPromises();

        const input = wrapper.find('input');
        expect(input.attributes('disabled')).toBeDefined();
        expect(input.attributes('placeholder')).toContain('Waiting for Internet Identity');
        expect(buttonByText(wrapper, 'Send').attributes('disabled')).toBeDefined();
    });

    it('unblocks chat and disables Sign in when AuthClient init fails', async () => {
        _setMockReady(true);
        _setMockAuth(false);
        _setMockInitError('IndexedDB unavailable');

        const wrapper = mountChat({
            icp_mode: 'icp',
            canister_id: 'aaaaa-aa',
            ii_provider_url: 'https://identity.ic0.app',
        });
        await flushPromises();

        // Chat must still run — init failure unblocks the input even in live
        // mode so the user can read and ask questions. Live writes remain
        // blocked by the canister's anonymous rejection.
        const input = wrapper.find('input');
        expect(input.attributes('disabled')).toBeUndefined();

        expect(wrapper.text()).toContain('Internet Identity is currently unavailable');
        expect(wrapper.text()).toContain('IndexedDB unavailable');

        // Sign in button is rendered but disabled — clicking it would just
        // retrigger the same failure.
        const signIn = buttonByText(wrapper, 'Sign in');
        expect(signIn).toBeTruthy();
        expect(signIn.attributes('disabled')).toBeDefined();
    });
});
