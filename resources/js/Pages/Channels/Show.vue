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
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <div class="flex items-center gap-3 flex-wrap">
                            <h1 class="text-xl font-bold text-white">{{ channel.name }}</h1>
                            <StatusBadge :status="state.stream_status" />
                            <span class="flex items-center gap-1.5 text-xs font-medium"
                                  :class="state.source_live ? 'text-green-400' : 'text-slate-500'">
                                <span class="w-1.5 h-1.5 rounded-full"
                                      :class="state.source_live ? 'bg-green-400 animate-pulse' : 'bg-slate-600'" />
                                Source {{ state.source_live ? 'Online' : 'Offline' }}
                            </span>
                        </div>
                        <p class="text-xs text-slate-500 font-mono mt-1">{{ channel.slug }}</p>
                        <p v-if="channel.notes" class="text-sm text-slate-400 mt-2">{{ channel.notes }}</p>

                    <!-- Source type mismatch warning -->
                    <div v-if="channel.source_type_hint"
                         class="mt-3 flex items-start gap-2 bg-yellow-500/10 border border-yellow-500/30 text-yellow-300 rounded-lg px-3 py-2 text-xs">
                        <span class="flex-shrink-0 mt-0.5">⚠</span>
                        <span>
                            This URL looks like an <strong>HTTP MPEG-TS</strong> stream (IPTV), not HLS —
                            it has no <code>.m3u8</code> extension.
                            SkyMedia will handle it correctly, but consider changing
                            <strong>Source Protocol</strong> to <strong>MPEG-TS</strong> in Edit to make it explicit.
                        </span>
                    </div>
                    </div>
                    <div class="flex items-center gap-2 flex-wrap justify-end">
                        <Link v-if="isManaged" :href="route('channels.content', channel.id)" class="px-3 py-1.5 text-xs text-indigo-300 border border-indigo-500/30 rounded-lg hover:bg-indigo-500/10">Content Manager</Link>
                        <button v-if="isAdmin || !isManaged" @click="probeStream" :disabled="probing"
                                class="px-3 py-1.5 text-xs text-slate-300 border border-slate-700 rounded-lg hover:border-slate-500 transition-colors disabled:opacity-40">
                            {{ probing ? 'Probing…' : '🔍 Probe Source' }}
                        </button>
                        <button v-if="isAdmin || !isManaged" @click="diagnoseIngest" :disabled="diagnosing"
                                class="px-3 py-1.5 text-xs text-orange-400 border border-orange-500/30 rounded-lg hover:bg-orange-500/10 transition-colors disabled:opacity-40"
                                title="Runs ffmpeg for 5s and shows the exact error output">
                            {{ diagnosing ? 'Running…' : '🩺 Diagnose' }}
                        </button>
                        <Link v-if="isAdmin || !isManaged" :href="route('channels.restart', channel.id)" method="post" as="button"
                              class="px-3 py-1.5 text-xs text-yellow-400 border border-yellow-500/30 rounded-lg hover:bg-yellow-500/10 transition-colors">
                            ↺ Restart
                        </Link>
                        <Link v-if="isAdmin || !isManaged" :href="route('channels.edit', channel.id)"
                              class="px-3 py-1.5 text-xs text-slate-300 border border-slate-700 rounded-lg hover:border-slate-500 transition-colors">
                            ✎ Edit
                        </Link>
                        <Link v-if="isAdmin" :href="route('channels.clone', channel.id)" method="post" as="button"
                              class="px-3 py-1.5 text-xs text-indigo-400 border border-indigo-500/30 rounded-lg hover:bg-indigo-500/10 transition-colors"
                              title="Duplicate all settings to a new channel">
                            ⧉ Clone
                        </Link>
                        <Link v-if="isAdmin || !isManaged" :href="route('channels.toggle', channel.id)" method="post" as="button"
                              class="px-4 py-1.5 text-xs font-semibold rounded-lg transition-colors"
                              :class="channel.is_active
                                  ? 'bg-red-600/20 text-red-400 border border-red-500/30 hover:bg-red-600/30'
                                  : 'bg-green-600/20 text-green-400 border border-green-500/30 hover:bg-green-600/30'">
                            {{ channel.is_active ? '■ Stop Channel' : '▶ Start Channel' }}
                        </Link>
                    </div>
                </div>

                <!-- Info grid -->
                <div class="mt-6 grid grid-cols-2 sm:grid-cols-4 gap-3">
                    <InfoItem label="Source" :value="channel.source_type.toUpperCase()"
                              :sub="channel.ingest_mode === 'push' ? channel.published_ingest_url : channel.source_url" />
                    <InfoItem v-if="isAdmin || !isManaged" label="Push Output" :value="channel.push_protocol.toUpperCase()" :sub="channel.push_url + '/' + channel.push_stream_key" />
                    <InfoItem v-if="isAdmin || !isManaged" label="Video Codec" :value="channel.push_video_codec.toUpperCase()"
                              :sub="channel.push_video_bitrate ? channel.push_video_bitrate + ' kbps' + (channel.push_resolution ? ' · ' + channel.push_resolution : '') : 'passthrough'" />
                    <InfoItem v-if="isAdmin || !isManaged" label="Audio Codec" :value="channel.push_audio_codec.toUpperCase()"
                              :sub="channel.push_audio_codec !== 'copy' ? (channel.push_audio_bitrate + 'k · ' + channel.push_audio_samplerate + 'Hz · ' + (channel.push_audio_channels === 1 ? 'mono' : channel.push_audio_channels === 6 ? '5.1' : 'stereo')) : 'passthrough'" />
                    <InfoItem v-if="isAdmin || !isManaged" label="DVR Window" :value="channel.dvr_window_label" />
                    <InfoItem v-if="isAdmin || !isManaged" label="Recording File" :value="channel.record_duration > 0 ? channel.record_duration_label : 'Disabled'" />
                    <InfoItem v-if="isAdmin || !isManaged" label="Health Check" :value="'Every ' + channel.check_interval + 's'" />
                    <InfoItem label="Last Live" :value="channel.last_live_at ? new Date(channel.last_live_at).toLocaleString() : 'Never'" />
                </div>
            </div>

            <!-- Status row -->
            <div class="grid grid-cols-2 sm:grid-cols-5 gap-4">
                <StatusCard label="Ingest" :status="state.stream_status" :pid="state.pid" />
                <StatusCard v-if="isAdmin || !isManaged" label="Playout" :status="state.playout_status" :pid="state.playout_pid" />
                <StatusCard v-if="isAdmin || !isManaged" label="Push Output" :status="state.push_status" :pid="state.push_pid" />
                <StatusCard v-if="isAdmin || !isManaged" label="DVR Recording" :status="state.dvr_status" />
                <StatusCard v-if="isAdmin || !isManaged" label="File Recording" :status="state.record_status" :pid="state.record_pid" />
            </div>

            <div v-if="isManaged" class="grid grid-cols-1 lg:grid-cols-2 gap-5">
                <div class="lg:col-span-2 bg-slate-900 border border-indigo-500/30 rounded-xl p-6">
                    <h2 class="text-sm font-semibold text-white mb-3">Publisher Connection</h2>
                    <div v-if="channel.source_type === 'rtmp'" class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <div class="text-xs text-slate-500 mb-1">OBS / vMix Server</div>
                            <div class="font-mono text-sm text-indigo-300 bg-slate-950 rounded-lg px-3 py-2 break-all">{{ channel.published_ingest_server }}</div>
                        </div>
                        <div>
                            <div class="text-xs text-slate-500 mb-1">Stream Key</div>
                            <div class="font-mono text-sm text-indigo-300 bg-slate-950 rounded-lg px-3 py-2 break-all">{{ channel.rtmp_input_key }}</div>
                        </div>
                    </div>
                    <div v-else>
                        <div class="text-xs text-slate-500 mb-1">SRT Caller URL</div>
                        <div class="font-mono text-sm text-indigo-300 bg-slate-950 rounded-lg px-3 py-2 break-all">{{ channel.published_ingest_server }}</div>
                    </div>
                    <p class="mt-3 text-xs" :class="state.pid ? 'text-green-400' : 'text-yellow-400'">
                        {{ state.pid ? 'Listener is ready for a publisher.' : 'Listener is not running. Ask an administrator to start this channel.' }}
                    </p>
                </div>
                <div class="bg-slate-900 border border-slate-800 rounded-xl p-6">
                    <h2 class="text-sm font-semibold text-white mb-3">Channel Preview</h2>
                    <video ref="previewPlayer" controls autoplay muted playsinline class="w-full aspect-video bg-black rounded-lg" />
                    <p class="mt-2 text-xs text-slate-500">Preview becomes available after the publisher starts sending media.</p>
                </div>
                <div class="bg-slate-900 border border-slate-800 rounded-xl p-6">
                    <h2 class="text-sm font-semibold text-white mb-2">Fallback VOD</h2>
                    <p class="text-xs text-slate-500 mb-4">This uploaded video loops whenever the pushed source is unavailable.</p>
                    <div v-if="state.fallback_vod_name" class="flex items-center justify-between gap-3">
                        <span class="text-sm text-slate-300 break-all">{{ state.fallback_vod_name }}</span>
                        <Link :href="route('channels.fallback-vod.remove', channel.id)" method="delete" as="button" class="text-xs text-red-400">Remove</Link>
                    </div>
                    <form v-else @submit.prevent="uploadFallback" class="space-y-3">
                        <input type="file" accept="video/*,.mkv,.ts" @change="fallbackForm.fallback_vod = $event.target.files[0]" class="form-input text-sm" required />
                        <button :disabled="fallbackForm.processing" class="px-4 py-2 bg-indigo-600 text-white text-sm rounded-lg disabled:opacity-50">Upload VOD</button>
                        <p v-if="fallbackForm.errors.fallback_vod" class="text-xs text-red-400">{{ fallbackForm.errors.fallback_vod }}</p>
                    </form>
                </div>
            </div>

            <!-- Push controls -->
            <div v-if="isAdmin || !isManaged" class="bg-slate-900 border border-slate-800 rounded-xl p-6">
                <h2 class="text-sm font-semibold text-white mb-4">Output Control</h2>
                <div class="space-y-4">

                    <!-- Playout layer -->
                    <div class="flex flex-wrap items-center gap-3">
                        <span class="text-xs text-slate-500 w-16">Playout</span>
                        <StatusBadge :status="state.playout_status" :pulse="false" />
                        <span v-if="state.playout_pid" class="text-xs text-slate-500 font-mono">PID {{ state.playout_pid }}</span>
                        <span v-if="state.playout_status === 'fallback'" class="text-xs text-yellow-400">⚠ on fallback recording</span>
                    </div>

                    <!-- Push layer -->
                    <div class="flex flex-wrap items-center gap-3">
                        <span class="text-xs text-slate-500 w-16">Push</span>
                        <StatusBadge :status="state.push_status" :pulse="false" />
                        <span v-if="state.push_pid" class="text-xs text-slate-500 font-mono">PID {{ state.push_pid }}</span>
                        <Link :href="route('channels.push.start', channel.id)" method="post" as="button"
                               class="px-3 py-1.5 text-xs bg-green-600/20 text-green-400 border border-green-500/30 rounded-lg hover:bg-green-600/30 transition-colors">
                            ▶ Start Push
                        </Link>
                        <Link :href="route('channels.push.stop', channel.id)" method="post" as="button"
                               class="px-3 py-1.5 text-xs bg-red-600/20 text-red-400 border border-red-500/30 rounded-lg hover:bg-red-600/30 transition-colors">
                            ■ Stop Push
                        </Link>
                        <button @click="togglePushLog"
                                class="px-3 py-1.5 text-xs text-slate-300 border border-slate-700 rounded-lg hover:border-slate-500 transition-colors"
                                :class="{ 'border-indigo-500 text-indigo-400': showPushLog }">
                            {{ showPushLog ? '✕ Hide Push Log' : '📋 Push Log' }}
                        </button>
                    </div>

                </div>
                <p v-if="state.fallback_recording_path" class="mt-3 text-xs text-slate-500 font-mono truncate">
                    Fallback file: {{ state.fallback_recording_path }}
                </p>
                <p v-else class="mt-3 text-xs text-yellow-500">
                    ⚠ No fallback recording yet — recording starts automatically when channel is live.
                </p>
            </div>

            <!-- Push log viewer -->
            <div v-if="(isAdmin || !isManaged) && showPushLog" class="bg-slate-900 border border-indigo-500/30 rounded-xl p-6">
                <div class="flex items-center justify-between mb-3">
                    <h2 class="text-sm font-semibold text-indigo-400">📋 Push Output Log</h2>
                    <span class="text-xs text-slate-500">ffmpeg stderr/stdout — last 80 lines</span>
                </div>
                <pre v-if="pushLog" class="bg-slate-950 rounded-lg p-3 text-xs text-slate-300 font-mono whitespace-pre-wrap overflow-auto max-h-96 border border-slate-800">{{ pushLog }}</pre>
                <p v-else-if="pushLogLoading" class="text-xs text-slate-500">Loading…</p>
                <p v-else class="text-xs text-slate-500">No log available</p>
            </div>

            <!-- DVR progress -->
            <div v-if="isAdmin || !isManaged" class="bg-slate-900 border border-slate-800 rounded-xl p-6">
                <div class="flex items-center justify-between mb-3">
                    <div>
                        <h2 class="text-sm font-semibold text-white">DVR Rolling Buffer</h2>
                        <p class="text-xs text-slate-500 mt-0.5">
                            {{ formatDuration(state.dvr_total_duration) }} of {{ channel.dvr_window_label }} recorded
                            · {{ formatBytes(state.dvr_total_size) }} on disk
                            · {{ state.dvr_segment_count }} segments
                        </p>
                    </div>
                    <Link :href="route('channels.purge-dvr', channel.id)" method="delete" as="button"
                          class="text-xs text-red-400 border border-red-500/20 px-3 py-1 rounded-lg hover:bg-red-500/10 transition-colors">
                        🗑 Clear DVR
                    </Link>
                </div>
                <div class="w-full bg-slate-800 rounded-full h-2">
                    <div class="h-2 rounded-full transition-all duration-700"
                         :class="state.dvr_buffer_pct >= 90 ? 'bg-green-500' : state.dvr_buffer_pct >= 50 ? 'bg-indigo-500' : 'bg-slate-600'"
                         :style="{ width: state.dvr_buffer_pct + '%' }" />
                </div>
                <div class="mt-2 flex justify-between text-xs text-slate-500">
                    <span>{{ state.dvr_buffer_pct }}% full</span>
                    <Link :href="route('dvr.show', channel.id)" class="text-indigo-400 hover:text-indigo-300 transition-colors">
                        View all segments →
                    </Link>
                </div>
            </div>

            <!-- Recordings -->
            <div v-if="isAdmin || !isManaged" class="bg-slate-900 border border-slate-800 rounded-xl overflow-hidden">
                <div class="px-6 py-4 border-b border-slate-800">
                    <h2 class="text-sm font-semibold text-white">Recording Files</h2>
                    <p class="text-xs text-slate-500 mt-0.5">
                        Timed MP4 files recorded from the live source. The latest completed file is looped as fallback when the source goes offline.
                    </p>
                </div>
                <table class="min-w-full">
                    <thead>
                        <tr class="border-b border-slate-800">
                            <th class="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">File</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">Duration</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">Size</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">Status</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">Completed</th>
                            <th class="px-6 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-800/50">
                        <tr v-for="rec in state.recordings" :key="rec.id" class="hover:bg-slate-800/20">
                            <td class="px-6 py-3 text-xs font-mono text-slate-300">
                                {{ rec.filename }}
                                <span v-if="rec.filepath === state.fallback_recording_path"
                                      class="ml-2 px-1.5 py-0.5 bg-yellow-500/15 text-yellow-400 text-xs rounded">
                                    active fallback
                                </span>
                                <span v-if="rec.status === 'recording'"
                                      class="ml-2 px-1.5 py-0.5 bg-blue-500/15 text-blue-400 text-xs rounded animate-pulse">
                                    recording…
                                </span>
                            </td>
                            <td class="px-6 py-3 text-xs text-slate-400">{{ formatDuration(rec.duration) }}</td>
                            <td class="px-6 py-3 text-xs text-slate-400">{{ formatBytes(rec.filesize) }}</td>
                            <td class="px-6 py-3">
                                <span class="text-xs font-medium"
                                      :class="{
                                          'text-green-400': rec.status === 'completed',
                                          'text-blue-400':  rec.status === 'recording',
                                          'text-red-400':   rec.status === 'failed',
                                      }">
                                    {{ rec.status }}
                                </span>
                            </td>
                            <td class="px-6 py-3 text-xs text-slate-500">
                                {{ rec.completed_at ? new Date(rec.completed_at).toLocaleString() : '—' }}
                            </td>
                            <td class="px-6 py-3">
                                <a v-if="rec.status === 'completed'"
                                   :href="route('recordings.play', rec.id)"
                                   target="_blank"
                                   class="px-2 py-1 text-xs bg-indigo-600/20 text-indigo-400 border border-indigo-500/30 rounded hover:bg-indigo-600/30 transition-colors">
                                    ▶ Play
                                </a>
                            </td>
                        </tr>
                        <tr v-if="!state.recordings?.length">
                            <td colspan="6" class="px-6 py-10 text-center text-slate-500 text-sm">
                                No recordings yet. Start the channel to begin recording.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Diagnose output -->
            <div v-if="diagnoseData" class="bg-slate-900 border border-orange-500/30 rounded-xl p-6">
                <div class="flex items-center justify-between mb-3">
                    <h2 class="text-sm font-semibold text-orange-400">🩺 Ingest Diagnostics</h2>
                    <button @click="diagnoseData = null" class="text-xs text-slate-500 hover:text-white">✕</button>
                </div>
                <div class="mb-2 text-xs text-slate-400 font-mono break-all">
                    <span class="text-slate-500">command:</span> {{ diagnoseData.command }}
                </div>
                <div class="mb-2">
                    <span class="text-xs font-semibold" :class="diagnoseData.success ? 'text-green-400' : 'text-red-400'">
                        {{ diagnoseData.success ? '✓ ffmpeg ran successfully' : `✗ ffmpeg exited with code ${diagnoseData.exit_code}` }}
                    </span>
                </div>
                <pre v-if="diagnoseData.stderr" class="bg-slate-800/80 rounded-lg p-3 text-xs text-red-300 font-mono whitespace-pre-wrap overflow-auto max-h-64">{{ diagnoseData.stderr }}</pre>
                <pre v-if="diagnoseData.stdout" class="mt-2 bg-slate-800/80 rounded-lg p-3 text-xs text-slate-300 font-mono whitespace-pre-wrap overflow-auto max-h-32">{{ diagnoseData.stdout }}</pre>
            </div>

            <!-- Probe output -->
            <div v-if="probeData" class="bg-slate-900 border border-slate-800 rounded-xl p-6">
                <div class="flex items-center justify-between mb-3">
                    <h2 class="text-sm font-semibold text-white">Stream Probe</h2>
                    <button @click="probeData = null" class="text-xs text-slate-500 hover:text-white">✕</button>
                </div>
                <div v-if="probeData.error" class="text-sm text-red-400">{{ probeData.error }}</div>
                <div v-else class="space-y-2">
                    <div v-for="(s, i) in probeData.streams" :key="i"
                         class="p-3 bg-slate-800/60 rounded-lg text-xs font-mono space-y-1 text-slate-300">
                        <div><span class="text-slate-500">codec:</span> {{ s.codec_name }} ({{ s.codec_type }})</div>
                        <div v-if="s.width"><span class="text-slate-500">resolution:</span> {{ s.width }}×{{ s.height }} @ {{ s.r_frame_rate }} fps</div>
                        <div v-if="s.bit_rate"><span class="text-slate-500">bitrate:</span> {{ Math.round(s.bit_rate / 1000) }} kbps</div>
                        <div v-if="s.sample_rate"><span class="text-slate-500">audio:</span> {{ s.sample_rate }} Hz · {{ s.channels }}ch</div>
                    </div>
                </div>
            </div>

            <!-- Live event log -->
            <div class="bg-slate-900 border border-slate-800 rounded-xl overflow-hidden">
                <div class="px-6 py-4 border-b border-slate-800 flex items-center justify-between">
                    <h2 class="text-sm font-semibold text-white">Recent Events</h2>
                    <span class="text-xs text-slate-500">Auto-refreshes every 5s</span>
                </div>
                <div class="divide-y divide-slate-800/50 max-h-64 overflow-y-auto">
                    <div v-for="log in logs" :key="log.id"
                         class="px-6 py-2.5 flex items-start gap-3 hover:bg-slate-800/20">
                        <span class="w-1.5 h-1.5 rounded-full flex-shrink-0 mt-1.5"
                              :class="{
                                  'bg-green-400':  log.level === 'info',
                                  'bg-yellow-400': log.level === 'warning',
                                  'bg-red-400':    log.level === 'error' || log.level === 'critical',
                              }" />
                        <span class="text-xs text-slate-500 flex-shrink-0 w-20">{{ formatTime(log.created_at) }}</span>
                        <span class="text-xs font-mono text-slate-400 flex-shrink-0 w-32 truncate">{{ log.event }}</span>
                        <span class="text-xs text-slate-300">{{ log.message }}</span>
                    </div>
                    <div v-if="logs.length === 0" class="px-6 py-8 text-center text-slate-500 text-sm">No events</div>
                </div>
            </div>

        </div>
    </AppLayout>
</template>

<script setup>
import { onMounted, onUnmounted, ref, reactive } from 'vue'
import { Link, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import StatusBadge from '@/Components/StatusBadge.vue'
import StatusCard from '@/Components/StatusCard.vue'
import InfoItem from '@/Components/InfoItem.vue'

const props = defineProps({ channel: Object, isAdmin: Boolean, previewUrl: String })
const isManaged = props.channel.ingest_mode === 'push'
const fallbackForm = useForm({ fallback_vod: null })
const previewPlayer = ref(null)
let hlsPlayer = null

// Live-reactive channel state (updated by polling)
const state = reactive({
    stream_status:            props.channel.stream_status,
    playout_status:           props.channel.playout_status ?? 'idle',
    push_status:              props.channel.push_status,
    dvr_status:               props.channel.dvr_status,
    record_status:            props.channel.record_status,
    source_live:              props.channel.source_live,
    pid:                      props.channel.pid,
    playout_pid:              props.channel.playout_pid,
    push_pid:                 props.channel.push_pid,
    record_pid:               props.channel.record_pid,
    dvr_buffer_pct:           props.channel.dvr_buffer_pct   ?? 0,
    dvr_total_duration:       props.channel.dvr_total_duration ?? 0,
    dvr_total_size:           props.channel.dvr_total_size    ?? 0,
    dvr_segment_count:        props.channel.dvr_segment_count ?? 0,
    fallback_recording_path:  props.channel.fallback_recording_path,
    fallback_vod_name:        props.channel.fallback_vod_name,
    recordings:               props.channel.recordings        ?? [],
    last_live_at:             props.channel.last_live_at,
})

const logs         = ref([])
const probing      = ref(false)
const probeData    = ref(null)
const diagnosing   = ref(false)
const diagnoseData = ref(null)
const showPushLog  = ref(false)
const pushLog      = ref('')
const pushLogLoading = ref(false)
let timer = null

async function poll() {
    try {
        // Fetch status + logs in parallel
        const requests = [fetch(route('channels.status', props.channel.id))]
        if (props.isAdmin || !isManaged) requests.push(fetch(route('channels.logs', props.channel.id)))
        const [statusRes, logsRes] = await Promise.all(requests)
        const status = await statusRes.json()
        const newLogs = logsRes ? await logsRes.json() : []

        // Update reactive state
        Object.assign(state, {
            stream_status:           status.stream_status,
            playout_status:          status.playout_status  ?? state.playout_status,
            push_status:             status.push_status,
            dvr_status:              status.dvr_status,
            record_status:           status.record_status,
            source_live:             status.source_live,
            pid:                     status.pid,
            playout_pid:             status.playout_pid,
            push_pid:                status.push_pid,
            record_pid:              status.record_pid,
            dvr_buffer_pct:          status.dvr_buffer_pct   ?? state.dvr_buffer_pct,
            dvr_total_duration:      status.dvr_total_duration ?? state.dvr_total_duration,
            dvr_total_size:          status.dvr_total_size    ?? state.dvr_total_size,
            dvr_segment_count:       status.dvr_segment_count ?? state.dvr_segment_count,
            fallback_recording_path: status.fallback_recording_path,
            fallback_vod_name:       status.fallback_vod_name,
            recordings:              status.recordings        ?? state.recordings,
            last_live_at:            status.last_live_at,
        })

        logs.value = newLogs
    } catch {}
}

function uploadFallback() {
    fallbackForm.post(route('channels.fallback-vod.upload', props.channel.id), { forceFormData: true })
}

async function probeStream() {
    probing.value = true
    try {
        const res = await fetch(route('channels.probe', props.channel.id))
        probeData.value = await res.json()
    } catch (e) {
        probeData.value = { error: e.message }
    } finally {
        probing.value = false
    }
}

async function diagnoseIngest() {
    diagnosing.value   = true
    diagnoseData.value = null
    try {
        const res = await fetch(route('channels.diagnose', props.channel.id))
        diagnoseData.value = await res.json()
    } catch (e) {
        diagnoseData.value = { exit_code: -1, success: false, stderr: e.message, stdout: '', command: '' }
    } finally {
        diagnosing.value = false
    }
}

onMounted(async () => {
    poll()
    timer = setInterval(poll, 4000)
    if (isManaged && previewPlayer.value) {
        if (previewPlayer.value.canPlayType('application/vnd.apple.mpegurl')) {
            previewPlayer.value.src = props.previewUrl
        } else {
            const { default: Hls } = await import('hls.js')
            if (Hls.isSupported()) {
                hlsPlayer = new Hls({ liveSyncDurationCount: 3 })
                hlsPlayer.loadSource(props.previewUrl)
                hlsPlayer.attachMedia(previewPlayer.value)
            }
        }
    }
})
onUnmounted(() => {
    clearInterval(timer)
    hlsPlayer?.destroy()
})

async function togglePushLog() {
    showPushLog.value = !showPushLog.value
    if (showPushLog.value) {
        pushLogLoading.value = true
        pushLog.value = ''
        try {
            const res = await fetch(route('push.log', props.channel.id))
            const data = await res.json()
            pushLog.value = data.log || '(empty)'
        } catch (e) {
            pushLog.value = 'Failed to load push log: ' + e.message
        } finally {
            pushLogLoading.value = false
        }
    }
}

function formatDuration(s) {
    if (!s || s <= 0) return '0s'
    const h = Math.floor(s / 3600), m = Math.floor((s % 3600) / 60), sec = Math.floor(s % 60)
    return h > 0 ? `${h}h ${m}m` : m > 0 ? `${m}m ${sec}s` : `${sec}s`
}
function formatBytes(b) {
    if (!b || b <= 0) return '0 B'
    const k = 1024, sizes = ['B', 'KB', 'MB', 'GB', 'TB']
    const i = Math.floor(Math.log(b) / Math.log(k))
    return parseFloat((b / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i]
}
function formatTime(ts) {
    return new Date(ts).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' })
}
</script>
