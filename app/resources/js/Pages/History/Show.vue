<template>
  <AppLayout>
    <Head :title="conversation.title || 'Conversation'" />

    <div class="flex-1 max-w-4xl w-full mx-auto px-4 py-8">
      <Link href="/history" class="text-xs text-sky-400 hover:text-sky-300">Back to history</Link>

      <!-- Header -->
      <header class="mt-3 mb-6">
        <h1 class="text-xl font-semibold text-gray-100 tracking-tight">
          {{ conversation.title || 'Untitled conversation' }}
        </h1>

        <div class="mt-2 flex items-center gap-2 flex-wrap text-xs text-gray-500">
          <span class="w-1.5 h-1.5 rounded-full" :class="providerDot(conversation.provider)"></span>
          <span>{{ providerName(conversation.provider) }}</span>
          <span>·</span>
          <span class="font-mono">{{ longDate(conversation.first_message_at || conversation.provider_created_at) }}</span>
          <span>·</span>
          <span>{{ conversation.message_count }} messages</span>
          <span v-if="conversation.workspace_label">·</span>
          <span v-if="conversation.workspace_label">{{ conversation.workspace_label }}</span>
          <span v-if="conversation.grain === 'activity_record'" class="rounded bg-gray-800 px-1.5 py-0.5 text-gray-400">
            activity record
          </span>
        </div>

        <p v-if="conversation.models?.length" class="mt-1 text-xs font-mono text-gray-600">
          models: {{ conversation.models.join(', ') }}
        </p>

        <p v-if="conversation.grain === 'activity_record'" class="mt-3 text-xs text-gray-400 max-w-2xl">
          Google Takeout exports Gemini history as an activity log rather than as threads, so each record is a single
          turn. Turns are not grouped into conversations here because the export carries nothing that says which turns
          belonged together, and inventing that grouping would misrepresent the record.
        </p>
      </header>

      <!-- Provenance -->
      <section v-if="importRow" class="mb-6 rounded-lg border border-gray-800 bg-gray-900/40 px-3.5 py-3">
        <h2 class="text-xs font-medium uppercase tracking-wider text-gray-500">Source</h2>
        <p class="mt-1.5 text-xs font-mono text-gray-400 break-all">
          {{ importRow.source_label }} · parsed by {{ importRow.adapter_version }}
        </p>
        <p v-if="importRow.source_sha256" class="text-xs font-mono text-gray-600 break-all">
          sha256 {{ importRow.source_sha256 }}
        </p>

        <button
          v-if="hasRawRecord"
          type="button"
          class="mt-2.5 text-xs text-sky-400 hover:text-sky-300"
          @click="toggleRaw"
        >
          {{ showRaw ? 'Hide preserved source' : 'View preserved source' }}
        </button>

        <p v-if="showRaw" class="mt-2 text-xs text-amber-400/90">
          This is the exact provider JSON, before redaction. It is stored so that nothing derived becomes the only
          surviving copy of what you said, and it is served only here, one conversation at a time.
        </p>

        <pre
          v-if="showRaw && rawPayload"
          class="mt-2 max-h-96 overflow-auto rounded bg-gray-950 border border-gray-800 p-3 text-[11px] font-mono text-gray-400"
        >{{ rawPayload }}</pre>
      </section>

      <!-- Messages -->
      <ol class="space-y-3">
        <li
          v-for="message in messages"
          :key="message.id"
          class="rounded-lg border px-4 py-3"
          :class="message.on_active_path
            ? 'border-gray-800 bg-gray-900/40'
            : 'border-gray-800/60 bg-gray-900/20 border-dashed'"
        >
          <div class="flex items-center gap-2 flex-wrap text-xs">
            <span class="font-medium" :class="roleColor(message.role)">{{ roleLabel(message) }}</span>
            <span class="text-gray-600 font-mono">{{ longDate(message.occurred_at) }}</span>
            <span v-if="message.model_slug" class="text-gray-600 font-mono">{{ message.model_slug }}</span>
            <span v-if="!message.on_active_path" class="rounded bg-gray-800 px-1.5 py-0.5 text-gray-400">
              edited-away branch
            </span>
            <span v-if="message.redaction" class="rounded bg-amber-950/60 px-1.5 py-0.5 text-amber-400">
              redacted: {{ message.redaction.categories.join(', ') }}
            </span>
          </div>

          <p class="mt-2 text-sm text-gray-200 whitespace-pre-wrap leading-relaxed">{{ message.content_text }}</p>

          <div v-if="message.attachments.length" class="mt-2.5 flex flex-wrap gap-1.5">
            <span
              v-for="(attachment, index) in message.attachments"
              :key="index"
              class="text-xs rounded border border-gray-800 bg-gray-950 px-2 py-1 text-gray-400"
            >
              {{ attachment.name || attachment.media_type || 'attachment' }}
              <span v-if="attachment.size_bytes" class="text-gray-600 font-mono">{{ kilobytes(attachment.size_bytes) }}</span>
            </span>
          </div>

          <p v-if="nonTextBlocks(message).length" class="mt-2 text-xs text-gray-600 font-mono">
            also carried: {{ nonTextBlocks(message).join(', ') }}
          </p>
        </li>
      </ol>

      <p v-if="!messages.length" class="rounded-lg border border-gray-800 bg-gray-900/40 p-4 text-sm text-gray-400">
        This conversation was exported with no readable messages. It is kept so the conversation count matches what
        the provider reported.
      </p>
    </div>
  </AppLayout>
</template>

<script setup>
import { ref } from 'vue'
import { Head, Link } from '@inertiajs/vue3'
import AppLayout from '../../Components/AppLayout.vue'

const props = defineProps({
  conversation: { type: Object, required: true },
  messages: { type: Array, default: () => [] },
  has_raw_record: { type: Boolean, default: false },
  import: { type: Object, default: null },
})

const conversation = props.conversation
const messages = props.messages
const hasRawRecord = props.has_raw_record
const importRow = props.import

const showRaw = ref(false)
const rawPayload = ref(null)

const toggleRaw = async () => {
  showRaw.value = !showRaw.value

  if (!showRaw.value || rawPayload.value) {
    return
  }

  try {
    const res = await fetch(`/api/history/conversations/${conversation.id}/raw`, {
      headers: { Accept: 'application/json' },
    })
    const data = await res.json()
    rawPayload.value = data.payload
      ? JSON.stringify(JSON.parse(data.payload), null, 2)
      : data.error
  } catch {
    rawPayload.value = 'The preserved source could not be loaded.'
  }
}

const providerNames = { chatgpt: 'ChatGPT', claude: 'Claude', gemini: 'Gemini' }
const providerDots = { chatgpt: 'bg-emerald-400', claude: 'bg-orange-400', gemini: 'bg-sky-400' }

const providerName = (key) => providerNames[key] ?? key
const providerDot = (key) => providerDots[key] ?? 'bg-gray-500'

const roleLabels = { user: 'You', assistant: 'Assistant', system: 'System', tool: 'Tool', unknown: 'Unattributed' }
const roleColors = {
  user: 'text-sky-400',
  assistant: 'text-emerald-400',
  system: 'text-gray-500',
  tool: 'text-violet-400',
  unknown: 'text-amber-400',
}

const roleLabel = (message) => {
  const base = roleLabels[message.role] ?? message.role
  return message.author_name ? `${base} (${message.author_name})` : base
}

const roleColor = (role) => roleColors[role] ?? 'text-gray-400'

const longDate = (iso) => {
  if (!iso) return 'no recorded time'
  return new Date(iso).toLocaleString(undefined, {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  })
}

const kilobytes = (bytes) => `${Math.max(1, Math.round(bytes / 1024))} KB`

// Reasoning traces and tool payloads are recorded structurally rather than
// inlined into the body, so the reader can still see that they were there.
const nonTextBlocks = (message) =>
  (message.content_blocks ?? [])
    .filter((block) => block.type && block.type !== 'text')
    .map((block) => block.name ? `${block.type} (${block.name})` : block.type)
</script>
