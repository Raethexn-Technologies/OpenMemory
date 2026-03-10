<template>
  <AppLayout>
    <div class="max-w-5xl mx-auto w-full px-4 py-8 space-y-6">

      <!-- Header -->
      <div class="flex items-start justify-between">
        <div>
          <h1 class="text-lg font-semibold text-gray-100">Memory Inspector</h1>
          <p class="text-sm text-gray-500 mt-1">
            Live view of memory records from the
            <span v-if="isMock" class="text-amber-400">mock cache</span>
            <span v-else class="text-emerald-400">ICP canister</span>.
          </p>
        </div>
        <div class="flex items-center gap-2">
          <!-- Truthful mode badge -->
          <span
            :class="[
              'text-xs px-2.5 py-1 rounded-full font-mono border',
              isMock
                ? 'bg-amber-950/60 border-amber-800/50 text-amber-400'
                : 'bg-emerald-950/60 border-emerald-800/50 text-emerald-400'
            ]"
          >
            {{ isMock ? 'Mock Mode' : 'ICP Live' }}
          </span>
          <button
            @click="refresh"
            class="flex items-center gap-1.5 text-xs text-gray-400 hover:text-gray-200 border border-gray-700 hover:border-gray-600 px-3 py-1.5 rounded-lg transition-colors"
          >
            <svg class="w-3.5 h-3.5" :class="{ 'animate-spin': refreshing }" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
            </svg>
            Refresh
          </button>
        </div>
      </div>

      <!-- Status bar — shows proof of what's actually connected -->
      <div :class="[
        'rounded-xl p-4 flex gap-3 border',
        isMock
          ? 'bg-amber-950/30 border-amber-800/30'
          : 'bg-sky-950/40 border-sky-800/30'
      ]">
        <svg class="w-5 h-5 flex-shrink-0 mt-0.5" :class="isMock ? 'text-amber-400' : 'text-sky-400'" fill="none" viewBox="0 0 24 24" stroke="currentColor">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
        </svg>
        <div class="text-sm">
          <p class="font-medium" :class="isMock ? 'text-amber-300' : 'text-sky-300'">
            {{ isMock ? 'Running in mock mode' : 'Connected to ICP Canister' }}
          </p>
          <p class="mt-0.5" :class="isMock ? 'text-amber-400/70' : 'text-sky-400/70'">
            <template v-if="isMock">
              Memories are stored in the app's file cache. To connect a real ICP canister, see the README.
            </template>
            <template v-else>
              Memories are stored in canister
              <code class="font-mono text-emerald-400">{{ canisterId || '—' }}</code>.
              The AI's memory is not owned by this server.
            </template>
          </p>
        </div>
      </div>

      <!-- Stats row -->
      <div class="grid grid-cols-3 gap-4">
        <StatCard label="Total Memories" :value="memories.length" />
        <StatCard label="Unique Identities" :value="uniqueUsers" />
        <StatCard label="Storage Layer" :value="isMock ? 'Mock (cache)' : 'ICP Canister'" :highlight="!isMock" />
      </div>

      <!-- Memory records -->
      <div class="bg-gray-900 border border-gray-800 rounded-xl overflow-hidden">
        <div class="px-4 py-3 border-b border-gray-800 flex items-center justify-between">
          <h2 class="text-sm font-medium text-gray-300">Stored Records</h2>
          <span class="text-xs text-gray-500">{{ memories.length }} records</span>
        </div>

        <!-- Empty state -->
        <div v-if="memories.length === 0" class="py-16 text-center">
          <div class="w-10 h-10 rounded-xl bg-gray-800 flex items-center justify-center mx-auto mb-3">
            <svg class="w-5 h-5 text-gray-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />
            </svg>
          </div>
          <p class="text-gray-500 text-sm">No memories stored yet.</p>
          <p class="text-gray-600 text-xs mt-1">Start a conversation in Chat to create memories.</p>
        </div>

        <!-- Records -->
        <div v-else class="divide-y divide-gray-800/60">
          <div
            v-for="memory in memories"
            :key="memory.id"
            class="px-4 py-4 hover:bg-gray-800/30 transition-colors"
          >
            <div class="flex items-start justify-between gap-4">
              <div class="flex-1 min-w-0">
                <div class="flex items-center gap-2 mb-1.5">
                  <span class="text-xs font-mono text-sky-400/80 truncate">{{ memory.user_id }}</span>
                  <span class="text-gray-700">·</span>
                  <span class="text-xs text-gray-500 font-mono">
                    session: {{ memory.session_id?.slice(0, 8) }}…
                  </span>
                </div>
                <p class="text-sm text-gray-200 leading-snug">{{ memory.content }}</p>
                <div v-if="memory.metadata" class="mt-1.5">
                  <code class="text-xs text-gray-500 bg-gray-800 px-1.5 py-0.5 rounded">{{ memory.metadata }}</code>
                </div>
              </div>
              <div class="text-right flex-shrink-0">
                <p class="text-xs text-gray-600 font-mono">{{ formatTime(memory.timestamp) }}</p>
                <div class="flex items-center gap-1 mt-1 justify-end">
                  <span class="w-1.5 h-1.5 rounded-full" :class="isMock ? 'bg-amber-500' : 'bg-emerald-500'"></span>
                  <span class="text-xs" :class="isMock ? 'text-amber-600' : 'text-emerald-600'">
                    {{ isMock ? 'mock' : 'ICP' }}
                  </span>
                </div>
              </div>
            </div>
            <div class="mt-2">
              <code class="text-xs text-gray-700 font-mono">ID: {{ memory.id }}</code>
            </div>
          </div>
        </div>
      </div>

      <!-- Architecture diagram -->
      <div class="bg-gray-900 border border-gray-800 rounded-xl p-6">
        <h3 class="text-sm font-medium text-gray-300 mb-4">Architecture</h3>
        <div class="flex items-center justify-center gap-0">
          <ArchNode label="Browser" icon="browser" />
          <ArchArrow />
          <ArchNode label="Laravel / Vue" icon="server" highlight />
          <ArchArrow />
          <ArchNode label="LLM API" icon="brain" />
          <ArchArrow :dashed="isMock" />
          <ArchNode label="ICP Canister" icon="chain" :accent="!isMock" />
        </div>
        <p class="text-center text-xs mt-4" :class="isMock ? 'text-amber-600' : 'text-gray-600'">
          {{ isMock ? 'Memory layer: mock (dashed = not connected)' : 'Normal app stack → decentralized memory layer' }}
        </p>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { ref, computed } from 'vue';
import axios from 'axios';
import AppLayout from '@/Components/AppLayout.vue';
import StatCard from '@/Components/StatCard.vue';
import ArchNode from '@/Components/ArchNode.vue';
import ArchArrow from '@/Components/ArchArrow.vue';

const props = defineProps({
  memories: Array,
  icp_mode: String,
  canister_id: String,
});

const memories = ref(props.memories ?? []);
const refreshing = ref(false);

const isMock = computed(() => props.icp_mode !== 'icp');
const canisterId = computed(() => props.canister_id || '');
const uniqueUsers = computed(() => new Set(memories.value.map(m => m.user_id)).size);

async function refresh() {
  refreshing.value = true;
  try {
    const { data } = await axios.get('/memory/refresh');
    if (data.memories) {
      memories.value = data.memories;
    }
  } finally {
    refreshing.value = false;
  }
}

function formatTime(ts) {
  if (!ts) return '—';
  // ICP timestamps are nanoseconds; JS timestamps are milliseconds
  const ms = typeof ts === 'number' && ts > 1e12 ? ts / 1e6 : ts;
  const d = new Date(ms);
  if (isNaN(d.getTime())) return String(ts).slice(0, 20);
  return d.toLocaleString();
}
</script>
