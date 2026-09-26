<template>
  <AppLayout>
    <div class="max-w-4xl mx-auto w-full px-4 py-8 space-y-6 text-gray-200">
      <h1 class="text-xl font-semibold">Context applications</h1>
      <p>These applications can receive bounded context only through the grants you select.
        Retrieval and disclosure are separate permissions. Native writes and raw history remain unavailable.</p>
      <p>Disclosure gives an application private text that OpenMemory cannot recall.
        Model use is denied unless you separately authorize a destination below. OpenMemory cannot technically prevent copying after delivery.</p>
      <p v-if="error" role="alert" class="text-red-300">{{ error }}</p>
      <p v-if="notice" role="status">{{ notice }}</p>

      <form @submit.prevent="create" class="space-y-3 border border-gray-700 rounded p-4">
        <h2 class="font-semibold">Register an application</h2>
        <label for="application-name">Application name</label>
        <input id="application-name" v-model="name" required maxlength="80" class="block bg-gray-900 border border-gray-700 p-2 rounded" />
        <label for="application-expiry">Credential lifetime in days</label>
        <input id="application-expiry" v-model.number="days" type="number" min="1" max="365" required class="block bg-gray-900 border border-gray-700 p-2 rounded" />
        <fieldset>
          <legend>Explicit application capabilities</legend>
          <label v-for="capability in capabilities" :key="capability" class="block">
            <input v-model="selected" type="checkbox" :value="capability" /> {{ capabilityLabels[capability] }} <code>{{ capability }}</code>
          </label>
        </fieldset>
        <fieldset v-if="resources.length">
          <legend>Explicitly granted GitHub repositories</legend>
          <label v-for="resource in resources" :key="resource.id" class="block">
            <input v-model="selectedResources" type="checkbox" :value="resource.id" /> {{ resource.reference }}
          </label>
        </fieldset>
        <p>Native access requires context.resolve, memory.read, and memory.disclose.
          History access separately requires context.resolve, history.search, and history.disclose.</p>
        <p>GitHub access requires github.commits.read, github.disclose, and explicit repository grants.
          The history.query_disclose.github grant additionally permits history-derived dates to constrain GitHub requests.</p>
        <label class="block"><input v-model="modelEnabled" type="checkbox" /> Authorize this application to send selected source excerpts to one model destination</label>
        <fieldset v-if="modelEnabled" class="space-y-2">
          <legend>Model disclosure is an instruction to the application, not technical control over received plaintext.</legend>
          <label class="block">Destination origin (for example https://api.openai.com)
            <input v-model="modelDestination" required type="url" maxlength="200" class="block bg-gray-900 border p-2" /></label>
          <label class="block">Exact model identifier
            <input v-model="modelName" required maxlength="120" class="block bg-gray-900 border p-2" /></label>
          <label v-for="source in modelSourceNames" :key="source" class="block">
            <input v-model="modelSources" type="checkbox" :value="source" /> Permit model disclosure of {{ source }} evidence</label>
          <p>Each source still requires its retrieval and application-disclosure grants. The client must ask before sending, and the model service controls its own retention.</p>
        </fieldset>
        <button :disabled="busy" class="border rounded px-3 py-1">Register application</button>
      </form>

      <section v-if="token" aria-label="One-time credential" class="border border-amber-700 rounded p-4 space-y-2">
        <p>Save this credential now in your application's secret storage. It cannot be retrieved later.</p>
        <input aria-label="Application credential" :type="reveal ? 'text' : 'password'" :value="token" readonly autocomplete="off"
          class="block w-full bg-gray-900 border border-gray-700 p-2 rounded" />
        <button @click="reveal = !reveal" class="mr-3">{{ reveal ? 'Hide credential' : 'Reveal credential' }}</button>
        <button @click="copyToken" class="mr-3">Copy credential</button>
        <button @click="token = ''; reveal = false">Clear credential from this page</button>
      </section>

      <button :disabled="busy" @click="run(refresh)" class="border rounded px-3 py-1">Refresh applications and access events</button>
      <article v-for="application in applications" :key="application.id" class="border border-gray-700 rounded p-4 space-y-2">
        <h2 class="font-semibold">{{ application.name }}</h2>
        <p class="text-sm break-all">{{ application.id }}</p>
        <p>Expires {{ application.expires_at }}. Grant revision {{ application.grant_revision }}.</p>
        <p v-if="application.revoked_at">Revoked {{ application.revoked_at }}.</p>
        <fieldset :disabled="busy || !!application.revoked_at">
          <legend>Granted capabilities</legend>
          <label v-for="capability in capabilities" :key="capability" class="block">
            <input v-model="application.capabilities" type="checkbox" :value="capability" /> {{ capabilityLabels[capability] }} <code>{{ capability }}</code>
          </label>
        </fieldset>
        <button v-if="!application.revoked_at" :disabled="busy" @click="save(application)" class="mr-4">Save grants</button>
        <fieldset v-if="resources.length" :disabled="busy || !!application.revoked_at">
          <legend>Granted GitHub repositories</legend>
          <label v-for="resource in resources" :key="resource.id" class="block">
            <input v-model="application.source_resources" type="checkbox" :value="resource.id" /> {{ resource.reference }}
          </label>
        </fieldset>
        <fieldset :disabled="busy || !!application.revoked_at">
          <legend>Optional onward model permission</legend>
          <label><input type="checkbox" :checked="!!application.model_disclosure" @change="application.model_disclosure = $event.target.checked ? { destination: '', model: '', sources: [] } : null" /> Authorize one named model destination</label>
          <div v-if="application.model_disclosure">
            <label class="block">Destination origin <input v-model="application.model_disclosure.destination" maxlength="200" class="bg-gray-900 border p-2" /></label>
            <label class="block">Exact model <input v-model="application.model_disclosure.model" maxlength="120" class="bg-gray-900 border p-2" /></label>
            <label v-for="source in modelSourceNames" :key="source" class="block">
              <input v-model="application.model_disclosure.sources" type="checkbox" :value="source" /> {{ source }}</label>
          </div>
        </fieldset>
        <button v-if="!application.revoked_at" :disabled="busy" @click="revoke(application)" class="text-red-300">Revoke application</button>
      </article>

      <section aria-label="Recent context access" class="space-y-3">
        <h2 class="font-semibold">Recent context access</h2>
        <p>This list contains the latest 100 retained events, without queries or evidence.
          The scheduled cleanup removes metadata older than 30 days when the scheduler runs.</p>
        <article v-for="event in events" :key="event.request_id" class="border border-gray-800 rounded p-3">
          <p>{{ event.created_at }} / {{ event.operation }} / {{ event.outcome }}</p>
          <p class="text-sm break-all">Application: {{ event.application_id || 'Direct owner request' }}.</p>
          <p>{{ event.fragment_count }} fragments selected in {{ event.duration_ms }} milliseconds. Core does not observe application receipt or model use.</p>
          <p v-if="event.context_request_id" class="text-sm break-all">Context request: {{ event.context_request_id }}</p>
          <pre class="text-xs whitespace-pre-wrap">{{ JSON.stringify(event.sources, null, 2) }}</pre>
        </article>
      </section>
    </div>
  </AppLayout>
</template>

<script setup>
import { onMounted, ref } from 'vue';
import axios from 'axios';
import AppLayout from '../../Components/AppLayout.vue';

const api = '/api/context/applications';
const capabilities = ['context.resolve', 'memory.read', 'memory.disclose', 'history.search', 'history.disclose',
  'github.commits.read', 'github.disclose', 'history.query_disclose.github'];
const capabilityLabels = {
  'context.resolve': 'Allow context requests.', 'memory.read': 'Search saved memory.', 'memory.disclose': 'Return saved excerpts to this application.',
  'history.search': 'Search private imported history.', 'history.disclose': 'Return selected history excerpts to this application.',
  'github.commits.read': 'Read commits from granted repositories.', 'github.disclose': 'Return commit excerpts to this application.',
  'history.query_disclose.github': 'Send dates learned from history to GitHub (connection consent is also required).',
};
const modelSourceNames = ['native_memory', 'history', 'github'];
const modelEnabled = ref(false);
const modelDestination = ref('');
const modelName = ref('');
const modelSources = ref([]);
const name = ref('');
const days = ref(30);
const selected = ref([]);
const selectedResources = ref([]);
const resources = ref([]);
const applications = ref([]);
const events = ref([]);
const token = ref('');
const reveal = ref(false);
const error = ref('');
const notice = ref('');
const busy = ref(false);

async function run(action) {
  if (busy.value) return;
  busy.value = true;
  error.value = '';
  notice.value = '';
  try {
    await action();
  } catch (failure) {
    error.value = failure?.response?.status === 409
      ? 'Grants changed since inspection. Refresh before trying again.'
      : 'The operation failed. Check your login and application settings.';
  } finally {
    busy.value = false;
  }
}

async function refresh() {
  const [apps, access, sources] = await Promise.all([
    axios.get(api), axios.get('/api/context/access-events'), axios.get('/api/context/github'),
  ]);
  applications.value = apps.data.applications;
  resources.value = sources.data.resources || [];
  for (const application of applications.value) {
    application.source_resources = (application.source_resources || []).filter(id => resources.value.some(resource => resource.id === id));
  }
  events.value = access.data.events;
}

function create() {
  return run(async () => {
    token.value = '';
    reveal.value = false;
    const { data } = await axios.post(api, { name: name.value, expires_in_days: days.value, capabilities: selected.value,
      source_resources: selectedResources.value,
      model_disclosure: modelEnabled.value ? { destination: modelDestination.value, model: modelName.value, sources: modelSources.value } : null });
    token.value = data.token;
    name.value = '';
    selected.value = [];
    selectedResources.value = [];
    modelEnabled.value = false;
    modelDestination.value = modelName.value = '';
    modelSources.value = [];
    await refresh();
  });
}

function save(application) {
  return run(async () => {
    await axios.put(api + '/' + application.id + '/grants', {
      grant_revision: application.grant_revision, capabilities: application.capabilities,
      source_resources: application.source_resources,
      model_disclosure: application.model_disclosure || null,
    });
    await refresh();
    notice.value = 'The application grants have been updated.';
  });
}

function revoke(application) {
  if (!window.confirm('Revoke this credential and stop future context requests? Already delivered data cannot be recalled.')) return;
  return run(async () => {
    await axios.delete(api + '/' + application.id, { data: {} });
    token.value = '';
    reveal.value = false;
    await refresh();
    notice.value = 'The application credential has been revoked.';
  });
}

async function copyToken() {
  try {
    await navigator.clipboard.writeText(token.value);
    notice.value = 'Credential copied. Clear it from this page after saving it securely.';
  } catch {
    error.value = 'Clipboard access failed. Reveal the credential to copy it manually.';
  }
}

onMounted(() => run(refresh));
</script>
