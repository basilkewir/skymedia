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
            <form @submit.prevent="submit" class="space-y-5">

                <!-- Basic -->
                <Section title="Basic Information">
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
                        <FormField label="Owner (Admin only)" v-if="isAdmin">
                            <select v-model="form.user_id" class="form-input">
                                <option value="">Unassigned</option>
                                <option v-for="u in users" :key="u.id" :value="u.id">
                                    {{ u.name }} ({{ u.email }})
                                </option>
                            </select>
                        </FormField>
                        <FormField label="Storage Quota (GB)" :error="form.errors.storage_quota_bytes">
                            <input v-model.number="form.storage_quota_gb" type="number"
                                   min="1" max="10000" step="1"
                                   class="form-input" />
                            <p class="mt-1 text-xs text-slate-500">Leave empty for unlimited storage</p>
                        </FormField>
                        <FormField label="Notes" class-name="sm:col-span-2">
                            <textarea v-model="form.notes" rows="2" class="form-input resize-none" />
                        </FormField>
                    </div>
                </Section>

                <!-- Source -->
                <Section title="Source Stream">
                    <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                        <FormField label="Source Protocol" :error="form.errors.source_type">
                            <select v-model="form.source_type" class="form-input">
                                <option value="hls">HLS</option>
                                <option value="youtube">YouTube Live</option>
                                <option value="udp">UDP Multicast</option>
                                <option value="mpegts">MPEG-TS</option>
                                <option value="rtmp">RTMP</option>
                                <option value="srt">SRT</option>
                            </select>
                        </FormField>
                        <FormField v-if="['rtmp', 'srt'].includes(form.source_type)" label="Ingest Direction" :error="form.errors.ingest_mode">
                            <select v-model="form.ingest_mode" class="form-input">
                                <option value="pull">Pull from a source URL</option>
                                <option value="push">Receive a publisher connection</option>
                            </select>
                        </FormField>
                        <FormField v-if="form.ingest_mode === 'push' && ['rtmp', 'srt'].includes(form.source_type)" label="Listener Port" :error="form.errors.ingest_port">
                            <input v-model.number="form.ingest_port" type="number"
                                   :min="form.source_type === 'srt' ? 30000 : 20000"
                                   :max="form.source_type === 'srt' ? 30099 : 20099"
                                   class="form-input" />
                        </FormField>
                        <FormField v-else label="Source URL" class-name="sm:col-span-2" :error="form.errors.source_url">
                            <input v-model="form.source_url" type="text" required class="form-input font-mono text-sm" />
                        </FormField>
                        <FormField v-if="form.ingest_mode === 'push' && form.source_type === 'rtmp'" label="RTMP Stream Key (Custom)" :error="form.errors.rtmp_input_key">
                            <input v-model="form.rtmp_input_key" type="text" class="form-input font-mono text-sm" placeholder="Leave empty to auto-generate" />
                            <p class="mt-1 text-xs text-slate-500">Custom stream key for OBS/vMix to publish to. Auto-generated if left blank.</p>
                        </FormField>
                        <FormField v-if="form.source_type === 'youtube'" label="YouTube Cookies (optional)" class-name="sm:col-span-2" :error="form.errors.youtube_cookies">
                            <textarea v-model="form.youtube_cookies" rows="4" class="form-input font-mono text-xs"
                                      placeholder="Netscape-format cookies from your browser (for private/restricted streams)&#10;Example:&#10;.youtube.com	TRUE	/	TRUE	0	SID	xxxxx"></textarea>
                            <p class="mt-1 text-xs text-slate-500">Export cookies from your browser using a cookies.txt extension. Required only for age-restricted or private streams.</p>
                        </FormField>
                        <FormField v-if="form.source_type === 'youtube'" label="YouTube PO Token (optional)" :error="form.errors.youtube_po_token">
                            <input v-model="form.youtube_po_token" type="text" class="form-input font-mono text-sm"
                                   placeholder="Visitor data + PO token pair" />
                            <p class="mt-1 text-slate-500">Bypasses YouTube bot detection. Get one from <a href="https://github.com/nickoala/youtube-po-token" target="_blank" class="text-indigo-400 hover:underline">youtube-po-token</a> or similar tools.</p>
                        </FormField>
                        <FormField v-if="form.ingest_mode === 'pull'" label="Re-encode on ingest" :error="form.errors.reencode_ingest">
                            <label class="inline-flex items-center gap-2 mt-2">
                                <input v-model="form.reencode_ingest" type="checkbox" class="form-checkbox rounded" />
                                <span class="text-sm text-slate-300">Transcode source to H.264/AAC</span>
                            </label>
                            <p class="mt-1 text-xs text-slate-500">Enable only for sources with broken H.264 NALs (e.g. catcast.tv) or incompatible codecs. Uses more CPU.</p>
                        </FormField>
                        <div v-if="form.ingest_mode === 'push' && channel.published_ingest_server" class="sm:col-span-2 px-3 py-2 bg-slate-800/60 rounded-lg text-xs break-all text-indigo-400">
                            <template v-if="form.source_type === 'rtmp'">
                                <div>OBS Server: <span class="font-mono">{{ channel.published_ingest_server }}</span></div>
                                <div class="mt-1">Stream Key: <span class="font-mono">{{ channel.rtmp_input_key }}</span></div>
                            </template>
                            <template v-else>SRT Caller URL: <span class="font-mono">{{ channel.published_ingest_server }}</span></template>
                        </div>
                    </div>
                </Section>

                <!-- Multiple Sources (failover) -->
                <Section v-if="form.ingest_mode === 'pull'" title="Stream Sources (Failover)">
                    <p class="text-xs text-slate-500 mb-4">
                        Add backup sources for automatic failover. When the primary source goes down, the system tries the next source before falling back to VOD.
                    </p>
                    <div class="space-y-3">
                        <div v-for="(src, idx) in sources" :key="src.id"
                             class="flex items-center gap-2 p-3 rounded-lg border transition-colors"
                             :class="src.id === currentSourceId ? 'border-emerald-500/40 bg-emerald-500/5' : 'border-slate-700 bg-slate-800/40'">
                            <div class="flex-shrink-0 w-7 h-7 rounded-full flex items-center justify-center text-xs font-bold"
                                 :class="src.id === currentSourceId ? 'bg-emerald-500 text-white' : 'bg-slate-700 text-slate-400'">
                                {{ idx + 1 }}
                            </div>
                            <div class="flex-1 min-w-0">
                                <div class="flex items-center gap-2">
                                    <span class="text-xs font-mono text-slate-300 truncate">{{ src.source_url }}</span>
                                    <span v-if="src.id === currentSourceId" class="text-[10px] px-1.5 py-0.5 rounded bg-emerald-500/20 text-emerald-400 font-medium">ACTIVE</span>
                                </div>
                                <div class="text-[11px] text-slate-500 mt-0.5">{{ src.source_type.toUpperCase() }}</div>
                            </div>
                            <button v-if="src.id !== currentSourceId" @click="activateSource(src)"
                                    class="text-xs text-emerald-400 hover:text-emerald-300 px-2 py-1 rounded hover:bg-emerald-500/10 transition-colors">
                                Set Active
                            </button>
                            <button @click="removeSource(src)"
                                    class="text-xs text-red-400 hover:text-red-300 px-2 py-1 rounded hover:bg-red-500/10 transition-colors">
                                Remove
                            </button>
                        </div>
                    </div>
                    <!-- Add new source -->
                    <div class="mt-3 flex items-end gap-2">
                        <FormField label="New Source URL" class-name="flex-1" :error="newSourceError">
                            <input v-model="newSourceUrl" type="text" class="form-input font-mono text-sm"
                                   placeholder="https://backup-server.com/stream.m3u8" />
                        </FormField>
                        <FormField label="Protocol" class-name="w-32">
                            <select v-model="newSourceType" class="form-input">
                                <option value="hls">HLS</option>
                                <option value="mpegts">MPEG-TS</option>
                                <option value="rtmp">RTMP</option>
                                <option value="srt">SRT</option>
                                <option value="youtube">YouTube</option>
                            </select>
                        </FormField>
                        <button @click="addSource" :disabled="!newSourceUrl"
                                class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 disabled:opacity-40 text-white text-sm font-medium rounded-lg transition-colors whitespace-nowrap">
                            Add Source
                        </button>
                    </div>
                </Section>

                <!-- Push Destination -->
                <Section title="Push Destination">
                    <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                        <FormField label="Push Protocol" :error="form.errors.push_protocol">
                            <select v-model="form.push_protocol" class="form-input">
                                <option value="rtmp">RTMP</option>
                                <option value="srt">SRT</option>
                                <option value="hls">HLS</option>
                            </select>
                        </FormField>
                        <FormField label="Push Server URL" :error="form.errors.push_url">
                            <input v-model="form.push_url" type="text" required class="form-input font-mono text-sm" />
                        </FormField>
                        <FormField label="Stream Key / Sub-path" :error="form.errors.push_stream_key">
                            <input v-model="form.push_stream_key" type="text" required class="form-input font-mono text-sm" />
                        </FormField>
                        <template v-if="form.push_protocol === 'hls'">
                            <FormField label="HLS Segment Duration" :error="form.errors.push_hls_segment_duration">
                                <input v-model.number="form.push_hls_segment_duration" type="number"
                                       min="1" max="30" class="form-input" />
                            </FormField>
                            <FormField label="HLS Playlist Size" :error="form.errors.push_hls_list_size">
                                <input v-model.number="form.push_hls_list_size" type="number"
                                       min="0" max="1000" class="form-input" />
                            </FormField>
                        </template>
                        <FormField v-if="form.push_protocol !== 'hls'" label="Username" :error="form.errors.push_username">
                            <input v-model="form.push_username" type="text"
                                   placeholder="Optional — leave blank if no auth required"
                                   class="form-input" />
                        </FormField>
                        <FormField v-if="form.push_protocol !== 'hls'" label="Password" :error="form.errors.push_password">
                            <input v-model="form.push_password" type="password"
                                   placeholder="Optional — leave blank if no auth required"
                                   class="form-input" />
                        </FormField>
                        <div class="sm:col-span-2 px-3 py-2 bg-slate-800/60 rounded-lg text-xs font-mono text-slate-400">
                            Push target: <span class="text-indigo-400">{{ pushTarget }}</span>
                        </div>
                    </div>
                </Section>

                <!-- Video Encoding -->
                <Section title="Video Encoding">
                    <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                        <FormField label="Video Codec" :error="form.errors.push_video_codec">
                            <select v-model="form.push_video_codec" class="form-input">
                                <option value="copy">copy — passthrough (no re-encode)</option>
                                <option value="h264">H.264 (libx264)</option>
                                <option value="h265">H.265 (libx265)</option>
                                <option value="vp8">VP8</option>
                                <option value="vp9">VP9</option>
                            </select>
                        </FormField>
                        <FormField label="Video Bitrate (kbps)" :error="form.errors.push_video_bitrate">
                            <input v-model.number="form.push_video_bitrate" type="number"
                                   min="100" max="50000" placeholder="e.g. 4000"
                                   :disabled="form.push_video_codec === 'copy'"
                                   class="form-input disabled:opacity-40" />
                        </FormField>
                        <FormField label="Output Resolution" :error="form.errors.push_resolution">
                            <select v-model="form.push_resolution"
                                    :disabled="form.push_video_codec === 'copy'" class="form-input disabled:opacity-40">
                                <option value="">Source resolution</option>
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
                                <option :value="24">24 fps</option>
                                <option :value="25">25 fps</option>
                                <option :value="30">30 fps</option>
                                <option :value="50">50 fps</option>
                                <option :value="60">60 fps</option>
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
                                <option value="aac">AAC-LC (recommended)</option>
                                <option value="mp3">MP3</option>
                                <option value="opus">Opus</option>
                                <option value="ac3">AC-3 / Dolby Digital</option>
                            </select>
                        </FormField>
                        <FormField label="Audio Bitrate (kbps)" :error="form.errors.push_audio_bitrate">
                            <select v-model.number="form.push_audio_bitrate"
                                    :disabled="form.push_audio_codec === 'copy'" class="form-input disabled:opacity-40">
                                <option :value="64">64 kbps</option>
                                <option :value="96">96 kbps</option>
                                <option :value="128">128 kbps</option>
                                <option :value="192">192 kbps</option>
                                <option :value="256">256 kbps</option>
                                <option :value="320">320 kbps</option>
                            </select>
                        </FormField>
                        <FormField label="Sample Rate" :error="form.errors.push_audio_samplerate">
                            <select v-model.number="form.push_audio_samplerate"
                                    :disabled="form.push_audio_codec === 'copy'" class="form-input disabled:opacity-40">
                                <option :value="44100">44100 Hz</option>
                                <option :value="48000">48000 Hz (broadcast)</option>
                                <option :value="32000">32000 Hz</option>
                                <option :value="22050">22050 Hz</option>
                            </select>
                        </FormField>
                        <FormField label="Audio Channels" :error="form.errors.push_audio_channels">
                            <select v-model.number="form.push_audio_channels"
                                    :disabled="form.push_audio_codec === 'copy'" class="form-input disabled:opacity-40">
                                <option :value="1">Mono</option>
                                <option :value="2">Stereo</option>
                                <option :value="6">5.1 Surround</option>
                            </select>
                        </FormField>
                    </div>
                </Section>

                <!-- DVR & Recording -->
                <Section title="DVR &amp; Recording">
                    <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                        <p v-if="channel.ingest_mode === 'push'" class="sm:col-span-2 text-sm text-indigo-300 bg-indigo-500/10 border border-indigo-500/20 rounded-lg px-4 py-3">
                            Recording and rolling DVR are disabled for managed push channels. Uploaded VOD playlists remain available for fallback.
                        </p>
                        <template v-else>
                        <FormField label="Rolling DVR" :error="form.errors.dvr_enabled" class-name="sm:col-span-2">
                            <select v-model="form.dvr_enabled" class="form-input">
                                <option :value="false">Disabled — live preview and forwarding only</option>
                                <option :value="true">Enabled — retain a rewindable rolling window</option>
                            </select>
                            <p class="mt-1 text-xs text-slate-500">Uploaded fallback VOD remains available when DVR is disabled.</p>
                        </FormField>
                        <FormField label="DVR Rolling Window (seconds)" :error="form.errors.dvr_duration" :class-name="!form.dvr_enabled ? 'opacity-50' : ''">
                            <div class="flex gap-2">
                                <input v-model.number="form.dvr_duration" type="number"
                                       min="60" max="86400" required :disabled="!form.dvr_enabled" class="form-input flex-1" />
                                <select @change="form.dvr_duration = $event.target.value ? parseInt($event.target.value) : form.dvr_duration" :disabled="!form.dvr_enabled" class="form-input w-28">
                                    <option value="">Custom</option>
                                    <option value="1800">30 min</option>
                                    <option value="3600">1 hour</option>
                                    <option value="10800">3 hours</option>
                                    <option value="18000">5 hours</option>
                                    <option value="43200">12 hours</option>
                                    <option value="86400">24 hours</option>
                                </select>
                            </div>
                            <p class="mt-1 text-xs text-slate-500">{{ formatDuration(form.dvr_duration) }}</p>
                        </FormField>
                        <FormField label="Segment Duration (seconds)" :error="form.errors.segment_duration" :class-name="!form.dvr_enabled ? 'opacity-50' : ''">
                            <input v-model.number="form.segment_duration" type="number"
                                   min="1" max="30" required :disabled="!form.dvr_enabled" class="form-input" />
                        </FormField>
                        <FormField label="Recording File Length (seconds, 0=disabled)" :error="form.errors.record_duration">
                            <div class="flex gap-2">
                                <input v-model.number="form.record_duration" type="number"
                                       min="0" max="86400" required class="form-input flex-1" />
                                <select @change="form.record_duration = parseInt($event.target.value)" class="form-input w-32">
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
                                {{ form.record_duration > 0 ? formatDuration(form.record_duration) + ' per file — looped as fallback' : 'Disabled — push will go offline if source drops' }}
                            </p>
                        </FormField>
                        <FormField label="Keep Recordings" :error="form.errors.keep_recordings">
                            <select v-model.number="form.keep_recordings" class="form-input">
                                <option :value="1">1 — keep only latest</option>
                                <option :value="3">3</option>
                                <option :value="5">5</option>
                                <option :value="7">7</option>
                                <option :value="10">10</option>
                            </select>
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
                                <option value="UTC">UTC</option>
                                <option value="America/New_York">America/New York</option>
                                <option value="America/Chicago">America/Chicago</option>
                                <option value="America/Los_Angeles">America/Los Angeles</option>
                                <option value="America/Sao_Paulo">America/São Paulo</option>
                                <option value="Europe/London">Europe/London</option>
                                <option value="Europe/Paris">Europe/Paris</option>
                                <option value="Europe/Moscow">Europe/Moscow</option>
                                <option value="Europe/Berlin">Europe/Berlin</option>
                                <option value="Asia/Dubai">Asia/Dubai</option>
                                <option value="Asia/Kolkata">Asia/Kolkata</option>
                                <option value="Asia/Shanghai">Asia/Shanghai</option>
                                <option value="Asia/Tokyo">Asia/Tokyo</option>
                                <option value="Asia/Seoul">Asia/Seoul</option>
                                <option value="Asia/Singapore">Asia/Singapore</option>
                                <option value="Australia/Sydney">Australia/Sydney</option>
                                <option value="Africa/Cairo">Africa/Cairo</option>
                            </select>
                        </FormField>
                    </div>
                </Section>

                <div class="flex justify-end gap-3 pb-4">
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

            <Section title="Uploaded Backup VOD" class="mt-5">
                <p class="text-xs text-slate-500 mb-4">When present, this file is looped during an outage instead of recorded stream video.</p>
                <div v-if="channel.fallback_vod_name" class="flex items-center justify-between gap-3">
                    <span class="text-sm text-slate-300 break-all">{{ channel.fallback_vod_name }}</span>
                    <Link :href="route('channels.fallback-vod.remove', channel.id)" method="delete" as="button" class="text-xs text-red-400">Remove</Link>
                </div>
                <form v-else @submit.prevent="uploadFallback" class="flex items-start gap-3">
                    <input type="file" accept="video/*,.mkv,.ts" @change="fallbackForm.fallback_vod = $event.target.files[0]" class="form-input text-sm flex-1" required />
                    <button :disabled="fallbackForm.processing" class="px-4 py-2 bg-indigo-600 text-white text-sm rounded-lg disabled:opacity-50">Upload</button>
                </form>
                <p v-if="fallbackForm.errors.fallback_vod" class="mt-2 text-xs text-red-400">{{ fallbackForm.errors.fallback_vod }}</p>
            </Section>
        </div>
    </AppLayout>
</template>

<script setup>
import { computed } from 'vue'
import { Link, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import FormField from '@/Components/FormField.vue'
import Section from '@/Components/Section.vue'

const props = defineProps({ channel: Object, users: Array, isAdmin: Boolean, sources: Array, currentSourceId: Number })

import { ref, onMounted } from 'vue'

const sources = ref(props.sources || [])
const currentSourceId = ref(props.currentSourceId || props.channel.current_source_id)
const newSourceUrl = ref('')
const newSourceType = ref('hls')
const newSourceError = ref('')

const form = useForm({
    name:                   props.channel.name,
    notes:                  props.channel.notes ?? '',
    is_active:              props.channel.is_active,
    user_id:                props.channel.user_id ?? '',
    storage_quota_gb:       props.channel.storage_quota_bytes ? Math.round(props.channel.storage_quota_bytes / 1024 / 1024 / 1024) : '',
    source_type:            props.channel.source_type,
    ingest_mode:            props.channel.ingest_mode ?? 'pull',
    ingest_port:            props.channel.ingest_port ?? null,
    source_url:             props.channel.source_url,
    reencode_ingest:        props.channel.reencode_ingest ?? false,
    youtube_cookies:        props.channel.youtube_cookies ?? '',
    youtube_po_token:       props.channel.youtube_po_token ?? '',
    youtube_po_token:       props.channel.youtube_po_token ?? '',
    push_protocol:          props.channel.push_protocol,
    push_url:               props.channel.push_url,
    push_stream_key:        props.channel.push_stream_key,
    push_username:          props.channel.push_username      ?? '',
    push_password:          props.channel.push_password      ?? '',
    push_hls_segment_duration: props.channel.push_hls_segment_duration ?? null,
    push_hls_list_size:     props.channel.push_hls_list_size ?? null,
    push_video_codec:       props.channel.push_video_codec    ?? 'copy',
    push_video_bitrate:     props.channel.push_video_bitrate  ?? null,
    push_resolution:        props.channel.push_resolution     ?? '',
    push_framerate:         props.channel.push_framerate      ?? null,
    push_audio_codec:       props.channel.push_audio_codec    ?? 'aac',
    push_audio_bitrate:     props.channel.push_audio_bitrate  ?? 128,
    push_audio_samplerate:  props.channel.push_audio_samplerate ?? 48000,
    push_audio_channels:    props.channel.push_audio_channels ?? 2,
    dvr_duration:           props.channel.dvr_duration,
    segment_duration:       props.channel.segment_duration,
    dvr_enabled:            props.channel.dvr_enabled ?? true,
    record_duration:        props.channel.record_duration     ?? 3600,
    keep_recordings:        props.channel.keep_recordings     ?? 3,
    timezone:               props.channel.timezone            ?? 'UTC',
    locale:                 props.channel.locale              ?? 'en',
    check_interval:         props.channel.check_interval,
    max_retries:            props.channel.max_retries,
})

const fallbackForm = useForm({ fallback_vod: null })

const pushTarget = computed(() => {
    if (!form.push_url) return '—'
    const base = form.push_url.replace(/\/$/, '')
    const key = form.push_stream_key || 'key'
    if (form.push_protocol === 'hls') return `${base}/${key}/index.m3u8`
    return `${base}/${key}`
})


function formatDuration(s) {
    if (!s) return 'Disabled'
    const h = Math.floor(s / 3600), m = Math.floor((s % 3600) / 60)
    return h > 0 ? `${h}h ${m}m` : `${m}m`
}

async function addSource() {
    newSourceError.value = ''
    try {
        const res = await fetch(route('channels.sources.store', props.channel.id), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': decodeURIComponent(getCookie('XSRF-TOKEN')) },
            body: JSON.stringify({ source_url: newSourceUrl.value, source_type: newSourceType.value }),
        })
        if (!res.ok) {
            const data = await res.json()
            newSourceError.value = data.message || 'Failed to add source'
            return
        }
        const source = await res.json()
        sources.value.push(source)
        sources.value.sort((a, b) => a.priority - b.priority)
        if (sources.value.length === 1) currentSourceId.value = source.id
        newSourceUrl.value = ''
    } catch (e) {
        newSourceError.value = 'Network error'
    }
}

async function removeSource(src) {
    if (!confirm('Remove this source?')) return
    try {
        await fetch(route('channels.sources.destroy', [props.channel.id, src.id]), {
            method: 'DELETE',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': decodeURIComponent(getCookie('XSRF-TOKEN')) },
        })
        sources.value = sources.value.filter(s => s.id !== src.id)
        if (currentSourceId.value === src.id && sources.value.length > 0) {
            currentSourceId.value = sources.value[0].id
        }
    } catch (e) { /* ignore */ }
}

async function activateSource(src) {
    try {
        await fetch(route('channels.sources.activate', [props.channel.id, src.id]), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': decodeURIComponent(getCookie('XSRF-TOKEN')) },
        })
        currentSourceId.value = src.id
    } catch (e) { /* ignore */ }
}

function getCookie(name) {
    return document.cookie.split('; ').find(row => row.startsWith(name + '='))?.split('=')[1] || ''
}

function submit() {
    if (!['rtmp', 'srt'].includes(form.source_type)) form.ingest_mode = 'pull'
    form.put(route('channels.update', props.channel.id))
}

function uploadFallback() {
    fallbackForm.post(route('channels.fallback-vod.upload', props.channel.id), { forceFormData: true })
}
</script>
