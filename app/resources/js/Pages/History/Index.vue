<template>
  <AppLayout>
    <Head title="History" />

    <div class="flex-1 max-w-6xl w-full mx-auto px-4 py-8">
      <!-- Header -->
      <header class="mb-8">
        <h1 class="text-2xl font-semibold text-gray-100 tracking-tight">Your AI history</h1>
        <p class="mt-1.5 text-sm text-gray-400 max-w-2xl">
          Conversations imported from the assistants you use, held in one place you control. Nothing here is
          shared with the memory graph, the chat assistant, or any connected agent.
        </p>
      </header>

      <!-- Empty state -->
      <section v-if="overview.conversation_count === 0" class="rounded-xl border border-gray-800 bg-gray-900/40 p-6">
        <h2 class="text-base font-medium text-gray-100">No history imported yet</h2>
        <p class="mt-2 text-sm text-gray-400 max-w-2xl">
          Request an export from each provider, then import the archive from a terminal. Archives are read where
          they already sit on disk. Nothing is uploaded, and parsing happens entirely on this machine.
        </p>

        <div class="mt-5 space-y-4">
          <div v-for="guide in exportGuides" :key="guide.provider" class="rounded-lg border border-gray-800 bg-gray-950/60 p-4">
            <div class="flex items-center gap-2">
              <span class="w-2 h-2 rounded-full" :class="providerDot(guide.provider)"></span>
              <h3 class="text-sm font-medium text-gray-200">{{ guide.name }}</h3>
            </div>
            <p class="mt-1.5 text-sm text-gray-400">{{ guide.steps }}</p>
            <code class="mt-2 block text-xs font-mono text-sky-300 bg-gray-900/80 rounded px-2.5 py-1.5 overflow-x-auto">{{ guide.command }}</code>
          </div>
        </div>
      </section>

      <template v-else>
        <!-- Corpus summary -->
        <section class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-8">
          <StatCard label="Conversations" :value="formatNumber(overview.conversation_count)" />
          <StatCard label="Messages" :value="formatNumber(overview.message_count)" />
          <StatCard label="Providers" :value="String(overview.provider_count)" />
          <StatCard label="Span" :value="spanLabel" />
        </section>

        <!-- Per-provider breakdown -->
        <section class="mb-8">
          <h2 class="text-xs font-medium uppercase tracking-wider text-gray-500 mb-2.5">By provider</h2>
          <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
            <div
              v-for="row in overview.providers"
              :key="row.provider"
              class="rounded-lg border border-gray-800 bg-gray-900/40 px-3.5 py-3"
            >
              <div class="flex items-center gap-2">
                <span class="w-2 h-2 rounded-full" :class="providerDot(row.provider)"></span>
                <span class="text-sm font-medium text-gray-200">{{ providerName(row.provider) }}</span>
              </div>
              <p class="mt-1 text-xs text-gray-400 font-mono">
                {{ formatNumber(row.conversation_count) }} conversations · {{ formatNumber(row.message_count) }} messages
              </p>
              <p class="mt-0.5 text-xs text-gray-500">{{ shortDate(row.first_at) }} to {{ shortDate(row.last_at) }}</p>
            </div>
          </div>
        </section>

        <!-- Tabs -->
        <nav class="flex gap-1 border-b border-gray-800 mb-6">
          <button
            v-for="tab in tabs"
            :key="tab.id"
            type="button"
            class="px-3.5 py-2 text-sm border-b-2 -mb-px transition-colors"
            :class="activeTab === tab.id
              ? 'border-sky-500 text-sky-400'
              : 'border-transparent text-gray-400 hover:text-gray-200'"
            @click="activeTab = tab.id"
          >
            {{ tab.label }}
          </button>
        </nav>

        <!-- Ask -->
        <section v-show="activeTab === 'ask'">
          <form class="flex flex-col sm:flex-row gap-2" @submit.prevent="runAsk">
            <input
              v-model="question"
              type="text"
              placeholder="What have I kept coming back to over the last two years?"
              class="flex-1 rounded-lg bg-gray-900 border border-gray-800 px-3.5 py-2.5 text-sm text-gray-100 placeholder-gray-600 focus:outline-none focus:border-sky-600"
            />
            <button
              type="submit"
              :disabled="askLoading || question.trim().length < 3"
              class="rounded-lg bg-sky-600 hover:bg-sky-500 disabled:bg-gray-800 disabled:text-gray-600 px-4 py-2.5 text-sm font-medium text-white transition-colors"
            >
              {{ askLoading ? 'Searching...' : 'Ask' }}
            </button>
          </form>

          <div class="mt-3 flex flex-wrap gap-1.5">
            <button
              v-for="example in exampleQuestions"
              :key="example"
              type="button"
              class="text-xs rounded-full border border-gray-800 bg-gray-900/60 text-gray-400 hover:text-gray-200 hover:border-gray-700 px-2.5 py-1 transition-colors"
              @click="question = example"
            >
              {{ example }}
            </button>
          </div>

          <p v-if="!answerGenerationEnabled" class="mt-4 text-xs text-amber-400/90">
            Answer generation is switched off. Questions return the matching excerpts and nothing is sent to a model.
          </p>

          <div v-if="askResult" class="mt-6 space-y-5">
            <p class="text-xs font-mono text-gray-500">
              {{ askResult.matched_count }} matching messages from {{ askResult.candidate_count }} scanned ·
              terms: {{ askResult.terms.join(', ') || 'none' }}
            </p>

            <div v-if="askResult.answer_state === 'no_evidence'" class="rounded-lg border border-gray-800 bg-gray-900/40 p-4 text-sm text-gray-400">
              Nothing in the imported history matches that question. The search is exact-term based, so a rephrasing
              using words you would actually have typed may find it.
            </div>

            <div v-if="askResult.answer" class="rounded-lg border border-gray-800 bg-gray-900/40 p-4">
              <p class="text-sm text-gray-200 whitespace-pre-wrap leading-relaxed">{{ askResult.answer }}</p>
              <p v-if="askResult.unresolved_citations.length" class="mt-3 text-xs text-amber-400">
                {{ askResult.unresolved_citations.length }} citation(s) in this answer did not refer to any retrieved
                excerpt and were removed.
              </p>
            </div>

            <div v-if="askResult.evidence.length">
              <h3 class="text-xs font-medium uppercase tracking-wider text-gray-500 mb-2.5">
                Evidence ({{ askResult.evidence.length }})
              </h3>
              <ol class="space-y-2">
                <li
                  v-for="(item, index) in askResult.evidence"
                  :key="item.message_id"
                  class="rounded-lg border border-gray-800 bg-gray-900/40 p-3.5"
                >
                  <div class="flex items-center gap-2 flex-wrap text-xs">
                    <span class="font-mono text-sky-400">E{{ index + 1 }}</span>
                    <span class="w-1.5 h-1.5 rounded-full" :class="providerDot(item.provider)"></span>
                    <span class="text-gray-400">{{ providerName(item.provider) }}</span>
                    <span class="text-gray-600">·</span>
                    <span class="text-gray-400 font-mono">{{ shortDate(item.occurred_at) }}</span>
                    <span class="text-gray-600">·</span>
                    <span class="text-gray-500">{{ item.role }}</span>
                  </div>
                  <p class="mt-2 text-sm text-gray-300 leading-relaxed">{{ item.excerpt }}</p>
                  <Link
                    :href="`/history/conversations/${item.conversation_id}`"
                    class="mt-2 inline-block text-xs text-sky-400 hover:text-sky-300"
                  >
                    Open {{ item.title || 'this conversation' }}
                  </Link>
                </li>
              </ol>
            </div>
          </div>
        </section>

        <!-- Explore -->
        <section v-show="activeTab === 'explore'">
          <div class="flex flex-col sm:flex-row gap-2 mb-4">
            <input
              v-model="titleSearch"
              type="text"
              placeholder="Filter by conversation title"
              class="flex-1 rounded-lg bg-gray-900 border border-gray-800 px-3.5 py-2.5 text-sm text-gray-100 placeholder-gray-600 focus:outline-none focus:border-sky-600"
              @keyup.enter="loadConversations(1)"
            />
            <select
              v-model="providerFilter"
              class="rounded-lg bg-gray-900 border border-gray-800 px-3 py-2.5 text-sm text-gray-200 focus:outline-none focus:border-sky-600"
              @change="loadConversations(1)"
            >
              <option value="">All providers</option>
              <option v-for="row in overview.providers" :key="row.provider" :value="row.provider">
                {{ providerName(row.provider) }}
              </option>
            </select>
            <button
              type="button"
              class="rounded-lg border border-gray-800 bg-gray-900 hover:border-gray-700 px-4 py-2.5 text-sm text-gray-200 transition-colors"
              @click="loadConversations(1)"
            >
              Apply
            </button>
          </div>

          <p class="text-xs font-mono text-gray-500 mb-2.5">
            {{ formatNumber(page.total) }} conversations · page {{ page.page }} of {{ page.last_page }}
          </p>

          <ul class="space-y-1.5">
            <li v-for="row in page.data" :key="row.id">
              <Link
                :href="`/history/conversations/${row.id}`"
                class="block rounded-lg border border-gray-800 bg-gray-900/40 hover:border-gray-700 hover:bg-gray-900/70 px-3.5 py-3 transition-colors"
              >
                <div class="flex items-start justify-between gap-3">
                  <span class="text-sm text-gray-200">{{ row.title || 'Untitled conversation' }}</span>
                  <span class="text-xs font-mono text-gray-500 shrink-0">{{ shortDate(row.first_message_at) }}</span>
                </div>
                <div class="mt-1.5 flex items-center gap-2 flex-wrap text-xs text-gray-500">
                  <span class="w-1.5 h-1.5 rounded-full" :class="providerDot(row.provider)"></span>
                  <span>{{ providerName(row.provider) }}</span>
                  <span>·</span>
                  <span>{{ row.message_count }} messages</span>
                  <span v-if="row.grain === 'activity_record'" class="rounded bg-gray-800 px-1.5 py-0.5 text-gray-400">
                    activity record
                  </span>
                  <span v-if="row.redaction" class="rounded bg-amber-950/60 text-amber-400 px-1.5 py-0.5">redacted</span>
                </div>
              </Link>
            </li>
          </ul>

          <div v-if="page.last_page > 1" class="mt-4 flex items-center gap-2">
            <button
              type="button"
              :disabled="page.page <= 1"
              class="rounded-lg border border-gray-800 bg-gray-900 disabled:opacity-40 px-3 py-1.5 text-sm text-gray-200"
              @click="loadConversations(page.page - 1)"
            >
              Previous
            </button>
            <button
              type="button"
              :disabled="page.page >= page.last_page"
              class="rounded-lg border border-gray-800 bg-gray-900 disabled:opacity-40 px-3 py-1.5 text-sm text-gray-200"
              @click="loadConversations(page.page + 1)"
            >
              Next
            </button>
          </div>
        </section>

        <!-- Timeline -->
        <section v-show="activeTab === 'timeline'">
          <form class="flex flex-col sm:flex-row gap-2" @submit.prevent="runTimeline">
            <input
              v-model="subject"
              type="text"
              placeholder="Track a subject through the corpus"
              class="flex-1 rounded-lg bg-gray-900 border border-gray-800 px-3.5 py-2.5 text-sm text-gray-100 placeholder-gray-600 focus:outline-none focus:border-sky-600"
            />
            <button
              type="submit"
              :disabled="timelineLoading || subject.trim().length < 2"
              class="rounded-lg bg-sky-600 hover:bg-sky-500 disabled:bg-gray-800 disabled:text-gray-600 px-4 py-2.5 text-sm font-medium text-white transition-colors"
            >
              {{ timelineLoading ? 'Counting...' : 'Track' }}
            </button>
          </form>

          <div v-if="suggestedSubjects.length" class="mt-3 flex flex-wrap gap-1.5">
            <button
              v-for="item in suggestedSubjects"
              :key="item.term"
              type="button"
              class="text-xs rounded-full border border-gray-800 bg-gray-900/60 text-gray-400 hover:text-gray-200 hover:border-gray-700 px-2.5 py-1 transition-colors"
              @click="subject = item.term; runTimeline()"
            >
              {{ item.term }}
              <span class="text-gray-600 font-mono">{{ item.conversations }}</span>
            </button>
          </div>

          <div v-if="timeline" class="mt-6">
            <p v-if="timeline.total_messages === 0" class="text-sm text-gray-400">
              No message in the imported history contains that term.
            </p>

            <template v-else>
              <p class="text-sm text-gray-300">
                <span class="font-mono text-sky-400">{{ timeline.total_messages }}</span> messages across
                <span class="font-mono text-sky-400">{{ timeline.total_conversations }}</span> conversations,
                first on {{ shortDate(timeline.first_at) }}, most recently {{ shortDate(timeline.last_at) }}.
                <span v-if="timeline.dormant_months > 0">
                  {{ timeline.dormant_months }} months in that span contain no mention.
                </span>
              </p>

              <div class="mt-5 flex items-end gap-1 h-32 overflow-x-auto pb-1">
                <div
                  v-for="bucket in timeline.months"
                  :key="bucket.month"
                  class="flex flex-col items-center gap-1 shrink-0"
                  :title="`${bucket.month}: ${bucket.messages} messages`"
                >
                  <div
                    class="w-5 rounded-t bg-sky-600/70"
                    :style="{ height: `${Math.max(4, (bucket.messages / timelinePeak) * 100)}px` }"
                  ></div>
                  <span class="text-[10px] font-mono text-gray-600 rotate-45 origin-left w-5">{{ bucket.month.slice(2) }}</span>
                </div>
              </div>

              <div class="mt-8 flex flex-wrap gap-2">
                <span
                  v-for="(count, provider) in timeline.providers"
                  :key="provider"
                  class="text-xs rounded-full border border-gray-800 bg-gray-900/60 px-2.5 py-1 text-gray-400"
                >
                  {{ providerName(provider) }}
                  <span class="font-mono text-gray-500">{{ count }}</span>
                </span>
              </div>

              <p v-if="timeline.pool_exhausted" class="mt-3 text-xs text-amber-400/90">
                This term matches more messages than one pass examines, so the counts are a lower bound.
              </p>
            </template>
          </div>
        </section>

        <!-- Imports -->
        <section v-show="activeTab === 'imports'">
          <ul class="space-y-2">
            <li v-for="row in imports" :key="row.id" class="rounded-lg border border-gray-800 bg-gray-900/40 p-3.5">
              <div class="flex items-center gap-2 flex-wrap text-sm">
                <span class="w-1.5 h-1.5 rounded-full" :class="providerDot(row.provider)"></span>
                <span class="text-gray-200">{{ row.source_label }}</span>
                <span
                  class="text-xs rounded px-1.5 py-0.5"
                  :class="row.status === 'completed' ? 'bg-emerald-950/60 text-emerald-400' : 'bg-red-950/60 text-red-400'"
                >
                  {{ row.status }}
                </span>
                <span class="text-xs font-mono text-gray-500">{{ row.adapter_version }}</span>
              </div>

              <p v-if="row.stats" class="mt-2 text-xs font-mono text-gray-400">
                {{ row.stats.conversations_seen }} seen · {{ row.stats.conversations_new }} new ·
                {{ row.stats.conversations_updated }} changed · {{ row.stats.conversations_unchanged }} already known ·
                {{ row.stats.raw_records_stored }} sources stored
              </p>

              <p v-if="row.source_sha256" class="mt-1 text-xs font-mono text-gray-600 truncate">
                sha256 {{ row.source_sha256 }}
              </p>

              <ul v-if="row.warnings.length" class="mt-2 space-y-1">
                <li v-for="(warning, index) in row.warnings.slice(0, 5)" :key="index" class="text-xs text-amber-400/90">
                  {{ warning }}
                </li>
              </ul>

              <p v-if="row.error" class="mt-2 text-xs text-red-400">{{ row.error }}</p>
            </li>
          </ul>
        </section>
      </template>
    </div>
  </AppLayout>
</template>

<script setup>
import { computed, ref } from 'vue'
import { Head, Link } from '@inertiajs/vue3'
import AppLayout from '../../Components/AppLayout.vue'
import StatCard from '../../Components/StatCard.vue'

const props = defineProps({
  overview: { type: Object, required: true },
  suggested_subjects: { type: Array, default: () => [] },
  imports: { type: Array, default: () => [] },
  conversations: { type: Object, required: true },
  filters: { type: Object, default: () => ({}) },
  answer_generation_enabled: { type: Boolean, default: true },
})

const overview = computed(() => props.overview)
const imports = computed(() => props.imports)
const suggestedSubjects = computed(() => props.suggested_subjects)
const answerGenerationEnabled = computed(() => props.answer_generation_enabled)

const tabs = [
  { id: 'ask', label: 'Ask' },
  { id: 'explore', label: 'Explore' },
  { id: 'timeline', label: 'Timeline' },
  { id: 'imports', label: 'Imports' },
]
const activeTab = ref('ask')

// Ask.
const question = ref('')
const askLoading = ref(false)
const askResult = ref(null)

const exampleQuestions = [
  'What have I asked for advice about more than once?',
  'What did I plan to do but never mention again?',
  'How has the way I describe my work changed?',
  'Where have I changed my mind?',
]

const runAsk = async () => {
  askLoading.value = true
  askResult.value = null

  try {
    askResult.value = await post('/api/history/ask', { question: question.value })
  } catch {
    askResult.value = null
  } finally {
    askLoading.value = false
  }
}

// Explore.
const providerFilter = ref(props.filters.provider ?? '')
const titleSearch = ref(props.filters.search ?? '')
const page = ref(props.conversations)

const loadConversations = async (target) => {
  const params = new URLSearchParams({ page: String(Math.max(1, target)) })
  if (providerFilter.value) params.set('provider', providerFilter.value)
  if (titleSearch.value) params.set('search', titleSearch.value)

  try {
    const res = await fetch(`/api/history/conversations?${params}`, {
      headers: { Accept: 'application/json' },
    })
    const data = await res.json()
    page.value = data.conversations
  } catch {
    // Leave the current page in place on a network failure.
  }
}

// Timeline.
const subject = ref('')
const timeline = ref(null)
const timelineLoading = ref(false)

const timelinePeak = computed(() => {
  const months = timeline.value?.months ?? []
  return months.reduce((peak, bucket) => Math.max(peak, bucket.messages), 1)
})

const runTimeline = async () => {
  timelineLoading.value = true

  try {
    timeline.value = await post('/api/history/timeline', { subject: subject.value })
  } catch {
    timeline.value = null
  } finally {
    timelineLoading.value = false
  }
}

const post = async (url, body) => {
  const res = await fetch(url, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      Accept: 'application/json',
      'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
    },
    body: JSON.stringify(body),
  })

  return res.json()
}

// Presentation helpers.
const providerNames = {
  chatgpt: 'ChatGPT',
  claude: 'Claude',
  gemini: 'Gemini',
}

const providerDots = {
  chatgpt: 'bg-emerald-400',
  claude: 'bg-orange-400',
  gemini: 'bg-sky-400',
}

const providerName = (key) => providerNames[key] ?? key
const providerDot = (key) => providerDots[key] ?? 'bg-gray-500'

const formatNumber = (value) => new Intl.NumberFormat().format(value ?? 0)

const shortDate = (iso) => {
  if (!iso) return 'unknown'
  return new Date(iso).toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' })
}

const spanLabel = computed(() => {
  if (!overview.value.first_at || !overview.value.last_at) return 'unknown'
  const start = new Date(overview.value.first_at)
  const end = new Date(overview.value.last_at)
  const months = Math.max(1, Math.round((end - start) / (1000 * 60 * 60 * 24 * 30.44)))
  return months >= 24 ? `${Math.floor(months / 12)} years` : `${months} months`
})

const exportGuides = [
  {
    provider: 'chatgpt',
    name: 'ChatGPT',
    steps: 'Settings, then Data Controls, then Export data. OpenAI emails a link to a ZIP containing conversations.json.',
    command: 'php artisan memory:import-archive ~/Downloads/chatgpt-export.zip --user=<your-id>',
  },
  {
    provider: 'claude',
    name: 'Claude',
    steps: 'Settings, then Privacy, then Export data, on the web or desktop app. The emailed link expires after 24 hours.',
    command: 'php artisan memory:import-archive ~/Downloads/claude-export.zip --user=<your-id>',
  },
  {
    provider: 'gemini',
    name: 'Gemini',
    steps: 'takeout.google.com, deselect all, choose My Activity, select only Gemini Apps, and change the activity record format from HTML to JSON.',
    command: 'php artisan memory:import-archive ~/Downloads/takeout.zip --user=<your-id>',
  },
]
</script>
