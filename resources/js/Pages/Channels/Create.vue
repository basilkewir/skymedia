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
            <form @submit.prevent="submit" class="space-y-6">
                <!-- Basic info -->
                <div class="bg-slate-900 border border-slate-800 rounded-xl p-6">
                    <h2 class="text-sm font-semibold text-white mb-4">Basic Information</h2>
                    <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                        <FormField label="Channel Name" :error="form.errors.name">
                            <input v-model="form.name" @input="autoSlug" type="text" required
                                   placeholder="CNN International"
                                   class="form-input" />
                        </FormField>
                        <FormField label="Slug (URL-safe identifier)" :error="form.errors.slug">
                            <input v-model="form.slug" type="text" required
                                   placeholder="cnn-international"
                                   class="form-input font-mono" />
                        </FormField>
                        <FormField label="Notes" class-name="sm:col-span-2" :error="form.errors.notes">
                            <textarea v-model="form.notes" rows="2" placeholder="Optional notes…"
                                      class="form-input resize-none" />
                        </FormField>
                    </div>
                </div>

                <!-- Source -->
                <div class="bg-slate-900 border border-slate-800 rounded-xl p-6">
                    <h2 class="text-sm font-semibold text-white mb-4">Source Stream</h2>
                    <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                        <FormField label="Source Protocol" :error="form.errors.source_type">
                            <select v-model="form.source_type" class="form-input">
                                <option value="hls">HLS (HTTP Live Streaming)</option>
                                <option value="udp">UDP Multicast</option>
                                <option value="mpegts">MPEG-TS (UDP/TCP)</option>
                                <option value="rtmp">RTMP</option>
                                <option value="srt">SRT (Secure Reliable Transport)</option>
                            </select>
                        </FormField>
                        <FormField label="Source URL" class-name="sm:col-span-2" :error="form.errors.source_url">
                            <input v-model="form.source_url" type="text" required
                                   :placeholder="sourcePlaceholder"
                                   class="form-input font-mono text-sm" />
                            <p class="mt-1 text-xs text-slate-500">{{ sourceHint }}</p>
                        </FormField>
                    </div>
                </div>

                <!-- Push output -->
                <div class="bg-slate-900 border border-slate-800 rounded-xl p-6">
                    <h2 class="text-sm font-semibold text-white mb-4">Push Output</h2>
                    <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                        <FormField label="Push Protocol" :error="form.errors.push_protocol">
                            <select v-model="form.push_protocol" class="form-input">
                                <option value="rtmp">RTMP (Wowza / Nginx-RTMP / SRS)</option>
                                <option value="srt">SRT</option>
                            </select>
                        </FormField>
                        <FormField label="Push Server URL" :error="form.errors.push_url">
                            <input v-model="form.push_url" type="text" required
                                   placeholder="rtmp://your-server/live"
                                   class="form-input font-mono text-sm" />
                        </FormField>
                        <FormField label="Stream Key" :error="form.errors.push_stream_key">
                            <input v-model="form.push_stream_key" type="text" required
                                   placeholder="my-channel-key"
                                   class="form-input font-mono text-sm" />
                        </FormField>
                        <div class="sm:col-span-2 p-3 bg-slate-800/60 rounded-lg text-xs text-slate-400 font-mono">
                            Push target: <span class="text-indigo-400">{{ pushTarget }}</span>
                        </div>
                    </div>
                </div>

                <!-- DVR -->
                <div class="bg-slate-900 border border-slate-800 rounded-xl p-6">
                    <h2 class="text-sm font-semibold text-white mb-1">DVR Configuration</h2>
                    <p class="text-xs text-slate-500 mb-4">The system maintains a rolling window of recordings. Old segments are continuously deleted as new ones arrive.</p>
                    <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                        <FormField label="DVR Window Duration" :error="form.errors.dvr_duration">
                            <div class="flex gap-2">
                                <input v-model.number="form.dvr_duration" type="number" min="60" max="86400" required
                                       class="form-input flex-1" />
                                <select @change="applyDvrPreset" v-model="dvrPreset" class="form-input w-32">
                                    <option value="">Custom</option>
                                    <option value="1800">30 min</option>
                                    <option value="3600">1 hour</option>
                                    <option value="10800">3 hours</option>
                                    <option value="18000">5 hours</option>
                                    <option value="43200">12 hours</option>
                                    <option value="86400">24 hours</option>
                                </select>
                            </div>
                            <p class="mt-1 text-xs text-slate-500">{{ formatDuration(form.dvr_duration) }} · ~{{ estimatedStorage }} MB estimated</p>
                        </FormField>
                        <FormField label="Segment Duration (seconds)" :error="form.errors.segment_duration">
                            <input v-model.number="form.segment_duration" type="number" min="2" max="30" required class="form-input" />
                            <p class="mt-1 text-xs text-slate-500">{{ Math.ceil(form.dvr_duration / form.segment_duration) }} segments total</p>
                        </FormField>
                        <FormField label="Health Check Interval (seconds)" :error="form.errors.check_interval">
                            <input v-model.number="form.check_interval" type="number" min="1" max="60" required class="form-input" />
                        </FormField>
                        <FormField label="Max Reconnect Retries" :error="form.errors.max_retries">
                            <input v-model.number="form.max_retries" type="number" min="0" max="10" required class="form-input" />
                        </FormField>
                    </div>
                </div>

                <div class="flex justify-end gap-3">
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

const dvrPreset = ref('3600')

const form = useForm({
    name: '', slug: '',
    source_type: 'hls', source_url: '',
    push_protocol: 'rtmp', push_url: '', push_stream_key: '',
    dvr_duration: 3600, segment_duration: 4,
    check_interval: 5, max_retries: 3, notes: '',
})

function autoSlug() {
    form.slug = form.name.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '')
}

function applyDvrPreset() {
    if (dvrPreset.value) form.dvr_duration = parseInt(dvrPreset.value)
}

const sourcePlaceholders = {
    hls:    'https://stream.example.com/live/stream.m3u8',
    udp:    'udp://239.1.1.1:1234',
    mpegts: 'udp://239.1.1.1:1234 or tcp://host:port',
    rtmp:   'rtmp://ingest.example.com/live/key',
    srt:    '192.168.1.100:9000',
}
const sourceHints = {
    hls:    'Standard HTTP(S) HLS playlist URL',
    udp:    'UDP multicast or unicast address',
    mpegts: 'UDP/TCP MPEG-TS stream address',
    rtmp:   'Full RTMP ingest URL including stream key',
    srt:    'Host:port only — srt:// prefix is added automatically',
}

const sourcePlaceholder = computed(() => sourcePlaceholders[form.source_type] ?? '')
const sourceHint        = computed(() => sourceHints[form.source_type] ?? '')

const pushTarget = computed(() => {
    if (!form.push_url) return 'rtmp://your-server/live/key'
    return form.push_url.replace(/\/$/, '') + '/' + (form.push_stream_key || 'key')
})

const estimatedStorage = computed(() => {
    // Assume 3 Mbps average bitrate
    return Math.round((form.dvr_duration * 3_000_000) / 8 / 1_048_576)
})

function formatDuration(s) {
    const h = Math.floor(s / 3600), m = Math.floor((s % 3600) / 60)
    return h > 0 ? `${h}h ${m}m` : `${m}m`
}

function submit() { form.post(route('channels.store')) }
</script>
