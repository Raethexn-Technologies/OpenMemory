<template>
  <AppLayout>
    <div class="max-w-4xl mx-auto w-full px-4 py-8 space-y-6 text-gray-200">
      <h1 class="text-xl font-semibold">Native memory</h1>
      <p>You intentionally save these private statements in this installation's SQL database.
        They are separate from imported history and legacy graph, ICP, and mock records.</p>
      <p>No model or external provider receives these records through this page.
        Do not save passwords, tokens, or other credentials. Content matching protected redaction categories must be removed before saving.</p>
      <p v-if="notice" role="status">{{ notice }}</p>
      <p v-if="error" role="alert" class="text-red-300">{{ error }}</p>

      <form @submit.prevent="save" class="space-y-2">
        <label for="native-content">{{ editing ? (mode === 'supersede' ? 'Replacement statement' : 'Correct this statement') : 'Save a new statement' }}</label>
        <textarea id="native-content" v-model="content" required maxlength="8000"
          class="block w-full rounded bg-gray-900 border border-gray-700 p-3" rows="4"></textarea>
        <p v-if="editing">{{ mode === 'supersede' ? 'The previous statement remains inspectable as superseded.' : 'Correction replaces this content without keeping an earlier copy. Use supersession to retain the previous statement.' }}</p>
        <button :disabled="busy" class="border rounded px-3 py-1">{{ editing ? 'Save change' : 'Save memory' }}</button>
        <button v-if="editing" type="button" @click="cancel" class="ml-3">Cancel editing</button>
      </form>

      <section aria-label="Portability" class="space-y-2 border-t border-gray-800 pt-4">
        <h2 class="font-semibold">Export and import</h2>
        <p>Exports include all lifecycle states and contain private text. Keep downloaded files secure.
          Export each page while the store is not being edited, then import every page on the receiving installation.</p>
        <button :disabled="busy" @click="download(false)" class="border rounded px-3 py-1">Start export</button>
        <button v-if="exportCursor" :disabled="busy" @click="download(true)" class="border rounded px-3 py-1 ml-2">Download next export page</button>
        <label for="native-import" class="block">Import an OpenMemory JSON page (up to 10 MiB)</label>
        <input id="native-import" type="file" accept=".json,application/json" :disabled="busy" @change="importFile" />
        <p>Import uses your authenticated ownership. Identical records are skipped, and conflicts stop the entire page without overwriting existing data.</p>
      </section>

      <form @submit.prevent="load(false)" class="flex flex-wrap items-end gap-3">
        <label>Search text
          <input v-model="query" maxlength="200" class="block bg-gray-900 border border-gray-700 p-2 rounded" />
        </label>
        <label>Lifecycle
          <select v-model="state" class="block bg-gray-900 border border-gray-700 p-2 rounded">
            <option value="active">Active</option>
            <option value="archived">Archived</option>
            <option value="superseded">Superseded</option>
            <option value="all">All states</option>
          </select>
        </label>
        <button :disabled="busy" class="border rounded px-3 py-2">Find memories</button>
      </form>

      <p v-if="!records.length">No memories match the current filters.</p>
      <article v-for="memory in records" :key="memory.id" class="border border-gray-700 rounded p-4 space-y-2">
        <p class="whitespace-pre-wrap break-words" data-testid="memory-content">{{ memory.content }}</p>
        <p class="text-sm">Origin: user assertion. Imported attribution is an unverified origin claim.</p>
        <p class="text-sm">{{ memory.state }} / Revision {{ memory.revision }}</p>
        <p class="text-xs break-all">ID: {{ memory.id }}</p>
        <p class="text-xs">Created {{ memory.created_at }}. Updated {{ memory.updated_at }}.</p>
        <p v-if="memory.state === 'superseded'" class="text-xs break-all">
          Replacement: {{ memory.superseded_by || 'Removed or unavailable' }}.
        </p>
        <div class="flex flex-wrap gap-3">
          <template v-if="memory.state !== 'superseded'">
            <button :disabled="busy" @click="edit(memory, 'correct')">Correct</button>
            <button :disabled="busy" @click="edit(memory, 'supersede')">Supersede</button>
            <button :disabled="busy" @click="archive(memory)">{{ memory.state === 'active' ? 'Archive' : 'Restore' }}</button>
          </template>
          <button :disabled="busy" @click="remove(memory)" class="text-red-300">Delete permanently</button>
        </div>
      </article>
      <button v-if="cursor" :disabled="busy" @click="load(true)" class="border rounded px-3 py-1">Load next page</button>
    </div>
  </AppLayout>
</template>

<script setup>
import { onMounted, ref } from 'vue';
import axios from 'axios';
import AppLayout from '../../Components/AppLayout.vue';

const api = '/api/native-memories';
const records = ref([]);
const content = ref('');
const editing = ref(null);
const mode = ref('correct');
const query = ref('');
const state = ref('active');
const cursor = ref(null);
const exportCursor = ref(null);
const exportPage = ref(0);
const busy = ref(false);
const error = ref('');
const notice = ref('');
let appliedFilters = { state: 'active', q: '' };

async function perform(action) {
  if (busy.value) return;
  busy.value = true;
  error.value = '';
  notice.value = '';
  try {
    await action();
  } catch (failure) {
    error.value = failure?.response?.status === 409
      ? 'A revision or import conflict occurred. Refresh and inspect the current records before retrying.'
      : 'The operation failed. Check your login, input format, file limits, and credential-like content.';
  } finally {
    busy.value = false;
  }
}

async function fetchRecords(next = false) {
  if (!next) appliedFilters = { state: state.value, q: query.value };
  const params = { ...appliedFilters, limit: 50 };
  if (next && cursor.value) params.after = cursor.value;
  let result;
  if (params.q !== '') result = await axios.post(api + '/search', params);
  else {
    delete params.q;
    result = await axios.get(api, { params });
  }
  const { data } = result;
  records.value = next ? [...records.value, ...data.data] : data.data;
  cursor.value = data.next_cursor;
}

function load(next = false) {
  return perform(() => fetchRecords(next));
}

function cancel() {
  editing.value = null;
  content.value = '';
}

function edit(memory, action) {
  editing.value = { ...memory };
  mode.value = action;
  content.value = action === 'correct' ? memory.content : '';
}

function save() {
  return perform(async () => {
    if (editing.value) {
      const path = api + '/' + editing.value.id;
      const input = { content: content.value, revision: editing.value.revision };
      if (mode.value === 'supersede') await axios.post(path + '/supersede', input);
      else await axios.patch(path, input);
    } else {
      await axios.post(api, { content: content.value });
    }
    cancel();
    await fetchRecords();
    notice.value = 'Your memory has been saved locally.';
  });
}

function archive(memory) {
  return perform(async () => {
    await axios.patch(api + '/' + memory.id, {
      revision: memory.revision, state: memory.state === 'active' ? 'archived' : 'active',
    });
    if (editing.value?.id === memory.id) cancel();
    await fetchRecords();
  });
}

function remove(memory) {
  if (!window.confirm('Delete this statement permanently from this installation? Downloaded exports and backups are not erased.')) return;
  return perform(async () => {
    await axios.delete(api + '/' + memory.id, { data: { revision: memory.revision } });
    if (editing.value?.id === memory.id) cancel();
    await fetchRecords();
  });
}

function download(next) {
  return perform(async () => {
    const params = { limit: 100 };
    if (next && exportCursor.value) params.after = exportCursor.value;
    else exportPage.value = 0;
    const { data } = await axios.get(api + '/export', { params });
    const url = URL.createObjectURL(new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' }));
    try {
      const link = document.createElement('a');
      link.href = url;
      link.download = 'openmemory-export-v1-page-' + (++exportPage.value) + '.json';
      link.click();
    } finally {
      URL.revokeObjectURL(url);
    }
    exportCursor.value = data.next_cursor;
    notice.value = data.next_cursor
      ? 'This page was downloaded. Download the next export page to continue.'
      : 'The last export page was downloaded.';
  });
}

function importFile(event) {
  const file = event.target.files?.[0];
  if (!file) return;
  return perform(async () => {
    if (file.size > 10 * 1024 * 1024) throw new Error('Input exceeds size limit.');
    const input = await file.text();
    const { data } = await axios.post(api + '/import', input, { headers: { 'Content-Type': 'application/json' } });
    await fetchRecords();
    notice.value = 'Imported ' + data.imported + ' memories; skipped ' + data.skipped + ' identical records.';
  }).finally(() => { event.target.value = ''; });
}

onMounted(() => load());
</script>
