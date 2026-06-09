<template>
    <AppLayout>
        <template #header>
            <h1 class="text-lg font-semibold text-white">Dashboard</h1>
        </template>

        <!-- Stats row -->
        <div class="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-7 gap-4 mb-6">
            <StatCard title="Total"     :value="stats.total"   color="slate" />
            <StatCard title="Live"      :value="liveCount"     color="green" />
            <StatCard title="DVR Play"  :value="dvrCount"      color="yellow" />
            <StatCard title="Error"     :value="errorCount"    color="red" />
            <StatCard title="Idle"      :value="stats.idle"    color="slate" />
            <StatCard title="Active"    :value="stats.active"  color="indigo" />
            <StatCard title="DVR Store" :value="formatBytes(stats.dvr_storage)" color="blue" />
        </div>

        <!-- Channel grid -->
        <div class="bg-slate-900 border border-slate-800 rounded-xl overflow-hidden mb-6">
            <div class="px-6 py-4 border-b border-slate-800 flex items-center justify-between">
                <h2 class="text-sm font-semibold text-white">Channels</h2>
                <div class="flex items-center gap-3">
                    <span class="text-xs text-slate-500">Auto-refresh every 5s</span>
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
                        <th class="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">Status</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">Source Signal</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">Push</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">DVR</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">Last Live</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-800/50">
                    <tr v-for="ch in liveChannels" :key="ch.id" class="hover:bg-slate-800/30 transition-colors">
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
                        <td class="px-6 py-4">
                            <span class="flex items-center gap-1.5 text-xs font-medium"
                                  :class="ch.source_live ? 'text-green-400' : 'text-red-400'">
                                <span class="w-1.5 h-1.5 rounded-full"
                                      :class="ch.source_live ? 'bg-green-400 animate-pulse' : 'bg-red-400'" />
                                {{ ch.source_live ? 'Live' : 'Offline' }}
                            </span>
                        </td>
                        <td class="px-6 py-4">
                            <span class="px-2 py-0.5 bg-slate-800 text-slate-300 text-xs font-mono rounded">
                                {{ ch.push_protocol?.toUpperCase() }}
                            </span>
                        </td>
                        <td class="px-6 py-4 text-xs text-slate-400">
                            {{ formatDuration(ch.dvr_duration) }}
                        </td>
                        <td class="px-6 py-4 text-xs text-slate-500">
                            {{ ch.last_live_at ? timeAgo(ch.last_live_at) : '—' }}
                        </td>
                        <td class="px-6 py-4">
                            <div class="flex items-center gap-2">
                                <Link :href="route('channels.show', ch.id)"
                                      class="text-xs text-slate-400 hover:text-white transition-colors">View</Link>
                                <Link :href="route('channels.toggle', ch.id)" method="post" as="button"
                                      class="text-xs font-medium transition-colors"
                                      :class="ch.is_active ? 'text-red-400 hover:text-red-300' : 'text-green-400 hover:text-green-300'">
                                    {{ ch.is_active ? 'Stop' : 'Start' }}
                                </Link>
                            </div>
                        </td>
                    </tr>
                    <tr v-if="liveChannels.length === 0">
                        <td colspan="8" class="px-6 py-12 text-center text-slate-500 text-sm">
                            No channels yet.
                            <Link :href="route('channels.create')" class="text-indigo-400 hover:underline ml-1">Create one</Link>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Recent events -->
        <div class="bg-slate-900 border border-slate-800 rounded-xl overflow-hidden">
            <div class="px-6 py-4 border-b border-slate-800">
                <h2 class="text-sm font-semibold text-white">Recent Events</h2>
            </div>
            <div class="divide-y divide-slate-800/50 max-h-80 overflow-y-auto">
                <div v-for="log in recentLogs" :key="log.id"
                     class="px-6 py-3 flex items-start gap-3 hover:bg-slate-800/20">
                    <span class="mt-0.5 w-1.5 h-1.5 rounded-full flex-shrink-0 mt-1.5"
                          :class="{
                              'bg-green-400': log.level === 'info',
                              'bg-yellow-400': log.level === 'warning',
                              'bg-red-400': log.level === 'error' || log.level === 'critical',
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
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import StatusBadge from '@/Components/StatusBadge.vue'
import StatCard from '@/Components/StatCard.vue'

const props = defineProps({
    channels:   Array,
    stats:      Object,
    recentLogs: Array,
})

const liveChannels = ref([...props.channels])
const liveCount    = ref(props.stats.live)
const dvrCount     = ref(props.stats.dvr)
const errorCount   = ref(props.stats.error)

let timer = null

async function pollStatus() {
    try {
        const res  = await fetch(route('api.channels.status-all'), { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        const data = await res.json()
        data.forEach(remote => {
            const local = liveChannels.value.find(c => c.id === remote.id)
            if (local) Object.assign(local, remote)
        })
        liveCount.value  = data.filter(c => c.stream_status === 'live').length
        dvrCount.value   = data.filter(c => c.stream_status === 'dvr_playback').length
        errorCount.value = data.filter(c => c.stream_status === 'error').length
    } catch {}
}

onMounted(() => { timer = setInterval(pollStatus, 5000) })
onUnmounted(() => clearInterval(timer))

function formatDuration(s) {
    const h = Math.floor(s / 3600), m = Math.floor((s % 3600) / 60)
    return h > 0 ? `${h}h ${m}m` : `${m}m`
}

function formatBytes(b) {
    if (!b) return '0 B'
    const k = 1024, sizes = ['B','KB','MB','GB','TB']
    const i = Math.floor(Math.log(b) / Math.log(k))
    return (b / Math.pow(k, i)).toFixed(1) + ' ' + sizes[i]
}

function formatTime(ts) {
    return new Date(ts).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' })
}

function timeAgo(ts) {
    const diff = Math.floor((Date.now() - new Date(ts)) / 1000)
    if (diff < 60) return `${diff}s ago`
    if (diff < 3600) return `${Math.floor(diff/60)}m ago`
    return `${Math.floor(diff/3600)}h ago`
}
</script>
