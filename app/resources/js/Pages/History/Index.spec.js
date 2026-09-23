import { mount, flushPromises } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import HistoryIndex from './Index.vue';

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({
        url: '/history',
        props: { icp: { mode: 'mock' } },
    }),
    Head: { template: '<div></div>' },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
}));

const emptyOverview = {
    conversation_count: 0,
    message_count: 0,
    provider_count: 0,
    providers: [],
    first_at: null,
    last_at: null,
    monthly_volume: [],
    undated_conversations: 0,
};

const seededOverview = {
    conversation_count: 2814,
    message_count: 19403,
    provider_count: 3,
    providers: [
        { provider: 'chatgpt', conversation_count: 2000, message_count: 14000, first_at: '2023-01-04T10:00:00+00:00', last_at: '2026-08-01T10:00:00+00:00' },
        { provider: 'claude', conversation_count: 700, message_count: 5000, first_at: '2024-02-01T10:00:00+00:00', last_at: '2026-08-20T10:00:00+00:00' },
        { provider: 'gemini', conversation_count: 114, message_count: 403, first_at: '2025-05-01T10:00:00+00:00', last_at: '2026-01-11T10:00:00+00:00' },
    ],
    first_at: '2023-01-04T10:00:00+00:00',
    last_at: '2026-08-20T10:00:00+00:00',
    monthly_volume: [],
    undated_conversations: 0,
};

const conversationsPage = {
    total: 1,
    page: 1,
    per_page: 25,
    last_page: 1,
    data: [
        {
            id: 'conv-1',
            provider: 'claude',
            provider_conversation_id: 'pc-1',
            title: 'Refactoring the retrieval scorer',
            message_count: 3,
            first_message_at: '2025-04-04T09:12:00+00:00',
            last_message_at: '2025-04-04T10:02:00+00:00',
            provider_created_at: '2025-04-04T09:12:00+00:00',
            models: [],
            workspace_label: null,
            visibility: 'private',
            parser_version: 'claude-1',
            grain: 'conversation',
            redaction: null,
        },
    ],
};

const mountPage = (props = {}) => mount(HistoryIndex, {
    props: {
        overview: emptyOverview,
        suggested_subjects: [],
        imports: [],
        conversations: { total: 0, page: 1, per_page: 25, last_page: 1, data: [] },
        filters: {},
        answer_generation_enabled: true,
        ...props,
    },
    global: {
        // AppLayout reads the Inertia $page global, which the plugin normally
        // installs. Mounting a page in isolation has to supply it.
        mocks: { $page: { url: '/history', props: { icp: { mode: 'mock' } } } },
    },
});

describe('History/Index', () => {
    beforeEach(() => {
        vi.restoreAllMocks();
        document.head.innerHTML = '<meta name="csrf-token" content="test-token">';
    });

    it('explains how to export and import when the corpus is empty', () => {
        const wrapper = mountPage();

        expect(wrapper.text()).toContain('No history imported yet');
        expect(wrapper.text()).toContain('Data Controls');
        expect(wrapper.text()).toContain('memory:import-archive');
        // The empty state should not imply anything was uploaded anywhere.
        expect(wrapper.text()).toContain('Nothing is uploaded');
    });

    it('summarizes the corpus across providers once history exists', () => {
        const wrapper = mountPage({ overview: seededOverview, conversations: conversationsPage });

        expect(wrapper.text()).toContain('ChatGPT');
        expect(wrapper.text()).toContain('Claude');
        expect(wrapper.text()).toContain('Gemini');
        expect(wrapper.text()).not.toContain('No history imported yet');
    });

    it('renders cited evidence with a resolvable link for each item', async () => {
        const wrapper = mountPage({ overview: seededOverview, conversations: conversationsPage });

        vi.stubGlobal('fetch', vi.fn().mockResolvedValue({
            json: async () => ({
                question: 'consulting',
                terms: ['consulting'],
                matched_count: 2,
                candidate_count: 40,
                answer: 'This appears in two conversations [E1].',
                answer_state: 'answered',
                unresolved_citations: [],
                model_called: true,
                conversations: [],
                evidence: [
                    {
                        message_id: 'msg-1',
                        conversation_id: 'conv-1',
                        provider: 'chatgpt',
                        role: 'user',
                        title: 'Deciding whether to leave consulting',
                        occurred_at: '2024-02-11T09:00:00+00:00',
                        model_slug: null,
                        on_active_path: true,
                        excerpt: 'I keep going back and forth about leaving consulting.',
                        excerpt_truncated: false,
                        score: 4.2,
                    },
                ],
            }),
        }));

        await wrapper.find('input[type="text"]').setValue('consulting');
        await wrapper.find('form').trigger('submit');
        await flushPromises();

        expect(wrapper.text()).toContain('This appears in two conversations [E1].');
        expect(wrapper.text()).toContain('going back and forth about leaving consulting');
        expect(wrapper.find('a[href="/history/conversations/conv-1"]').exists()).toBe(true);
    });

    it('warns when the model produced a citation that resolves to nothing', async () => {
        const wrapper = mountPage({ overview: seededOverview, conversations: conversationsPage });

        vi.stubGlobal('fetch', vi.fn().mockResolvedValue({
            json: async () => ({
                question: 'consulting',
                terms: ['consulting'],
                matched_count: 1,
                candidate_count: 10,
                answer: 'A claim [unresolved citation].',
                answer_state: 'answered',
                unresolved_citations: ['E99'],
                model_called: true,
                conversations: [],
                evidence: [],
            }),
        }));

        await wrapper.find('input[type="text"]').setValue('consulting');
        await wrapper.find('form').trigger('submit');
        await flushPromises();

        expect(wrapper.text()).toContain('did not refer to any retrieved');
    });

    it('says plainly when nothing in the history matches', async () => {
        const wrapper = mountPage({ overview: seededOverview, conversations: conversationsPage });

        vi.stubGlobal('fetch', vi.fn().mockResolvedValue({
            json: async () => ({
                question: 'sonar',
                terms: ['sonar'],
                matched_count: 0,
                candidate_count: 0,
                answer: null,
                answer_state: 'no_evidence',
                unresolved_citations: [],
                model_called: false,
                conversations: [],
                evidence: [],
            }),
        }));

        await wrapper.find('input[type="text"]').setValue('sonar');
        await wrapper.find('form').trigger('submit');
        await flushPromises();

        expect(wrapper.text()).toContain('Nothing in the imported history matches');
    });

    it('states that nothing reaches a model when answer generation is off', () => {
        const wrapper = mountPage({
            overview: seededOverview,
            conversations: conversationsPage,
            answer_generation_enabled: false,
        });

        expect(wrapper.text()).toContain('nothing is sent to a model');
    });

    it('requires an unchecked per-request choice before sending history to a model', async () => {
        const wrapper = mountPage({ overview: seededOverview, conversations: conversationsPage });
        const request = vi.fn().mockResolvedValue({
            json: async () => ({ answer_state: 'no_evidence', terms: [], evidence: [], conversations: [], matched_count: 0, candidate_count: 0 }),
        });
        vi.stubGlobal('fetch', request);
        await wrapper.find('input[type="text"]').setValue('ledger');
        expect(wrapper.find('input[type="checkbox"]').element.checked).toBe(false);
        await wrapper.find('form').trigger('submit');
        await flushPromises();
        expect(JSON.parse(request.mock.calls[0][1].body).generate).toBe(false);
        await wrapper.find('input[type="checkbox"]').setValue(true);
        await wrapper.find('form').trigger('submit');
        await flushPromises();
        expect(JSON.parse(request.mock.calls[1][1].body).generate).toBe(true);
    });
});
