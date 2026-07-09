<template>
    <AppLayout>
        <template #header>
            <h1 class="text-lg font-semibold text-white">Dashboard</h1>
        </template>

        <!-- Stats row -->
        <div class="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-7 gap-4 mb-6">
            <StatCard title="Total"      :value="stats.total"              color="slate" />
            <StatCard title="Live"       :value="counts.live"              color="green" />
            <StatCard title="Fallback"   :value="counts.fallback"          color="yellow" />
            <StatCard title="Error"      :value="counts.error"             color="red" />
            <StatCard title="Idle"       :value="counts.idle"              color="slate" />
            <StatCard title="Active"     :value="stats.active"             color="indigo" />
            <StatCard title="DVR Store"  :value="formatBytes(stats.dvr_storage)" color="blue" />
        </div>

        <!-- System resources -->
        <div class="bg-slate-900 border border-slate-800 rounded-xl p-4 mb-6">
            <div class="flex items-center justify-between mb-3">
                <h2 class="text-sm font-semibold text-white">System Resources</h2>
                <span class="text-xs text-slate-500">refreshes every 5s</span>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div class="bg-slate-800/50 rounded-lg p-3">
                    <div class="text-xs text-slate-500 mb-1">CPU Usage</div>
                    <div class="flex items-end gap-2">
                        <span class="text-2xl font-bold text-white">{{ system.cpu_percent ?? 0 }}%</span>
                        <span class="text-xs text-slate-400 mb-1">/ {{ system.cpu_cores ?? 1 }} cores</span>
                    </div>
                    <div class="text-xs text-slate-500 mt-1">
                        Load: {{ system.load_average_1m ?? 0 }} / {{ system.load_average_5m ?? 0 }} / {{ system.load_average_15m ?? 0 }}
                    </div>
                </div>
                <div class="bg-slate-800/50 rounded-lg p-3">
                    <div class="text-xs text-slate-500 mb-1">Memory</div>
                    <div class="flex items-end gap-2">
                        <span class="text-2xl font-bold text-white">{{ system.memory?.used_percent ?? 0 }}%</span>
                        <span class="text-xs text-slate-400 mb-1">
                            {{ system.memory?.used_mb ?? 0 }} / {{ system.memory?.total_mb ?? 0 }} MB
                        </span>
                    </div>
                    <div class="text-xs text-slate-500 mt-1">
                        {{ system.memory?.free_mb ?? 0 }} MB free
                    </div>
                </div>
                <div class="bg-slate-800/50 rounded-lg p-3">
                    <div class="text-xs text-slate-500 mb-1">DVR Disk</div>
                    <div class="flex items-end gap-2">
                        <span class="text-2xl font-bold"
                              :class="diskPct > 90 ? 'text-red-400' : diskPct > 70 ? 'text-yellow-400' : 'text-green-400'">
                            {{ diskPct }}%
                        </span>
                        <span class="text-xs text-slate-400 mb-1">used</span>
                    </div>
                    <div class="text-xs text-slate-500 mt-1">{{ formatBytes(dvrUsed) }} / {{ formatBytes(dvrTotal) }}</div>
                </div>
            </div>
        </div>

        <!-- Channel grid -->
        <div class="bg-slate-900 border border-slate-800 rounded-xl overflow-hidden mb-6">
            <div class="px-6 py-4 border-b border-slate-800 flex items-center justify-between">
                <h2 class="text-sm font-semibold text-white">Channels</h2>
                <div class="flex items-center gap-3">
                    <span class="text-xs text-slate-500">Live · refreshes every 5s</span>
                    <Link :href="route('channels.create')"
                          class="px-3 py-1.5 bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-medium rounded-lg transition-colors">
                        + Add Channel
                    </Link>
                </div>
            </div>

            <table class="min-w-full">
                <thead>
                    <tr class="border-b border-slate-800">
                        <th class="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">Channel</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">Source</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">Ingest</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">Push</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">Signal</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">DVR</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">Last Live</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-800/50">
                    <tr v-for="ch in paginatedChannels" :key="ch.id" class="hover:bg-slate-800/30 transition-colors">
                        <td class="px-6 py-4">
                            <Link :href="route('channels.show', ch.id)" class="text-sm font-medium text-indigo-400 hover:text-indigo-300">
                                {{ ch.name }}
                            </Link>
                        </td>
                        <td class="px-6 py-4">
                            <span class="px-2 py-0.5 bg-slate-800 text-slate-300 text-xs font-mono rounded">
                                {{ ch.source_type?.toUpperCase() }}
                            </span>
                        </td>
                        <td class="px-6 py-4"><StatusBadge :status="ch.stream_status" /></td>
                        <td class="px-6 py-4"><StatusBadge :status="ch.push_status ?? 'idle'" /></td>
                        <td class="px-6 py-4">
                            <span class="flex items-center gap-1.5 text-xs font-medium"
                                  :class="ch.source_live ? 'text-green-400' : 'text-red-400'">
                                <span class="w-1.5 h-1.5 rounded-full"
                                      :class="ch.source_live ? 'bg-green-400 animate-pulse' : 'bg-red-400'" />
                                {{ ch.source_live ? 'Live' : 'Offline' }}
                            </span>
                        </td>
                        <td class="px-6 py-4 text-xs text-slate-400">
                            {{ formatDuration(ch.dvr_duration) }}
                        </td>
                        <td class="px-6 py-4 text-xs text-slate-500">
                            {{ ch.last_live_at ? timeAgo(ch.last_live_at) : '—' }}
                        </td>
                        <td class="px-6 py-4">
                            <div class="flex items-center gap-3">
                                <Link :href="route('channels.show', ch.id)"
                                      class="text-xs text-slate-400 hover:text-white transition-colors">View</Link>
                                <Link :href="route('channels.toggle', ch.id)" method="post" as="button" preserve-scroll
                                      class="text-xs font-medium transition-colors"
                                      :class="ch.is_active ? 'text-red-400 hover:text-red-300' : 'text-green-400 hover:text-green-300'">
                                    {{ ch.is_active ? 'Stop' : 'Start' }}
                                </Link>
                            </div>
                        </td>
                    </tr>
                    <tr v-if="paginatedChannels.length === 0">
                        <td colspan="8" class="px-6 py-12 text-center text-slate-500 text-sm">
                            No channels yet.
                            <Link :href="route('channels.create')" class="text-indigo-400 hover:underline ml-1">Create one</Link>
                        </td>
                    </tr>
                </tbody>
            </table>
            <div v-if="totalPages > 1" class="px-6 py-4 border-t border-slate-800">
                <div class="flex items-center justify-between">
                    <span class="text-xs text-slate-500">
                        Showing {{ (currentPage - 1) * perPage + 1 }}–{{ Math.min(currentPage * perPage, liveChannels.length) }} of {{ liveChannels.length }}
                    </span>
                    <div class="flex items-center gap-1">
                        <button @click="currentPage = Math.max(1, currentPage - 1)" :disabled="currentPage <= 1"
                                class="px-3 py-1.5 text-xs border border-slate-700 rounded-lg hover:border-slate-500 disabled:opacity-40 disabled:cursor-not-allowed text-slate-400 hover:text-white transition-colors">
                            ← Prev
                        </button>
                        <template v-for="p in visiblePages" :key="p">
                            <button v-if="p === '...'" disabled class="px-2 py-1.5 text-xs text-slate-600">…</button>
                            <button v-else @click="currentPage = p"
                                    class="px-3 py-1.5 text-xs border rounded-lg transition-colors"
                                    :class="p === currentPage
                                        ? 'bg-indigo-600 text-white border-indigo-600'
                                        : 'text-slate-400 border-slate-700 hover:border-slate-500 hover:text-white'">
                                {{ p }}
                            </button>
                        </template>
                        <button @click="currentPage = Math.min(totalPages, currentPage + 1)" :disabled="currentPage >= totalPages"
                                class="px-3 py-1.5 text-xs border border-slate-700 rounded-lg hover:border-slate-500 disabled:opacity-40 disabled:cursor-not-allowed text-slate-400 hover:text-white transition-colors">
                            Next →
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Recent events -->
        <div class="bg-slate-900 border border-slate-800 rounded-xl overflow-hidden">
            <div class="px-6 py-4 border-b border-slate-800">
                <h2 class="text-sm font-semibold text-white">Recent Events</h2>
            </div>
            <div class="divide-y divide-slate-800/50 max-h-80 overflow-y-auto">
                <div v-for="log in recentLogs" :key="log.id"
                     class="px-6 py-3 flex items-start gap-3 hover:bg-slate-800/20">
                    <span class="w-1.5 h-1.5 rounded-full flex-shrink-0 mt-1.5"
                          :class="{
                              'bg-green-400':  log.level === 'info',
                              'bg-yellow-400': log.level === 'warning',
                              'bg-red-400':    log.level === 'error' || log.level === 'critical',
                          }" />
                    <span class="text-xs text-slate-500 flex-shrink-0 w-16">{{ formatTime(log.created_at) }}</span>
                    <span class="text-xs font-medium text-slate-300 flex-shrink-0 w-32 truncate">{{ log.channel?.name }}</span>
                    <span class="text-xs text-slate-400">{{ log.message }}</span>
                </div>
                <div v-if="recentLogs.length === 0" class="px-6 py-10 text-center text-slate-500 text-sm">
                    No events yet
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { onMounted, onUnmounted, ref, reactive, computed } from 'vue'
import { Link } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import StatusBadge from '@/Components/StatusBadge.vue'
import StatCard from '@/Components/StatCard.vue'

const props = defineProps({
    channels:   Array,
    stats:      Object,
    recentLogs: Array,
})

const liveChannels = ref(props.channels.map(c => ({ ...c })))

const currentPage = ref(1)
const perPage = 20

const totalPages = computed(() => Math.ceil(liveChannels.value.length / perPage))
const paginatedChannels = computed(() => {
    const start = (currentPage.value - 1) * perPage
    return liveChannels.value.slice(start, start + perPage)
})
const visiblePages = computed(() => {
    const total = totalPages.value
    const cur = currentPage.value
    if (total <= 7) return Array.from({ length: total }, (_, i) => i + 1)
    const pages = []
    pages.push(1)
    if (cur > 3) pages.push('...')
    for (let i = Math.max(2, cur - 1); i <= Math.min(total - 1, cur + 1); i++) pages.push(i)
    if (cur < total - 2) pages.push('...')
    pages.push(total)
    return pages
})

const counts = reactive({
    live:     props.stats.live,
    fallback: props.stats.dvr,
    error:    props.stats.error,
    idle:     props.stats.idle,
})

const system = reactive({
    cpu_percent: 0,
    cpu_cores: 1,
    load_average_1m: 0,
    load_average_5m: 0,
    load_average_15m: 0,
    memory: { total_mb: 0, free_mb: 0, used_mb: 0, used_percent: 0 },
})

const dvrUsed = ref(props.stats.dvr_storage ?? 0)
const dvrTotal = ref(0)

let timer = null

async function pollStatus() {
    try {
        // Use the web route (session-authenticated, no 401)
        const res  = await fetch(route('dashboard.status'), {
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
            credentials: 'same-origin',
        })
        if (!res.ok) return
        const data = await res.json()

        data.channels.forEach(remote => {
            const local = liveChannels.value.find(c => c.id === remote.id)
            if (local) Object.assign(local, remote)
        })

        counts.live     = data.channels.filter(c => c.stream_status === 'live').length
        counts.fallback = data.channels.filter(c => ['fallback', 'dvr_playback'].includes(c.stream_status)).length
        counts.error    = data.channels.filter(c => c.stream_status === 'error').length
        counts.idle     = data.channels.filter(c => ['idle', 'stopped', 'offline'].includes(c.stream_status)).length

        if (data.system) {
            Object.assign(system, data.system)
        }
        if (data.disk) {
            dvrUsed.value = data.disk.used ?? 0
            dvrTotal.value = data.disk.total ?? 0
        }
    } catch {}
}

const diskPct = computed(() => {
    return dvrTotal.value > 0 ? Math.round((dvrUsed.value / dvrTotal.value) * 100) : 0
})

onMounted(() => { timer = setInterval(pollStatus, 5000) })
onUnmounted(() => clearInterval(timer))

function formatDuration(s) {
    const h = Math.floor(s / 3600), m = Math.floor((s % 3600) / 60)
    return h > 0 ? `${h}h ${m}m` : `${m}m`
}

function formatBytes(b) {
    if (!b) return '0 B'
    const k = 1024, sizes = ['B', 'KB', 'MB', 'GB', 'TB']
    const i = Math.floor(Math.log(b) / Math.log(k))
    return (b / Math.pow(k, i)).toFixed(1) + ' ' + sizes[i]
}

function formatTime(ts) {
    return new Date(ts).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' })
}

function timeAgo(ts) {
    const diff = Math.floor((Date.now() - new Date(ts)) / 1000)
    if (diff < 60) return `${diff}s ago`
    if (diff < 3600) return `${Math.floor(diff / 60)}m ago`
    return `${Math.floor(diff / 3600)}h ago`
}
</script>
