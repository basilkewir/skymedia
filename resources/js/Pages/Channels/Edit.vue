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
                                <option value="udp">UDP Multicast</option>
                                <option value="mpegts">MPEG-TS</option>
                                <option value="rtmp">RTMP</option>
                                <option value="srt">SRT</option>
                            </select>
                        </FormField>
                        <FormField label="Source URL" class-name="sm:col-span-2" :error="form.errors.source_url">
                            <input v-model="form.source_url" type="text" required class="form-input font-mono text-sm" />
                        </FormField>
                    </div>
                </Section>

                <!-- Push Destination -->
                <Section title="Push Destination">
                    <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                        <FormField label="Push Protocol" :error="form.errors.push_protocol">
                            <select v-model="form.push_protocol" class="form-input">
                                <option value="rtmp">RTMP</option>
                                <option value="srt">SRT</option>
                            </select>
                        </FormField>
                        <FormField label="Push Server URL" :error="form.errors.push_url">
                            <input v-model="form.push_url" type="text" required class="form-input font-mono text-sm" />
                        </FormField>
                        <FormField label="Stream Key" :error="form.errors.push_stream_key">
                            <input v-model="form.push_stream_key" type="text" required class="form-input font-mono text-sm" />
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
                        <FormField label="DVR Rolling Window (seconds)" :error="form.errors.dvr_duration">
                            <input v-model.number="form.dvr_duration" type="number"
                                   min="60" max="86400" required class="form-input" />
                            <p class="mt-1 text-xs text-slate-500">{{ formatDuration(form.dvr_duration) }}</p>
                        </FormField>
                        <FormField label="Segment Duration (seconds)" :error="form.errors.segment_duration">
                            <input v-model.number="form.segment_duration" type="number"
                                   min="2" max="30" required class="form-input" />
                        </FormField>
                        <FormField label="Recording File Length (seconds, 0=disabled)" :error="form.errors.record_duration">
                            <input v-model.number="form.record_duration" type="number"
                                   min="0" max="86400" required class="form-input" />
                            <p class="mt-1 text-xs text-slate-500">
                                {{ form.record_duration > 0 ? formatDuration(form.record_duration) + ' per file — looped as fallback' : 'Disabled — push will go offline if source drops' }}
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
import { computed } from 'vue'
import { Link, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import FormField from '@/Components/FormField.vue'
import Section from '@/Components/Section.vue'

const props = defineProps({ channel: Object })

const form = useForm({
    name:                   props.channel.name,
    notes:                  props.channel.notes ?? '',
    is_active:              props.channel.is_active,
    source_type:            props.channel.source_type,
    source_url:             props.channel.source_url,
    push_protocol:          props.channel.push_protocol,
    push_url:               props.channel.push_url,
    push_stream_key:        props.channel.push_stream_key,
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
    record_duration:        props.channel.record_duration     ?? 3600,
    check_interval:         props.channel.check_interval,
    max_retries:            props.channel.max_retries,
})

const pushTarget = computed(() =>
    !form.push_url ? '—' : `${form.push_url.replace(/\/$/, '')}/${form.push_stream_key || 'key'}`
)

function formatDuration(s) {
    if (!s) return 'Disabled'
    const h = Math.floor(s / 3600), m = Math.floor((s % 3600) / 60)
    return h > 0 ? `${h}h ${m}m` : `${m}m`
}

function submit() { form.put(route('channels.update', props.channel.id)) }
</script>
