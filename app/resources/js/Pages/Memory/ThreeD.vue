<template>
  <div class="relative w-screen h-screen bg-slate-950 overflow-hidden select-none">

    <!-- ── Three.js canvas container ── -->
    <div ref="canvasContainer" class="absolute inset-0"></div>

    <!-- ── Top-left header bar ── -->
    <div class="absolute top-0 left-0 right-0 flex items-center gap-3 px-4 py-3 bg-slate-950/80 backdrop-blur border-b border-slate-800 z-10">
      <div class="flex items-center gap-2">
        <a href="/chat" class="text-slate-500 hover:text-slate-300 text-sm">← Chat</a>
        <span class="text-slate-700">|</span>
        <a href="/graph" class="text-slate-500 hover:text-slate-300 text-sm">Graph</a>
        <span class="text-slate-700">|</span>
        <a href="/agents" class="text-slate-500 hover:text-slate-300 text-sm">Agents</a>
      </div>
      <h1 class="text-sm font-semibold text-amber-400 ml-2">Mission Control</h1>
      <p class="text-xs text-slate-500">Collective memory oversight surface</p>

      <div class="ml-auto flex items-center gap-3">
        <!-- Cluster heat map toggle -->
        <button
          @click="showHeat = !showHeat"
          :class="['px-2.5 py-1 text-xs rounded border transition-colors', showHeat
            ? 'bg-amber-900/40 border-amber-700 text-amber-300'
            : 'bg-slate-800 border-slate-700 text-slate-400 hover:border-slate-600']"
        >Heat map</button>

        <!-- Alignment panel toggle -->
        <button
          @click="showAlignment = !showAlignment"
          :class="['px-2.5 py-1 text-xs rounded border transition-colors', showAlignment
            ? 'bg-violet-900/40 border-violet-700 text-violet-300'
            : 'bg-slate-800 border-slate-700 text-slate-400 hover:border-slate-600']"
        >Alignment</button>

        <!-- Node count badge -->
        <span class="text-xs text-slate-500">
          <span class="text-slate-300 font-mono">{{ totalNodeCount }}</span> nodes
        </span>
        <span class="text-xs text-slate-500">
          <span class="text-slate-300 font-mono">{{ clusters.length }}</span> clusters
        </span>
      </div>
    </div>

    <!-- ── Right panel: intent alignment ── -->
    <transition name="slide-right">
      <div
        v-if="showAlignment && alignmentPairs.length > 0"
        class="absolute top-14 right-4 w-64 bg-slate-900/95 border border-slate-700 rounded-lg p-3 z-10 backdrop-blur"
      >
        <p class="text-xs font-medium text-slate-400 mb-2 uppercase tracking-wide">Intent alignment</p>
        <div class="space-y-2">
          <div
            v-for="pair in alignmentPairs"
            :key="pair.agent_a_id + pair.agent_b_id"
            class="flex items-center gap-2"
          >
            <div class="flex-1 min-w-0">
              <p class="text-xs text-slate-300 truncate">{{ pair.agent_a_name }} — {{ pair.agent_b_name }}</p>
              <div class="mt-0.5 h-1.5 rounded-full bg-slate-800 overflow-hidden">
                <div
                  class="h-full rounded-full transition-all duration-700"
                  :style="{
                    width: (pair.jaccard * 100) + '%',
                    background: jaccardColor(pair.jaccard)
                  }"
                ></div>
              </div>
            </div>
            <span class="text-xs font-mono flex-shrink-0" :style="{ color: jaccardColor(pair.jaccard) }">
              {{ (pair.jaccard * 100).toFixed(0) }}%
            </span>
          </div>
        </div>
        <p v-if="lastAlignmentAt" class="text-xs text-slate-600 mt-2">Updated {{ lastAlignmentAt }}</p>
      </div>
    </transition>

    <!-- ── Left info panel: agent partitions ── -->
    <div class="absolute top-14 left-4 w-48 bg-slate-900/90 border border-slate-800 rounded-lg p-3 z-10 backdrop-blur">
      <p class="text-xs font-medium text-slate-400 mb-2 uppercase tracking-wide">Partitions</p>
      <div v-if="agents.length === 0" class="text-xs text-slate-600">
        No agents. Create agents on the Agents page to see collective partitions.
      </div>
      <div v-for="(agent, i) in agents" :key="agent.id" class="flex items-center gap-2 mb-2">
        <span
          class="inline-block w-2.5 h-2.5 rounded-full flex-shrink-0"
          :style="{ background: agentColor(i) }"
        ></span>
        <span class="text-xs text-slate-300 truncate flex-1">{{ agent.name }}</span>
        <span class="text-xs text-slate-500 font-mono">{{ (agent.trust_score * 100).toFixed(0) }}%</span>
      </div>
      <div class="mt-2 pt-2 border-t border-slate-800">
        <div class="flex items-center gap-2">
          <span class="inline-block w-2.5 h-2.5 rounded-full flex-shrink-0 bg-violet-500"></span>
          <span class="text-xs text-slate-500">Shared nodes</span>
        </div>
      </div>
    </div>

    <!-- ── Simulation controls (bottom-left) ── -->
    <div class="absolute bottom-16 left-4 flex items-center gap-2 bg-slate-900/90 border border-slate-800 rounded-full px-3 py-2 z-10 backdrop-blur">
      <!-- Play/pause -->
      <button
        @click="toggleSimulation"
        :class="['w-7 h-7 rounded-full flex items-center justify-center text-xs transition-colors',
          simRunning ? 'bg-amber-500 text-slate-900' : 'bg-slate-700 text-slate-300 hover:bg-slate-600']"
      >{{ simRunning ? '■' : '▶' }}</button>

      <!-- Speed -->
      <div class="flex gap-1">
        <button
          v-for="s in SIM_SPEEDS"
          :key="s.label"
          @click="setSimSpeed(s.ms)"
          :class="['px-2 py-0.5 text-xs rounded transition-colors',
            simSpeedMs === s.ms
              ? 'bg-amber-900/60 text-amber-300'
              : 'bg-slate-800 text-slate-500 hover:text-slate-300']"
        >{{ s.label }}</button>
      </div>

      <!-- Tick counter -->
      <span class="text-xs text-slate-500 font-mono ml-1">tick {{ simTick }}</span>

      <!-- Active node badge -->
      <span
        v-if="simActiveCount > 0"
        :class="['text-xs font-mono px-1.5 py-0.5 rounded bg-amber-900/40 text-amber-300',
          simRunning ? 'animate-pulse' : '']"
      >{{ simActiveCount }} active</span>

      <!-- Simulate all agents -->
      <button
        @click="runSimulateAll"
        class="ml-1 px-2.5 py-1 text-xs rounded bg-violet-900/40 border border-violet-800 text-violet-300 hover:bg-violet-800/40 transition-colors"
      >Sim all</button>
    </div>

    <!-- ── Temporal axis scrubber (bottom) ── -->
    <div
      v-if="snapshots.length > 0"
      class="absolute bottom-4 left-1/2 -translate-x-1/2 flex items-center gap-3 bg-slate-900/90 border border-slate-800 rounded-full px-4 py-2 z-10 backdrop-blur w-1/2 max-w-xl"
    >
      <span class="text-xs text-slate-500 flex-shrink-0">Past</span>
      <input
        type="range"
        :min="0"
        :max="snapshots.length"
        :value="scrubberIndex"
        @input="onScrub($event.target.value)"
        class="flex-1 accent-amber-500"
      />
      <span class="text-xs text-slate-500 flex-shrink-0">Live</span>
      <span class="text-xs text-slate-400 font-mono flex-shrink-0 w-24 text-right">
        {{ scrubberLabel }}
      </span>
    </div>

    <!-- ── Loading overlay ── -->
    <div
      v-if="loading"
      class="absolute inset-0 flex items-center justify-center bg-slate-950/60 z-20"
    >
      <div class="text-slate-400 text-sm">Loading graph...</div>
    </div>

    <!-- ── Empty state ── -->
    <div
      v-if="!loading && totalNodeCount === 0"
      class="absolute inset-0 flex items-center justify-center z-10 pointer-events-none"
    >
      <div class="text-center">
        <p class="text-slate-500 text-sm">No memory nodes yet.</p>
        <p class="text-slate-600 text-xs mt-1">Send a message in the chat to start building the graph.</p>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted, watch } from 'vue'
import * as THREE from 'three'
import { OrbitControls } from 'three/examples/jsm/controls/OrbitControls.js'

// ── Props ─────────────────────────────────────────────────────────────────────
const props = defineProps({
  agents: { type: Array, default: () => [] },
})

// ── Refs ──────────────────────────────────────────────────────────────────────
const canvasContainer = ref(null)
const loading = ref(true)
const showHeat = ref(true)
const showAlignment = ref(true)
const totalNodeCount = ref(0)
const clusters = ref([])
const snapshots = ref([])
const scrubberIndex = ref(null) // null = live
const alignmentPairs = ref([])
const lastAlignmentAt = ref(null)

// Simulation state
const simRunning = ref(false)
const simTick = ref(0)
const simActiveCount = ref(0)
const simSpeedMs = ref(1000)
let simIntervalId = null
let simTickInFlight = false
let simGeneration = 0

const SIM_SPEEDS = [
  { label: 'slow', ms: 2000 },
  { label: '1x', ms: 1000 },
  { label: 'fast', ms: 350 },
]

// ── Three.js objects (module-scope, not reactive) ─────────────────────────────
let renderer, scene, camera, controls, clock
let instancedMesh = null
let lineSegments = null
let heatSpheres = [] // {mesh, clusterId}
let nodePositions = new Map()   // node_uuid -> THREE.Vector3
let nodeIndexMap = new Map()    // node_uuid -> instancedMesh index
let edgeIndexMap = new Map()    // edge_id -> byte offset in color Float32Array
let colorAttr = null            // reference to lineSegments color BufferAttribute

// ── Agent partition data (fetched per agent's graph_user_id) ──────────────────
let partitionData = {} // graph_user_id -> {nodes, edges}

// ── Colors ────────────────────────────────────────────────────────────────────
const NODE_TYPE_COLORS = {
  memory:   0x38bdf8,
  person:   0x4ade80,
  project:  0xa78bfa,
  document: 0xfbbf24,
  task:     0xfb923c,
  event:    0xf472b6,
  concept:  0x94a3b8,
}
const AGENT_COLORS = [
  '#38bdf8', '#4ade80', '#fb923c', '#f472b6', '#a78bfa', '#fbbf24',
  '#34d399', '#60a5fa', '#e879f9', '#facc15',
]

function agentColor(i) {
  return AGENT_COLORS[i % AGENT_COLORS.length]
}

function jaccardColor(j) {
  if (j >= 0.7) return '#4ade80'
  if (j >= 0.4) return '#facc15'
  return '#f87171'
}

// ── Cluster heat color interpolation ─────────────────────────────────────────
function clusterHeatColor(meanWeight) {
  const cool = new THREE.Color('#3b82f6')
  const hot = new THREE.Color('#f59e0b')
  const alpha = Math.max(0, Math.min(1, (meanWeight - 0.05) / 0.95))
  return cool.clone().lerp(hot, alpha)
}

// ── Computed ──────────────────────────────────────────────────────────────────
const scrubberLabel = computed(() => {
  if (scrubberIndex.value === null || scrubberIndex.value >= snapshots.value.length) {
    return 'Live'
  }
  const snap = snapshots.value[snapshots.value.length - 1 - scrubberIndex.value]
  if (!snap) return 'Live'
  return new Date(snap.snapshot_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
})

// ── Data fetching ─────────────────────────────────────────────────────────────
async function fetchPartitions() {
  // Fetch the personal user graph
  const res = await fetch('/api/graph?sensitivity[]=public')
  const data = await res.json()
  partitionData['__personal__'] = data

  // Fetch each agent's partition
  for (const agent of props.agents) {
    // Agent graphs are partitioned by graph_user_id but served from the same endpoint
    // using the session. We request their data by fetching as normal (agent graph data
    // is served relative to each agent's graph_user_id, not session user_id).
    // For now we reuse the same /api/graph but agents page shows agent-specific data.
    // The 3D page shows partitions based on the agent graph data from /api/agents simulate.
    partitionData[agent.id] = { nodes: [], edges: [] }
  }

  // Collect all unique nodes across all partitions
  const allNodeMap = new Map()

  // Add personal nodes
  for (const node of (partitionData['__personal__']?.nodes ?? [])) {
    allNodeMap.set(node.id, { ...node, partitions: ['__personal__'] })
  }

  // Build content-hash map for shared node detection
  const contentToAgentIds = new Map() // content -> [agentIds]

  return { allNodeMap, contentToAgentIds }
}

async function fetchClusters() {
  const res = await fetch('/api/graph/clusters')
  const data = await res.json()
  clusters.value = data.clusters ?? []
  updateHeatSpheres()
}

async function fetchSnapshots() {
  const res = await fetch('/api/graph/snapshots')
  const data = await res.json()
  snapshots.value = (data.snapshots ?? []).reverse() // oldest first for slider
  scrubberIndex.value = snapshots.value.length // point to "Live"
}

async function fetchAlignment() {
  if (props.agents.length < 2) return
  const res = await fetch('/api/agents/alignment')
  const data = await res.json()
  alignmentPairs.value = data.pairs ?? []
  lastAlignmentAt.value = new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
}

async function fetchSnapshotAt(index) {
  const snap = snapshots.value[snapshots.value.length - 1 - index]
  if (!snap) return fetchClusters()
  const res = await fetch(`/api/graph/snapshots/${snap.id}`)
  const data = await res.json()
  const historicalClusters = data.clusters ?? []
  recolorHeatSpheres(historicalClusters)
}

// ── Scene construction ────────────────────────────────────────────────────────
async function buildScene() {
  // Renderer
  renderer = new THREE.WebGLRenderer({ antialias: true })
  renderer.setPixelRatio(window.devicePixelRatio)
  renderer.setSize(window.innerWidth, window.innerHeight)
  renderer.setClearColor(0x020817)
  canvasContainer.value.appendChild(renderer.domElement)

  // Scene + camera
  scene = new THREE.Scene()
  camera = new THREE.PerspectiveCamera(60, window.innerWidth / window.innerHeight, 0.1, 2000)
  camera.position.set(0, 60, 160)
  camera.lookAt(0, 0, 0)

  // Controls
  controls = new OrbitControls(camera, renderer.domElement)
  controls.enableDamping = true
  controls.dampingFactor = 0.08

  // Lights
  scene.add(new THREE.AmbientLight(0x334155, 1.2))
  const dirLight = new THREE.DirectionalLight(0xffffff, 0.5)
  dirLight.position.set(0, 100, 50)
  scene.add(dirLight)

  // Clock for animations
  clock = new THREE.Clock()

  // Load graph data
  const graphRes = await fetch('/api/graph?sensitivity[]=public')
  const graphData = await graphRes.json()
  const nodes = graphData.nodes ?? []
  const edges = graphData.edges ?? []

  totalNodeCount.value = nodes.length

  if (nodes.length === 0) {
    loading.value = false
    animate()
    return
  }

  // Compute agent centroids (ring layout in XZ plane)
  const agentCount = props.agents.length
  const agentCentroids = {}
  props.agents.forEach((agent, i) => {
    const angle = (2 * Math.PI * i) / agentCount
    agentCentroids[agent.id] = new THREE.Vector3(
      80 * Math.cos(angle),
      0,
      80 * Math.sin(angle),
    )
  })

  // Personal graph centroid (origin if no agents, or offset if agents exist)
  const personalCentroid = agentCount > 0
    ? new THREE.Vector3(0, 30, 0)
    : new THREE.Vector3(0, 0, 0)

  // Assign 3D positions to nodes
  nodes.forEach((node) => {
    const theta = Math.random() * 2 * Math.PI
    const phi = Math.acos(2 * Math.random() - 1)
    const r = 20
    const offset = new THREE.Vector3(
      r * Math.sin(phi) * Math.cos(theta),
      r * Math.sin(phi) * Math.sin(theta),
      r * Math.cos(phi),
    )
    nodePositions.set(node.id, personalCentroid.clone().add(offset))
  })

  // Build node index map (deterministic order: sorted by node ID)
  const sortedNodeIds = [...nodePositions.keys()].sort()
  sortedNodeIds.forEach((id, index) => {
    nodeIndexMap.set(id, index)
  })

  // InstancedMesh for nodes
  const nodeGeo = new THREE.SphereGeometry(0.8, 10, 10)
  const nodeMat = new THREE.MeshStandardMaterial()
  instancedMesh = new THREE.InstancedMesh(nodeGeo, nodeMat, nodes.length)
  instancedMesh.instanceMatrix.setUsage(THREE.DynamicDrawUsage)
  instancedMesh.instanceColor = new THREE.InstancedBufferAttribute(
    new Float32Array(nodes.length * 3), 3,
  )

  const matrix = new THREE.Matrix4()
  const color = new THREE.Color()

  nodes.forEach((node) => {
    const idx = nodeIndexMap.get(node.id)
    if (idx === undefined) return
    const pos = nodePositions.get(node.id)
    matrix.compose(pos, new THREE.Quaternion(), new THREE.Vector3(1, 1, 1))
    instancedMesh.setMatrixAt(idx, matrix)
    color.setHex(NODE_TYPE_COLORS[node.type] ?? NODE_TYPE_COLORS.memory)
    instancedMesh.setColorAt(idx, color)
  })

  instancedMesh.instanceMatrix.needsUpdate = true
  instancedMesh.instanceColor.needsUpdate = true
  scene.add(instancedMesh)

  // LineSegments for edges
  const positions = new Float32Array(edges.length * 6)
  const colors = new Float32Array(edges.length * 6)

  edges.forEach((edge, k) => {
    const fromPos = nodePositions.get(edge.source)
    const toPos = nodePositions.get(edge.target)
    if (!fromPos || !toPos) return

    edgeIndexMap.set(edge.id, k)

    positions[k * 6 + 0] = fromPos.x
    positions[k * 6 + 1] = fromPos.y
    positions[k * 6 + 2] = fromPos.z
    positions[k * 6 + 3] = toPos.x
    positions[k * 6 + 4] = toPos.y
    positions[k * 6 + 5] = toPos.z

    const g = edge.weight * 0.6
    colors[k * 6 + 0] = g; colors[k * 6 + 1] = g; colors[k * 6 + 2] = g
    colors[k * 6 + 3] = g; colors[k * 6 + 4] = g; colors[k * 6 + 5] = g
  })

  const lineGeo = new THREE.BufferGeometry()
  lineGeo.setAttribute('position', new THREE.BufferAttribute(positions, 3))
  colorAttr = new THREE.BufferAttribute(colors, 3)
  colorAttr.setUsage(THREE.DynamicDrawUsage)
  lineGeo.setAttribute('color', colorAttr)

  lineSegments = new THREE.LineSegments(
    lineGeo,
    new THREE.LineBasicMaterial({ vertexColors: true }),
  )
  scene.add(lineSegments)

  loading.value = false
  animate()

  // Fetch clusters and snapshots in parallel after scene is built
  fetchClusters()
  fetchSnapshots()
  fetchAlignment()
}

// ── Heat sphere management ────────────────────────────────────────────────────
function updateHeatSpheres() {
  // Remove old spheres
  for (const { mesh } of heatSpheres) {
    scene.remove(mesh)
    mesh.geometry.dispose()
    mesh.material.dispose()
  }
  heatSpheres = []

  if (!showHeat.value || !scene) return

  for (const cluster of clusters.value) {
    if (cluster.node_ids.length === 0) continue

    // Cluster centroid = average of member node positions
    const centroid = new THREE.Vector3()
    let count = 0
    for (const nodeId of cluster.node_ids) {
      const pos = nodePositions.get(nodeId)
      if (pos) {
        centroid.add(pos)
        count++
      }
    }
    if (count === 0) continue
    centroid.divideScalar(count)

    const radius = Math.sqrt(cluster.node_count)
    const geo = new THREE.SphereGeometry(radius, 16, 16)
    const mat = new THREE.MeshBasicMaterial({
      color: clusterHeatColor(cluster.mean_weight),
      transparent: true,
      opacity: 0.08,
      depthWrite: false,
    })
    const mesh = new THREE.Mesh(geo, mat)
    mesh.position.copy(centroid)
    scene.add(mesh)
    heatSpheres.push({ mesh, clusterId: cluster.id })
  }
}

function recolorHeatSpheres(historicalClusters) {
  const clusterById = new Map(historicalClusters.map(c => [c.id, c]))
  for (const { mesh, clusterId } of heatSpheres) {
    const cluster = clusterById.get(clusterId)
    if (cluster) {
      mesh.material.color.copy(clusterHeatColor(cluster.mean_weight))
      mesh.material.needsUpdate = true
    }
  }
}

watch(showHeat, () => {
  if (!scene) return
  if (showHeat.value) {
    updateHeatSpheres()
  } else {
    for (const { mesh } of heatSpheres) {
      scene.remove(mesh)
      mesh.geometry.dispose()
      mesh.material.dispose()
    }
    heatSpheres = []
  }
})

// ── Simulation tick ───────────────────────────────────────────────────────────
async function runSimTick() {
  if (simTickInFlight) return
  simTickInFlight = true
  const gen = simGeneration

  try {
    const res = await fetch('/api/graph/simulate', {
      method: 'POST',
      headers: csrfHeaders(),
    })
    if (gen !== simGeneration) return // stale response — discard
    const data = await res.json()

    simTick.value++
    simActiveCount.value = (data.active_node_ids ?? []).length

    // Flash active nodes
    flashActiveNodes(data.active_node_ids ?? [])

    // Update edge weights in-place
    updateEdgeColors(data.updated_edges ?? [])
  } catch (_) {
    // Network error — skip tick
  } finally {
    simTickInFlight = false
  }
}

function flashActiveNodes(activeIds) {
  if (!instancedMesh) return
  const matrix = new THREE.Matrix4()
  const scaleUp = new THREE.Vector3(1.6, 1.6, 1.6)
  const scaleNormal = new THREE.Vector3(1, 1, 1)

  for (const nodeId of activeIds) {
    const idx = nodeIndexMap.get(nodeId)
    if (idx === undefined) continue
    instancedMesh.getMatrixAt(idx, matrix)
    const pos = new THREE.Vector3()
    pos.setFromMatrixPosition(matrix)
    matrix.compose(pos, new THREE.Quaternion(), scaleUp)
    instancedMesh.setMatrixAt(idx, matrix)
  }
  instancedMesh.instanceMatrix.needsUpdate = true

  // Reset scale after 400ms
  setTimeout(() => {
    if (!instancedMesh) return
    for (const nodeId of activeIds) {
      const idx = nodeIndexMap.get(nodeId)
      if (idx === undefined) continue
      instancedMesh.getMatrixAt(idx, matrix)
      const pos = new THREE.Vector3()
      pos.setFromMatrixPosition(matrix)
      matrix.compose(pos, new THREE.Quaternion(), scaleNormal)
      instancedMesh.setMatrixAt(idx, matrix)
    }
    instancedMesh.instanceMatrix.needsUpdate = true
  }, 400)
}

function updateEdgeColors(updatedEdges) {
  if (!colorAttr || updatedEdges.length === 0) return
  const colors = colorAttr.array

  for (const edge of updatedEdges) {
    const k = edgeIndexMap.get(edge.id)
    if (k === undefined) continue
    const g = edge.weight * 0.85
    colors[k * 6 + 0] = g; colors[k * 6 + 1] = g; colors[k * 6 + 2] = g
    colors[k * 6 + 3] = g; colors[k * 6 + 4] = g; colors[k * 6 + 5] = g
  }

  colorAttr.needsUpdate = true
}

// ── Simulate all agents ───────────────────────────────────────────────────────
async function runSimulateAll() {
  await fetch('/api/agents/simulate-all', {
    method: 'POST',
    headers: csrfHeaders(),
  })
  fetchAlignment()
}

// ── Simulation controls ───────────────────────────────────────────────────────
function toggleSimulation() {
  simRunning.value ? stopSimulation() : startSimulation()
}

function startSimulation() {
  simRunning.value = true
  simGeneration++
  simIntervalId = setInterval(runSimTick, simSpeedMs.value)
}

function stopSimulation() {
  simRunning.value = false
  simGeneration++
  if (simIntervalId) {
    clearInterval(simIntervalId)
    simIntervalId = null
  }
}

function setSimSpeed(ms) {
  simSpeedMs.value = ms
  if (simRunning.value) {
    stopSimulation()
    startSimulation()
  }
}

// ── Temporal scrubber ─────────────────────────────────────────────────────────
function onScrub(value) {
  const idx = parseInt(value, 10)
  scrubberIndex.value = idx
  if (idx >= snapshots.value.length) {
    fetchClusters() // restore live state
  } else {
    fetchSnapshotAt(idx)
  }
}

// ── Render loop ───────────────────────────────────────────────────────────────
function animate() {
  requestAnimationFrame(animate)
  controls.update()
  renderer.render(scene, camera)
}

// ── Resize handler ────────────────────────────────────────────────────────────
function onResize() {
  if (!renderer || !camera) return
  camera.aspect = window.innerWidth / window.innerHeight
  camera.updateProjectionMatrix()
  renderer.setSize(window.innerWidth, window.innerHeight)
}

function csrfHeaders() {
  const token = document.querySelector('meta[name="csrf-token"]')?.content ?? ''

  return token ? { 'X-CSRF-TOKEN': token } : {}
}

// ── Lifecycle ─────────────────────────────────────────────────────────────────
onMounted(async () => {
  window.addEventListener('resize', onResize)
  await buildScene()
})

onUnmounted(() => {
  stopSimulation()
  window.removeEventListener('resize', onResize)
  if (renderer) {
    renderer.dispose()
  }
})
</script>

<style scoped>
.slide-right-enter-active,
.slide-right-leave-active {
  transition: opacity 0.2s, transform 0.2s;
}
.slide-right-enter-from,
.slide-right-leave-to {
  opacity: 0;
  transform: translateX(8px);
}
</style>
