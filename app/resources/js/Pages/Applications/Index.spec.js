import { mount, flushPromises } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import axios from 'axios';
import Applications from './Index.vue';

vi.mock('axios', () => ({
  default: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() },
}));

const application = {
  id: '12345678-1234-4234-9234-123456789abc', name: 'Synthetic application',
  capabilities: ['context.resolve'], grant_revision: 2,
  expires_at: '2026-10-01T00:00:00Z', revoked_at: null,
};

function page() {
  return mount(Applications, {
    global: { stubs: { AppLayout: { template: '<main><slot /></main>' } } },
  });
}

async function click(wrapper, text) {
  const target = wrapper.findAll('button').find(node => node.text() === text);
  expect(target).toBeTruthy();
  await target.trigger('click');
  await flushPromises();
}

beforeEach(() => {
  vi.restoreAllMocks();
  vi.clearAllMocks();
  axios.get.mockImplementation(url => Promise.resolve({
    data: url.endsWith('access-events') ? { events: [] } : { applications: [{ ...application, capabilities: [...application.capabilities] }] },
  }));
  axios.post.mockResolvedValue({ data: { application, token: 'synthetic-once-only-credential' } });
  axios.put.mockResolvedValue({ data: { application } });
  axios.delete.mockResolvedValue({});
});

describe('owner application controls', () => {
  it('requires an explicit named model grant with separate source selection', async () => {
    const wrapper = page();
    await flushPromises();
    const form = wrapper.get('form');
    await form.get('#application-name').setValue('Local validation client');
    for (const capability of ['context.resolve', 'memory.read', 'memory.disclose']) {
      await form.get(`input[value="${capability}"]`).setValue(true);
    }
    await form.findAll('input[type="checkbox"]').at(-1).setValue(true);
    const model = form.findAll('fieldset').find(node => node.text().includes('Model disclosure is an instruction'));
    await model.get('input[type="url"]').setValue('https://api.openai.com');
    await model.get('input[maxlength="120"]').setValue('gpt-5.4');
    await model.get('input[value="native_memory"]').setValue(true);
    await form.trigger('submit');
    await flushPromises();
    expect(axios.post).toHaveBeenCalledWith('/api/context/applications', expect.objectContaining({
      model_disclosure: { destination: 'https://api.openai.com', model: 'gpt-5.4', sources: ['native_memory'] },
    }));
  });

  it('requires explicit selection of repository grants independently of capabilities', async () => {
    axios.get.mockImplementation(url => Promise.resolve({ data: url.endsWith('/github')
      ? { resources: [{ id: 'resource-fixture', reference: 'fixture/private' }] }
      : url.endsWith('access-events') ? { events: [] }
        : { applications: [{ ...application, capabilities: [...application.capabilities], source_resources: [] }] },
    }));
    const wrapper = page();
    await flushPromises();
    const fieldset = wrapper.get('article').findAll('fieldset').find(node => node.text().includes('Granted GitHub repositories'));
    expect(fieldset.get('input').element.checked).toBe(false);
    await fieldset.get('input').setValue(true);
    await click(wrapper, 'Save grants');
    expect(axios.put).toHaveBeenCalledWith('/api/context/applications/' + application.id + '/grants', {
      grant_revision: 2, capabilities: ['context.resolve'], source_resources: ['resource-fixture'], model_disclosure: null,
    });
  });

  it('starts with no capabilities selected and never registers implicitly', async () => {
    const wrapper = page();
    await flushPromises();
    const checkboxes = wrapper.get('form').findAll('input[type="checkbox"]');
    expect(checkboxes).toHaveLength(9);
    expect(checkboxes.every(input => !input.element.checked)).toBe(true);
    expect(axios.post).not.toHaveBeenCalled();
    expect(wrapper.text()).toContain('cannot technically prevent copying');
  });

  it('registers explicit capabilities and exposes the credential only in a clearable field', async () => {
    const wrapper = page();
    await flushPromises();
    await wrapper.get('#application-name').setValue('Local coding application');
    await wrapper.get('form').findAll('input[type="checkbox"]')[0].setValue(true);
    await wrapper.get('form').trigger('submit');
    await flushPromises();
    expect(axios.post).toHaveBeenCalledWith('/api/context/applications', {
      name: 'Local coding application', expires_in_days: 30, capabilities: ['context.resolve'],
      source_resources: [], model_disclosure: null,
    });
    expect(wrapper.get('input[aria-label="Application credential"]').attributes('type')).toBe('password');
    await click(wrapper, 'Reveal credential');
    expect(wrapper.get('input[aria-label="Application credential"]').element.value).toBe('synthetic-once-only-credential');
    await click(wrapper, 'Clear credential from this page');
    expect(wrapper.find('input[aria-label="Application credential"]').exists()).toBe(false);
  });

  it('sends the inspected grant revision when changing permissions', async () => {
    const wrapper = page();
    await flushPromises();
    await click(wrapper, 'Save grants');
    expect(axios.put).toHaveBeenCalledWith('/api/context/applications/' + application.id + '/grants', {
      grant_revision: 2, capabilities: ['context.resolve'],
      source_resources: [], model_disclosure: null,
    });
  });

  it('requires confirmation before revoking a credential', async () => {
    const confirm = vi.spyOn(window, 'confirm').mockReturnValue(false);
    const wrapper = page();
    await flushPromises();
    await click(wrapper, 'Revoke application');
    expect(axios.delete).not.toHaveBeenCalled();
    confirm.mockReturnValue(true);
    await click(wrapper, 'Revoke application');
    expect(axios.delete).toHaveBeenCalledWith('/api/context/applications/' + application.id, { data: {} });
  });

  it('renders application names as untrusted text', async () => {
    axios.get.mockImplementation(url => Promise.resolve({ data: url.endsWith('access-events')
      ? { events: [] } : { applications: [{ ...application, name: '<script>publishSecrets()</script>' }] },
    }));
    const wrapper = page();
    await flushPromises();
    expect(wrapper.text()).toContain('<script>publishSecrets()</script>');
    expect(wrapper.find('script').exists()).toBe(false);
  });

  it('never reflects sensitive error details into notifications or the console', async () => {
    const consoleError = vi.spyOn(console, 'error').mockImplementation(() => {});
    axios.post.mockRejectedValue({ response: { status: 500, data: { error: 'SENSITIVE CREDENTIAL PAYLOAD' } } });
    const wrapper = page();
    await flushPromises();
    await wrapper.get('#application-name').setValue('Synthetic');
    await wrapper.get('form').trigger('submit');
    await flushPromises();
    expect(wrapper.get('[role="alert"]').text()).toContain('operation failed');
    expect(wrapper.text()).not.toContain('SENSITIVE CREDENTIAL');
    expect(consoleError).not.toHaveBeenCalled();
  });

  it('reports revision conflicts without overwriting newer grants', async () => {
    axios.put.mockRejectedValue({ response: { status: 409 } });
    const wrapper = page();
    await flushPromises();
    await click(wrapper, 'Save grants');
    expect(wrapper.get('[role="alert"]').text()).toContain('Refresh before trying again');
    expect(axios.put).toHaveBeenCalledTimes(1);
  });

  it('shows only metadata in the access-event inspection surface', async () => {
    axios.get.mockImplementation(url => Promise.resolve({ data: url.endsWith('access-events')
      ? { events: [{ request_id: 'event', application_id: application.id, created_at: '2026-09-23',
        operation: 'context.resolve', outcome: 'partial', fragment_count: 1, duration_ms: 10,
        sources: { history: { status: 'source_not_authorized', searched: false, returned_count: 0 } } }] }
      : { applications: [application] },
    }));
    const wrapper = page();
    await flushPromises();
    expect(wrapper.get('section[aria-label="Recent context access"]').text()).toContain('source_not_authorized');
    expect(wrapper.text()).toContain('without queries or evidence');
  });
});
