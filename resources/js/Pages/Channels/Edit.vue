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
                <div class="bg-slate-900 border border-slate-800 rounded-xl p-6">
                    <h2 class="text-sm font-semibold text-white mb-4">Basic Information</h2>
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
                </div>

                <!-- Source -->
                <div class="bg-slate-900 border border-slate-800 rounded-xl p-6">
                    <h2 class="text-sm font-semibold text-white mb-4">Source Stream</h2>
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
                        <FormField label="Source URL" className="sm:col-span-2" :error="form.errors.source_url">
                            <input v-model="form.source_url" type="text" required class="form-input font-mono text-sm" />
                        </FormField>
                    </div>
                </div>

                <!-- Push -->
                <div class="bg-slate-900 border border-slate-800 rounded-xl p-6">
                    <h2 class="text-sm font-semibold text-white mb-4">Push Output</h2>
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
                    </div>
                </div>

                <!-- DVR -->
                <div class="bg-slate-900 border border-slate-800 rounded-xl p-6">
                    <h2 class="text-sm font-semibold text-white mb-4">DVR Configuration</h2>
                    <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                        <FormField label="DVR Window (seconds)" :error="form.errors.dvr_duration">
                            <input v-model.number="form.dvr_duration" type="number" min="60" max="86400" required class="form-input" />
                            <p class="mt-1 text-xs text-slate-500">{{ formatDuration(form.dvr_duration) }}</p>
                        </FormField>
                        <FormField label="Segment Duration (seconds)" :error="form.errors.segment_duration">
                            <input v-model.number="form.segment_duration" type="number" min="2" max="30" required class="form-input" />
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
import { Link, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import FormField from '@/Components/FormField.vue'

const props = defineProps({ channel: Object })

const form = useForm({
    name:             props.channel.name,
    source_type:      props.channel.source_type,
    source_url:       props.channel.source_url,
    push_protocol:    props.channel.push_protocol,
    push_url:         props.channel.push_url,
    push_stream_key:  props.channel.push_stream_key,
    dvr_duration:     props.channel.dvr_duration,
    segment_duration: props.channel.segment_duration,
    check_interval:   props.channel.check_interval,
    max_retries:      props.channel.max_retries,
    is_active:        props.channel.is_active,
    notes:            props.channel.notes ?? '',
})

function formatDuration(s) {
    const h = Math.floor(s / 3600), m = Math.floor((s % 3600) / 60)
    return h > 0 ? `${h}h ${m}m` : `${m}m`
}

function submit() { form.put(route('channels.update', props.channel.id)) }
</script>
