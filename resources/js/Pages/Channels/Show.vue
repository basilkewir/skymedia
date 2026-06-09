<template>
    <AppLayout>
        <template #header>
            <div class="flex items-center gap-2 text-sm text-slate-400">
                <Link :href="route('channels.index')" class="hover:text-white transition-colors">Channels</Link>
                <span>/</span>
                <span class="text-white">{{ channel.name }}</span>
            </div>
        </template>

        <div class="space-y-5">

            <!-- ── Channel Header ───────────────────────────────────────── -->
            <div class="bg-slate-900 border border-slate-800 rounded-xl p-6">
                <div class="flex items-start justify-between flex-wrap gap-4">
                    <div>
                        <h1 class="text-xl font-bold text-white">{{ channel.name }}</h1>
                        <p class="text-xs text-slate-500 font-mono mt-0.5">{{ channel.slug }}</p>
                        <p v-if="channel.notes" class="text-sm text-slate-400 mt-1">{{ channel.notes }}</p>
                    </div>
                    <div class="flex items-center gap-2">
                        <Link :href="route('channels.edit', channel.id)"
                              class="px-3 py-1.5 text-xs text-slate-300 border border-slate-700 rounded-lg hover:border-slate-500 transition-colors">
                            Edit Channel
                        </Link>
                        <Link :href="route('channels.destroy', channel.id)" method="delete" as="button"
                              class="px-3 py-1.5 text-xs text-red-400 border border-red-500/30 rounded-lg hover:bg-red-500/10 transition-colors"
                              @click.prevent="confirmDelete">
                            Delete
                        </Link>
                    </div>
                </div>
            </div>

            <!-- ── THREE SECTIONS GRID ──────────────────────────────────── -->
            <div class="grid grid-cols-1 xl:grid-cols-3 gap-5">

                <!-- ══ 1. INGEST (Source) ══════════════════════════════════ -->
                <div class="bg-slate-900 border border-slate-800 rounded-xl flex flex-col">
                    <div class="px-5 py-4 border-b border-slate-800 flex items-center justify-between">
                        <div class="flex items-center gap-2">
                            <div class="w-2 h-2 rounded-full" :class="ingestDot" />
                            <h2 class="text-sm font-semibold text-white">Source Ingest</h2>
                        </div>
                        <StatusBadge :status="channel.stream_status" />
                    </div>

                    <div class="p-5 flex-1 space-y-4">
                        <!-- Info -->
                        <div class="space-y-2">
                            <div class="flex justify-between text-xs">
                                <span class="text-slate-500">Protocol</span>
                                <span class="text-slate-300 font-mono uppercase">{{ channel.source_type }}</span>
                            </div>
                            <div class="flex justify-between text-xs gap-2">
                                <span class="text-slate-500 shrink-0">URL</span>
                                <span class="text-slate-300 font-mono truncate text-right">{{ channel.source_url }}</span>
                            </div>
                            <div class="flex justify-between text-xs">
                                <span class="text-slate-500">Signal</span>
                                <span :class="channel.source_live ? 'text-green-400' : 'text-red-400'">
                                    {{ channel.source_live ? '● Live' : '● Offline' }}
                                </span>
                            </div>
                            <div class="flex justify-between text-xs">
                                <span class="text-slate-500">Last Live</span>
                                <span class="text-slate-400">{{ channel.last_live_at ? new Date(channel.last_live_at).toLocaleString() : 'Never' }}</span>
                            </div>
                            <div class="flex justify-between text-xs">
                                <span class="text-slate-500">PID</span>
                                <span class="text-slate-400 font-mono">{{ channel.pid ?? '—' }}</span>
                            </div>
                        </div>

                        <!-- Probe -->
                        <button @click="probeStream" :disabled="probing"
                                class="w-full px-3 py-2 text-xs text-slate-300 border border-slate-700 rounded-lg hover:border-slate-500 disabled:opacity-40 transition-colors">
                            {{ probing ? 'Probing…' : '🔍 Probe Source' }}
                        </button>

                        <div v-if="probeData" class="bg-slate-800/60 rounded-lg p-3 text-xs space-y-1">
                            <div v-if="probeData.error" class="text-red-400">{{ probeData.error }}</div>
                            <template v-else>
                                <div v-for="(s, i) in probeData.streams" :key="i" class="text-slate-300 font-mono">
                                    <span class="text-slate-500">{{ s.codec_type }}:</span>
                                    {{ s.codec_name }}
                                    <span v-if="s.width"> {{ s.width }}×{{ s.height }}</span>
                                    <span v-if="s.bit_rate"> {{ Math.round(s.bit_rate/1000) }}kbps</span>
                                </div>
                            </template>
                        </div>

                        <!-- Log viewer -->
                        <button @click="toggleLog('ingest')"
                                class="w-full px-3 py-1.5 text-xs text-slate-400 border border-slate-800 rounded-lg hover:border-slate-700 transition-colors">
                            {{ showLog === 'ingest' ? 'Hide Log' : 'View FFmpeg Log' }}
                        </button>
                        <pre v-if="showLog === 'ingest'" class="bg-black/40 rounded p-3 text-xs text-green-400 font-mono overflow-auto max-h-40 whitespace-pre-wrap">{{ logs.ingest || 'No log output' }}</pre>
                    </div>

                    <!-- Controls -->
                    <div class="px-5 py-4 border-t border-slate-800 flex gap-2">
                        <Link :href="route('ingest.start', channel.id)" method="post" as="button"
                              :disabled="channel.ingest_running"
                              class="flex-1 py-2 text-xs font-semibold rounded-lg transition-colors text-center"
                              :class="channel.ingest_running ? 'bg-slate-800 text-slate-500 cursor-not-allowed' : 'bg-green-600/20 text-green-400 border border-green-500/30 hover:bg-green-600/30'">
                            ▶ Start
                        </Link>
                        <Link :href="route('ingest.restart', channel.id)" method="post" as="button"
                              class="flex-1 py-2 text-xs font-semibold rounded-lg bg-yellow-600/20 text-yellow-400 border border-yellow-500/30 hover:bg-yellow-600/30 transition-colors text-center">
                            ↺ Restart
                        </Link>
                        <Link :href="route('ingest.stop', channel.id)" method="post" as="button"
                              :disabled="!channel.ingest_running"
                              class="flex-1 py-2 text-xs font-semibold rounded-lg transition-colors text-center"
                              :class="!channel.ingest_running ? 'bg-slate-800 text-slate-500 cursor-not-allowed' : 'bg-red-600/20 text-red-400 border border-red-500/30 hover:bg-red-600/30'">
                            ■ Stop
                        </Link>
                    </div>
                </div>

                <!-- ══ 2. DVR ═══════════════════════════════════════════════ -->
                <div class="bg-slate-900 border border-slate-800 rounded-xl flex flex-col">
                    <div class="px-5 py-4 border-b border-slate-800 flex items-center justify-between">
                        <div class="flex items-center gap-2">
                            <div class="w-2 h-2 rounded-full" :class="dvrDot" />
                            <h2 class="text-sm font-semibold text-white">DVR Recording</h2>
                        </div>
                        <StatusBadge :status="channel.dvr_status" />
                    </div>

                    <div class="p-5 flex-1 space-y-4">
                        <!-- Window progress -->
                        <div>
                            <div class="flex justify-between text-xs mb-1.5">
                                <span class="text-slate-500">Rolling Window</span>
                                <span class="text-slate-300">{{ formatDuration(channel.dvr_total_duration) }} / {{ formatDuration(channel.dvr_duration) }}</span>
                            </div>
                            <div class="w-full bg-slate-800 rounded-full h-2">
                                <div class="h-2 rounded-full transition-all duration-500"
                                     :class="dvrPct > 90 ? 'bg-green-500' : dvrPct > 50 ? 'bg-yellow-500' : 'bg-indigo-500'"
                                     :style="{ width: dvrPct + '%' }" />
                            </div>
                            <div class="flex justify-between text-xs mt-1 text-slate-500">
                                <span>{{ channel.dvr_segments_count }} segments</span>
                                <span>{{ dvrPct }}% full</span>
                            </div>
                        </div>

                        <!-- Stats -->
                        <div class="space-y-2">
                            <div class="flex justify-between text-xs">
                                <span class="text-slate-500">Segment Size</span>
                                <span class="text-slate-300">{{ channel.segment_duration }}s each</span>
                            </div>
                            <div class="flex justify-between text-xs">
                                <span class="text-slate-500">Total Size</span>
                                <span class="text-slate-300">{{ formatBytes(channel.dvr_total_size) }}</span>
                            </div>
                            <div class="flex justify-between text-xs">
                                <span class="text-slate-500">DVR PID</span>
                                <span class="text-slate-400 font-mono">{{ channel.dvr_pid ?? '—' }}</span>
                            </div>
                            <div class="flex justify-between text-xs">
                                <span class="text-slate-500">Storage Path</span>
                                <span class="text-slate-400 font-mono truncate text-right text-xs">{{ channel.dvr_path }}</span>
                            </div>
                        </div>

                        <!-- Segments table -->
                        <div class="rounded-lg border border-slate-800 overflow-hidden">
                            <div class="px-3 py-2 border-b border-slate-800 flex justify-between items-center">
                                <span class="text-xs font-medium text-slate-400">Recent Segments</span>
                                <Link :href="route('dvr.show', channel.id)" class="text-xs text-indigo-400 hover:text-indigo-300">View all →</Link>
                            </div>
                            <div class="overflow-y-auto max-h-36">
                                <table class="min-w-full">
                                    <tbody class="divide-y divide-slate-800/50">
                                        <tr v-for="seg in channel.dvr_segments?.slice(0,8)" :key="seg.id" class="hover:bg-slate-800/20">
                                            <td class="px-3 py-1.5 text-xs font-mono text-slate-500">{{ seg.sequence }}</td>
                                            <td class="px-3 py-1.5 text-xs font-mono text-slate-300">{{ seg.filename }}</td>
                                            <td class="px-3 py-1.5 text-xs text-slate-400">{{ seg.duration }}s</td>
                                            <td class="px-3 py-1.5 text-xs text-slate-500">{{ formatBytes(seg.filesize) }}</td>
                                        </tr>
                                        <tr v-if="!channel.dvr_segments?.length">
                                            <td colspan="4" class="px-3 py-4 text-center text-slate-600 text-xs">No segments yet</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- Controls -->
                    <div class="px-5 py-4 border-t border-slate-800 flex gap-2">
                        <Link :href="route('dvr.show', channel.id)"
                              class="flex-1 py-2 text-xs font-semibold rounded-lg bg-indigo-600/20 text-indigo-400 border border-indigo-500/30 hover:bg-indigo-600/30 transition-colors text-center">
                            📁 Browse DVR
                        </Link>
                        <button @click="confirmPurge"
                                class="flex-1 py-2 text-xs font-semibold rounded-lg bg-red-600/20 text-red-400 border border-red-500/30 hover:bg-red-600/30 transition-colors">
                            🗑 Clear DVR
                        </button>
                    </div>
                </div>

                <!-- ══ 3. PUSH (Output) ═════════════════════════════════════ -->
                <div class="bg-slate-900 border border-slate-800 rounded-xl flex flex-col">
                    <div class="px-5 py-4 border-b border-slate-800 flex items-center justify-between">
                        <div class="flex items-center gap-2">
                            <div class="w-2 h-2 rounded-full" :class="pushDot" />
                            <h2 class="text-sm font-semibold text-white">Push Output</h2>
                        </div>
                        <StatusBadge :status="channel.push_status" />
                    </div>

                    <div class="p-5 flex-1 space-y-4">
                        <!-- Info -->
                        <div class="space-y-2">
                            <div class="flex justify-between text-xs">
                                <span class="text-slate-500">Protocol</span>
                                <span class="text-slate-300 font-mono uppercase">{{ channel.push_protocol }}</span>
                            </div>
                            <div class="flex justify-between text-xs gap-2">
                                <span class="text-slate-500 shrink-0">URL</span>
                                <span class="text-slate-300 font-mono truncate text-right">{{ channel.push_url }}</span>
                            </div>
                            <div class="flex justify-between text-xs">
                                <span class="text-slate-500">Stream Key</span>
                                <span class="text-slate-300 font-mono">{{ channel.push_stream_key || '—' }}</span>
                            </div>
                            <div class="flex justify-between text-xs">
                                <span class="text-slate-500">Push PID</span>
                                <span class="text-slate-400 font-mono">{{ channel.push_pid ?? '—' }}</span>
                            </div>
                            <div class="flex justify-between text-xs">
                                <span class="text-slate-500">Mode</span>
                                <span :class="channel.stream_status === 'dvr_playback' ? 'text-yellow-400' : 'text-green-400'" class="text-xs font-semibold">
                                    {{ channel.stream_status === 'dvr_playback' ? 'DVR Fallback' : 'Live Source' }}
                                </span>
                            </div>
                        </div>

                        <!-- Mode selector -->
                        <div class="flex gap-2">
                            <button @click="pushMode = 'live'"
                                    class="flex-1 py-1.5 text-xs rounded-lg border transition-colors"
                                    :class="pushMode === 'live' ? 'bg-green-600/20 text-green-400 border-green-500/30' : 'text-slate-400 border-slate-700 hover:border-slate-500'">
                                Live Source
                            </button>
                            <button @click="pushMode = 'dvr'"
                                    class="flex-1 py-1.5 text-xs rounded-lg border transition-colors"
                                    :class="pushMode === 'dvr' ? 'bg-yellow-600/20 text-yellow-400 border-yellow-500/30' : 'text-slate-400 border-slate-700 hover:border-slate-500'">
                                DVR Playback
                            </button>
                        </div>

                        <!-- Log viewer -->
                        <button @click="toggleLog('push')"
                                class="w-full px-3 py-1.5 text-xs text-slate-400 border border-slate-800 rounded-lg hover:border-slate-700 transition-colors">
                            {{ showLog === 'push' ? 'Hide Log' : 'View FFmpeg Log' }}
                        </button>
                        <pre v-if="showLog === 'push'" class="bg-black/40 rounded p-3 text-xs text-green-400 font-mono overflow-auto max-h-40 whitespace-pre-wrap">{{ logs.push || 'No log output' }}</pre>
                    </div>

                    <!-- Controls -->
                    <div class="px-5 py-4 border-t border-slate-800 flex gap-2">
                        <Link :href="route('push.start', channel.id)" method="post" as="button"
                              :data="{ mode: pushMode }"
                              :disabled="channel.push_running"
                              class="flex-1 py-2 text-xs font-semibold rounded-lg transition-colors text-center"
                              :class="channel.push_running ? 'bg-slate-800 text-slate-500 cursor-not-allowed' : 'bg-green-600/20 text-green-400 border border-green-500/30 hover:bg-green-600/30'">
                            ▶ Start
                        </Link>
                        <Link :href="route('push.restart', channel.id)" method="post" as="button"
                              :data="{ mode: pushMode }"
                              class="flex-1 py-2 text-xs font-semibold rounded-lg bg-yellow-600/20 text-yellow-400 border border-yellow-500/30 hover:bg-yellow-600/30 transition-colors text-center">
                            ↺ Restart
                        </Link>
                        <Link :href="route('push.stop', channel.id)" method="post" as="button"
                              :disabled="!channel.push_running"
                              class="flex-1 py-2 text-xs font-semibold rounded-lg transition-colors text-center"
                              :class="!channel.push_running ? 'bg-slate-800 text-slate-500 cursor-not-allowed' : 'bg-red-600/20 text-red-400 border border-red-500/30 hover:bg-red-600/30'">
                            ■ Stop
                        </Link>
                    </div>
                </div>

            </div>

            <!-- ── Recent Events ─────────────────────────────────────────── -->
            <div class="bg-slate-900 border border-slate-800 rounded-xl overflow-hidden">
                <div class="px-5 py-4 border-b border-slate-800 flex items-center justify-between">
                    <h2 class="text-sm font-semibold text-white">Recent Events</h2>
                    <Link :href="route('logs.index', { channel_id: channel.id })" class="text-xs text-indigo-400 hover:text-indigo-300">View all →</Link>
                </div>
                <div class="divide-y divide-slate-800/50">
                    <div v-for="log in recentLogs" :key="log.id" class="px-5 py-2.5 flex items-start gap-3 text-xs hover:bg-slate-800/20">
                        <span class="shrink-0 px-1.5 py-0.5 rounded font-semibold"
                              :class="{
                                  'bg-green-500/10 text-green-400':    log.metadata?.category === 'source',
                                  'bg-yellow-500/10 text-yellow-400':  log.metadata?.category === 'dvr',
                                  'bg-indigo-500/10 text-indigo-400':  log.metadata?.category === 'push',
                                  'bg-slate-500/10 text-slate-400':    !log.metadata?.category,
                              }">{{ log.metadata?.category ?? 'sys' }}</span>
                        <span class="shrink-0 px-1.5 py-0.5 rounded font-semibold"
                              :class="{
                                  'bg-blue-500/10 text-blue-400':      log.level === 'info',
                                  'bg-yellow-500/10 text-yellow-400':  log.level === 'warning',
                                  'bg-red-500/10 text-red-400':        log.level === 'error' || log.level === 'critical',
                              }">{{ log.level }}</span>
                        <span class="text-slate-400 flex-1">{{ log.message }}</span>
                        <span class="text-slate-600 shrink-0">{{ new Date(log.created_at).toLocaleTimeString() }}</span>
                    </div>
                    <div v-if="!recentLogs?.length" class="px-5 py-8 text-center text-slate-600 text-sm">No events yet</div>
                </div>
            </div>
        </div>

        <!-- Delete confirm -->
        <div v-if="showDeleteConfirm" class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-sm">
            <div class="bg-slate-900 border border-slate-700 rounded-xl p-6 w-full max-w-sm">
                <h3 class="text-base font-semibold text-white mb-2">Delete "{{ channel.name }}"?</h3>
                <p class="text-sm text-slate-400 mb-6">All DVR data and logs will be permanently deleted.</p>
                <div class="flex justify-end gap-3">
                    <button @click="showDeleteConfirm = false" class="px-4 py-2 text-sm text-slate-400 border border-slate-700 rounded-lg hover:border-slate-500 transition-colors">Cancel</button>
                    <Link :href="route('channels.destroy', channel.id)" method="delete" as="button"
                          class="px-4 py-2 bg-red-600 hover:bg-red-700 text-white text-sm font-medium rounded-lg transition-colors">
                        Delete
                    </Link>
                </div>
            </div>
        </div>

        <!-- DVR purge confirm -->
        <div v-if="showPurgeConfirm" class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-sm">
            <div class="bg-slate-900 border border-slate-700 rounded-xl p-6 w-full max-w-sm">
                <h3 class="text-base font-semibold text-white mb-2">Clear DVR for "{{ channel.name }}"?</h3>
                <p class="text-sm text-slate-400 mb-6">All recorded segments will be permanently deleted.</p>
                <div class="flex justify-end gap-3">
                    <button @click="showPurgeConfirm = false" class="px-4 py-2 text-sm text-slate-400 border border-slate-700 rounded-lg hover:border-slate-500 transition-colors">Cancel</button>
                    <Link :href="route('dvr.purge', channel.id)" method="delete" as="button"
                          class="px-4 py-2 bg-red-600 hover:bg-red-700 text-white text-sm font-medium rounded-lg transition-colors">
                        Clear DVR
                    </Link>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { computed, ref } from 'vue'
import { Link } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import StatusBadge from '@/Components/StatusBadge.vue'

const props = defineProps({ channel: Object, recentLogs: Array })

const probing           = ref(false)
const probeData         = ref(null)
const showPurgeConfirm  = ref(false)
const showDeleteConfirm = ref(false)
const showLog           = ref(null)
const pushMode          = ref('live')
const logs              = ref({ ingest: '', push: '' })

const dvrPct = computed(() => {
    if (!props.channel.dvr_duration) return 0
    return Math.min(100, Math.round(((props.channel.dvr_total_duration ?? 0) / props.channel.dvr_duration) * 100))
})

const ingestDot = computed(() => ({
    'bg-green-400 animate-pulse': props.channel.ingest_running,
    'bg-red-400':                 props.channel.stream_status === 'error',
    'bg-slate-600':               !props.channel.ingest_running && props.channel.stream_status !== 'error',
}))

const dvrDot = computed(() => ({
    'bg-green-400 animate-pulse': props.channel.dvr_status === 'recording',
    'bg-yellow-400 animate-pulse': props.channel.dvr_status === 'playing',
    'bg-slate-600':               !['recording','playing'].includes(props.channel.dvr_status),
}))

const pushDot = computed(() => ({
    'bg-indigo-400 animate-pulse': props.channel.push_running,
    'bg-red-400':                  props.channel.push_status === 'error',
    'bg-slate-600':                !props.channel.push_running && props.channel.push_status !== 'error',
}))

async function probeStream() {
    probing.value = true
    try {
        const res = await fetch(route('channels.probe', props.channel.id))
        probeData.value = await res.json()
    } catch (e) {
        probeData.value = { error: 'Probe failed: ' + e.message }
    } finally {
        probing.value = false
    }
}

async function toggleLog(type) {
    if (showLog.value === type) { showLog.value = null; return }
    showLog.value = type
    try {
        const res = await fetch(route(type === 'ingest' ? 'ingest.log' : 'push.log', props.channel.id))
        const data = await res.json()
        logs.value[type] = data.log
    } catch (e) {
        logs.value[type] = 'Failed to load log'
    }
}

function confirmPurge()  { showPurgeConfirm.value  = true }
function confirmDelete() { showDeleteConfirm.value = true }

function formatDuration(s) {
    if (!s) return '0m'
    const h = Math.floor(s / 3600), m = Math.floor((s % 3600) / 60)
    return h > 0 ? `${h}h ${m}m` : `${m}m`
}

function formatBytes(b) {
    if (!b) return '0 B'
    const k = 1024, sizes = ['B','KB','MB','GB']
    const i = Math.floor(Math.log(b) / Math.log(k))
    return (b / Math.pow(k, i)).toFixed(1) + ' ' + sizes[i]
}
</script>
