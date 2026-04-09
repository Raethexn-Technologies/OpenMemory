<template>
  <div class="relative w-screen h-screen bg-slate-950 overflow-hidden select-none">

    <!-- ── Three.js canvas container ── -->
    <div ref="canvasContainer" class="absolute inset-0"></div>

    <!-- ── Top-left header bar ── -->
    <div class="absolute top-0 left-0 right-0 flex items-center gap-3 px-4 py-3 bg-slate-950/80 backdrop-blur border-b border-slate-800 z-10">
      <div class="flex items-center gap-2">
        <a href="/chat" class="text-slate-500 hover:text-slate-300 text-sm">← Chat</a>
        <span class="text-slate-700">|</span>
        <a href="/memory" class="text-slate-500 hover:text-slate-300 text-sm">Memory</a>
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
import { EffectComposer } from 'three/examples/jsm/postprocessing/EffectComposer.js'
import { RenderPass } from 'three/examples/jsm/postprocessing/RenderPass.js'
import { UnrealBloomPass } from 'three/examples/jsm/postprocessing/UnrealBloomPass.js'

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
let composer = null      // post-processing chain (bloom)
let instancedMesh = null
let auraMesh = null      // glow halo layer
let starField = null     // background star points
let lineSegments = null
let particleSystem = null  // traversal particles that travel along edges
let nebulaMeshes = []    // central atmospheric layered spheres
let ambientDust = null   // slow-drifting background particle field
let heatSpheres = [] // {mesh, clusterId}
let activePulses = []    // expanding wireframe rings spawned on node activation
let particleSlots = []   // pool of reusable particle slots
let edgeEndpoints = []   // [{from, to, sourceId, targetId, weight}] for particle spawning
let edgeEndpointMap = new Map() // edge_id -> edge endpoint record for live weight updates
const MAX_PARTICLES = 400
let nodePositions = new Map()   // node_uuid -> THREE.Vector3
let nodeIndexMap = new Map()    // node_uuid -> instancedMesh index
let nodeScaleMap = new Map()    // node_uuid -> base render scale
let edgeIndexMap = new Map()    // edge_id -> byte offset in color Float32Array
let colorAttr = null            // reference to lineSegments color BufferAttribute
let animationFrameId = null

// ── Colors ────────────────────────────────────────────────────────────────────
const NODE_TYPE_COLORS = {
  memory:   0x38bdf8,
  person:   0x4ade80,
  project:  0xa78bfa,
  document: 0xfbbf24,
  task:     0xfb923c,
  event:    0xf472b6,
  concept:  0x94a3b8,
  goal:     0x84cc16,
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

function intraEdgeBrightness(weight) {
  return 0.15 + weight * 0.75
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

/**
 * Fetch all graph partitions (personal + one per agent) in parallel.
 *
 * Returns:
 *   partitions   - Map<partitionId, {nodes, edges}>
 *   contentMap   - Map<contentString, Set<partitionId>> for shared-node detection
 *   sharedEdges  - raw shared-edge summary from /api/agents/shared-edges
 */
async function fetchPartitions() {
  const requests = [fetch('/api/graph?sensitivity[]=public')]
  for (const agent of props.agents) {
    requests.push(fetch(`/api/agents/${agent.id}/graph`))
  }
  requests.push(fetch('/api/agents/shared-edges'))

  const responses = await Promise.all(requests)
  const payloads = await Promise.all(responses.map(r => r.json()))

  const personalData = payloads[0]
  const partitions = new Map()
  partitions.set('__personal__', personalData)

  for (let i = 0; i < props.agents.length; i++) {
    partitions.set(props.agents[i].id, payloads[i + 1])
  }

  const sharedEdges = payloads[payloads.length - 1]?.shared_edges ?? []

  // Build content -> [partitionIds] map for shared-node detection.
  // Nodes with identical content strings across partitions represent
  // the same underlying memory appearing in multiple agent subgraphs.
  const contentMap = new Map()
  for (const [partitionId, data] of partitions) {
    for (const node of (data.nodes ?? [])) {
      if (!contentMap.has(node.content)) contentMap.set(node.content, new Set())
      contentMap.get(node.content).add(partitionId)
    }
  }

  return { partitions, contentMap, sharedEdges }
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

// ── Helpers ───────────────────────────────────────────────────────────────────
function randomOnSphere(r) {
  const theta = Math.random() * 2 * Math.PI
  const phi = Math.acos(2 * Math.random() - 1)
  return new THREE.Vector3(
    r * Math.sin(phi) * Math.cos(theta),
    r * Math.sin(phi) * Math.sin(theta),
    r * Math.cos(phi),
  )
}

function disposeScene() {
  if (animationFrameId !== null) {
    cancelAnimationFrame(animationFrameId)
    animationFrameId = null
  }

  for (const { mesh } of heatSpheres) {
    scene?.remove(mesh)
    mesh.geometry.dispose()
    mesh.material.dispose()
  }
  heatSpheres = []

  for (const mesh of nebulaMeshes) {
    scene?.remove(mesh)
    mesh.geometry.dispose()
    mesh.material.dispose()
  }
  nebulaMeshes = []

  if (ambientDust) {
    scene?.remove(ambientDust)
    ambientDust.geometry.dispose()
    ambientDust.material.dispose()
    ambientDust = null
  }

  if (starField) {
    scene?.remove(starField)
    starField.geometry.dispose()
    starField.material.dispose()
    starField = null
  }

  if (particleSystem) {
    scene?.remove(particleSystem)
    particleSystem.geometry.dispose()
    particleSystem.material.dispose()
    particleSystem = null
  }

  for (const { mesh } of activePulses) {
    scene?.remove(mesh)
    mesh.geometry.dispose()
    mesh.material.dispose()
  }
  activePulses = []
  particleSlots = []
  edgeEndpoints = []
  edgeEndpointMap = new Map()

  if (lineSegments) {
    scene?.remove(lineSegments)
    lineSegments.geometry.dispose()
    lineSegments.material.dispose()
    lineSegments = null
  }

  if (auraMesh) {
    scene?.remove(auraMesh)
    auraMesh.geometry.dispose()
    auraMesh.material.dispose()
    auraMesh = null
  }

  if (instancedMesh) {
    scene?.remove(instancedMesh)
    instancedMesh.geometry.dispose()
    instancedMesh.material.dispose()
    instancedMesh = null
  }

  if (composer) {
    composer.dispose()
    composer = null
  }

  if (controls) {
    controls.dispose()
    controls = null
  }

  if (renderer) {
    const canvas = renderer.domElement
    renderer.dispose()
    if (canvas.parentNode === canvasContainer.value) {
      canvasContainer.value.removeChild(canvas)
    }
    renderer = null
  }

  scene = null
  camera = null
  clock = null
  colorAttr = null
  nodePositions = new Map()
  nodeIndexMap = new Map()
  nodeScaleMap = new Map()
  edgeIndexMap = new Map()
}

async function rebuildScene() {
  loading.value = true
  disposeScene()
  await buildScene()
}

// ── Scene construction ────────────────────────────────────────────────────────
async function buildScene() {
  // Renderer
  renderer = new THREE.WebGLRenderer({ antialias: true })
  renderer.setPixelRatio(window.devicePixelRatio)
  renderer.setSize(window.innerWidth, window.innerHeight)
  renderer.setClearColor(0x020817)
  renderer.toneMapping = THREE.ACESFilmicToneMapping
  renderer.toneMappingExposure = 1.4
  canvasContainer.value.appendChild(renderer.domElement)

  // Post-processing bloom — the visual upgrade that makes nodes and edges
  // genuinely glow rather than just using transparent halos as a substitute.
  const renderPass = new RenderPass(scene, camera)
  const bloomPass = new UnrealBloomPass(
    new THREE.Vector2(window.innerWidth, window.innerHeight),
    0.9,   // strength  — overall bloom intensity
    0.55,  // radius    — how far bloom spreads from each bright pixel
    0.08,  // threshold — luminance level above which bloom kicks in
  )
  composer = new EffectComposer(renderer)
  composer.addPass(renderPass)
  composer.addPass(bloomPass)

  // Scene + camera
  scene = new THREE.Scene()
  scene.fog = new THREE.FogExp2(0x020817, 0.0018)
  camera = new THREE.PerspectiveCamera(60, window.innerWidth / window.innerHeight, 0.1, 2000)
  camera.position.set(0, 60, 160)
  camera.lookAt(0, 0, 0)

  controls = new OrbitControls(camera, renderer.domElement)
  controls.enableDamping = true
  controls.dampingFactor = 0.08
  controls.autoRotate = true
  controls.autoRotateSpeed = 0.4

  scene.add(new THREE.AmbientLight(0x334155, 0.8))
  const dirLight = new THREE.DirectionalLight(0x94c5ff, 0.3)
  dirLight.position.set(0, 100, 50)
  scene.add(dirLight)

  clock = new THREE.Clock()

  // ── Starfield background ──────────────────────────────────────────────────
  // Mixed-color starfield: mostly white, with cyan, violet, and amber accent
  // stars so the backdrop feels like deep space rather than a flat dot field.
  const starCount = 3200
  const starPos = new Float32Array(starCount * 3)
  const starColors = new Float32Array(starCount * 3)
  // Palette: white (frequent) + node type colors (sparse)
  const starPalette = [
    [1.00, 1.00, 1.00], [1.00, 1.00, 1.00], [1.00, 1.00, 1.00], [1.00, 1.00, 1.00],
    [0.22, 0.74, 0.97], // sky-400 cyan
    [0.65, 0.55, 0.98], // violet-400
    [0.98, 0.75, 0.14], // amber-400
    [0.30, 0.86, 0.50], // emerald-400
  ]
  for (let i = 0; i < starCount; i++) {
    const r = 380 + Math.random() * 280
    const theta = Math.random() * Math.PI * 2
    const phi = Math.acos(2 * Math.random() - 1)
    starPos[i * 3]     = r * Math.sin(phi) * Math.cos(theta)
    starPos[i * 3 + 1] = r * Math.sin(phi) * Math.sin(theta)
    starPos[i * 3 + 2] = r * Math.cos(phi)
    const col = starPalette[Math.floor(Math.random() * starPalette.length)]
    starColors[i * 3]     = col[0]
    starColors[i * 3 + 1] = col[1]
    starColors[i * 3 + 2] = col[2]
  }
  const starGeo = new THREE.BufferGeometry()
  starGeo.setAttribute('position', new THREE.BufferAttribute(starPos, 3))
  starGeo.setAttribute('color',    new THREE.BufferAttribute(starColors, 3))
  starField = new THREE.Points(starGeo, new THREE.PointsMaterial({
    vertexColors: true,
    size: 0.95,
    sizeAttenuation: true,
    transparent: true,
    opacity: 0.70,
  }))
  scene.add(starField)

  // ── Central nebula ────────────────────────────────────────────────────────
  // Layered transparent spheres create a "glowing planet" at the graph origin.
  // The innermost layer is brightest; outer layers fall off to a barely-visible
  // halo. The bloom pass turns the bright core into a genuine glow.
  // Each layer uses BackSide so the geometry is visible from outside the sphere,
  // creating a soft volumetric-like appearance rather than a hard ball.
  const nebulaLayers = [
    { r: 5,  color: 0x818cf8, opacity: 0.60 }, // indigo core — bright enough to bloom
    { r: 12, color: 0x6366f1, opacity: 0.20 }, // mid indigo atmosphere
    { r: 24, color: 0x4338ca, opacity: 0.09 }, // outer halo
    { r: 48, color: 0x1e1b4b, opacity: 0.04 }, // distant deep-space glow
  ]
  nebulaMeshes = nebulaLayers.map(({ r, color, opacity }) => {
    const geo = new THREE.SphereGeometry(r, 32, 32)
    const mat = new THREE.MeshBasicMaterial({
      color,
      transparent: true,
      opacity,
      blending: THREE.AdditiveBlending,
      depthWrite: false,
      side: THREE.BackSide,
    })
    const mesh = new THREE.Mesh(geo, mat)
    scene.add(mesh)
    return mesh
  })

  // ── Ambient dust ──────────────────────────────────────────────────────────
  // Slow-drifting particles that float through the graph space continuously,
  // giving the scene a "living" quality even when the simulation is not running.
  const DUST_COUNT = 280
  const dustPos = new Float32Array(DUST_COUNT * 3)
  for (let i = 0; i < DUST_COUNT; i++) {
    dustPos[i * 3]     = (Math.random() - 0.5) * 160
    dustPos[i * 3 + 1] = (Math.random() - 0.5) * 100
    dustPos[i * 3 + 2] = (Math.random() - 0.5) * 160
  }
  const dustGeo = new THREE.BufferGeometry()
  dustGeo.setAttribute('position', new THREE.BufferAttribute(dustPos, 3))
  ambientDust = new THREE.Points(dustGeo, new THREE.PointsMaterial({
    color: 0x94c5ff,
    size: 0.22,
    transparent: true,
    opacity: 0.35,
    blending: THREE.AdditiveBlending,
    depthWrite: false,
    sizeAttenuation: true,
  }))
  scene.add(ambientDust)

  // ── Fetch all partition data ──────────────────────────────────────────────
  const { partitions, contentMap, sharedEdges } = await fetchPartitions()

  // Count all unique nodes across all partitions
  let nodeCount = 0
  for (const data of partitions.values()) {
    nodeCount += (data.nodes ?? []).length
  }
  totalNodeCount.value = nodeCount

  if (nodeCount === 0) {
    loading.value = false
    animate()
    return
  }

  // ── Compute centroids ─────────────────────────────────────────────────────
  // Agent partitions arranged in a ring in the XZ plane.
  // Personal graph sits above origin on the Y axis so it is visually distinct.
  const agentCount = props.agents.length
  const agentCentroids = {}
  const ringRadius = agentCount > 0 ? 80 : 0
  props.agents.forEach((agent, i) => {
    const angle = (2 * Math.PI * i) / agentCount
    agentCentroids[agent.id] = new THREE.Vector3(
      ringRadius * Math.cos(angle),
      0,
      ringRadius * Math.sin(angle),
    )
  })
  const personalCentroid = agentCount > 0
    ? new THREE.Vector3(0, 50, 0)
    : new THREE.Vector3(0, 0, 0)

  // centroidFor(partitionId) — used when positioning shared nodes
  function centroidFor(partitionId) {
    if (partitionId === '__personal__') return personalCentroid
    return agentCentroids[partitionId] ?? new THREE.Vector3(0, 0, 0)
  }

  // ── Assign 3D positions ───────────────────────────────────────────────────
  // A node whose content string appears in more than one partition is "shared".
  // Its position is the average of the contributing partition centroids, offset
  // upward on Y so it visually floats between the subgraphs.
  //
  // Intra-partition nodes are scattered within a sphere of radius 20 around
  // their partition centroid.

  // Collect all node records tagged with their partition
  const allNodes = [] // {node, partitionId, isShared}
  for (const [partitionId, data] of partitions) {
    for (const node of (data.nodes ?? [])) {
      const contributingPartitions = contentMap.get(node.content) ?? new Set()
      const isShared = contributingPartitions.size > 1
      allNodes.push({ node, partitionId, isShared })
    }
  }

  for (const { node, partitionId, isShared } of allNodes) {
    if (isShared) {
      const contributing = [...(contentMap.get(node.content) ?? new Set([partitionId]))]
      const avg = new THREE.Vector3()
      for (const pid of contributing) avg.add(centroidFor(pid))
      avg.divideScalar(contributing.length)
      avg.y += 12 // float above the XZ plane so shared nodes are visually distinct
      nodePositions.set(node.id, avg)
    } else {
      nodePositions.set(node.id, centroidFor(partitionId).clone().add(randomOnSphere(20)))
    }
  }

  // ── Build deterministic node index map ────────────────────────────────────
  const sortedNodeIds = [...nodePositions.keys()].sort()
  sortedNodeIds.forEach((id, index) => nodeIndexMap.set(id, index))

  // ── Pre-compute degree map for hub-node sizing ────────────────────────────
  // Degree = number of edges touching a node. Hub nodes (high degree) will be
  // rendered larger so the scale-free topology is immediately visible.
  const degreeMap = new Map()
  for (const [, data] of partitions) {
    for (const edge of (data.edges ?? [])) {
      degreeMap.set(edge.source, (degreeMap.get(edge.source) ?? 0) + 1)
      degreeMap.set(edge.target, (degreeMap.get(edge.target) ?? 0) + 1)
    }
  }
  const maxDegree = Math.max(...degreeMap.values(), 1)

  // ── InstancedMesh for all nodes ───────────────────────────────────────────
  const nodeGeo = new THREE.SphereGeometry(0.8, 14, 14)
  instancedMesh = new THREE.InstancedMesh(
    nodeGeo,
    new THREE.MeshBasicMaterial({ vertexColors: true }),
    allNodes.length,
  )
  instancedMesh.instanceMatrix.setUsage(THREE.DynamicDrawUsage)
  instancedMesh.instanceColor = new THREE.InstancedBufferAttribute(
    new Float32Array(allNodes.length * 3), 3,
  )

  // Glow aura — larger transparent sphere behind each node
  const auraGeo = new THREE.SphereGeometry(0.8, 8, 8)
  auraMesh = new THREE.InstancedMesh(
    auraGeo,
    new THREE.MeshBasicMaterial({
      vertexColors: true,
      transparent: true,
      opacity: 0.14,
      blending: THREE.AdditiveBlending,
      depthWrite: false,
    }),
    allNodes.length,
  )
  auraMesh.instanceMatrix.setUsage(THREE.DynamicDrawUsage)
  auraMesh.instanceColor = new THREE.InstancedBufferAttribute(
    new Float32Array(allNodes.length * 3), 3,
  )

  const matrix = new THREE.Matrix4()
  const color = new THREE.Color()

  for (const { node, isShared } of allNodes) {
    const idx = nodeIndexMap.get(node.id)
    if (idx === undefined) continue
    const pos = nodePositions.get(node.id)
    // Hub nodes scale up to 4× based on degree — scale-free topology becomes
    // immediately visible as a few large hubs surrounded by sparse leaf nodes.
    const nodeDegree = degreeMap.get(node.id) ?? 0
    const hubScale = 1.0 + (nodeDegree / maxDegree) * 3.2
    const scale = isShared ? Math.max(2.5, hubScale) : hubScale
    nodeScaleMap.set(node.id, scale)
    matrix.compose(pos, new THREE.Quaternion(), new THREE.Vector3(scale, scale, scale))
    instancedMesh.setMatrixAt(idx, matrix)
    // Aura is proportional — hub nodes have a larger halo
    const auraScale = scale * 4.0
    matrix.compose(pos, new THREE.Quaternion(), new THREE.Vector3(auraScale, auraScale, auraScale))
    auraMesh.setMatrixAt(idx, matrix)
    if (isShared) {
      color.set('#8b5cf6') // violet for shared nodes
    } else {
      color.setHex(NODE_TYPE_COLORS[node.type] ?? NODE_TYPE_COLORS.memory)
    }
    instancedMesh.setColorAt(idx, color)
    auraMesh.setColorAt(idx, color)
  }

  instancedMesh.instanceMatrix.needsUpdate = true
  instancedMesh.instanceColor.needsUpdate = true
  auraMesh.instanceMatrix.needsUpdate = true
  auraMesh.instanceColor.needsUpdate = true
  scene.add(auraMesh)  // aura first (behind nodes)
  scene.add(instancedMesh)

  // ── Collect all edges ─────────────────────────────────────────────────────
  // Two types:
  //   intra-partition edges — from memory_edges within a partition (grey, weight-opacity)
  //   cross-partition edges — from shared_memory_edges between agents (violet, weight-opacity)

  const intraEdges = [] // {id, source, target, weight}
  for (const data of partitions.values()) {
    for (const edge of (data.edges ?? [])) {
      // Only include edges where both endpoints have positions
      if (nodePositions.has(edge.source) && nodePositions.has(edge.target)) {
        intraEdges.push(edge)
      }
    }
  }

  const crossEdges = sharedEdges.filter(
    e => nodePositions.has(e.node_a_id) && nodePositions.has(e.node_b_id),
  )

  const totalEdges = intraEdges.length + crossEdges.length
  if (totalEdges === 0) {
    loading.value = false
    animate()
    fetchClusters()
    fetchSnapshots()
    fetchAlignment()
    return
  }

  const edgePositions = new Float32Array(totalEdges * 6)
  const edgeColors = new Float32Array(totalEdges * 6)

  // Intra-partition edges: cyan tint, weight-scaled brightness, additive blending
  // Also stored in edgeEndpoints so particles can travel them during simulation.
  intraEdges.forEach((edge, k) => {
    const fromPos = nodePositions.get(edge.source)
    const toPos = nodePositions.get(edge.target)
    edgeIndexMap.set(edge.id, k)
    edgePositions[k * 6 + 0] = fromPos.x; edgePositions[k * 6 + 1] = fromPos.y; edgePositions[k * 6 + 2] = fromPos.z
    edgePositions[k * 6 + 3] = toPos.x;   edgePositions[k * 6 + 4] = toPos.y;   edgePositions[k * 6 + 5] = toPos.z
    // sky-400 (#38bdf8) = R:0.220 G:0.741 B:0.973 — brighter for heavier edges
    const b = intraEdgeBrightness(edge.weight)
    edgeColors[k * 6 + 0] = 0.220 * b; edgeColors[k * 6 + 1] = 0.741 * b; edgeColors[k * 6 + 2] = 0.973 * b
    edgeColors[k * 6 + 3] = 0.220 * b; edgeColors[k * 6 + 4] = 0.741 * b; edgeColors[k * 6 + 5] = 0.973 * b
    // Store for particle traversal
    const endpoint = {
      id: edge.id,
      from: fromPos.clone(),
      to: toPos.clone(),
      sourceId: edge.source,
      targetId: edge.target,
      weight: edge.weight,
    }
    edgeEndpoints.push(endpoint)
    edgeEndpointMap.set(edge.id, endpoint)
  })

  // Cross-partition shared edges: violet, brighter with higher weight
  // These are the connections "between files in different folders" — the signal
  // that two agent subgraphs have converged on the same underlying memory.
  const offset = intraEdges.length
  crossEdges.forEach((edge, i) => {
    const k = offset + i
    const fromPos = nodePositions.get(edge.node_a_id)
    const toPos = nodePositions.get(edge.node_b_id)
    edgePositions[k * 6 + 0] = fromPos.x; edgePositions[k * 6 + 1] = fromPos.y; edgePositions[k * 6 + 2] = fromPos.z
    edgePositions[k * 6 + 3] = toPos.x;   edgePositions[k * 6 + 4] = toPos.y;   edgePositions[k * 6 + 5] = toPos.z
    // violet: R=0.545, G=0.361, B=0.965 scaled by weight
    const v = 0.35 + edge.weight * 0.5
    edgeColors[k * 6 + 0] = 0.545 * v; edgeColors[k * 6 + 1] = 0.361 * v; edgeColors[k * 6 + 2] = 0.965 * v
    edgeColors[k * 6 + 3] = 0.545 * v; edgeColors[k * 6 + 4] = 0.361 * v; edgeColors[k * 6 + 5] = 0.965 * v
  })

  const lineGeo = new THREE.BufferGeometry()
  lineGeo.setAttribute('position', new THREE.BufferAttribute(edgePositions, 3))
  colorAttr = new THREE.BufferAttribute(edgeColors, 3)
  colorAttr.setUsage(THREE.DynamicDrawUsage)
  lineGeo.setAttribute('color', colorAttr)

  lineSegments = new THREE.LineSegments(
    lineGeo,
    new THREE.LineBasicMaterial({
      vertexColors: true,
      blending: THREE.AdditiveBlending,
      transparent: true,
    }),
  )
  scene.add(lineSegments)

  // ── Traversal particle system ─────────────────────────────────────────────
  // Pre-allocate MAX_PARTICLES slots. During simulation ticks, particles are
  // plucked from the pool, moved along edge vectors each frame, then released.
  const particleGeo = new THREE.SphereGeometry(0.32, 5, 5)
  particleSystem = new THREE.InstancedMesh(
    particleGeo,
    new THREE.MeshBasicMaterial({
      color: 0xffffff,
      transparent: true,
      blending: THREE.AdditiveBlending,
      depthWrite: false,
    }),
    MAX_PARTICLES,
  )
  particleSystem.instanceMatrix.setUsage(THREE.DynamicDrawUsage)
  const hiddenMatrix = new THREE.Matrix4().makeScale(0.001, 0.001, 0.001)
  for (let i = 0; i < MAX_PARTICLES; i++) particleSystem.setMatrixAt(i, hiddenMatrix)
  particleSystem.instanceMatrix.needsUpdate = true
  scene.add(particleSystem)

  particleSlots = Array.from({ length: MAX_PARTICLES }, () => ({
    active: false, from: null, to: null, progress: 0, speed: 0.014,
  }))

  loading.value = false
  animate()

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
  const activeSet = new Set(activeIds)

  // Scale up active nodes and spawn expanding pulse rings at each
  for (const nodeId of activeIds) {
    const idx = nodeIndexMap.get(nodeId)
    if (idx === undefined) continue
    instancedMesh.getMatrixAt(idx, matrix)
    const pos = new THREE.Vector3()
    pos.setFromMatrixPosition(matrix)
    const baseScale = nodeScaleMap.get(nodeId) ?? 1
    matrix.compose(pos, new THREE.Quaternion(), new THREE.Vector3(baseScale * 1.8, baseScale * 1.8, baseScale * 1.8))
    instancedMesh.setMatrixAt(idx, matrix)
    // Pulse ring — an expanding wireframe sphere that fades out
    spawnPulse(pos, 0x38bdf8)
  }
  instancedMesh.instanceMatrix.needsUpdate = true

  // Spawn traversal particles along every edge touching an active node
  for (const ep of edgeEndpoints) {
    const srcActive = activeSet.has(ep.sourceId)
    const tgtActive = activeSet.has(ep.targetId)
    if (!srcActive && !tgtActive) continue
    // Particle flows from source to target
    spawnParticle(ep.from, ep.to, ep.weight)
    // Heavier edges emit a return particle too (bidirectional signal)
    if (ep.weight > 0.55) spawnParticle(ep.to, ep.from, ep.weight)
  }

  // Reset node scale after flash
  setTimeout(() => {
    if (!instancedMesh) return
    for (const nodeId of activeIds) {
      const idx = nodeIndexMap.get(nodeId)
      if (idx === undefined) continue
      instancedMesh.getMatrixAt(idx, matrix)
      const pos = new THREE.Vector3()
      pos.setFromMatrixPosition(matrix)
      const baseScale = nodeScaleMap.get(nodeId) ?? 1
      matrix.compose(pos, new THREE.Quaternion(), new THREE.Vector3(baseScale, baseScale, baseScale))
      instancedMesh.setMatrixAt(idx, matrix)
    }
    instancedMesh.instanceMatrix.needsUpdate = true
  }, 450)
}

function updateEdgeColors(updatedEdges) {
  if (!colorAttr || updatedEdges.length === 0) return
  const colors = colorAttr.array

  for (const edge of updatedEdges) {
    const k = edgeIndexMap.get(edge.id)
    if (k === undefined) continue
    const endpoint = edgeEndpointMap.get(edge.id)
    if (endpoint) endpoint.weight = edge.weight
    const b = intraEdgeBrightness(edge.weight)
    colors[k * 6 + 0] = 0.220 * b; colors[k * 6 + 1] = 0.741 * b; colors[k * 6 + 2] = 0.973 * b
    colors[k * 6 + 3] = 0.220 * b; colors[k * 6 + 4] = 0.741 * b; colors[k * 6 + 5] = 0.973 * b
  }

  colorAttr.needsUpdate = true
}

// ── Simulate all agents ───────────────────────────────────────────────────────
async function runSimulateAll() {
  const wasRunning = simRunning.value
  stopSimulation()

  const res = await fetch('/api/agents/simulate-all', {
    method: 'POST',
    headers: csrfHeaders(),
  })

  if (!res.ok) {
    if (wasRunning) startSimulation()
    return
  }

  await rebuildScene()

  if (wasRunning) startSimulation()
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

// ── Traversal particle helpers ────────────────────────────────────────────────

function spawnParticle(from, to, weight) {
  const slot = particleSlots.find(s => !s.active)
  if (!slot) return
  slot.active = true
  slot.from = from
  slot.to = to
  slot.progress = 0
  slot.speed = 0.011 + weight * 0.009  // heavier edges carry faster signals
}

function spawnPulse(pos, hexColor) {
  if (!scene) return
  const geo = new THREE.SphereGeometry(1, 10, 10)
  const mat = new THREE.MeshBasicMaterial({
    color: hexColor,
    transparent: true,
    opacity: 0.55,
    blending: THREE.AdditiveBlending,
    depthWrite: false,
    wireframe: true,
  })
  const mesh = new THREE.Mesh(geo, mat)
  mesh.position.copy(pos)
  scene.add(mesh)
  activePulses.push({ mesh, time: 0, maxTime: 1.1 })
}

function updateParticles() {
  if (!particleSystem || particleSlots.length === 0) return
  const mat = new THREE.Matrix4()
  const pos = new THREE.Vector3()

  for (let i = 0; i < MAX_PARTICLES; i++) {
    const slot = particleSlots[i]
    if (!slot.active) {
      mat.makeScale(0.001, 0.001, 0.001)
      particleSystem.setMatrixAt(i, mat)
      continue
    }
    slot.progress += slot.speed
    if (slot.progress >= 1.0) {
      slot.active = false
      mat.makeScale(0.001, 0.001, 0.001)
    } else {
      pos.lerpVectors(slot.from, slot.to, slot.progress)
      // Ease in and out — particle is largest at the midpoint
      const ease = Math.sin(slot.progress * Math.PI)
      const s = 0.08 + ease * 0.55
      mat.compose(pos, new THREE.Quaternion(), new THREE.Vector3(s, s, s))
    }
    particleSystem.setMatrixAt(i, mat)
  }
  particleSystem.instanceMatrix.needsUpdate = true
}

function updatePulses() {
  if (!scene) return
  for (let i = activePulses.length - 1; i >= 0; i--) {
    const pulse = activePulses[i]
    pulse.time += 0.016
    const t = pulse.time / pulse.maxTime
    if (t >= 1) {
      scene.remove(pulse.mesh)
      pulse.mesh.geometry.dispose()
      pulse.mesh.material.dispose()
      activePulses.splice(i, 1)
      continue
    }
    pulse.mesh.scale.setScalar(1 + t * 11)
    pulse.mesh.material.opacity = 0.5 * (1 - t)
    pulse.mesh.material.needsUpdate = true
  }
}

// ── Render loop ───────────────────────────────────────────────────────────────
function animate() {
  animationFrameId = requestAnimationFrame(animate)
  const delta = clock ? clock.getDelta() : 0.016

  // Pause auto-orbit during simulation so the user can watch traversal clearly
  if (controls) controls.autoRotate = !simRunning.value
  controls.update()

  // Nebula breathes slowly — the innermost core pulses in opacity
  if (nebulaMeshes.length > 0) {
    const t = performance.now() * 0.0004
    nebulaMeshes[0].material.opacity = 0.50 + Math.sin(t) * 0.12
    nebulaMeshes[0].material.needsUpdate = true
  }

  // Ambient dust drifts — two slow counter-rotating axes give chaotic but gentle motion
  if (ambientDust) {
    ambientDust.rotation.y += delta * 0.018
    ambientDust.rotation.x += delta * 0.009
  }

  updateParticles()
  updatePulses()

  // Use the bloom composer when available, fall back to plain renderer
  if (composer) {
    composer.render()
  } else {
    renderer.render(scene, camera)
  }
}

// ── Resize handler ────────────────────────────────────────────────────────────
function onResize() {
  if (!renderer || !camera) return
  camera.aspect = window.innerWidth / window.innerHeight
  camera.updateProjectionMatrix()
  renderer.setSize(window.innerWidth, window.innerHeight)
  if (composer) composer.setSize(window.innerWidth, window.innerHeight)
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
  disposeScene()
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
