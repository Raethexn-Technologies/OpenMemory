import { mount, flushPromises } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import axios from 'axios';
import ChatIndex from './Index.vue';

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
        post: vi.fn(),
    },
}));

vi.mock('@/composables/useIcpIdentity', () => ({
    useIcpIdentity: () => ({
        identity: { principal: 'test-principal' },
        principal: 'test-principal',
    }),
}));

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
        });

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
