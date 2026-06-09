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
            <!-- Header card -->
            <div class="bg-slate-900 border border-slate-800 rounded-xl p-6">
                <div class="flex items-start justify-between">
                    <div>
                        <div class="flex items-center gap-3">
                            <h1 class="text-xl font-bold text-white">{{ channel.name }}</h1>
                            <div class="flex items-center gap-2">
                                <span class="text-xs text-slate-500">Source</span>
                                <StatusBadge :status="channel.stream_status" />
                            </div>
                            <div class="flex items-center gap-2">
                                <span class="text-xs text-slate-500">DVR</span>
                                <StatusBadge :status="channel.dvr_status" />
                            </div>
                            <div class="flex items-center gap-2">
                                <span class="text-xs text-slate-500">Push</span>
                                <StatusBadge :status="channel.push_status" />
                            </div>
                        </div>
                        <p class="text-xs text-slate-500 font-mono mt-1">{{ channel.slug }}</p>
                        <p v-if="channel.notes" class="text-sm text-slate-400 mt-2">{{ channel.notes }}</p>
                    </div>
                    <div class="flex items-center gap-2">
                        <button @click="probeStream" :disabled="probing"
                                class="px-3 py-1.5 text-xs text-slate-300 border border-slate-700 rounded-lg hover:border-slate-500 transition-colors disabled:opacity-40">
                            {{ probing ? 'Probing…' : '🔍 Probe' }}
                        </button>
                        <Link :href="route('channels.restart', channel.id)" method="post" as="button"
                              class="px-3 py-1.5 text-xs text-yellow-400 border border-yellow-500/30 rounded-lg hover:bg-yellow-500/10 transition-colors">
                            ↺ Restart
                        </Link>
                        <Link :href="route('channels.edit', channel.id)"
                              class="px-3 py-1.5 text-xs text-slate-300 border border-slate-700 rounded-lg hover:border-slate-500 transition-colors">
                            Edit
                        </Link>
                        <Link :href="route('channels.toggle', channel.id)" method="post" as="button"
                              class="px-4 py-1.5 text-xs font-semibold rounded-lg transition-colors"
                              :class="channel.is_active
                                  ? 'bg-red-600/20 text-red-400 border border-red-500/30 hover:bg-red-600/30'
                                  : 'bg-green-600/20 text-green-400 border border-green-500/30 hover:bg-green-600/30'">
                            {{ channel.is_active ? 'Stop Channel' : 'Start Channel' }}
                        </Link>
                    </div>
                </div>

                <!-- Info grid -->
                <div class="mt-6 grid grid-cols-2 sm:grid-cols-4 gap-3">
                    <InfoItem label="Source Type" :value="channel.source_type.toUpperCase()" />
                    <InfoItem label="Source URL"  :value="channel.source_url" :sub="channel.source_url" />
                    <InfoItem label="Push Output" :value="channel.push_protocol.toUpperCase()" :sub="channel.push_url + '/' + channel.push_stream_key" />
                    <InfoItem label="DVR Window"  :value="formatDuration(channel.dvr_duration)" />
                    <InfoItem label="Segment Size" :value="channel.segment_duration + 's'" />
                    <InfoItem label="Health Check" :value="'Every ' + channel.check_interval + 's'" />
                    <InfoItem label="Last Live"    :value="channel.last_live_at ? new Date(channel.last_live_at).toLocaleString() : 'Never'" />
                    <InfoItem label="Source Signal" :value="channel.source_live ? 'Live ●' : 'Offline ●'"
                              :class="channel.source_live ? 'text-green-400' : 'text-red-400'" />
                    <InfoItem label="Retries" :value="channel.retry_count + ' / ' + channel.max_retries" />
                    <InfoItem v-if="channel.last_error" label="Last Error" :value="channel.last_error" class-name="sm:col-span-2" />
                </div>
            </div>

            <!-- DVR progress -->
            <div class="bg-slate-900 border border-slate-800 rounded-xl p-6">
                <div class="flex items-center justify-between mb-3">
                    <div>
                        <h2 class="text-sm font-semibold text-white">DVR Rolling Window</h2>
                        <p class="text-xs text-slate-500 mt-0.5">
                            {{ formatDuration(channel.dvr_total_duration) }} recorded of {{ formatDuration(channel.dvr_duration) }} window
                            ({{ dvrPct }}% full)
                        </p>
                    </div>
                    <Link :href="route('channels.purge-dvr', channel.id)" method="delete" as="button"
                          class="text-xs text-red-400 border border-red-500/20 px-3 py-1 rounded-lg hover:bg-red-500/10 transition-colors"
                          @click.prevent="confirmPurge">
                        Clear DVR
                    </Link>
                </div>
                <div class="w-full bg-slate-800 rounded-full h-2.5">
                    <div class="h-2.5 rounded-full transition-all duration-500"
                         :class="dvrPct > 90 ? 'bg-green-500' : dvrPct > 50 ? 'bg-yellow-500' : 'bg-slate-600'"
                         :style="{ width: dvrPct + '%' }" />
                </div>
                <div class="mt-2 flex justify-between text-xs text-slate-500">
                    <span>{{ channel.dvr_segments_count }} segments</span>
                    <Link :href="route('dvr.show', channel.id)" class="text-indigo-400 hover:text-indigo-300">View all in DVR →</Link>
                </div>
            </div>

            <!-- Probe output -->
            <div v-if="probeData" class="bg-slate-900 border border-slate-800 rounded-xl p-6">
                <div class="flex items-center justify-between mb-3">
                    <h2 class="text-sm font-semibold text-white">Stream Probe</h2>
                    <button @click="probeData = null" class="text-xs text-slate-500 hover:text-white">✕ Close</button>
                </div>
                <div v-if="probeData.error" class="text-sm text-red-400">{{ probeData.error }}</div>
                <div v-else class="space-y-2">
                    <div v-for="(stream, i) in probeData.streams" :key="i"
                         class="p-3 bg-slate-800/60 rounded-lg text-xs text-slate-300 font-mono space-y-1">
                        <div><span class="text-slate-500">codec:</span> {{ stream.codec_name }} ({{ stream.codec_type }})</div>
                        <div v-if="stream.width"><span class="text-slate-500">resolution:</span> {{ stream.width }}×{{ stream.height }}</div>
                        <div v-if="stream.bit_rate"><span class="text-slate-500">bitrate:</span> {{ Math.round(stream.bit_rate / 1000) }} kbps</div>
                        <div v-if="stream.sample_rate"><span class="text-slate-500">sample_rate:</span> {{ stream.sample_rate }} Hz</div>
                    </div>
                </div>
            </div>

            <!-- Recent segments -->
            <div class="bg-slate-900 border border-slate-800 rounded-xl overflow-hidden">
                <div class="px-6 py-4 border-b border-slate-800">
                    <h2 class="text-sm font-semibold text-white">Recent DVR Segments</h2>
                </div>
                <table class="min-w-full">
                    <thead>
                        <tr class="border-b border-slate-800">
                            <th class="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">Seq</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">Filename</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">Duration</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">Size</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">Recorded</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-800/50">
                        <tr v-for="seg in channel.dvr_segments" :key="seg.id" class="hover:bg-slate-800/20">
                            <td class="px-6 py-3 text-xs text-slate-500">{{ seg.sequence }}</td>
                            <td class="px-6 py-3 text-xs font-mono text-slate-300">{{ seg.filename }}</td>
                            <td class="px-6 py-3 text-xs text-slate-400">{{ seg.duration }}s</td>
                            <td class="px-6 py-3 text-xs text-slate-400">{{ formatBytes(seg.filesize) }}</td>
                            <td class="px-6 py-3 text-xs text-slate-500">{{ new Date(seg.recorded_at).toLocaleString() }}</td>
                        </tr>
                        <tr v-if="!channel.dvr_segments?.length">
                            <td colspan="5" class="px-6 py-10 text-center text-slate-500 text-sm">No segments yet</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Purge confirm -->
        <div v-if="showPurgeConfirm" class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-sm">
            <div class="bg-slate-900 border border-slate-700 rounded-xl p-6 w-full max-w-sm shadow-2xl">
                <h3 class="text-base font-semibold text-white mb-2">Clear DVR for "{{ channel.name }}"?</h3>
                <p class="text-sm text-slate-400 mb-6">All recorded segments will be deleted from disk and database. This cannot be undone.</p>
                <div class="flex justify-end gap-3">
                    <button @click="showPurgeConfirm = false"
                            class="px-4 py-2 text-sm text-slate-400 border border-slate-700 rounded-lg hover:border-slate-500 transition-colors">
                        Cancel
                    </button>
                    <Link :href="route('channels.purge-dvr', channel.id)" method="delete" as="button"
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
import InfoItem from '@/Components/InfoItem.vue'

const props = defineProps({ channel: Object })

const liveStatus       = ref(props.channel.stream_status)
const probing          = ref(false)
const probeData        = ref(null)
const showPurgeConfirm = ref(false)

const dvrPct = computed(() => {
    if (!props.channel.dvr_duration) return 0
    return Math.min(100, Math.round(((props.channel.dvr_total_duration ?? 0) / props.channel.dvr_duration) * 100))
})

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

function confirmPurge() { showPurgeConfirm.value = true }

function formatDuration(s) {
    if (!s) return '0m'
    const h = Math.floor(s / 3600), m = Math.floor((s % 3600) / 60)
    return h > 0 ? `${h}h ${m}m` : `${m}m`
}

function formatBytes(b) {
    if (!b) return '0 B'
    const k = 1024, sizes = ['B', 'KB', 'MB', 'GB']
    const i = Math.floor(Math.log(b) / Math.log(k))
    return (b / Math.pow(k, i)).toFixed(1) + ' ' + sizes[i]
}
</script>
