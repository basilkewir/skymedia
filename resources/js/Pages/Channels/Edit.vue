<template>
    <AppLayout>
        <template #header>
            <div class="flex items-center gap-2 text-sm text-slate-400">
                <Link :href="route('channels.index')" class="hover:text-white transition-colors">Channels</Link>
                <span>/</span>
                <Link :href="route('channels.show', channel.id)" class="hover:text-white transition-colors">{{ channel.name }}</Link>
                <span>/</span>
                <span class="text-white">Edit</span>
            </div>
        </template>

        <div class="max-w-3xl">
            <form @submit.prevent="submit" class="space-y-6">

                <!-- Basic -->
                <section class="card">
                    <h2 class="section-title">Basic Information</h2>
                    <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                        <FormField label="Channel Name" :error="form.errors.name">
                            <input v-model="form.name" type="text" required class="form-input" />
                        </FormField>
                        <FormField label="Active">
                            <select v-model="form.is_active" class="form-input">
                                <option :value="true">Enabled</option>
                                <option :value="false">Disabled</option>
                            </select>
                        </FormField>
                        <FormField label="Notes" className="sm:col-span-2" :error="form.errors.notes">
                            <textarea v-model="form.notes" rows="2" class="form-input resize-none" />
                        </FormField>
                    </div>
                </section>

                <!-- Source -->
                <section class="card">
                    <h2 class="section-title">Source Stream</h2>
                    <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                        <FormField label="Protocol" :error="form.errors.source_type">
                            <select v-model="form.source_type" class="form-input">
                                <option value="hls">HLS</option>
                                <option value="udp">UDP Multicast</option>
                                <option value="mpegts">MPEG-TS</option>
                                <option value="rtmp">RTMP</option>
                                <option value="srt">SRT</option>
                            </select>
                        </FormField>
                        <FormField label="Source URL" className="sm:col-span-2" :error="form.errors.source_url">
                            <input v-model="form.source_url" type="text" required class="form-input font-mono text-sm" />
                        </FormField>
                    </div>
                </section>

                <!-- Push destination -->
                <section class="card">
                    <h2 class="section-title">Push Destination</h2>
                    <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                        <FormField label="Protocol" :error="form.errors.push_protocol">
                            <select v-model="form.push_protocol" class="form-input">
                                <option value="rtmp">RTMP</option>
                                <option value="srt">SRT</option>
                            </select>
                        </FormField>
                        <FormField label="Push Server URL" :error="form.errors.push_url">
                            <input v-model="form.push_url" type="text" required class="form-input font-mono text-sm" />
                        </FormField>
                        <FormField label="Stream Key" :error="form.errors.push_stream_key">
                            <input v-model="form.push_stream_key" type="text" class="form-input font-mono text-sm" />
                        </FormField>
                        <div class="sm:col-span-2 px-3 py-2 bg-slate-800/60 rounded-lg text-xs text-slate-400 font-mono">
                            Output: <span class="text-indigo-400">{{ pushTarget }}</span>
                        </div>
                    </div>
                </section>

                <!-- Push encoding -->
                <section class="card">
                    <h2 class="section-title">Push Encoding</h2>
                    <p class="text-xs text-slate-500 mb-4">
                        Changes take effect on the next channel restart.
                    </p>

                    <h3 class="subsection-title">Video</h3>
                    <div class="grid grid-cols-1 gap-5 sm:grid-cols-2 mb-6">
                        <FormField label="Video Codec" :error="form.errors.push_video_codec">
                            <select v-model="form.push_video_codec" class="form-input">
                                <option value="copy">Copy (pass-through)</option>
                                <option value="h264">H.264 (libx264)</option>
                                <option value="h265">H.265 / HEVC (libx265)</option>
                                <option value="vp8">VP8 (libvpx)</option>
                                <option value="vp9">VP9 (libvpx-vp9)</option>
                            </select>
                        </FormField>

                        <FormField label="Video Bitrate (kbps)" :error="form.errors.push_video_bitrate">
                            <input v-model.number="form.push_video_bitrate" type="number"
                                   :disabled="form.push_video_codec === 'copy'"
                                   min="100" max="50000" placeholder="e.g. 4000"
                                   class="form-input disabled:opacity-40" />
                            <p class="hint">Leave blank for codec default</p>
                        </FormField>

                        <FormField label="Resolution (WxH)" :error="form.errors.push_resolution">
                            <select v-model="form.push_resolution"
                                    :disabled="form.push_video_codec === 'copy'"
                                    class="form-input disabled:opacity-40">
                                <option value="">Source resolution</option>
                                <option value="3840x2160">4K — 3840×2160</option>
                                <option value="1920x1080">FHD — 1920×1080</option>
                                <option value="1280x720">HD — 1280×720</option>
                                <option value="854x480">SD — 854×480</option>
                                <option value="640x360">360p — 640×360</option>
                            </select>
                        </FormField>

                        <FormField label="Frame Rate (fps)" :error="form.errors.push_framerate">
                            <select v-model.number="form.push_framerate"
                                    :disabled="form.push_video_codec === 'copy'"
                                    class="form-input disabled:opacity-40">
                                <option :value="null">Source frame rate</option>
                                <option :value="24">24 fps</option>
                                <option :value="25">25 fps (PAL)</option>
                                <option :value="30">30 fps (NTSC)</option>
                                <option :value="50">50 fps</option>
                                <option :value="60">60 fps</option>
                            </select>
                        </FormField>
                    </div>

                    <h3 class="subsection-title">Audio</h3>
                    <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                        <FormField label="Audio Codec" :error="form.errors.push_audio_codec">
                            <select v-model="form.push_audio_codec" class="form-input">
                                <option value="copy">Copy (pass-through)</option>
                                <option value="aac">AAC-LC (recommended)</option>
                                <option value="mp3">MP3 (libmp3lame)</option>
                                <option value="opus">Opus (libopus)</option>
                                <option value="ac3">AC-3 Dolby Digital</option>
                            </select>
                        </FormField>

                        <FormField label="Audio Bitrate (kbps)" :error="form.errors.push_audio_bitrate">
                            <select v-model.number="form.push_audio_bitrate"
                                    :disabled="form.push_audio_codec === 'copy'"
                                    class="form-input disabled:opacity-40">
                                <option :value="64">64 kbps</option>
                                <option :value="96">96 kbps</option>
                                <option :value="128">128 kbps</option>
                                <option :value="160">160 kbps</option>
                                <option :value="192">192 kbps</option>
                                <option :value="256">256 kbps</option>
                                <option :value="320">320 kbps</option>
                            </select>
                        </FormField>

                        <FormField label="Sample Rate" :error="form.errors.push_audio_samplerate">
                            <select v-model.number="form.push_audio_samplerate"
                                    :disabled="form.push_audio_codec === 'copy'"
                                    class="form-input disabled:opacity-40">
                                <option :value="22050">22,050 Hz</option>
                                <option :value="44100">44,100 Hz (CD)</option>
                                <option :value="48000">48,000 Hz (Broadcast)</option>
                                <option :value="96000">96,000 Hz (Hi-Res)</option>
                            </select>
                        </FormField>

                        <FormField label="Channels" :error="form.errors.push_audio_channels">
                            <select v-model.number="form.push_audio_channels"
                                    :disabled="form.push_audio_codec === 'copy'"
                                    class="form-input disabled:opacity-40">
                                <option :value="1">Mono (1ch)</option>
                                <option :value="2">Stereo (2ch)</option>
                                <option :value="6">5.1 Surround (6ch)</option>
                            </select>
                        </FormField>
                    </div>
                </section>

                <!-- DVR -->
                <section class="card">
                    <h2 class="section-title">DVR Segment Buffer</h2>
                    <p class="text-xs text-slate-500 mb-4">
                        Rolling HLS segment buffer — the last
                        <strong class="text-slate-300">{{ dvrWindowLabel }}</strong>
                        is always kept. Older segments are deleted automatically.
                    </p>
                    <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                        <FormField label="DVR Window (seconds)" :error="form.errors.dvr_duration">
                            <div class="flex gap-2">
                                <input v-model.number="form.dvr_duration" type="number"
                                       min="60" max="86400" required class="form-input flex-1" />
                                <select @change="applyPreset" v-model="dvrPreset" class="form-input w-32">
                                    <option value="">Custom</option>
                                    <option value="300">5 min</option>
                                    <option value="900">15 min</option>
                                    <option value="1800">30 min</option>
                                    <option value="3600">1 hour</option>
                                    <option value="7200">2 hours</option>
                                    <option value="10800">3 hours</option>
                                    <option value="18000">5 hours</option>
                                    <option value="43200">12 hours</option>
                                    <option value="86400">24 hours</option>
                                </select>
                            </div>
                            <p class="hint">{{ dvrWindowLabel }} · ~{{ estimatedStorageMB }} MB estimated</p>
                        </FormField>

                        <FormField label="Segment Duration (seconds)" :error="form.errors.segment_duration">
                            <input v-model.number="form.segment_duration" type="number"
                                   min="2" max="30" required class="form-input" />
                            <p class="hint">{{ segmentCount }} segments in buffer</p>
                        </FormField>

                        <FormField label="Health Check Interval (s)" :error="form.errors.check_interval">
                            <input v-model.number="form.check_interval" type="number"
                                   min="1" max="60" required class="form-input" />
                        </FormField>

                        <FormField label="Max Reconnect Retries" :error="form.errors.max_retries">
                            <input v-model.number="form.max_retries" type="number"
                                   min="0" max="20" required class="form-input" />
                        </FormField>
                    </div>
                </section>

                <!-- Recording -->
                <section class="card">
                    <h2 class="section-title">Scheduled Recording</h2>
                    <p class="text-xs text-slate-500 mb-4">
                        Records a single MP4 file while the stream is live. Old file deleted only after new recording
                        is verified complete. Loops as fallback when source goes offline.
                        Set to <strong class="text-slate-300">0</strong> to disable.
                    </p>
                    <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                        <FormField label="Recording Duration (seconds)" :error="form.errors.record_duration">
                            <div class="flex gap-2">
                                <input v-model.number="form.record_duration" type="number"
                                       min="0" max="86400" required class="form-input flex-1" />
                                <select @change="applyRecordPreset" v-model="recordPreset" class="form-input w-32">
                                    <option value="">Custom</option>
                                    <option value="0">Disabled</option>
                                    <option value="1800">30 min</option>
                                    <option value="3600">1 hour</option>
                                    <option value="7200">2 hours</option>
                                    <option value="10800">3 hours</option>
                                    <option value="18000">5 hours</option>
                                    <option value="43200">12 hours</option>
                                    <option value="86400">24 hours</option>
                                </select>
                            </div>
                            <p class="hint">
                                <template v-if="form.record_duration > 0">
                                    {{ recordDurationLabel }} per cycle · ~{{ recordStorageMB }} MB per file
                                </template>
                                <template v-else>Recording disabled</template>
                            </p>
                        </FormField>

                        <div v-if="channel.fallback_recording_path" class="flex items-start pt-1">
                            <div class="px-3 py-2.5 bg-slate-800/50 rounded-lg text-xs text-slate-400 w-full">
                                <span class="text-green-400 font-medium block mb-1">✓ Fallback file available</span>
                                <span class="font-mono break-all">{{ channel.fallback_recording_path.split('/').pop() }}</span>
                            </div>
                        </div>
                    </div>
                </section>

                <div class="flex justify-end gap-3">
                    <Link :href="route('channels.show', channel.id)"
                          class="px-4 py-2 text-sm text-slate-400 border border-slate-700 rounded-lg hover:border-slate-500 hover:text-white transition-colors">
                        Cancel
                    </Link>
                    <button type="submit" :disabled="form.processing"
                            class="px-6 py-2 bg-indigo-600 hover:bg-indigo-700 disabled:opacity-50 text-white text-sm font-medium rounded-lg transition-colors">
                        {{ form.processing ? 'Saving…' : 'Save Changes' }}
                    </button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>

<script setup>
import { computed, ref } from 'vue'
import { Link, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import FormField from '@/Components/FormField.vue'

const props = defineProps({ channel: Object })

const dvrPreset    = ref('')
const recordPreset = ref('')

const form = useForm({
    name:             props.channel.name,
    is_active:        props.channel.is_active,
    notes:            props.channel.notes ?? '',
    source_type:      props.channel.source_type,
    source_url:       props.channel.source_url,
    push_protocol:    props.channel.push_protocol,
    push_url:         props.channel.push_url,
    push_stream_key:  props.channel.push_stream_key ?? '',
    // Video
    push_video_codec:   props.channel.push_video_codec   ?? 'copy',
    push_video_bitrate: props.channel.push_video_bitrate ?? null,
    push_resolution:    props.channel.push_resolution    ?? '',
    push_framerate:     props.channel.push_framerate     ?? null,
    // Audio
    push_audio_codec:       props.channel.push_audio_codec       ?? 'aac',
    push_audio_bitrate:     props.channel.push_audio_bitrate     ?? 128,
    push_audio_samplerate:  props.channel.push_audio_samplerate  ?? 48000,
    push_audio_channels:    props.channel.push_audio_channels    ?? 2,
    // DVR
    dvr_duration:     props.channel.dvr_duration,
    segment_duration: props.channel.segment_duration,
    check_interval:   props.channel.check_interval,
    max_retries:      props.channel.max_retries,
    record_duration:  props.channel.record_duration ?? 0,
})

function applyPreset() {
    if (dvrPreset.value) form.dvr_duration = parseInt(dvrPreset.value)
}

function applyRecordPreset() {
    if (recordPreset.value !== '') form.record_duration = parseInt(recordPreset.value)
}

const pushTarget = computed(() => {
    if (!form.push_url) return '—'
    return form.push_url.replace(/\/$/, '') + '/' + (form.push_stream_key || 'key')
})

const dvrWindowLabel = computed(() => {
    const s = form.dvr_duration
    const h = Math.floor(s / 3600)
    const m = Math.floor((s % 3600) / 60)
    return h > 0 ? `${h}h ${m}m` : `${m}m`
})

const segmentCount = computed(() =>
    Math.ceil(form.dvr_duration / Math.max(1, form.segment_duration))
)

const estimatedStorageMB = computed(() => {
    const videoBitrate = form.push_video_codec !== 'copy' && form.push_video_bitrate
        ? form.push_video_bitrate : 4000
    const audioBitrate = form.push_audio_codec !== 'copy' ? form.push_audio_bitrate : 128
    const totalKbps = videoBitrate + audioBitrate
    return Math.round((form.dvr_duration * totalKbps * 1000) / 8 / 1_048_576)
})

const recordDurationLabel = computed(() => {
    const s = form.record_duration
    const h = Math.floor(s / 3600), m = Math.floor((s % 3600) / 60)
    return h > 0 ? `${h}h ${m}m` : `${m}m`
})

const recordStorageMB = computed(() => {
    const videoBitrate = form.push_video_codec !== 'copy' && form.push_video_bitrate ? form.push_video_bitrate : 4000
    const audioBitrate = form.push_audio_codec !== 'copy' ? form.push_audio_bitrate : 128
    return Math.round((form.record_duration * (videoBitrate + audioBitrate) * 1000) / 8 / 1_048_576)
})

function submit() { form.put(route('channels.update', props.channel.id)) }
</script>

<style scoped>
.card             { @apply bg-slate-900 border border-slate-800 rounded-xl p-6; }
.section-title    { @apply text-sm font-semibold text-white mb-4; }
.subsection-title { @apply text-xs font-semibold text-slate-400 uppercase tracking-wider mb-3; }
.hint             { @apply mt-1 text-xs text-slate-500; }
</style>
