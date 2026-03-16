<template>
  <div class="flex h-screen bg-slate-950 text-slate-100 overflow-hidden">

    <!-- ── Left panel: Explorer / Filters ── -->
    <aside class="w-60 flex-shrink-0 border-r border-slate-800 flex flex-col bg-slate-900">
      <div class="px-4 py-4 border-b border-slate-800">
        <div class="flex items-center gap-2 mb-1 flex-wrap">
          <a href="/chat" class="text-slate-500 hover:text-slate-300 text-sm">← Chat</a>
          <span class="text-slate-700">|</span>
          <a href="/memory" class="text-slate-500 hover:text-slate-300 text-sm">Memory</a>
          <span class="text-slate-700">|</span>
          <a href="/agents" class="text-slate-500 hover:text-slate-300 text-sm">Agents</a>
          <span class="text-slate-700">|</span>
          <a href="/3d" class="text-slate-500 hover:text-slate-300 text-sm">3D</a>
        </div>
        <h1 class="text-base font-semibold text-sky-400">Memory Graph</h1>
        <p class="text-xs text-slate-500 mt-0.5">Physarum-weighted node explorer</p>
      </div>

      <!-- Search -->
      <div class="px-3 py-3 border-b border-slate-800">
        <input
          v-model="searchQuery"
          type="text"
          placeholder="Search nodes…"
          class="w-full px-3 py-1.5 text-sm bg-slate-800 border border-slate-700 rounded text-slate-200 placeholder-slate-500 focus:outline-none focus:border-sky-500"
        />
      </div>

      <!-- Node type filters -->
      <div class="px-3 py-3 border-b border-slate-800">
        <p class="text-xs font-medium text-slate-500 mb-2 uppercase tracking-wide">Node types</p>
        <div class="flex flex-col gap-1">
          <label
            v-for="t in NODE_TYPES"
            :key="t.key"
            class="flex items-center gap-2 cursor-pointer group"
          >
            <input
              type="checkbox"
              :checked="activeTypes.includes(t.key)"
              @change="toggleType(t.key)"
              class="rounded"
            />
            <span
              class="inline-block w-2.5 h-2.5 rounded-full flex-shrink-0"
              :style="{ background: t.color }"
            ></span>
            <span class="text-xs text-slate-300 group-hover:text-white capitalize">{{ t.key }}</span>
            <span class="ml-auto text-xs text-slate-600">{{ typeCount(t.key) }}</span>
          </label>
        </div>
      </div>

      <!-- View switcher -->
      <div class="px-3 py-3 border-b border-slate-800">
        <p class="text-xs font-medium text-slate-500 mb-2 uppercase tracking-wide">View</p>
        <div class="flex gap-1">
          <button
            v-for="v in VIEWS"
            :key="v"
            @click="activeView = v"
            :class="[
              'px-2.5 py-1 text-xs rounded capitalize',
              activeView === v
                ? 'bg-sky-600 text-white'
                : 'bg-slate-800 text-slate-400 hover:bg-slate-700'
            ]"
          >{{ v }}</button>
        </div>
      </div>

      <!-- Graph Topology -->
      <div class="px-3 py-3 border-b border-slate-800 overflow-y-auto">
        <p class="text-xs font-medium text-slate-500 mb-2 uppercase tracking-wide">Graph Topology</p>

        <button
          @click="runTopology"
          :disabled="topologyLoading"
          class="w-full px-2 py-1.5 text-xs bg-slate-800 hover:bg-slate-700 disabled:opacity-50 text-slate-300 rounded mb-2"
        >{{ topologyLoading ? 'Analysing…' : 'Run Analysis' }}</button>

        <div v-if="topologyResult" class="space-y-1 mb-2">
          <div class="flex justify-between text-xs">
            <span class="text-slate-500">Nodes</span>
            <span class="text-slate-300">{{ topologyResult.node_count }}</span>
          </div>
          <div class="flex justify-between text-xs">
            <span class="text-slate-500">Edges</span>
            <span class="text-slate-300">{{ topologyResult.edge_count }}</span>
          </div>
          <div class="flex justify-between text-xs">
            <span class="text-slate-500">Gamma</span>
            <span class="text-slate-300">{{ topologyResult.power_law.gamma ?? '—' }}</span>
          </div>
          <div class="flex justify-between text-xs">
            <span class="text-slate-500">R²</span>
            <span class="text-slate-300">{{ topologyResult.power_law.r_squared ?? '—' }}</span>
          </div>
          <div class="flex justify-between text-xs items-center">
            <span class="text-slate-500">Scale-Free</span>
            <span
              :class="[
                'px-1.5 py-0.5 rounded text-xs',
                topologyResult.power_law.is_scale_free
                  ? 'bg-emerald-900/50 text-emerald-400'
                  : 'bg-slate-700 text-slate-400'
              ]"
            >{{ topologyResult.power_law.is_scale_free ? 'Scale-Free' : 'Not Scale-Free' }}</span>
          </div>
          <div class="flex justify-between text-xs">
            <span class="text-slate-500">Clustering</span>
            <span class="text-slate-300">{{ topologyResult.mean_clustering_coefficient ?? '—' }}</span>
          </div>
        </div>

        <div class="flex flex-col gap-1.5">
          <button
            @click="runDecay"
            :disabled="decayLoading"
            class="w-full px-2 py-1.5 text-xs bg-slate-800 hover:bg-slate-700 disabled:opacity-50 text-slate-300 rounded"
          >{{ decayLoading ? 'Decaying…' : 'Run Decay' }}</button>
          <div v-if="decayResult" class="text-xs text-slate-500 text-center">
            {{ decayResult.edges_decayed }} edge{{ decayResult.edges_decayed === 1 ? '' : 's' }} decayed
          </div>

          <button
            @click="runSnapshot"
            :disabled="snapshotLoading"
            class="w-full px-2 py-1.5 text-xs bg-slate-800 hover:bg-slate-700 disabled:opacity-50 text-slate-300 rounded"
          >{{ snapshotLoading ? 'Snapshotting…' : 'Take Snapshot' }}</button>
          <div v-if="snapshotResult" class="text-xs text-slate-500 text-center">
            {{ snapshotResult.cluster_count }} cluster{{ snapshotResult.cluster_count === 1 ? '' : 's' }} saved
          </div>
        </div>
      </div>

      <!-- Stats -->
      <div class="px-3 py-3 mt-auto border-t border-slate-800">
        <div class="text-xs text-slate-500 space-y-1">
          <div class="flex justify-between">
            <span>Nodes</span>
            <span class="text-slate-300">{{ graphData.nodes.length }}</span>
          </div>
          <div class="flex justify-between">
            <span>Edges</span>
            <span class="text-slate-300">{{ graphData.edges.length }}</span>
          </div>
          <div class="flex justify-between">
            <span>Clusters</span>
            <span class="text-slate-300">{{ clusterCount }}</span>
          </div>
        </div>
      </div>
    </aside>

    <!-- ── Center: Visualization ── -->
    <main class="flex-1 relative overflow-hidden">

      <!-- Loading overlay -->
      <div
        v-if="loading"
        class="absolute inset-0 flex items-center justify-center bg-slate-950/80 z-10"
      >
        <div class="text-center">
          <div class="w-8 h-8 border-2 border-sky-500 border-t-transparent rounded-full animate-spin mx-auto mb-3"></div>
          <p class="text-sm text-slate-400">Building memory graph…</p>
        </div>
      </div>

      <!-- Empty state -->
      <div
        v-else-if="!loading && graphData.nodes.length === 0"
        class="absolute inset-0 flex items-center justify-center"
      >
        <div class="text-center max-w-xs">
          <div class="text-5xl mb-4">🧠</div>
          <h2 class="text-lg font-semibold text-slate-300 mb-2">No memories yet</h2>
          <p class="text-sm text-slate-500 mb-4">
            Chat with the assistant and memories will automatically appear here as a navigable graph.
          </p>
          <a
            href="/chat"
            class="inline-block px-4 py-2 bg-sky-600 hover:bg-sky-500 text-white text-sm rounded"
          >Start chatting</a>
        </div>
      </div>

      <!-- Graph view -->
      <svg
        v-show="activeView === 'graph' && !loading"
        ref="svgRef"
        class="w-full h-full"
        @click="selectedNode = null"
      ></svg>

      <!-- Timeline view -->
      <div
        v-if="activeView === 'timeline' && !loading"
        class="h-full overflow-y-auto px-8 py-6"
      >
        <div class="max-w-2xl mx-auto space-y-3">
          <div
            v-for="node in timelineNodes"
            :key="node.id"
            @click="selectedNode = node"
            :class="[
              'flex gap-4 p-3 rounded-lg border cursor-pointer transition-colors',
              selectedNode?.id === node.id
                ? 'border-sky-600 bg-slate-800'
                : 'border-slate-800 bg-slate-900 hover:border-slate-700'
            ]"
          >
            <div class="flex flex-col items-center gap-1 flex-shrink-0 w-14">
              <span
                class="inline-block w-2.5 h-2.5 rounded-full"
                :style="{ background: typeColor(node.type) }"
              ></span>
              <span class="text-xs text-slate-600 text-center leading-tight">{{ formatDate(node.created_at) }}</span>
            </div>
            <div class="flex-1 min-w-0">
              <div class="flex items-center gap-2 mb-0.5">
                <span class="text-xs font-medium" :style="{ color: typeColor(node.type) }">{{ node.type }}</span>
                <span
                  v-if="node.sensitivity !== 'public'"
                  :class="[
                    'text-xs px-1.5 py-0.5 rounded',
                    node.sensitivity === 'sensitive' ? 'bg-red-900/40 text-red-400' : 'bg-violet-900/40 text-violet-400'
                  ]"
                >{{ node.sensitivity }}</span>
              </div>
              <p class="text-sm font-medium text-slate-200 truncate">{{ node.label }}</p>
              <p class="text-xs text-slate-500 mt-0.5 line-clamp-2">{{ node.content }}</p>
            </div>
          </div>
        </div>
      </div>

      <!-- List view -->
      <div
        v-if="activeView === 'list' && !loading"
        class="h-full overflow-y-auto px-6 py-4"
      >
        <div class="grid grid-cols-1 gap-2 max-w-3xl mx-auto">
          <div
            v-for="node in filteredNodes"
            :key="node.id"
            @click="selectedNode = node"
            :class="[
              'p-3 rounded border cursor-pointer transition-colors',
              selectedNode?.id === node.id
                ? 'border-sky-600 bg-slate-800'
                : 'border-slate-800 bg-slate-900 hover:border-slate-700'
            ]"
          >
            <div class="flex items-center gap-2 mb-1">
              <span
                class="inline-block w-2 h-2 rounded-full flex-shrink-0"
                :style="{ background: typeColor(node.type) }"
              ></span>
              <span class="text-xs font-medium capitalize" :style="{ color: typeColor(node.type) }">{{ node.type }}</span>
              <span class="text-xs text-slate-500 ml-auto">{{ formatDate(node.created_at) }}</span>
            </div>
            <p class="text-sm font-medium text-slate-200">{{ node.label }}</p>
            <div v-if="node.tags.length" class="flex flex-wrap gap-1 mt-1.5">
              <span
                v-for="tag in node.tags.slice(0, 4)"
                :key="tag"
                class="text-xs px-1.5 py-0.5 bg-slate-800 text-slate-400 rounded"
              >{{ tag }}</span>
            </div>
          </div>
        </div>
      </div>

      <!-- Simulation controls -->
      <div v-if="activeView === 'graph'" class="absolute bottom-4 left-4 z-10">
        <div class="flex items-center gap-3 bg-slate-900/95 border border-slate-700 rounded-xl px-4 py-2.5 backdrop-blur-sm shadow-lg">
          <!-- Play / Pause -->
          <button
            @click="toggleSimulation"
            :class="[
              'w-7 h-7 flex items-center justify-center rounded-full text-sm font-bold transition-colors',
              simulationRunning
                ? 'bg-amber-500 hover:bg-amber-400 text-slate-900'
                : 'bg-slate-700 hover:bg-sky-600 text-slate-200'
            ]"
            :title="simulationRunning ? 'Pause simulation' : 'Run Physarum simulation'"
          >
            {{ simulationRunning ? '⏸' : '▶' }}
          </button>

          <!-- Label -->
          <span class="text-xs text-slate-400 font-medium">Simulate</span>

          <div class="w-px h-4 bg-slate-700"></div>

          <!-- Speed -->
          <div class="flex items-center gap-1.5">
            <span class="text-xs text-slate-600">speed</span>
            <button
              v-for="s in SIM_SPEEDS"
              :key="s.ms"
              @click="setSimSpeed(s.ms)"
              :class="[
                'text-xs px-2 py-0.5 rounded-full transition-colors',
                simSpeed === s.ms
                  ? 'bg-sky-600 text-white'
                  : 'text-slate-500 hover:text-slate-300'
              ]"
            >{{ s.label }}</button>
          </div>

          <div class="w-px h-4 bg-slate-700"></div>

          <!-- Tick counter -->
          <div class="flex items-center gap-1.5">
            <span class="text-xs text-slate-600">tick</span>
            <span class="text-xs font-mono text-slate-300 w-8 text-right">{{ simTick }}</span>
          </div>

          <!-- Active node count badge -->
          <div
            v-if="simActiveCount > 0"
            class="flex items-center gap-1 bg-amber-500/20 border border-amber-500/40 rounded-full px-2 py-0.5"
          >
            <span class="inline-block w-1.5 h-1.5 rounded-full bg-amber-400 animate-pulse"></span>
            <span class="text-xs text-amber-300 font-mono">{{ simActiveCount }}</span>
          </div>
        </div>
      </div>

      <!-- Zoom controls -->
      <div v-if="activeView === 'graph'" class="absolute bottom-4 right-4 flex flex-col gap-1.5 z-10">
        <button
          @click="zoomIn"
          class="w-8 h-8 flex items-center justify-center bg-slate-800 hover:bg-slate-700 border border-slate-700 rounded text-slate-300 text-lg leading-none"
        >+</button>
        <button
          @click="zoomOut"
          class="w-8 h-8 flex items-center justify-center bg-slate-800 hover:bg-slate-700 border border-slate-700 rounded text-slate-300 text-lg leading-none"
        >−</button>
        <button
          @click="resetZoom"
          class="w-8 h-8 flex items-center justify-center bg-slate-800 hover:bg-slate-700 border border-slate-700 rounded text-slate-400 text-xs"
          title="Reset zoom"
        >⊡</button>
      </div>
    </main>

    <!-- ── Right panel: Node detail ── -->
    <aside
      v-if="selectedNode"
      class="w-72 flex-shrink-0 border-l border-slate-800 bg-slate-900 flex flex-col overflow-y-auto"
    >
      <div class="px-4 py-4 border-b border-slate-800 flex items-start justify-between gap-2">
        <div>
          <div class="flex items-center gap-2 mb-1">
            <span
              class="inline-block w-3 h-3 rounded-full flex-shrink-0"
              :style="{ background: typeColor(selectedNode.type) }"
            ></span>
            <span class="text-xs font-semibold capitalize" :style="{ color: typeColor(selectedNode.type) }">{{ selectedNode.type }}</span>
            <span
              v-if="selectedNode.sensitivity !== 'public'"
              :class="[
                'text-xs px-1.5 py-0.5 rounded',
                selectedNode.sensitivity === 'sensitive' ? 'bg-red-900/40 text-red-400' : 'bg-violet-900/40 text-violet-400'
              ]"
            >{{ selectedNode.sensitivity }}</span>
          </div>
          <h2 class="text-sm font-semibold text-slate-100">{{ selectedNode.label }}</h2>
        </div>
        <button @click="selectedNode = null" class="text-slate-500 hover:text-slate-300 text-lg leading-none flex-shrink-0">×</button>
      </div>

      <div class="px-4 py-3 border-b border-slate-800">
        <p class="text-xs text-slate-400 leading-relaxed">{{ selectedNode.content }}</p>
      </div>

      <!-- Tags -->
      <div v-if="selectedNode.tags.length" class="px-4 py-3 border-b border-slate-800">
        <p class="text-xs font-medium text-slate-500 mb-2 uppercase tracking-wide">Tags</p>
        <div class="flex flex-wrap gap-1.5">
          <span
            v-for="tag in selectedNode.tags"
            :key="tag"
            class="text-xs px-2 py-0.5 bg-slate-800 text-slate-300 rounded-full"
          >{{ tag }}</span>
        </div>
      </div>

      <!-- Connected nodes -->
      <div class="px-4 py-3 border-b border-slate-800">
        <p class="text-xs font-medium text-slate-500 mb-2 uppercase tracking-wide">Connected ({{ connectedNodes.length }})</p>
        <div v-if="connectedNodes.length === 0" class="text-xs text-slate-600">No connections yet</div>
        <div class="space-y-1.5">
          <div
            v-for="conn in connectedNodes"
            :key="conn.node.id"
            @click="selectedNode = conn.node"
            class="flex items-start gap-2 p-2 rounded bg-slate-800 hover:bg-slate-700 cursor-pointer"
          >
            <span
              class="inline-block w-2 h-2 rounded-full flex-shrink-0 mt-0.5"
              :style="{ background: typeColor(conn.node.type) }"
            ></span>
            <div class="min-w-0">
              <p class="text-xs text-slate-300 truncate">{{ conn.node.label }}</p>
              <p class="text-xs text-slate-600">{{ conn.edge.relationship.replace(/_/g, ' ') }}</p>
            </div>
          </div>
        </div>
      </div>

      <!-- Actions -->
      <div class="px-4 py-3">
        <p class="text-xs font-medium text-slate-500 mb-2 uppercase tracking-wide">Actions</p>
        <div class="space-y-1.5">
          <button
            @click="expandNeighborhood(selectedNode.id)"
            class="w-full text-left px-3 py-2 text-xs bg-slate-800 hover:bg-slate-700 text-slate-300 rounded"
          >
            🔭 Expand neighborhood
          </button>
          <button
            @click="copyNodeContent"
            class="w-full text-left px-3 py-2 text-xs bg-slate-800 hover:bg-slate-700 text-slate-300 rounded"
          >
            📋 Copy content
          </button>
        </div>
      </div>

      <!-- Meta -->
      <div class="px-4 py-3 mt-auto border-t border-slate-800">
        <div class="text-xs text-slate-600 space-y-1">
          <div class="flex justify-between">
            <span>Source</span>
            <span class="text-slate-500">{{ selectedNode.source }}</span>
          </div>
          <div class="flex justify-between">
            <span>Confidence</span>
            <span class="text-slate-500">{{ Math.round(selectedNode.confidence * 100) }}%</span>
          </div>
          <div class="flex justify-between">
            <span>Created</span>
            <span class="text-slate-500">{{ formatDateFull(selectedNode.created_at) }}</span>
          </div>
        </div>
      </div>
    </aside>

  </div>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted, watch, nextTick } from 'vue'
import * as d3 from 'd3'

// ── Constants ─────────────────────────────────────────────────────────────────

const NODE_TYPES = [
  { key: 'memory',   color: '#0ea5e9' },  // sky-500
  { key: 'person',   color: '#8b5cf6' },  // violet-500
  { key: 'project',  color: '#10b981' },  // emerald-500
  { key: 'document', color: '#f59e0b' },  // amber-500
  { key: 'task',     color: '#f97316' },  // orange-500
  { key: 'event',    color: '#ef4444' },  // rose-500
  { key: 'concept',  color: '#94a3b8' },  // slate-400
]

const TYPE_COLOR_MAP = Object.fromEntries(NODE_TYPES.map(t => [t.key, t.color]))
const VIEWS = ['graph', 'timeline', 'list']

// ── State ─────────────────────────────────────────────────────────────────────

const graphData   = ref({ nodes: [], edges: [] })
const selectedNode = ref(null)
const loading     = ref(true)
const activeTypes = ref(NODE_TYPES.map(t => t.key))
const searchQuery = ref('')
const activeView  = ref('graph')
const svgRef      = ref(null)

// Simulation state
const simulationRunning = ref(false)
const simSpeed  = ref(1000)    // milliseconds between ticks
const simTick   = ref(0)
const simActiveCount = ref(0)
let   simIntervalId = null
let   simTickInFlight = false
let   simGeneration = 0

const SIM_SPEEDS = [
  { label: 'slow', ms: 2000 },
  { label: '1×',   ms: 1000 },
  { label: 'fast', ms: 350  },
]

// Topology panel state
const topologyLoading  = ref(false)
const topologyResult   = ref(null)
const decayLoading     = ref(false)
const decayResult      = ref(null)
const snapshotLoading  = ref(false)
const snapshotResult   = ref(null)

// D3 internals
let simulation = null
let svgEl      = null
let zoomBehavior = null
let gRoot      = null
let linkSel    = null   // live reference for weight transitions
let nodeSel    = null   // live reference for active-node flash

// ── Computed ──────────────────────────────────────────────────────────────────

const filteredNodes = computed(() => {
  let nodes = graphData.value.nodes.filter(n => activeTypes.value.includes(n.type))
  if (searchQuery.value.trim()) {
    const q = searchQuery.value.toLowerCase()
    nodes = nodes.filter(n =>
      n.label.toLowerCase().includes(q) ||
      n.content.toLowerCase().includes(q) ||
      (n.tags || []).some(t => t.toLowerCase().includes(q))
    )
  }
  return nodes
})

const timelineNodes = computed(() =>
  [...filteredNodes.value].sort((a, b) => new Date(b.created_at) - new Date(a.created_at))
)

const typeCount = (type) => graphData.value.nodes.filter(n => n.type === type).length

const clusterCount = computed(() => {
  // Simple union-find to count connected components
  const ids = graphData.value.nodes.map(n => n.id)
  const parent = Object.fromEntries(ids.map(id => [id, id]))
  const find = (x) => parent[x] === x ? x : (parent[x] = find(parent[x]))
  const union = (a, b) => { parent[find(a)] = find(b) }
  graphData.value.edges.forEach(e => union(e.source, e.target))
  return new Set(ids.map(find)).size
})

const connectedNodes = computed(() => {
  if (!selectedNode.value) return []
  const id   = selectedNode.value.id
  const nodeMap = Object.fromEntries(graphData.value.nodes.map(n => [n.id, n]))
  return graphData.value.edges
    .filter(e => e.source === id || e.target === id || e.source?.id === id || e.target?.id === id)
    .map(e => {
      const otherId = (e.source?.id ?? e.source) === id
        ? (e.target?.id ?? e.target)
        : (e.source?.id ?? e.source)
      return { node: nodeMap[otherId], edge: e }
    })
    .filter(c => c.node)
})

// ── Data fetching ─────────────────────────────────────────────────────────────

const fetchGraph = async () => {
  loading.value = true
  try {
    const params = new URLSearchParams()
    activeTypes.value.forEach(t => params.append('types[]', t))
    params.append('sensitivity[]', 'public')
    const res = await fetch(`/api/graph?${params}`)
    graphData.value = await res.json()
  } finally {
    loading.value = false
  }
}

const expandNeighborhood = async (nodeId) => {
  loading.value = true
  try {
    const res = await fetch(`/api/graph/neighborhood/${nodeId}?depth=2`)
    graphData.value = await res.json()
  } finally {
    loading.value = false
  }
}

const runTopology = async () => {
  topologyLoading.value = true
  try {
    const res = await fetch('/api/graph/topology')
    topologyResult.value = await res.json()
  } catch {
    // Network error — leave previous result in place.
  } finally {
    topologyLoading.value = false
  }
}

const runDecay = async () => {
  decayLoading.value = true
  decayResult.value = null
  try {
    const res = await fetch('/api/graph/decay', {
      method: 'POST',
      headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '' },
    })
    decayResult.value = await res.json()
    await fetchGraph()
    if (topologyResult.value) {
      await runTopology()
    }
  } catch {
    // Network error — leave result null.
  } finally {
    decayLoading.value = false
  }
}

const runSnapshot = async () => {
  snapshotLoading.value = true
  snapshotResult.value = null
  try {
    const res = await fetch('/api/graph/snapshot', {
      method: 'POST',
      headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '' },
    })
    snapshotResult.value = await res.json()
  } catch {
    // Network error — leave result null.
  } finally {
    snapshotLoading.value = false
  }
}

// ── D3 graph ──────────────────────────────────────────────────────────────────

const typeColor = (type) => TYPE_COLOR_MAP[type] ?? '#94a3b8'

const nodeRadius = (node, degreeMap) => {
  const base   = ['person', 'project'].includes(node.type) ? 14 : 10
  const degree = degreeMap[node.id] ?? 0
  return base + Math.min(degree * 1.5, 14)
}

const initD3 = () => {
  const el = svgRef.value
  if (!el) return

  const width  = el.clientWidth
  const height = el.clientHeight

  d3.select(el).selectAll('*').remove()

  svgEl = d3.select(el).attr('width', width).attr('height', height)

  // Definitions: arrow marker + glow filter
  const defs = svgEl.append('defs')

  defs.append('marker')
    .attr('id', 'oma-arrow')
    .attr('viewBox', '0 -5 10 10')
    .attr('refX', 22).attr('refY', 0)
    .attr('markerWidth', 5).attr('markerHeight', 5)
    .attr('orient', 'auto')
    .append('path').attr('d', 'M0,-5L10,0L0,5').attr('fill', '#475569')

  // Glow filter — outer blur merged with original graphic
  const glow = defs.append('filter').attr('id', 'node-glow').attr('x', '-50%').attr('y', '-50%').attr('width', '200%').attr('height', '200%')
  glow.append('feGaussianBlur').attr('in', 'SourceGraphic').attr('stdDeviation', 5).attr('result', 'blur')
  const merge = glow.append('feMerge')
  merge.append('feMergeNode').attr('in', 'blur')
  merge.append('feMergeNode').attr('in', 'blur')
  merge.append('feMergeNode').attr('in', 'SourceGraphic')

  // Radial gradient for the background
  const radial = defs.append('radialGradient').attr('id', 'bg-radial').attr('cx', '50%').attr('cy', '50%').attr('r', '65%')
  radial.append('stop').attr('offset', '0%').attr('stop-color', '#0d1829')
  radial.append('stop').attr('offset', '100%').attr('stop-color', '#020817')

  // Dark background rect
  svgEl.append('rect').attr('width', width).attr('height', height).attr('fill', 'url(#bg-radial)')

  gRoot = svgEl.append('g').attr('class', 'graph-root')

  zoomBehavior = d3.zoom()
    .scaleExtent([0.08, 5])
    .on('zoom', (event) => gRoot.attr('transform', event.transform))

  svgEl.call(zoomBehavior)

  renderGraph(width, height)
}

const renderGraph = (width, height) => {
  if (!gRoot) return

  gRoot.selectAll('*').remove()

  if (simulation) simulation.stop()

  const nodes = filteredNodes.value.map(n => ({ ...n }))
  const nodeIds = new Set(nodes.map(n => n.id))

  const edges = graphData.value.edges
    .filter(e => nodeIds.has(e.source) && nodeIds.has(e.target))
    .map(e => ({ ...e }))

  const degreeMap = {}
  edges.forEach(e => {
    degreeMap[e.source] = (degreeMap[e.source] ?? 0) + 1
    degreeMap[e.target] = (degreeMap[e.target] ?? 0) + 1
  })

  simulation = d3.forceSimulation(nodes)
    .force('link', d3.forceLink(edges).id(d => d.id).distance(120).strength(0.4))
    .force('charge', d3.forceManyBody().strength(-280))
    .force('center', d3.forceCenter(width / 2, height / 2))
    .force('collide', d3.forceCollide().radius(d => nodeRadius(d, degreeMap) + 10))

  // Node id -> type color map for edge tinting
  const nodeTypeMap = Object.fromEntries(nodes.map(n => [n.id, n.type]))

  // Edge lines — tinted by source node type, weight-scaled width
  linkSel = gRoot.append('g').attr('opacity', 0.45)
    .selectAll('line')
    .data(edges)
    .join('line')
    .attr('stroke', d => {
      const srcId = d.source?.id ?? d.source
      return typeColor(nodeTypeMap[srcId]) ?? '#475569'
    })
    .attr('stroke-width', d => 0.6 + d.weight * 2.2)
    .attr('marker-end', 'url(#oma-arrow)')

  // Node groups
  const node = gRoot.append('g')
    .selectAll('g')
    .data(nodes)
    .join('g')
    .attr('cursor', 'pointer')
    .call(
      d3.drag()
        .on('start', (event, d) => {
          if (!event.active) simulation.alphaTarget(0.3).restart()
          d.fx = d.x; d.fy = d.y
        })
        .on('drag', (event, d) => { d.fx = event.x; d.fy = event.y })
        .on('end', (event, d) => {
          if (!event.active) simulation.alphaTarget(0)
          d.fx = null; d.fy = null
        })
    )
    .on('click', (event, d) => {
      event.stopPropagation()
      selectedNode.value = d
    })
    .on('mouseenter', function (event, d) {
      d3.select(this).select('circle')
        .attr('stroke-width', 3)
        .attr('stroke', '#fff')
      d3.select(this).select('title').text(d.content)
    })
    .on('mouseleave', function (event, d) {
      d3.select(this).select('circle')
        .attr('stroke-width', 1.5)
        .attr('stroke', typeColor(d.type))
    })

  // Simulation active-node flash ring (amber, shown briefly on each sim tick)
  node.append('circle')
    .attr('r', d => nodeRadius(d, degreeMap) + 8)
    .attr('fill', 'none')
    .attr('stroke', '#f59e0b')
    .attr('stroke-width', 2.5)
    .attr('opacity', 0)
    .attr('class', 'sim-ring')

  // Pulse ring for selected
  node.append('circle')
    .attr('r', d => nodeRadius(d, degreeMap) + 4)
    .attr('fill', 'none')
    .attr('stroke', d => typeColor(d.type))
    .attr('stroke-width', 2)
    .attr('opacity', d => selectedNode.value?.id === d.id ? 0.4 : 0)
    .attr('class', 'selection-ring')

  // Main circle — with glow filter for neon effect
  node.append('circle')
    .attr('r', d => nodeRadius(d, degreeMap))
    .attr('fill', d => typeColor(d.type))
    .attr('fill-opacity', 0.9)
    .attr('stroke', d => typeColor(d.type))
    .attr('stroke-width', 1.5)
    .attr('filter', 'url(#node-glow)')

  // Tooltip
  node.append('title').text(d => `${d.type}: ${d.label}\n${d.content}`)

  // Label
  node.append('text')
    .attr('dy', d => nodeRadius(d, degreeMap) + 13)
    .attr('text-anchor', 'middle')
    .attr('font-size', 10)
    .attr('fill', '#cbd5e1')
    .attr('pointer-events', 'none')
    .text(d => d.label.length > 22 ? d.label.slice(0, 20) + '…' : d.label)

  nodeSel = node

  simulation.on('tick', () => {
    linkSel
      .attr('x1', d => d.source.x)
      .attr('y1', d => d.source.y)
      .attr('x2', d => d.target.x)
      .attr('y2', d => d.target.y)

    node.attr('transform', d => `translate(${d.x},${d.y})`)
  })
}

// ── Simulation ────────────────────────────────────────────────────────────────

const runSimTick = async () => {
  if (!simulationRunning.value || simTickInFlight) return

  const generation = simGeneration
  simTickInFlight = true

  try {
    const res = await fetch('/api/graph/simulate', {
      method: 'POST',
      headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '' },
    })
    if (!res.ok) return

    const data = await res.json()
    if (generation !== simGeneration || !simulationRunning.value) return

    const activeIds = data.active_node_ids ?? []
    const updatedEdges = data.updated_edges ?? []

    simTick.value++
    simActiveCount.value = activeIds.length

    // Flash active nodes with amber ring, then fade.
    if (nodeSel && activeIds.length > 0) {
      const activeSet = new Set(activeIds)
      nodeSel.selectAll('.sim-ring')
        .interrupt()
        .attr('opacity', d => activeSet.has(d.id) ? 0.85 : 0)
        .transition().delay(500).duration(800)
        .attr('opacity', 0)
    }

    // Transition edge stroke widths for reinforced edges.
    if (linkSel && updatedEdges.length > 0) {
      const weightMap = Object.fromEntries(updatedEdges.map(e => [e.id, e.weight]))
      linkSel.transition().duration(600)
        .attr('stroke-width', d => {
          const w = weightMap[d.id] ?? d.weight ?? 0.5
          return 0.6 + w * 2.2
        })
    }
  } catch {
    // Network error during sim — do not crash, just skip this tick.
  } finally {
    simTickInFlight = false
  }
}

const toggleSimulation = () => {
  if (simulationRunning.value) {
    stopSimulation()
  } else {
    startSimulation()
  }
}

const startSimulation = () => {
  if (simulationRunning.value) return
  simulationRunning.value = true
  simGeneration++
  runSimTick()
  simIntervalId = setInterval(runSimTick, simSpeed.value)
}

const stopSimulation = () => {
  simulationRunning.value = false
  simGeneration++
  simActiveCount.value = 0
  if (simIntervalId) {
    clearInterval(simIntervalId)
    simIntervalId = null
  }
  // Clear any remaining sim rings.
  if (nodeSel) {
    nodeSel.selectAll('.sim-ring').interrupt().transition().duration(400).attr('opacity', 0)
  }
}

const setSimSpeed = (ms) => {
  simSpeed.value = ms
  if (simulationRunning.value) {
    // Restart the interval at the new speed.
    clearInterval(simIntervalId)
    simIntervalId = setInterval(runSimTick, ms)
  }
}

// ── Zoom controls ─────────────────────────────────────────────────────────────

const zoomIn  = () => svgEl && svgEl.transition().call(zoomBehavior.scaleBy, 1.4)
const zoomOut = () => svgEl && svgEl.transition().call(zoomBehavior.scaleBy, 0.7)
const resetZoom = () => svgEl && svgEl.transition().call(zoomBehavior.transform, d3.zoomIdentity)

// ── Helpers ───────────────────────────────────────────────────────────────────

const toggleType = (type) => {
  if (activeTypes.value.includes(type)) {
    activeTypes.value = activeTypes.value.filter(t => t !== type)
  } else {
    activeTypes.value = [...activeTypes.value, type]
  }
}

const formatDate = (iso) => {
  if (!iso) return ''
  const d = new Date(iso)
  return d.toLocaleDateString(undefined, { month: 'short', day: 'numeric' })
}

const formatDateFull = (iso) => {
  if (!iso) return ''
  const d = new Date(iso)
  return d.toLocaleString()
}

const copyNodeContent = () => {
  if (selectedNode.value) {
    navigator.clipboard.writeText(selectedNode.value.content)
  }
}

// ── Lifecycle ─────────────────────────────────────────────────────────────────

watch(filteredNodes, async () => {
  if (activeView.value === 'graph' && svgRef.value) {
    await nextTick()
    const el = svgRef.value
    renderGraph(el.clientWidth, el.clientHeight)
  }
})

watch(activeView, async (view) => {
  if (view !== 'graph') {
    stopSimulation()
    return
  }

  if (view === 'graph') {
    await nextTick()
    if (svgRef.value && !gRoot) initD3()
    else if (svgRef.value) {
      const el = svgRef.value
      renderGraph(el.clientWidth, el.clientHeight)
    }
  }
})

watch(selectedNode, async () => {
  if (activeView.value === 'graph' && gRoot) {
    gRoot.selectAll('.selection-ring')
      .attr('opacity', d => selectedNode.value?.id === d.id ? 0.4 : 0)
  }
})

onMounted(async () => {
  await fetchGraph()
  if (activeView.value === 'graph') {
    await nextTick()
    initD3()
  }
})

onUnmounted(() => {
  if (simulation) simulation.stop()
  if (simIntervalId) clearInterval(simIntervalId)
})
</script>
