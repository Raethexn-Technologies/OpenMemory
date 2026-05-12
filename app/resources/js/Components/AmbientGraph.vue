<template>
  <canvas
    ref="canvasEl"
    class="ambient-graph"
    aria-hidden="true"
  />
</template>

<script setup>
// Ambient background visualization for the chat page.
//
// Renders the public hive-mind graph as drifting nodes and faint edges behind
// the chat surface. Pulls from /api/graph/ambient, which returns anonymised
// public memory nodes from every visitor along with a `mine` flag for the
// session user. Own nodes render in their type colour at higher brightness;
// others' nodes render in dim slate. This is the visual proof that the user
// is contributing to a collective rather than a private store.
//
// The canvas is pointer-events:none and sits below all chat content. The
// parent can call `pulse()` via the exposed ref to trigger a flash when the
// user has just sent a message and a new memory landed.

import { onMounted, onUnmounted, ref } from 'vue'

const POLL_INTERVAL_MS = 9000
const TYPE_COLOR = {
  memory:   [56, 189, 248],   // sky-400
  person:   [74, 222, 128],   // green-400
  project:  [167, 139, 250],  // violet-400
  document: [251, 191, 36],   // amber-400
  task:     [251, 146, 60],   // orange-400
  event:    [244, 114, 182],  // pink-400
  concept:  [148, 163, 184],  // slate-400
  goal:     [132, 204, 22],   // lime-500
}
const OTHER_COLOR = [71, 85, 105]   // slate-600
const EDGE_COLOR = [71, 85, 105]    // slate-600

const canvasEl = ref(null)

// Module-scope render state — not reactive on purpose.
let ctx = null
let animationFrameId = null
let pollIntervalId = null
let width = 0
let height = 0
let dpr = 1

// Node and edge pools. Nodes carry their own position + velocity so they keep
// drifting between polls; on poll we merge in updates without resetting motion.
const nodes = new Map() // id -> { id, type, mine, tags, x, y, vx, vy, age, fadeIn, brightness }
const edges = []        // [{ source, target, weight, age, fadeIn }]
const pulses = []       // ephemeral flashes: { x, y, age, color, maxAge }

// ── Lifecycle ────────────────────────────────────────────────────────────────
onMounted(() => {
  dpr = window.devicePixelRatio || 1
  ctx = canvasEl.value.getContext('2d')
  resize()
  window.addEventListener('resize', resize)
  fetchAmbient()
  pollIntervalId = setInterval(fetchAmbient, POLL_INTERVAL_MS)
  animate()
})

onUnmounted(() => {
  if (animationFrameId) cancelAnimationFrame(animationFrameId)
  if (pollIntervalId) clearInterval(pollIntervalId)
  window.removeEventListener('resize', resize)
})

function resize() {
  if (!canvasEl.value) return
  width = window.innerWidth
  height = window.innerHeight
  canvasEl.value.width = Math.floor(width * dpr)
  canvasEl.value.height = Math.floor(height * dpr)
  canvasEl.value.style.width = width + 'px'
  canvasEl.value.style.height = height + 'px'
  ctx.setTransform(dpr, 0, 0, dpr, 0, 0)
}

// ── Data fetch ───────────────────────────────────────────────────────────────
async function fetchAmbient() {
  try {
    const res = await fetch('/api/graph/ambient', { credentials: 'same-origin' })
    if (!res.ok) return
    const data = await res.json()
    mergeAmbient(data.nodes ?? [], data.edges ?? [])
  } catch (_) {
    // Network blip — keep current pool drifting
  }
}

function mergeAmbient(nextNodes, nextEdges) {
  const nextIds = new Set(nextNodes.map(n => n.id))

  // Remove nodes that dropped off the recent list — let them age out gradually.
  for (const [id, node] of nodes) {
    if (!nextIds.has(id)) {
      node.fadeIn = Math.max(-1, node.fadeIn - 1.5) // negative = fading out
    }
  }

  for (const incoming of nextNodes) {
    const existing = nodes.get(incoming.id)
    if (existing) {
      // Keep position/velocity, refresh metadata in case sensitivity/ownership flipped.
      existing.type = incoming.type
      existing.mine = incoming.mine
      existing.tags = incoming.tags ?? []
      existing.fadeIn = Math.min(1, existing.fadeIn + 0.1)
    } else {
      // Spawn a new drifting node at a random position. New "mine" nodes also
      // emit a pulse so the user sees their own contribution land.
      const x = Math.random() * width
      const y = Math.random() * height
      nodes.set(incoming.id, {
        id: incoming.id,
        type: incoming.type,
        mine: incoming.mine,
        tags: incoming.tags ?? [],
        x, y,
        vx: (Math.random() - 0.5) * 0.18,
        vy: (Math.random() - 0.5) * 0.18,
        age: 0,
        fadeIn: 0,
        brightness: 1.0,
      })
      if (incoming.mine) {
        pulses.push({ x, y, age: 0, color: typeColor(incoming.type, true), maxAge: 1.2 })
      }
    }
  }

  // Garbage-collect fully faded nodes once they pass a safe threshold.
  for (const [id, node] of nodes) {
    if (node.fadeIn < -0.6) nodes.delete(id)
  }

  // Edges always reflect the latest server state — they're cheap to rebuild.
  edges.length = 0
  for (const e of nextEdges) {
    if (nodes.has(e.source) && nodes.has(e.target)) {
      edges.push({ source: e.source, target: e.target, weight: e.weight, age: 0, fadeIn: 0 })
    }
  }
}

// ── Render loop ──────────────────────────────────────────────────────────────
function animate() {
  animationFrameId = requestAnimationFrame(animate)
  if (!ctx) return
  ctx.clearRect(0, 0, width, height)

  // Edges first so nodes sit on top
  ctx.lineWidth = 1
  for (const edge of edges) {
    const a = nodes.get(edge.source)
    const b = nodes.get(edge.target)
    if (!a || !b) continue
    edge.fadeIn = Math.min(1, edge.fadeIn + 0.02)
    const visibility = Math.min(a.fadeIn, b.fadeIn, edge.fadeIn)
    if (visibility <= 0) continue
    const alpha = 0.06 + edge.weight * 0.14 * visibility
    ctx.strokeStyle = `rgba(${EDGE_COLOR[0]}, ${EDGE_COLOR[1]}, ${EDGE_COLOR[2]}, ${alpha})`
    ctx.beginPath()
    ctx.moveTo(a.x, a.y)
    ctx.lineTo(b.x, b.y)
    ctx.stroke()
  }

  // Nodes — drift, wrap at edges, fade according to age
  for (const node of nodes.values()) {
    node.age += 0.016
    node.fadeIn = Math.min(1, node.fadeIn + 0.018)
    node.x += node.vx
    node.y += node.vy
    if (node.x < -10) node.x = width + 10
    if (node.x > width + 10) node.x = -10
    if (node.y < -10) node.y = height + 10
    if (node.y > height + 10) node.y = -10

    if (node.fadeIn <= 0) continue
    const color = typeColor(node.type, node.mine)
    const base = node.mine ? 0.85 : 0.32
    const alpha = base * Math.max(0, Math.min(1, node.fadeIn))
    const radius = node.mine ? 3.2 : 1.8

    // Subtle halo for mine nodes only — keeps the layer quiet for others
    if (node.mine) {
      const halo = ctx.createRadialGradient(node.x, node.y, 0, node.x, node.y, radius * 6)
      halo.addColorStop(0, `rgba(${color[0]}, ${color[1]}, ${color[2]}, ${alpha * 0.35})`)
      halo.addColorStop(1, `rgba(${color[0]}, ${color[1]}, ${color[2]}, 0)`)
      ctx.fillStyle = halo
      ctx.beginPath()
      ctx.arc(node.x, node.y, radius * 6, 0, Math.PI * 2)
      ctx.fill()
    }

    ctx.fillStyle = `rgba(${color[0]}, ${color[1]}, ${color[2]}, ${alpha})`
    ctx.beginPath()
    ctx.arc(node.x, node.y, radius, 0, Math.PI * 2)
    ctx.fill()
  }

  // Pulses on top — short-lived expanding rings
  for (let i = pulses.length - 1; i >= 0; i--) {
    const p = pulses[i]
    p.age += 0.016
    const t = p.age / p.maxAge
    if (t >= 1) {
      pulses.splice(i, 1)
      continue
    }
    const r = 6 + t * 80
    const a = 0.6 * (1 - t)
    ctx.strokeStyle = `rgba(${p.color[0]}, ${p.color[1]}, ${p.color[2]}, ${a})`
    ctx.lineWidth = 1.5
    ctx.beginPath()
    ctx.arc(p.x, p.y, r, 0, Math.PI * 2)
    ctx.stroke()
  }
}

function typeColor(type, mine) {
  if (!mine) return OTHER_COLOR
  return TYPE_COLOR[type] ?? TYPE_COLOR.memory
}

// ── Public API ───────────────────────────────────────────────────────────────
// Called by the chat page after a successful send. Triggers a bright flash at
// a screen-centre-ish position and pre-fetches the ambient data so the new
// memory shows up faster than the next poll interval would allow.
function pulse() {
  const x = width * (0.4 + Math.random() * 0.2)
  const y = height * (0.4 + Math.random() * 0.2)
  pulses.push({ x, y, age: 0, color: [56, 189, 248], maxAge: 1.4 })
  fetchAmbient()
}

defineExpose({ pulse })
</script>

<style scoped>
.ambient-graph {
  position: fixed;
  inset: 0;
  width: 100%;
  height: 100%;
  pointer-events: none;
  z-index: 0;
  opacity: 0.55;
}
</style>
