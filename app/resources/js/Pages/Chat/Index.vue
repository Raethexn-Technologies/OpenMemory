<template>
  <AppLayout>
    <div class="flex-1 flex flex-col max-w-4xl mx-auto w-full px-4 py-6 gap-4">

      <!-- Header row -->
      <div class="flex items-center justify-between">
        <div>
          <h1 class="text-lg font-semibold text-gray-100">Chat</h1>
          <p class="text-xs text-gray-500 mt-0.5">
            Identity: <code class="text-sky-400/80 font-mono">{{ props.user_id }}</code>
            · Provider: <span class="text-sky-400/80">{{ props.llm_provider }}</span>
          </p>
        </div>
        <div class="flex items-center gap-2">
          <!-- Mode badge — always truthful -->
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

      <!-- Memory flash notification -->
      <transition name="fade">
        <div
          v-if="lastMemory"
          class="flex items-start gap-2.5 bg-emerald-950/60 border border-emerald-800/50 rounded-xl px-4 py-3 text-sm"
        >
          <svg class="w-4 h-4 text-emerald-400 flex-shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
          </svg>
          <div>
            <span class="text-emerald-400 font-medium">
              Memory stored{{ props.icp_mode === 'icp' ? ' on ICP' : ' (mock)' }}:
            </span>
            <span class="text-emerald-300/80 ml-1">{{ lastMemory }}</span>
          </div>
        </div>
      </transition>

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
import { ref, nextTick, onMounted } from 'vue';
import { router } from '@inertiajs/vue3';
import axios from 'axios';
import AppLayout from '@/Components/AppLayout.vue';

const props = defineProps({
  session_id: String,
  user_id: String,
  messages: Array,
  llm_provider: String,
  icp_mode: String,
});

const messages = ref(props.messages ?? []);
const input = ref('');
const loading = ref(false);
const lastMemory = ref(null);
const messagesEl = ref(null);

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

async function send() {
  const text = input.value.trim();
  if (!text || loading.value) return;

  messages.value.push({ role: 'user', content: text });
  input.value = '';
  loading.value = true;
  lastMemory.value = null;
  await scrollToBottom();

  try {
    const { data } = await axios.post('/chat/send', { message: text });
    messages.value.push({ role: 'assistant', content: data.message });

    if (data.memory) {
      lastMemory.value = data.memory;
      setTimeout(() => { lastMemory.value = null; }, 6000);
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
  if (confirm('Start a new session? Chat history will be cleared.\n\nYour identity and memory are preserved — the agent will still remember you.')) {
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
