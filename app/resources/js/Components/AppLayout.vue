<template>
  <div class="min-h-screen bg-gray-950 flex flex-col">
    <!-- Nav -->
    <nav class="border-b border-gray-800 bg-gray-900/50 backdrop-blur-sm sticky top-0 z-50">
      <div class="max-w-6xl mx-auto px-4 h-14 flex items-center justify-between">
        <!-- Logo -->
        <a href="/chat" class="flex items-center gap-2.5 group">
          <div class="w-7 h-7 rounded-lg bg-sky-500/20 border border-sky-500/40 flex items-center justify-center">
            <svg class="w-4 h-4 text-sky-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z" />
            </svg>
          </div>
          <span class="font-semibold text-gray-100 text-sm tracking-tight group-hover:text-sky-400 transition-colors">
            OpenMemory
          </span>
        </a>

        <!-- Nav links -->
        <div class="flex items-center gap-1">
          <NavLink href="/chat"    :active="$page.url.startsWith('/chat')">Chat</NavLink>
          <NavLink href="/history" :active="$page.url.startsWith('/history')">History</NavLink>
          <NavLink href="/native-memory" :active="$page.url.startsWith('/native-memory')">Native memory</NavLink>
          <NavLink href="/memory"  :active="$page.url.startsWith('/memory')">Legacy memory</NavLink>
          <NavLink href="/graph"   :active="$page.url.startsWith('/graph')">Graph</NavLink>
          <NavLink href="/agents"  :active="$page.url.startsWith('/agents')">Agents</NavLink>
          <NavLink href="/3d"      :active="$page.url === '/3d'">3D</NavLink>
        </div>

        <!-- Global mode badge — honest about what's connected -->
        <div class="flex items-center gap-2">
          <button v-if="$page.props.auth?.user" type="button" class="text-sm text-gray-300" @click="router.post('/logout')">Sign out of OpenMemory</button>
          <span
            :class="[
              'flex items-center gap-1.5 text-xs px-2.5 py-1 rounded-full border font-mono',
              isMock
                ? 'bg-amber-950/60 border-amber-800/50 text-amber-400'
                : 'bg-emerald-950/60 border-emerald-800/50 text-emerald-400'
            ]"
          >
            <span class="w-1.5 h-1.5 rounded-full" :class="isMock ? 'bg-amber-500' : 'bg-emerald-500 animate-pulse'"></span>
            {{ $page.url.startsWith('/native-memory') ? 'Local SQL' : (isMock ? 'Mock memory' : 'ICP Live') }}
          </span>
        </div>
      </div>
    </nav>

    <!-- Page content -->
    <main class="flex-1 flex flex-col">
      <slot />
    </main>
  </div>
</template>

<script setup>
import { computed } from 'vue';
import { usePage, router } from '@inertiajs/vue3';
import NavLink from './NavLink.vue';

const page = usePage();
const isMock = computed(() => page.props.icp?.mode !== 'icp');
</script>
