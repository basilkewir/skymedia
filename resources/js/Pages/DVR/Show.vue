<template>
    <AppLayout>
        <template #header>
            <div class="flex items-center gap-2 text-sm text-slate-400">
                <Link :href="route('dvr.index')" class="hover:text-white transition-colors">DVR Storage</Link>
                <span>/</span>
                <span class="text-white">{{ channel.name }}</span>
            </div>
        </template>

        <div class="space-y-5">
            <!-- Summary -->
            <div class="bg-slate-900 border border-slate-800 rounded-xl p-6">
                <div class="flex items-center justify-between">
                    <div>
                        <h1 class="text-xl font-bold text-white">{{ channel.name }}</h1>
                        <p class="text-sm text-slate-400 mt-1">
                            {{ totalDuration }}h recorded · {{ totalSize }} MB on disk
                        </p>
                    </div>
                    <div class="flex gap-2">
                        <Link :href="route('channels.show', channel.id)"
                              class="px-3 py-1.5 text-xs text-slate-300 border border-slate-700 rounded-lg hover:border-slate-500 transition-colors">
                            ← Channel
                        </Link>
                        <Link :href="route('dvr.purge', channel.id)" method="delete" as="button"
                              class="px-3 py-1.5 text-xs text-red-400 border border-red-500/30 rounded-lg hover:bg-red-500/10 transition-colors"
                              @click.prevent="showPurge = true">
                            🗑 Purge All
                        </Link>
                    </div>
                </div>

                <!-- Progress bar -->
                <div class="mt-4">
                    <div class="flex justify-between text-xs text-slate-500 mb-1">
                        <span>DVR Window: {{ formatDuration(maxDuration) }}</span>
                        <span>{{ fillPct }}% filled</span>
                    </div>
                    <div class="w-full bg-slate-800 rounded-full h-2">
                        <div class="h-2 rounded-full bg-indigo-500 transition-all" :style="{ width: fillPct + '%' }" />
                    </div>
                </div>
            </div>

            <!-- Segment table -->
            <div class="bg-slate-900 border border-slate-800 rounded-xl overflow-hidden">
                <div class="px-6 py-4 border-b border-slate-800">
                    <h2 class="text-sm font-semibold text-white">Segments</h2>
                    <p class="text-xs text-slate-500 mt-0.5">Newest first. Old segments are auto-deleted to maintain the rolling window.</p>
                </div>
                <table class="min-w-full">
                    <thead>
                        <tr class="border-b border-slate-800">
                            <th class="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">Seq</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">Filename</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">Duration</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">Size</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">Recorded</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-800/50">
                        <tr v-for="seg in segments.data" :key="seg.id" class="hover:bg-slate-800/20 transition-colors">
                            <td class="px-6 py-3 text-xs text-slate-500">{{ seg.sequence }}</td>
                            <td class="px-6 py-3 text-xs font-mono text-slate-300">{{ seg.filename }}</td>
                            <td class="px-6 py-3 text-xs text-slate-400">{{ seg.duration }}s</td>
                            <td class="px-6 py-3 text-xs text-slate-400">{{ formatBytes(seg.filesize) }}</td>
                            <td class="px-6 py-3 text-xs text-slate-500">{{ new Date(seg.recorded_at).toLocaleString() }}</td>
                            <td class="px-6 py-3">
                                <Link :href="route('dvr.segment.destroy', seg.id)" method="delete" as="button"
                                      class="text-xs text-red-400 hover:text-red-300 transition-colors">
                                    Delete
                                </Link>
                            </td>
                        </tr>
                        <tr v-if="segments.data.length === 0">
                            <td colspan="6" class="px-6 py-12 text-center text-slate-500 text-sm">No segments recorded yet</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <Pagination :links="segments.links" />
        </div>

        <!-- Purge confirm modal -->
        <div v-if="showPurge" class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-sm">
            <div class="bg-slate-900 border border-slate-700 rounded-xl p-6 w-full max-w-sm shadow-2xl">
                <h3 class="text-base font-semibold text-white mb-2">Purge all DVR data?</h3>
                <p class="text-sm text-slate-400 mb-6">
                    All {{ segments.total }} segments for <strong class="text-white">{{ channel.name }}</strong>
                    will be permanently deleted from disk.
                </p>
                <div class="flex justify-end gap-3">
                    <button @click="showPurge = false"
                            class="px-4 py-2 text-sm text-slate-400 border border-slate-700 rounded-lg hover:border-slate-500 transition-colors">
                        Cancel
                    </button>
                    <Link :href="route('dvr.purge', channel.id)" method="delete" as="button"
                          class="px-4 py-2 bg-red-600 hover:bg-red-700 text-white text-sm font-medium rounded-lg transition-colors">
                        Purge All
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
import Pagination from '@/Components/Pagination.vue'

const props = defineProps({
    channel:       Object,
    segments:      Object,
    totalDuration: [Number, String],
    totalSize:     [Number, String],
    maxDuration:   Number,
})

const showPurge = ref(false)

const fillPct = computed(() => {
    if (!props.maxDuration) return 0
    return Math.min(100, Math.round(((props.totalDuration * 3600) / props.maxDuration) * 100))
})

function formatDuration(s) {
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
