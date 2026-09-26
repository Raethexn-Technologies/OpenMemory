<template>
  <AppLayout>
    <div class="max-w-4xl mx-auto w-full px-4 py-8 space-y-5 text-gray-200">
      <h1 class="text-xl font-semibold">GitHub context source</h1>
      <p>Connect an expiring fine-grained token restricted to selected repositories, with Contents read permission.
        OpenMemory retrieves commit metadata when requested. Repository files are not retrieved.</p>
      <p v-if="error" role="alert">{{ error }}</p>
      <form v-if="!connection || connection.disconnected_at" @submit.prevent="connect" class="space-y-3">
        <label class="block">GitHub token
          <input v-model="token" aria-label="GitHub token" type="password" autocomplete="off" required class="block bg-gray-900 border p-2" />
        </label>
        <label class="block">Token expiry in UTC
          <input v-model="expires" aria-label="Token expiry" type="datetime-local" required class="block bg-gray-900 border p-2" />
        </label>
        <p>Enter the token's actual expiry. OpenMemory also stops using it at this local deadline.</p>
        <button :disabled="busy">Connect GitHub</button>
      </form>
      <section v-else class="space-y-4">
        <p>Connected account: {{ connection.external_account_login }} ({{ connection.external_account_id }}).</p>
        <p>Credential expires {{ connection.credential_expires_at }}.</p>
        <p v-if="notice" role="status">{{ notice }}</p>
        <button :disabled="busy" @click="run(() => discover(1))">Load accessible repositories</button>
        <fieldset :disabled="busy">
          <legend>Select repositories allowed as context sources</legend>
          <label v-for="repository in choices" :key="repository.external_id" class="block">
            <input v-model="selected" type="checkbox" :value="repository.reference" /> {{ repository.reference }}
          </label>
        </fieldset>
        <button v-if="nextPage" :disabled="busy" @click="run(() => discover(nextPage))">Load next repository page</button>
        <label class="block">
          <input v-model="allowDates" type="checkbox" :disabled="busy" />
          Allow dates learned from imported history to be sent to GitHub as commit query bounds.
        </label>
        <p>This sends dates to GitHub when a temporal request is explicitly made. Applications also need their own grant.</p>
        <button :disabled="busy" @click="save">Save repository selection and date permission</button>
        <p><a href="/applications" class="underline">Inspect application capabilities and repository grants</a>.</p>
        <p>Disconnect removes the stored token and repository selections. Revoke the token in GitHub settings to invalidate other copies.</p>
        <button :disabled="busy" @click="disconnect" class="text-red-300">Disconnect GitHub</button>
      </section>
    </div>
  </AppLayout>
</template>

<script setup>
import { onMounted, ref } from 'vue';
import axios from 'axios';
import AppLayout from '../../Components/AppLayout.vue';

const api = '/api/context/github';
const connection = ref(null);
const choices = ref([]);
const selected = ref([]);
const allowDates = ref(false);
const token = ref('');
const expires = ref('');
const busy = ref(false);
const error = ref('');
const notice = ref('');
const nextPage = ref(null);

async function run(action) {
  if (busy.value) return;
  busy.value = true;
  error.value = '';
  notice.value = '';
  try { await action(); }
  catch (failure) {
    const statuses = {
      credential_invalid: 'GitHub rejected the credential. Disconnect and reconnect with a new token.',
      credential_expired: 'The credential has expired. Disconnect and reconnect with a new token.',
      rate_limited: 'GitHub has limited requests. Retry after its cooldown.',
      repository_access_denied: 'GitHub denied repository access. Check token permissions and organization approval.',
      repository_inaccessible: 'A repository is missing or inaccessible to this token.',
    };
    error.value = statuses[failure?.response?.data?.status] || 'The operation failed. Refresh and check your connection settings.';
  } finally { busy.value = false; }
}
async function refresh() {
  const { data } = await axios.get(api);
  connection.value = data.connection;
  choices.value = data.resources;
  selected.value = data.resources.filter(resource => resource.selected).map(resource => resource.reference);
  allowDates.value = data.connection?.query_disclosures.includes('history') || false;
  nextPage.value = null;
}
function connect() {
  return run(async () => {
    const credential = token.value;
    token.value = '';
    await axios.post(api, { token: credential, expires_at: expires.value + ':00Z' });
    await refresh();
  });
}
async function discover(page) {
  const { data } = await axios.post(`${api}/${connection.value.id}/repositories`, { page });
  const merged = new Map(choices.value.map(repo => [repo.external_id, repo]));
  data.repositories.forEach(repo => merged.set(repo.external_id, repo));
  choices.value = [...merged.values()];
  nextPage.value = data.next_page;
  if (data.truncated) notice.value = 'The repository discovery limit was reached. Some accessible repositories are not shown.';
}
function save() {
  return run(async () => {
    await axios.put(`${api}/${connection.value.id}`, {
      revision: connection.value.revision, repositories: selected.value,
      query_disclosures: allowDates.value ? ['history'] : [],
    });
    await refresh();
    notice.value = 'The repository selection and date permission were saved.';
  });
}
function disconnect() {
  return run(async () => {
    await axios.delete(`${api}/${connection.value.id}`, { data: {} });
    token.value = '';
    await refresh();
  });
}
onMounted(() => run(refresh));
</script>
