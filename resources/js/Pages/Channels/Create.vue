<template>
    <AppLayout>
        <template #header>
            <div class="flex items-center gap-2 text-sm text-slate-400">
                <Link :href="route('channels.index')" class="hover:text-white transition-colors">Channels</Link>
                <span>/</span>
                <span class="text-white">New Channel</span>
            </div>
        </template>

        <div class="max-w-3xl">
            <form @submit.prevent="submit" class="space-y-5">

                <!-- Basic Info -->
                <Section title="Basic Information">
                    <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                        <FormField label="Channel Name" :error="form.errors.name">
                            <input v-model="form.name" @input="autoSlug" type="text" required
                                   placeholder="BBC World News" class="form-input" />
                        </FormField>
                        <FormField label="Slug" :error="form.errors.slug">
                            <input v-model="form.slug" type="text" required placeholder="bbc-world-news"
                                   class="form-input font-mono" />
                        </FormField>
                        <FormField label="Notes" class-name="sm:col-span-2">
                            <textarea v-model="form.notes" rows="2" placeholder="Optional notes…"
                                      class="form-input resize-none" />
                        </FormField>
                    </div>
                </Section>

                <!-- Source -->
                <Section title="Source Stream">
                    <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                        <FormField label="Source Protocol" :error="form.errors.source_type">
                            <select v-model="form.source_type" class="form-input">
                                <option value="hls">HLS (HTTP Live Streaming)</option>
                                <option value="udp">UDP Multicast</option>
                                <option value="mpegts">MPEG-TS</option>
                                <option value="rtmp">RTMP</option>
                                <option value="srt">SRT (Secure Reliable Transport)</option>
                            </select>
                        </FormField>
                        <FormField label="Source URL" class-name="sm:col-span-2" :error="form.errors.source_url">
                            <input v-model="form.source_url" type="text" required class="form-input font-mono text-sm"
                                   :placeholder="sourcePlaceholder" />
                            <p class="mt-1 text-xs text-slate-500">{{ sourceHint }}</p>
                        </FormField>
                    </div>
                </Section>

                <!-- Push Destination -->
                <Section title="Push Destination">
                    <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                        <FormField label="Push Protocol" :error="form.errors.push_protocol">
                            <select v-model="form.push_protocol" class="form-input">
                                <option value="rtmp">RTMP (Wowza / nginx-rtmp / SRS)</option>
                                <option value="srt">SRT</option>
                            </select>
                        </FormField>
                        <FormField label="Push Server URL" :error="form.errors.push_url">
                            <input v-model="form.push_url" type="text" required
                                   placeholder="rtmp://your-server/live" class="form-input font-mono text-sm" />
                        </FormField>
                        <FormField label="Stream Key" :error="form.errors.push_stream_key">
                            <input v-model="form.push_stream_key" type="text" required
                                   placeholder="channel1" class="form-input font-mono text-sm" />
                        </FormField>
                        <FormField label="Username" :error="form.errors.push_username">
                            <input v-model="form.push_username" type="text"
                                   placeholder="Optional — only if server requires auth"
                                   class="form-input" />
                        </FormField>
                        <FormField label="Password" :error="form.errors.push_password">
                            <input v-model="form.push_password" type="password"
                                   placeholder="Optional — only if server requires auth"
                                   class="form-input" />
                        </FormField>
                        <div class="sm:col-span-2 px-3 py-2 bg-slate-800/60 rounded-lg text-xs font-mono text-slate-400">
                            Push target: <span class="text-indigo-400">{{ pushTarget }}</span>
                        </div>
                    </div>
                </Section>

                <!-- Video Encoding -->
                <Section title="Video Encoding">
                    <p class="text-xs text-slate-500 mb-4">Applied to the push output. Use <code class="text-indigo-400">copy</code> to pass video through without re-encoding (lowest CPU, preserves source quality).</p>
                    <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                        <FormField label="Video Codec" :error="form.errors.push_video_codec">
                            <select v-model="form.push_video_codec" class="form-input">
                                <option value="copy">copy — passthrough (no re-encode)</option>
                                <option value="h264">H.264 (libx264) — most compatible</option>
                                <option value="h265">H.265 (libx265) — 50% smaller, higher CPU</option>
                                <option value="vp8">VP8 (libvpx)</option>
                                <option value="vp9">VP9 (libvpx-vp9)</option>
                            </select>
                        </FormField>
                        <FormField label="Video Bitrate (kbps)" :error="form.errors.push_video_bitrate">
                            <input v-model.number="form.push_video_bitrate" type="number"
                                   min="100" max="50000" placeholder="e.g. 4000"
                                   :disabled="form.push_video_codec === 'copy'"
                                   class="form-input disabled:opacity-40" />
                            <p class="mt-1 text-xs text-slate-500">Leave blank for codec default. Ignored when codec=copy.</p>
                        </FormField>
                        <FormField label="Output Resolution" :error="form.errors.push_resolution">
                            <select v-model="form.push_resolution"
                                    :disabled="form.push_video_codec === 'copy'" class="form-input disabled:opacity-40">
                                <option value="">Source resolution (no scale)</option>
                                <option value="3840x2160">4K — 3840×2160</option>
                                <option value="1920x1080">1080p — 1920×1080</option>
                                <option value="1280x720">720p — 1280×720</option>
                                <option value="854x480">480p — 854×480</option>
                                <option value="640x360">360p — 640×360</option>
                                <option value="1280:-2">720p width (auto height)</option>
                                <option value="1920:-2">1080p width (auto height)</option>
                            </select>
                        </FormField>
                        <FormField label="Frame Rate (fps)" :error="form.errors.push_framerate">
                            <select v-model.number="form.push_framerate"
                                    :disabled="form.push_video_codec === 'copy'" class="form-input disabled:opacity-40">
                                <option :value="null">Source frame rate</option>
                                <option :value="24">24 fps (film)</option>
                                <option :value="25">25 fps (PAL)</option>
                                <option :value="30">30 fps (NTSC)</option>
                                <option :value="50">50 fps (PAL HFR)</option>
                                <option :value="60">60 fps (NTSC HFR)</option>
                            </select>
                        </FormField>
                    </div>
                </Section>

                <!-- Audio Encoding -->
                <Section title="Audio Encoding">
                    <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                        <FormField label="Audio Codec" :error="form.errors.push_audio_codec">
                            <select v-model="form.push_audio_codec" class="form-input">
                                <option value="copy">copy — passthrough</option>
                                <option value="aac">AAC-LC (recommended for RTMP)</option>
                                <option value="mp3">MP3 (libmp3lame)</option>
                                <option value="opus">Opus (libopus)</option>
                                <option value="ac3">AC-3 / Dolby Digital</option>
                            </select>
                        </FormField>
                        <FormField label="Audio Bitrate (kbps)" :error="form.errors.push_audio_bitrate">
                            <select v-model.number="form.push_audio_bitrate"
                                    :disabled="form.push_audio_codec === 'copy'" class="form-input disabled:opacity-40">
                                <option :value="64">64 kbps</option>
                                <option :value="96">96 kbps</option>
                                <option :value="128">128 kbps (default)</option>
                                <option :value="192">192 kbps</option>
                                <option :value="256">256 kbps</option>
                                <option :value="320">320 kbps</option>
                            </select>
                        </FormField>
                        <FormField label="Sample Rate" :error="form.errors.push_audio_samplerate">
                            <select v-model.number="form.push_audio_samplerate"
                                    :disabled="form.push_audio_codec === 'copy'" class="form-input disabled:opacity-40">
                                <option :value="44100">44100 Hz (CD quality)</option>
                                <option :value="48000">48000 Hz (broadcast standard)</option>
                                <option :value="32000">32000 Hz</option>
                                <option :value="22050">22050 Hz</option>
                            </select>
                        </FormField>
                        <FormField label="Audio Channels" :error="form.errors.push_audio_channels">
                            <select v-model.number="form.push_audio_channels"
                                    :disabled="form.push_audio_codec === 'copy'" class="form-input disabled:opacity-40">
                                <option :value="1">Mono (1 ch)</option>
                                <option :value="2">Stereo (2 ch) — default</option>
                                <option :value="6">5.1 Surround (6 ch)</option>
                            </select>
                        </FormField>
                    </div>
                </Section>

                <!-- DVR & Recording -->
                <Section title="DVR &amp; Recording">
                    <p class="text-xs text-slate-500 mb-4">
                        The DVR maintains a rolling window of HLS segments. The recording produces timed MP4 files
                        that are looped automatically to the push output when the source goes offline — keeping the
                        output live at all times.
                    </p>
                    <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                        <FormField label="DVR Rolling Window" :error="form.errors.dvr_duration">
                            <div class="flex gap-2">
                                <input v-model.number="form.dvr_duration" type="number"
                                       min="60" max="86400" required class="form-input flex-1" />
                                <select @change="applyDvrPreset" v-model="dvrPreset" class="form-input w-28">
                                    <option value="">Custom</option>
                                    <option value="1800">30 min</option>
                                    <option value="3600">1 hr</option>
                                    <option value="10800">3 hrs</option>
                                    <option value="18000">5 hrs</option>
                                    <option value="43200">12 hrs</option>
                                    <option value="86400">24 hrs</option>
                                </select>
                            </div>
                            <p class="mt-1 text-xs text-slate-500">
                                {{ formatDuration(form.dvr_duration) }} · ~{{ estimatedDvrMb }} MB
                            </p>
                        </FormField>
                        <FormField label="Segment Duration (seconds)" :error="form.errors.segment_duration">
                            <input v-model.number="form.segment_duration" type="number"
                                   min="2" max="30" required class="form-input" />
                            <p class="mt-1 text-xs text-slate-500">
                                {{ Math.ceil(form.dvr_duration / form.segment_duration) }} segments total
                            </p>
                        </FormField>
                        <FormField label="Recording File Length" :error="form.errors.record_duration">
                            <div class="flex gap-2">
                                <input v-model.number="form.record_duration" type="number"
                                       min="0" max="86400" required class="form-input flex-1" />
                                <select @change="applyRecPreset" v-model="recPreset" class="form-input w-28">
                                    <option value="0">Disabled</option>
                                    <option value="3600">1 hr</option>
                                    <option value="7200">2 hrs</option>
                                    <option value="14400">4 hrs</option>
                                    <option value="21600">6 hrs</option>
                                    <option value="43200">12 hrs</option>
                                    <option value="86400">24 hrs</option>
                                </select>
                            </div>
                            <p class="mt-1 text-xs text-slate-500">
                                <template v-if="form.record_duration > 0">
                                    {{ formatDuration(form.record_duration) }} per file · ~{{ estimatedRecMb }} MB · looped as fallback when source is offline
                                </template>
                                <template v-else>
                                    Set to 0 to disable fallback recording (push will go offline if source drops)
                                </template>
                            </p>
                        </FormField>
                        <FormField label="Health Check Interval (seconds)" :error="form.errors.check_interval">
                            <input v-model.number="form.check_interval" type="number"
                                   min="1" max="60" required class="form-input" />
                        </FormField>
                        <FormField label="Max Retries" :error="form.errors.max_retries">
                            <input v-model.number="form.max_retries" type="number"
                                   min="0" max="20" required class="form-input" />
                        </FormField>
                    </div>
                </Section>

                <div class="flex justify-end gap-3 pb-4">
                    <Link :href="route('channels.index')"
                          class="px-4 py-2 text-sm text-slate-400 border border-slate-700 rounded-lg hover:border-slate-500 hover:text-white transition-colors">
                        Cancel
                    </Link>
                    <button type="submit" :disabled="form.processing"
                            class="px-6 py-2 bg-indigo-600 hover:bg-indigo-700 disabled:opacity-50 text-white text-sm font-medium rounded-lg transition-colors">
                        {{ form.processing ? 'Creating…' : 'Create Channel' }}
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
import Section from '@/Components/Section.vue'

const dvrPreset = ref('3600')
const recPreset = ref('3600')

const form = useForm({
    name: '', slug: '', notes: '',
    source_type: 'hls', source_url: '',
    push_protocol: 'rtmp', push_url: '', push_stream_key: '',
    push_username: '', push_password: '',
    push_video_codec: 'copy',   push_video_bitrate: null,
    push_resolution: '',        push_framerate: null,
    push_audio_codec: 'aac',    push_audio_bitrate: 128,
    push_audio_samplerate: 48000, push_audio_channels: 2,
    dvr_duration: 3600, segment_duration: 4,
    record_duration: 3600,
    check_interval: 5, max_retries: 3,
})

function autoSlug() {
    form.slug = form.name.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '')
}
function applyDvrPreset() { if (dvrPreset.value !== '') form.dvr_duration = parseInt(dvrPreset.value) }
function applyRecPreset() { if (recPreset.value !== '') form.record_duration = parseInt(recPreset.value) }

const sourcePlaceholders = {
    hls: 'https://stream.example.com/live/stream.m3u8',
    udp: 'udp://239.1.1.1:1234', mpegts: 'udp://239.1.1.1:1234',
    rtmp: 'rtmp://ingest.example.com/live/key', srt: '192.168.1.100:9000',
}
const sourceHints = {
    hls: 'HTTP(S) HLS playlist URL', udp: 'UDP multicast or unicast address',
    mpegts: 'UDP/TCP MPEG-TS address', rtmp: 'Full RTMP ingest URL with stream key',
    srt: 'Host:port — srt:// prefix added automatically',
}
const sourcePlaceholder = computed(() => sourcePlaceholders[form.source_type] ?? '')
const sourceHint        = computed(() => sourceHints[form.source_type] ?? '')
const pushTarget        = computed(() => !form.push_url ? 'rtmp://server/live/key' : `${form.push_url.replace(/\/$/, '')}/${form.push_stream_key || 'key'}`)

// Storage estimates at 3 Mbps average
const estimatedDvrMb = computed(() => Math.round(form.dvr_duration * 3_000_000 / 8 / 1_048_576))
const estimatedRecMb = computed(() => form.record_duration > 0 ? Math.round(form.record_duration * 3_000_000 / 8 / 1_048_576) : 0)

function formatDuration(s) {
    const h = Math.floor(s / 3600), m = Math.floor((s % 3600) / 60)
    return h > 0 ? `${h}h ${m}m` : `${m}m`
}
function submit() { form.post(route('channels.store')) }
</script>
