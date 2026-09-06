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
                <Section title="Channel Type">
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                        <button type="button" @click="selectChannelKind('managed')"
                                class="text-left rounded-xl border p-4 transition-colors"
                                :class="channelKind === 'managed' ? 'border-indigo-500 bg-indigo-500/10' : 'border-slate-700 hover:border-slate-600'">
                            <span class="block text-sm font-semibold text-white">Managed push channel</span>
                            <span class="block mt-1 text-xs text-slate-400">OBS or vMix sends RTMP/SRT to this server. A publisher URL and stream key are generated.</span>
                        </button>
                        <button type="button" @click="selectChannelKind('streamed')"
                                class="text-left rounded-xl border p-4 transition-colors"
                                :class="channelKind === 'streamed' ? 'border-indigo-500 bg-indigo-500/10' : 'border-slate-700 hover:border-slate-600'">
                            <span class="block text-sm font-semibold text-white">Streamed source channel</span>
                            <span class="block mt-1 text-xs text-slate-400">This server pulls an existing HLS, YouTube Live, UDP, MPEG-TS, RTMP, or SRT source URL.</span>
                        </button>
                        <button type="button" @click="selectChannelKind('tv_playout')"
                                class="text-left rounded-xl border p-4 transition-colors"
                                :class="channelKind === 'tv_playout' ? 'border-emerald-500 bg-emerald-500/10' : 'border-slate-700 hover:border-slate-600'">
                            <span class="block text-sm font-semibold text-white">TV Playout channel</span>
                            <span class="block mt-1 text-xs text-slate-400">Runs 100% on the VPS. No ingest, no push. FFmpeg renders a playlist with CG overlays and outputs HLS to MediaMTX.</span>
                        </button>
                    </div>
                </Section>

                <Section :title="channelKind === 'managed' ? 'Publisher Ingest' : 'Source Stream'">
                    <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                        <FormField :label="channelKind === 'managed' ? 'Receive Protocol' : 'Source Protocol'" :error="form.errors.source_type">
                            <select v-model="form.source_type" class="form-input">
                                <template v-if="channelKind === 'managed'">
                                    <option value="rtmp">RTMP — OBS / vMix</option>
                                    <option value="srt">SRT — caller publishing mode</option>
                                </template>
                                <template v-else>
                                    <option value="hls">HLS (HTTP Live Streaming)</option>
                                    <option value="youtube">YouTube Live</option>
                                    <option value="udp">UDP Multicast</option>
                                    <option value="mpegts">MPEG-TS</option>
                                    <option value="rtmp">RTMP</option>
                                    <option value="srt">SRT (Secure Reliable Transport)</option>
                                </template>
                            </select>
                        </FormField>
                        <FormField v-if="channelKind === 'managed'" label="Listener Port" :error="form.errors.ingest_port">
                            <input v-model.number="form.ingest_port" type="number"
                                   :min="form.source_type === 'srt' ? 30000 : 20000"
                                   :max="form.source_type === 'srt' ? 30099 : 20099"
                                   placeholder="Assigned automatically" class="form-input" />
                            <p class="mt-1 text-xs text-slate-500">Leave empty for automatic assignment.</p>
                        </FormField>
                        <FormField v-if="channelKind === 'managed' && form.source_type === 'rtmp'" label="RTMP Stream Key (Custom)" :error="form.errors.rtmp_input_key">
                            <input v-model="form.rtmp_input_key" type="text" class="form-input font-mono text-sm" placeholder="Leave empty to auto-generate" />
                            <p class="mt-1 text-xs text-slate-500">Custom stream key for OBS/vMix to publish to. Auto-generated if left blank.</p>
                        </FormField>
                        <FormField v-if="channelKind === 'streamed'" label="Source URL" class-name="sm:col-span-2" :error="form.errors.source_url">
                            <input v-model="form.source_url" type="text" required class="form-input font-mono text-sm"
                                   :placeholder="sourcePlaceholder" />
                            <p class="mt-1 text-xs text-slate-500">{{ sourceHint }}</p>
                        </FormField>
                        <FormField v-if="channelKind === 'streamed' && form.source_type === 'youtube'" label="YouTube Cookies (optional)" class-name="sm:col-span-2" :error="form.errors.youtube_cookies">
                            <textarea v-model="form.youtube_cookies" rows="4" class="form-input font-mono text-xs"
                                      placeholder="Netscape-format cookies from your browser (for private/restricted streams)&#10;Example:&#10;.youtube.com	TRUE	/	TRUE	0	SID	xxxxx"></textarea>
                            <p class="mt-1 text-xs text-slate-500">Export cookies from your browser using a cookies.txt extension. Required only for age-restricted or private streams.</p>
                        </FormField>
                        <FormField v-if="channelKind === 'streamed' && form.source_type === 'youtube'" label="YouTube PO Token (optional)" :error="form.errors.youtube_po_token">
                            <input v-model="form.youtube_po_token" type="text" class="form-input font-mono text-sm"
                                   placeholder="Visitor data + PO token pair" />
                            <p class="mt-1 text-slate-500">Bypasses YouTube bot detection. Get one from <a href="https://github.com/nickoala/youtube-po-token" target="_blank" class="text-indigo-400 hover:underline">youtube-po-token</a> or similar tools.</p>
                        </FormField>
                        <FormField v-if="channelKind === 'streamed'" label="Re-encode on ingest" :error="form.errors.reencode_ingest">
                            <label class="inline-flex items-center gap-2 mt-2">
                                <input v-model="form.reencode_ingest" type="checkbox" class="form-checkbox rounded" />
                                <span class="text-sm text-slate-300">Transcode source to H.264/AAC</span>
                            </label>
                            <p class="mt-1 text-xs text-slate-500">Enable only for sources with broken H.264 NALs (e.g. catcast.tv) or incompatible codecs. Uses more CPU.</p>
                        </FormField>
                        <p v-if="channelKind === 'managed'" class="sm:col-span-2 text-xs text-indigo-300 bg-indigo-500/10 border border-indigo-500/20 rounded-lg px-3 py-2">
                            After creation, copy the generated Server URL and Stream Key into OBS or vMix.
                        </p>
                    </div>
                </Section>

                <!-- Push Destination -->
                <Section title="Push Destination">
                    <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                        <FormField label="Push Protocol" :error="form.errors.push_protocol">
                            <select v-model="form.push_protocol" class="form-input">
                                <option value="rtmp">RTMP (Wowza / nginx-rtmp / SRS)</option>
                                <option value="srt">SRT</option>
                                <option value="hls">HLS (HTTP Live Streaming push)</option>
                            </select>
                        </FormField>
                        <FormField label="Push Server URL" :error="form.errors.push_url">
                            <input v-model="form.push_url" type="text" required
                                   :placeholder="pushUrlPlaceholder" class="form-input font-mono text-sm" />
                        </FormField>
                        <FormField label="Stream Key / Sub-path" :error="form.errors.push_stream_key">
                            <input v-model="form.push_stream_key" type="text" required
                                   :placeholder="pushStreamKeyPlaceholder" class="form-input font-mono text-sm" />
                        </FormField>
                        <template v-if="form.push_protocol === 'hls'">
                            <FormField label="HLS Segment Duration" :error="form.errors.push_hls_segment_duration">
                                <input v-model.number="form.push_hls_segment_duration" type="number"
                                       min="1" max="30" class="form-input" />
                                <p class="mt-1 text-xs text-slate-500">Leave blank to use the DVR segment duration.</p>
                            </FormField>
                            <FormField label="HLS Playlist Size" :error="form.errors.push_hls_list_size">
                                <input v-model.number="form.push_hls_list_size" type="number"
                                       min="0" max="1000" class="form-input" />
                                <p class="mt-1 text-xs text-slate-500">Number of segments in the pushed playlist (0 = keep all).</p>
                            </FormField>
                        </template>
                        <FormField v-if="form.push_protocol !== 'hls'" label="Username" :error="form.errors.push_username">
                            <input v-model="form.push_username" type="text"
                                   placeholder="Optional — only if server requires auth"
                                   class="form-input" />
                        </FormField>
                        <FormField v-if="form.push_protocol !== 'hls'" label="Password" :error="form.errors.push_password">
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
                        <p v-if="channelKind === 'managed'" class="sm:col-span-2 text-sm text-indigo-300 bg-indigo-500/10 border border-indigo-500/20 rounded-lg px-4 py-3">
                            Recording and rolling DVR are disabled for managed push channels. Uploaded VOD playlists remain available for fallback.
                        </p>
                        <template v-else>
                        <FormField label="Rolling DVR" :error="form.errors.dvr_enabled" class-name="sm:col-span-2">
                            <select v-model="form.dvr_enabled" class="form-input">
                                <option :value="false">Disabled — live preview and forwarding only</option>
                                <option :value="true">Enabled — retain a rewindable rolling window</option>
                            </select>
                            <p class="mt-1 text-xs text-slate-500">Uploaded fallback VOD works whether DVR is enabled or disabled.</p>
                        </FormField>
                        <FormField label="DVR Rolling Window" :error="form.errors.dvr_duration" :class-name="!form.dvr_enabled ? 'opacity-50' : ''">
                            <div class="flex gap-2">
                                <input v-model.number="form.dvr_duration" type="number"
                                       min="60" max="86400" required :disabled="!form.dvr_enabled" class="form-input flex-1" />
                                <select @change="applyDvrPreset" v-model="dvrPreset" :disabled="!form.dvr_enabled" class="form-input w-28">
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
                        <FormField label="Segment Duration (seconds)" :error="form.errors.segment_duration" :class-name="!form.dvr_enabled ? 'opacity-50' : ''">
                            <input v-model.number="form.segment_duration" type="number"
                                   min="1" max="30" required :disabled="!form.dvr_enabled" class="form-input" />
                            <p class="mt-1 text-xs text-slate-500">
                                {{ Math.ceil(form.dvr_duration / form.segment_duration) }} segments total
                            </p>
                        </FormField>
                        <FormField label="Recording File Length" :error="form.errors.record_duration">
                            <div class="flex gap-2">
                                <input v-model.number="form.record_duration" type="number"
                                       min="0" max="86400" required class="form-input flex-1" />
                                <select @change="applyRecPreset" v-model="recPreset" class="form-input w-32">
                                    <option value="0">Disabled</option>
                                    <option value="3600">1 hour</option>
                                    <option value="7200">2 hours</option>
                                    <option value="10800">3 hours</option>
                                    <option value="18000">5 hours</option>
                                    <option value="43200">12 hours</option>
                                    <option value="86400">24 hours</option>
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
                        <FormField label="Keep Recordings" :error="form.errors.keep_recordings">
                            <select v-model.number="form.keep_recordings" class="form-input">
                                <option :value="1">1 — current file only</option>
                                <option :value="3">3 — ~{{ (3 * estimatedRecMb) }} MB total</option>
                                <option :value="5">5</option>
                                <option :value="7">7</option>
                                <option :value="10">10</option>
                            </select>
                            <p class="mt-1 text-xs text-slate-500">
                                Oldest completed recordings are auto-deleted to maintain disk space
                            </p>
                        </FormField>
                        </template>
                        <FormField label="Health Check Interval (seconds)" :error="form.errors.check_interval">
                            <input v-model.number="form.check_interval" type="number"
                                   min="1" max="60" required class="form-input" />
                        </FormField>
                        <FormField label="Max Retries" :error="form.errors.max_retries">
                            <input v-model.number="form.max_retries" type="number"
                                   min="0" max="20" required class="form-input" />
                        </FormField>
                        <FormField label="Channel Timezone" :error="form.errors.timezone">
                            <select v-model="form.timezone" class="form-input font-mono text-sm">
                                <option value="UTC">UTC (Coordinated Universal Time)</option>
                                <option value="America/New_York">America/New York (EST/EDT)</option>
                                <option value="America/Chicago">America/Chicago (CST/CDT)</option>
                                <option value="America/Denver">America/Denver (MST/MDT)</option>
                                <option value="America/Los_Angeles">America/Los Angeles (PST/PDT)</option>
                                <option value="America/Sao_Paulo">America/São Paulo (BRT)</option>
                                <option value="Europe/London">Europe/London (GMT/BST)</option>
                                <option value="Europe/Paris">Europe/Paris (CET/CEST)</option>
                                <option value="Europe/Moscow">Europe/Moscow (MSK)</option>
                                <option value="Europe/Berlin">Europe/Berlin (CET/CEST)</option>
                                <option value="Asia/Dubai">Asia/Dubai (GST)</option>
                                <option value="Asia/Kolkata">Asia/Kolkata (IST)</option>
                                <option value="Asia/Shanghai">Asia/Shanghai (CST)</option>
                                <option value="Asia/Tokyo">Asia/Tokyo (JST)</option>
                                <option value="Asia/Seoul">Asia/Seoul (KST)</option>
                                <option value="Asia/Singapore">Asia/Singapore (SGT)</option>
                                <option value="Australia/Sydney">Australia/Sydney (AEST/AEDT)</option>
                                <option value="Pacific/Auckland">Pacific/Auckland (NZST/NZDT)</option>
                                <option value="Africa/Cairo">Africa/Cairo (EET)</option>
                                <option value="Africa/Johannesburg">Africa/Johannesburg (SAST)</option>
                            </select>
                            <p class="mt-1 text-xs text-slate-500">
                                Recording filenames will use this timezone
                            </p>
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
const channelKind = ref('managed')

const form = useForm({
    name: '', slug: '', notes: '',
    source_type: 'rtmp', ingest_mode: 'push', ingest_port: null, source_url: '',
    reencode_ingest: false,
    youtube_cookies: '',
    youtube_po_token: '',
    push_protocol: 'rtmp', push_url: '', push_stream_key: '',
    push_username: '', push_password: '',
    push_hls_segment_duration: null, push_hls_list_size: null,
    push_video_codec: 'copy',   push_video_bitrate: null,
    push_resolution: '',        push_framerate: null,
    push_audio_codec: 'aac',    push_audio_bitrate: 128,
    push_audio_samplerate: 48000, push_audio_channels: 2,
    dvr_duration: 3600, segment_duration: 2, dvr_enabled: false,
    record_duration: 3600, keep_recordings: 10,
    timezone: 'UTC', locale: 'en',
    check_interval: 5, max_retries: 3,
})

function autoSlug() {
    form.slug = form.name
        .toLowerCase()
        .normalize('NFD').replace(/[\u0300-\u036f]/g, '') // strip accents
        .replace(/[^a-z0-9\u4e00-\u9fff\u0600-\u06ff\u0400-\u04ff]+/g, '-')
        .replace(/^-|-$/g, '')
}
function applyDvrPreset() { if (dvrPreset.value !== '') form.dvr_duration = parseInt(dvrPreset.value) }
function applyRecPreset() { if (recPreset.value !== '') form.record_duration = parseInt(recPreset.value) }

function selectChannelKind(kind) {
    channelKind.value = kind
    if (kind === 'managed') {
        form.source_type = 'rtmp'
        form.ingest_mode = 'push'
        form.source_url = ''
        form.dvr_enabled = false
    } else if (kind === 'tv_playout') {
        form.source_type = 'tv_playout'
        form.ingest_mode = 'pull'
        form.source_url = 'tv_playout://local'
        form.dvr_enabled = false
        form.record_duration = 0
    } else {
        form.source_type = 'hls'
        form.ingest_mode = 'pull'
        form.ingest_port = null
        form.dvr_enabled = true
    }
}

const sourcePlaceholders = {
    hls: 'https://stream.example.com/live/stream.m3u8',
    youtube: 'https://www.youtube.com/watch?v=XXXXXXX',
    udp: 'udp://239.1.1.1:1234', mpegts: 'udp://239.1.1.1:1234',
    rtmp: 'rtmp://ingest.example.com/live/key', srt: '192.168.1.100:9000',
}
const sourceHints = {
    hls: 'HTTP(S) HLS playlist URL',
    youtube: 'YouTube watch/live URL — resolved via yt-dlp (requires yt-dlp installed on server)',
    udp: 'UDP multicast or unicast address',
    mpegts: 'UDP/TCP MPEG-TS address', rtmp: 'Full RTMP ingest URL with stream key',
    srt: 'Host:port — srt:// prefix added automatically',
}
const sourcePlaceholder = computed(() => sourcePlaceholders[form.source_type] ?? '')
const sourceHint        = computed(() => sourceHints[form.source_type] ?? '')
const pushUrlPlaceholder = computed(() =>
    form.push_protocol === 'hls' ? '/var/www/hls or https://cdn.example.com/hls' : 'rtmp://your-server/live'
)
const pushStreamKeyPlaceholder = computed(() =>
    form.push_protocol === 'hls' ? 'channel1 (sub-directory)' : 'channel1'
)
const pushTarget = computed(() => {
    if (!form.push_url) return form.push_protocol === 'hls' ? '/var/www/hls/channel1/index.m3u8' : 'rtmp://server/live/key'
    const base = form.push_url.replace(/\/$/, '')
    const key = form.push_stream_key || 'key'
    if (form.push_protocol === 'hls') return `${base}/${key}/index.m3u8`
    return `${base}/${key}`
})

// Storage estimates at 3 Mbps average
const estimatedDvrMb = computed(() => Math.round(form.dvr_duration * 3_000_000 / 8 / 1_048_576))
const estimatedRecMb = computed(() => form.record_duration > 0 ? Math.round(form.record_duration * 3_000_000 / 8 / 1_048_576) : 0)

function formatDuration(s) {
    const h = Math.floor(s / 3600), m = Math.floor((s % 3600) / 60)
    return h > 0 ? `${h}h ${m}m` : `${m}m`
}
function submit() {
    if (channelKind.value === 'managed') {
        form.ingest_mode = 'push'
        form.source_url = ''
    } else if (channelKind.value === 'tv_playout') {
        form.ingest_mode = 'pull'
        form.source_url = 'tv_playout://local'
    } else {
        form.ingest_mode = 'pull'
    }
    form.post(route('channels.store'))
}
</script>
