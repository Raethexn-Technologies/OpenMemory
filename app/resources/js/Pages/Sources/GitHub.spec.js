import { mount, flushPromises } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import axios from 'axios';
import GitHub from './GitHub.vue';

vi.mock('axios', () => ({ default: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() } }));
const connection = { id: 'connection-fixture', revision: 1, external_account_id: '501', external_account_login: 'fixture',
  credential_expires_at: '2026-10-01T00:00:00Z', query_disclosures: [], disconnected_at: null };
function page() { return mount(GitHub, { global: { stubs: { AppLayout: { template: '<main><slot /></main>' } } } }); }
async function click(wrapper, text) {
  await wrapper.findAll('button').find(node => node.text() === text).trigger('click');
  await flushPromises();
}
beforeEach(() => {
  vi.clearAllMocks();
  axios.get.mockResolvedValue({ data: { connection: null, resources: [] } });
  axios.post.mockResolvedValue({ data: {} });
  axios.put.mockResolvedValue({});
  axios.delete.mockResolvedValue({});
});
describe('GitHub owner controls', () => {
  it('masks and clears the submitted token even when connection fails', async () => {
    const wrapper = page();
    await flushPromises();
    const input = wrapper.get('input[aria-label="GitHub token"]');
    expect(input.attributes('type')).toBe('password');
    await input.setValue('github_pat_synthetic');
    await wrapper.get('input[aria-label="Token expiry"]').setValue('2026-10-01T00:00');
    axios.post.mockRejectedValue({ response: { data: { status: 'SECRET PROVIDER RESPONSE' } } });
    await wrapper.get('form').trigger('submit');
    await flushPromises();
    expect(axios.post).toHaveBeenCalledWith('/api/context/github', { token: 'github_pat_synthetic', expires_at: '2026-10-01T00:00:00Z' });
    expect(input.element.value).toBe('');
    expect(wrapper.text()).not.toContain('SECRET PROVIDER RESPONSE');
  });
  it('discovers repositories without selecting or granting date disclosure implicitly', async () => {
    axios.get.mockResolvedValue({ data: { connection, resources: [] } });
    axios.post.mockResolvedValue({ data: { repositories: [{ external_id: '100', reference: 'fixture/private' }], next_page: null, truncated: false } });
    const wrapper = page();
    await flushPromises();
    expect(axios.post).not.toHaveBeenCalled();
    await click(wrapper, 'Load accessible repositories');
    expect(wrapper.findAll('input[type="checkbox"]').every(input => !input.element.checked)).toBe(true);
    await wrapper.get('fieldset input').setValue(true);
    await click(wrapper, 'Save repository selection and date permission');
    expect(axios.put).toHaveBeenCalledWith('/api/context/github/connection-fixture', {
      revision: 1, repositories: ['fixture/private'], query_disclosures: [],
    });
  });
  it('renders provider identity as text and disconnects through the owner endpoint', async () => {
    axios.get.mockResolvedValue({ data: { connection: { ...connection, external_account_login: '<script>steal()</script>' }, resources: [] } });
    const wrapper = page();
    await flushPromises();
    expect(wrapper.text()).toContain('<script>steal()</script>');
    expect(wrapper.find('script').exists()).toBe(false);
    await click(wrapper, 'Disconnect GitHub');
    expect(axios.delete).toHaveBeenCalledWith('/api/context/github/connection-fixture', { data: {} });
  });
});
