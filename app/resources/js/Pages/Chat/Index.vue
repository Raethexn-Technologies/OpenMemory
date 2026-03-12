<template>
  <AppLayout>
    <div class="flex-1 flex flex-col max-w-4xl mx-auto w-full px-4 py-6 gap-4">

      <!-- Header row -->
      <div class="flex items-center justify-between">
        <div>
          <h1 class="text-lg font-semibold text-gray-100">Chat</h1>
          <p class="text-xs text-gray-500 mt-0.5 flex items-center gap-1.5 flex-wrap">
            <!-- Identity badge — shows source of the principal -->
            <span
              :title="identityTooltip"
              :class="[
                'inline-flex items-center gap-1 px-1.5 py-0.5 rounded border font-mono text-[11px]',
                identityReady
                  ? 'bg-emerald-950/40 border-emerald-800/40 text-emerald-400/80'
                  : 'bg-gray-800/60 border-gray-700/60 text-gray-500'
              ]"
            >
              <svg class="w-2.5 h-2.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                  d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z" />
              </svg>
              {{ identityReady ? 'Browser key' : 'Generating…' }}
            </span>
            <code class="text-sky-400/70 font-mono truncate max-w-[200px]" :title="displayUserId">{{ displayUserId }}</code>
            <span class="text-gray-700">·</span>
            <span class="text-sky-400/80">{{ props.llm_provider }}</span>
          </p>
        </div>
        <div class="flex items-center gap-2">
          <!-- Memory mode badge -->
          <span
            :class="[
              'text-xs px-2 py-0.5 rounded-full font-mono border',
              props.icp_mode === 'mock'
                ? 'bg-amber-950/60 border-amber-800/50 text-amber-400'
                : 'bg-emerald-950/60 border-emerald-800/50 text-emerald-400'
            ]"
          >
            {{ props.icp_mode === 'mock' ? 'Memory: Mock' : 'Memory: ICP Live' }}
          </span>
          <button
            @click="resetSession"
            class="text-xs text-gray-500 hover:text-red-400 transition-colors px-2 py-1 rounded border border-gray-800 hover:border-red-900"
          >
            New session
          </button>
        </div>
      </div>

      <!-- Identity divergence warning -->
      <!-- This appears when localStorage was cleared but the session still holds the old principal.
           Reads and writes will go to different identities until the session is reset. -->
      <div
        v-if="identityDiverged"
        class="flex items-start gap-3 bg-yellow-950/50 border border-yellow-700/40 rounded-xl px-4 py-3 text-sm"
        role="alert"
      >
        <svg class="w-4 h-4 text-yellow-400 flex-shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
        </svg>
        <div class="flex-1">
          <p class="text-yellow-300 font-medium">Browser key has changed</p>
          <p class="text-yellow-500/80 text-xs mt-0.5">
            Your localStorage key no longer matches the session identity. Reads will use the old principal;
            new writes will use the current browser key. Start a new session to realign them.
          </p>
        </div>
        <button
          @click="resetSession"
          class="text-xs text-yellow-400 hover:text-yellow-200 border border-yellow-700/50 hover:border-yellow-500 px-2 py-1 rounded transition-colors flex-shrink-0"
        >
          Reset session
        </button>
      </div>

      <!-- Messages -->
      <div
        ref="messagesEl"
        class="flex-1 overflow-y-auto scrollbar-thin space-y-4 min-h-0 pr-1"
        style="max-height: calc(100vh - 260px)"
      >
        <!-- Empty state -->
        <div v-if="messages.length === 0" class="flex flex-col items-center justify-center h-full gap-4 py-16">
          <div class="w-14 h-14 rounded-2xl bg-sky-500/10 border border-sky-500/20 flex items-center justify-center">
            <svg class="w-7 h-7 text-sky-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z" />
            </svg>
          </div>
          <div class="text-center">
            <p class="text-gray-300 font-medium">Start a conversation</p>
            <p class="text-gray-500 text-sm mt-1">
              Tell me something about yourself — I'll remember it.
            </p>
          </div>
          <div class="flex flex-wrap gap-2 justify-center">
            <button
              v-for="suggestion in suggestions"
              :key="suggestion"
              @click="useSuggestion(suggestion)"
              class="text-xs px-3 py-1.5 rounded-full border border-gray-700 text-gray-400 hover:border-sky-700 hover:text-sky-400 transition-colors"
            >
              {{ suggestion }}
            </button>
          </div>
        </div>

        <!-- Message bubbles -->
        <template v-else>
          <div
            v-for="(msg, i) in messages"
            :key="i"
            :class="['flex gap-3', msg.role === 'user' ? 'justify-end' : 'justify-start']"
          >
            <!-- Assistant avatar -->
            <div v-if="msg.role === 'assistant'" class="w-7 h-7 rounded-lg bg-sky-500/20 border border-sky-500/30 flex items-center justify-center flex-shrink-0 mt-0.5">
              <svg class="w-3.5 h-3.5 text-sky-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z" />
              </svg>
            </div>

            <div
              :class="[
                'max-w-[80%] px-4 py-2.5 rounded-2xl text-sm leading-relaxed',
                msg.role === 'user'
                  ? 'bg-sky-600 text-white rounded-tr-sm'
                  : 'bg-gray-800 text-gray-100 rounded-tl-sm'
              ]"
            >
              {{ msg.content }}
            </div>
          </div>

          <!-- Typing indicator -->
          <div v-if="loading" class="flex gap-3 justify-start">
            <div class="w-7 h-7 rounded-lg bg-sky-500/20 border border-sky-500/30 flex items-center justify-center flex-shrink-0">
              <svg class="w-3.5 h-3.5 text-sky-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z" />
              </svg>
            </div>
            <div class="bg-gray-800 px-4 py-3 rounded-2xl rounded-tl-sm flex gap-1 items-center">
              <span class="w-1.5 h-1.5 bg-gray-400 rounded-full animate-bounce" style="animation-delay: 0ms"></span>
              <span class="w-1.5 h-1.5 bg-gray-400 rounded-full animate-bounce" style="animation-delay: 150ms"></span>
              <span class="w-1.5 h-1.5 bg-gray-400 rounded-full animate-bounce" style="animation-delay: 300ms"></span>
            </div>
          </div>
        </template>
      </div>

      <!-- Memory write notification — three states: pending / success / failed -->
      <transition name="fade">
        <!-- Pending: browser write in flight -->
        <div
          v-if="memoryState?.status === 'pending'"
          class="flex items-center gap-2.5 bg-sky-950/40 border border-sky-800/40 rounded-xl px-4 py-3 text-sm"
        >
          <svg class="w-4 h-4 text-sky-400 flex-shrink-0 animate-spin" fill="none" viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
          </svg>
          <span class="text-sky-400">Signing and writing to ICP canister…</span>
        </div>
      </transition>
      <transition name="fade">
        <!-- Success -->
        <div
          v-if="memoryState?.status === 'success'"
          class="flex items-start gap-2.5 bg-emerald-950/60 border border-emerald-800/50 rounded-xl px-4 py-3 text-sm"
        >
          <svg class="w-4 h-4 text-emerald-400 flex-shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
          </svg>
          <div class="flex-1">
            <span class="font-medium" :class="memoryState.source === 'browser' ? 'text-emerald-400' : 'text-amber-400'">
              {{ memoryState.source === 'browser' ? 'Written to ICP (browser-signed):' : 'Stored (mock):' }}
            </span>
            <span
              v-if="memoryState.type"
              :class="[
                'text-[10px] font-mono px-1.5 py-0.5 rounded border ml-1.5',
                memoryState.type === 'sensitive' ? 'bg-red-950/50 border-red-800/40 text-red-400' :
                memoryState.type === 'private'   ? 'bg-sky-950/50 border-sky-800/40 text-sky-400' :
                                                   'bg-gray-800/60 border-gray-700/40 text-gray-400'
              ]"
            >{{ memoryState.type }}</span>
            <span class="text-emerald-300/80 ml-1">{{ memoryState.content }}</span>
            <p v-if="memoryState.source === 'browser'" class="text-emerald-600/60 text-xs mt-0.5 font-mono">
              msg.caller = your browser principal · server did not write this
            </p>
          </div>
        </div>
      </transition>
      <transition name="fade">
        <!-- Failed -->
        <div
          v-if="memoryState?.status === 'failed'"
          class="flex items-start gap-2.5 bg-red-950/50 border border-red-800/40 rounded-xl px-4 py-3 text-sm"
        >
          <svg class="w-4 h-4 text-red-400 flex-shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
          </svg>
          <div class="flex-1">
            <span class="text-red-400 font-medium">ICP write failed:</span>
            <span class="text-red-300/70 ml-1">{{ memoryState.content }}</span>
            <p class="text-red-600/60 text-xs mt-0.5">Summary was extracted but not stored. Check console and canister connection.</p>
          </div>
        </div>
      </transition>

      <!-- Sensitive memory approval — shown instead of auto-signing -->
      <transition name="fade">
        <div
          v-if="pendingApproval"
          class="flex items-start gap-3 bg-yellow-950/50 border border-yellow-700/50 rounded-xl px-4 py-4 text-sm"
          role="alert"
        >
          <svg class="w-4 h-4 text-yellow-400 flex-shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
          </svg>
          <div class="flex-1 min-w-0">
            <p class="text-yellow-300 font-medium mb-1">
              {{ pendingApproval.type === 'sensitive' ? 'Sensitive memory — review before storing' : 'Private memory — review before storing' }}
            </p>
            <p class="text-yellow-200/80 mb-3 italic">"{{ pendingApproval.content }}"</p>
            <p class="text-yellow-600/70 text-xs mb-3 font-mono">
              <template v-if="pendingApproval.type === 'sensitive'">
                The agent flagged this as sensitive. Approving will store it under your principal — only you can read it back. Rejecting discards it permanently.
              </template>
              <template v-else>
                The agent flagged this as private. Approving stores it under your principal — it won't be shared with the LLM or any public endpoint. Rejecting discards it.
              </template>
            </p>
            <div class="flex gap-2">
              <button
                @click="approveMemory"
                class="text-xs bg-emerald-800/60 hover:bg-emerald-700/60 text-emerald-300 border border-emerald-700/50 px-3 py-1.5 rounded-lg transition-colors"
              >
                Sign &amp; store
              </button>
              <button
                @click="rejectMemory"
                class="text-xs bg-red-950/40 hover:bg-red-900/40 text-red-400 border border-red-800/40 px-3 py-1.5 rounded-lg transition-colors"
              >
                Discard
              </button>
            </div>
          </div>
        </div>
      </transition>

      <!-- My Memories — owner-authenticated read (live ICP mode only) -->
      <!-- This call is signed by the browser's Ed25519 identity. The canister returns   -->
      <!-- public + private + sensitive records because msg.caller == user_id.            -->
      <!-- The LLM only ever sees public records. This panel shows the owner everything.  -->
      <div v-if="icpMode === 'icp' && canisterId" class="border border-gray-800 rounded-xl overflow-hidden">
        <button
          @click="toggleMyMemories"
          class="w-full px-4 py-2.5 flex items-center justify-between text-left hover:bg-gray-800/30 transition-colors"
        >
          <div class="flex items-center gap-2">
            <svg class="w-3.5 h-3.5 text-gray-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
            </svg>
            <span class="text-xs font-medium text-gray-400">
              My memories
              <span v-if="myMemories.length" class="text-gray-600 font-normal ml-1">({{ myMemories.length }} · owner-authenticated)</span>
            </span>
          </div>
          <svg
            class="w-3.5 h-3.5 text-gray-600 transition-transform flex-shrink-0"
            :class="{ 'rotate-180': showMyMemories }"
            fill="none" viewBox="0 0 24 24" stroke="currentColor"
          >
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
          </svg>
        </button>

        <div v-if="showMyMemories" class="border-t border-gray-800">
          <!-- Loading -->
          <div v-if="myMemoriesLoading" class="px-4 py-3 text-xs text-gray-500">
            Fetching from canister…
          </div>
          <!-- Error — never show as empty -->
          <div v-else-if="myMemoriesError" class="px-4 py-3 text-xs text-red-400/80">
            Could not load memories: {{ myMemoriesError }}
          </div>
          <!-- Empty -->
          <div v-else-if="myMemories.length === 0" class="px-4 py-3 text-xs text-gray-600">
            No memories stored yet. Send a message to create some.
          </div>
          <!-- Records -->
          <div v-else class="divide-y divide-gray-800/60 max-h-56 overflow-y-auto">
            <div
              v-for="m in myMemories"
              :key="m.id"
              class="px-4 py-2.5 flex items-start gap-2.5"
            >
              <span
                :class="[
                  'text-[10px] font-mono px-1.5 py-0.5 rounded border flex-shrink-0 mt-0.5',
                  m.memory_type === 'sensitive' ? 'bg-red-950/60 border-red-800/50 text-red-400'     :
                  m.memory_type === 'private'   ? 'bg-violet-950/60 border-violet-800/50 text-violet-400' :
                                                  'bg-sky-950/60 border-sky-800/50 text-sky-400'
                ]"
              >{{ m.memory_type }}</span>
              <span class="text-xs text-gray-300 leading-snug">{{ m.content }}</span>
            </div>
          </div>
          <div class="px-4 py-2 border-t border-gray-800/60">
            <p class="text-[10px] text-gray-600 font-mono">
              msg.caller = {{ principal }} · private &amp; sensitive visible only to you · LLM sees public only
            </p>
          </div>
        </div>
      </div>

      <!-- Input -->
      <div class="flex gap-3">
        <input
          v-model="input"
          @keydown.enter.exact.prevent="send"
          :disabled="loading"
          type="text"
          placeholder="Type a message..."
          class="flex-1 bg-gray-900 border border-gray-700 rounded-xl px-4 py-3 text-sm text-gray-100 placeholder-gray-600 focus:outline-none focus:border-sky-600 focus:ring-1 focus:ring-sky-600/30 disabled:opacity-50 transition-colors"
        />
        <button
          @click="send"
          :disabled="loading || !input.trim()"
          class="bg-sky-600 hover:bg-sky-500 disabled:bg-gray-800 disabled:text-gray-600 text-white px-5 py-3 rounded-xl text-sm font-medium transition-colors flex items-center gap-2"
        >
          <svg v-if="!loading" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8" />
          </svg>
          <svg v-else class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
          </svg>
          Send
        </button>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { ref, computed, nextTick, onMounted } from 'vue';
import { usePage, router } from '@inertiajs/vue3';
import axios from 'axios';
import AppLayout from '@/Components/AppLayout.vue';
import { useIcpIdentity } from '@/composables/useIcpIdentity';
import { useIcpMemory } from '@/composables/useIcpMemory';

const props = defineProps({
  session_id:      String,
  user_id:         String,
  identity_source: String,
  messages:        Array,
  llm_provider:    String,
  icp_mode:        String,
  canister_id:     String,
  browser_host:    String,
});

const page = usePage();

// ─── Identity ──────────────────────────────────────────────────────
// Generate (or load) the Ed25519 key pair from localStorage.
// This runs synchronously — the principal is available immediately.
const { identity, principal } = useIcpIdentity();
const identityReady = ref(true);

const displayUserId = computed(() => principal);

const identityTooltip = computed(() =>
  `Ed25519 principal generated in your browser.\nStored in localStorage — the server never has your private key.\nThis is your memory identity in ICP live mode.`
);

// Detect identity divergence: localStorage was cleared but the session still holds
// the old principal. Reads and writes will target different identities until reset.
// Only meaningful when the session has already adopted a browser principal.
const identityDiverged = computed(() =>
  props.identity_source === 'browser' &&
  !!props.user_id &&
  props.user_id !== principal
);

// ─── ICP memory writer (live mode only) ───────────────────────────
const icpMode     = computed(() => props.icp_mode);
const canisterId  = computed(() => props.canister_id || '');
const browserHost = computed(() => props.browser_host || 'http://localhost:4943');

const icpMemory = computed(() =>
  useIcpMemory({
    identity,
    canisterId: canisterId.value,
    host: browserHost.value,
  })
);

// ─── My Memories (owner-authenticated read, live mode only) ────────
const showMyMemories     = ref(false);
const myMemories         = ref([]);
const myMemoriesLoading  = ref(false);
const myMemoriesError    = ref(null);  // string | null — distinct from "no records"

async function toggleMyMemories() {
  showMyMemories.value = !showMyMemories.value;
  // Lazy-load on first open; refresh on subsequent opens to pick up new writes.
  if (showMyMemories.value) {
    myMemoriesLoading.value = true;
    myMemoriesError.value   = null;
    const result = await icpMemory.value.getMyMemories(principal);
    if (result.ok) {
      myMemories.value = result.records;
    } else {
      myMemories.value      = [];
      myMemoriesError.value = result.error;
    }
    myMemoriesLoading.value = false;
  }
}

// ─── Chat state ────────────────────────────────────────────────────
const messages   = ref(props.messages ?? []);
const input      = ref('');
const loading    = ref(false);
const messagesEl = ref(null);

// memoryState drives the three-state memory notification:
//   null                                    — no notification
//   { status: 'pending' }                   — browser write in flight
//   { status: 'success', content, source }  — write confirmed
//   { status: 'failed',  content }          — write failed
const memoryState = ref(null);

// pendingApproval holds a private or sensitive memory waiting for user review.
// Private and Sensitive memories are NOT auto-signed — the user explicitly approves or rejects.
// { content, type, metadata }
const pendingApproval = ref(null);

const suggestions = [
  "My name is Anthony and I build AI tools.",
  "I'm an electrical engineer working in Toronto.",
  "What do you remember about me?",
  "I love distributed systems and open infrastructure.",
];

function useSuggestion(text) {
  input.value = text;
}

async function scrollToBottom() {
  await nextTick();
  if (messagesEl.value) {
    messagesEl.value.scrollTop = messagesEl.value.scrollHeight;
  }
}

function clearMemoryState(delay = 7000) {
  setTimeout(() => { memoryState.value = null; }, delay);
}

// Sign and write a memory to the canister from the browser.
// Called for auto-signed public memories (live mode) and after manual approval (private/sensitive).
async function writeMemoryToBrowser(content, type, metadata) {
  memoryState.value = { status: 'pending' };
  const id = await icpMemory.value.storeMemory({
    sessionId: props.session_id,
    content,
    type:      type ?? 'public',
    metadata:  metadata ?? null,
  });
  if (id) {
    memoryState.value = { status: 'success', content, source: 'browser' };
    // Refresh the owner panel so the new record appears immediately.
    if (showMyMemories.value) {
      const result = await icpMemory.value.getMyMemories(principal);
      if (result.ok) {
        myMemories.value      = result.records;
        myMemoriesError.value = null;
      } else {
        myMemoriesError.value = result.error;
      }
    }
  } else {
    memoryState.value = { status: 'failed', content };
  }
  clearMemoryState();
}

// User approved a Private/Sensitive memory — sign and store it now.
async function approveMemory() {
  const m = pendingApproval.value;
  pendingApproval.value = null;
  if (!m) return;

  if (icpMode.value === 'icp' && canisterId.value) {
    // Live mode: browser signs and writes directly to the canister.
    await writeMemoryToBrowser(m.content, m.type, m.metadata);
  } else {
    // Mock mode: POST to server after user approval — server writes to file cache.
    // Consent behavior is identical to live mode; only the storage destination differs.
    memoryState.value = { status: 'pending' };
    try {
      await axios.post('/chat/store-memory', {
        content:     m.content,
        memory_type: m.type,
        metadata:    m.metadata ?? null,
      });
      memoryState.value = { status: 'success', content: m.content, source: 'server', type: m.type };
    } catch {
      memoryState.value = { status: 'failed', content: m.content };
    }
    clearMemoryState();
  }
}

// User rejected the sensitive memory — discard it silently.
function rejectMemory() {
  pendingApproval.value = null;
}

async function send() {
  const text = input.value.trim();
  if (!text || loading.value) return;

  messages.value.push({ role: 'user', content: text });
  input.value = '';
  loading.value = true;
  memoryState.value = null;
  pendingApproval.value = null;
  await scrollToBottom();

  try {
    const { data } = await axios.post('/chat/send', {
      message:   text,
      principal: principal,
    });

    messages.value.push({ role: 'assistant', content: data.message });

    if (data.memory) {
      if (data.memory_type !== 'public') {
        // Private and Sensitive always require user review before storing — in both modes.
        // Live mode: user approves → browser signs → writes to canister.
        // Mock mode: user approves → browser POSTs to /chat/store-memory → file cache write.
        pendingApproval.value = {
          content:  data.memory,
          type:     data.memory_type,
          metadata: data.memory_metadata ?? null,
        };
      } else if (icpMode.value === 'icp' && canisterId.value) {
        // Public in live mode: auto-sign and write to canister.
        await writeMemoryToBrowser(data.memory, data.memory_type, data.memory_metadata ?? null);
      } else {
        // Public in mock mode: server already wrote. Report success.
        memoryState.value = {
          status:  'success',
          content: data.memory,
          source:  'server',
          type:    data.memory_type,
        };
        clearMemoryState();
      }
    }
  } catch (err) {
    messages.value.push({
      role: 'assistant',
      content: 'Something went wrong. Please try again.',
    });
  } finally {
    loading.value = false;
    await scrollToBottom();
  }
}

function resetSession() {
  if (confirm(
    'Start a new session? Chat history will be cleared.\n\n' +
    'Your identity and memory are preserved — the agent will still remember you.'
  )) {
    router.post('/chat/reset');
  }
}

onMounted(() => scrollToBottom());
</script>

<style scoped>
.fade-enter-active, .fade-leave-active {
  transition: opacity 0.3s ease, transform 0.3s ease;
}
.fade-enter-from, .fade-leave-to {
  opacity: 0;
  transform: translateY(4px);
}
</style>
