<template>
    <AppLayout>
        <template #header>
            <h1 class="text-lg font-semibold text-white">DVR Storage</h1>
        </template>

        <div class="space-y-4">
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-2">
                <StatCard title="Channels with DVR" :value="channels.length" color="indigo" />
                <StatCard title="Total Recorded" :value="totalHours + ' hours'" color="blue" />
                <StatCard title="Total Storage" :value="totalMb + ' MB'" color="slate" />
            </div>

            <div class="bg-slate-900 border border-slate-800 rounded-xl overflow-hidden">
                <div class="px-6 py-4 border-b border-slate-800">
                    <h2 class="text-sm font-semibold text-white">Channel DVR Overview</h2>
                </div>
                <div class="divide-y divide-slate-800/50">
                    <div v-for="ch in channels" :key="ch.id"
                         class="px-6 py-5 hover:bg-slate-800/20 transition-colors">
                        <div class="flex items-center justify-between mb-3">
                            <div>
                                <Link :href="route('dvr.show', ch.id)"
                                      class="text-sm font-medium text-indigo-400 hover:text-indigo-300 transition-colors">
                                    {{ ch.name }}
                                </Link>
                                <div class="flex items-center gap-2 mt-1">
                                    <StatusBadge :status="ch.stream_status" />
                                    <span class="text-xs text-slate-500">{{ ch.dvr_segments_count }} segments</span>
                                    <span class="text-xs text-slate-500">·</span>
                                    <span class="text-xs text-slate-500">{{ ch.dvr_mb }} MB on disk</span>
                                </div>
                            </div>
                            <div class="text-right">
                                <p class="text-sm font-semibold text-white">{{ ch.dvr_hours }}h / {{ formatDuration(ch.dvr_duration) }}</p>
                                <p class="text-xs text-slate-500">{{ ch.dvr_pct }}% full</p>
                            </div>
                        </div>
                        <div class="w-full bg-slate-800 rounded-full h-1.5">
                            <div class="h-1.5 rounded-full transition-all duration-700"
                                 :class="ch.dvr_pct >= 90 ? 'bg-green-500' : ch.dvr_pct >= 50 ? 'bg-yellow-500' : 'bg-indigo-500'"
                                 :style="{ width: ch.dvr_pct + '%' }" />
                        </div>
                    </div>
                    <div v-if="channels.length === 0" class="px-6 py-16 text-center text-slate-500 text-sm">
                        No DVR data. Channels will begin recording when they go live.
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { computed } from 'vue'
import { Link } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import StatusBadge from '@/Components/StatusBadge.vue'
import StatCard from '@/Components/StatCard.vue'

const props = defineProps({ channels: Array })

const totalHours = computed(() => props.channels.reduce((s, c) => s + c.dvr_hours, 0).toFixed(1))
const totalMb    = computed(() => props.channels.reduce((s, c) => s + c.dvr_mb, 0).toFixed(0))

function formatDuration(s) {
    const h = Math.floor(s / 3600), m = Math.floor((s % 3600) / 60)
    return h > 0 ? `${h}h ${m}m` : `${m}m`
}
</script>
