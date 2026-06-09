<template>
    <AppLayout>
        <template #header>
            <h1 class="text-lg font-semibold text-white">Event Logs</h1>
        </template>

        <div class="space-y-4">
            <!-- Filters -->
            <div class="bg-slate-900 border border-slate-800 rounded-xl p-4">
                <form @submit.prevent="applyFilters" class="flex flex-wrap items-end gap-3">
                    <div class="flex-1 min-w-[140px]">
                        <label class="block text-xs font-medium text-slate-500 mb-1">Channel</label>
                        <select v-model="f.channel_id" class="form-input">
                            <option value="">All Channels</option>
                            <option v-for="c in channels" :key="c.id" :value="c.id">{{ c.name }}</option>
                        </select>
                    </div>
                    <div class="flex-1 min-w-[120px]">
                        <label class="block text-xs font-medium text-slate-500 mb-1">Category</label>
                        <select v-model="f.category" class="form-input">
                            <option value="">All</option>
                            <option value="source">Source</option>
                            <option value="dvr">DVR</option>
                            <option value="push">Push</option>
                            <option value="system">System</option>
                        </select>
                    </div>
                    <div class="flex-1 min-w-[120px]">
                        <select v-model="f.level" class="form-input">
                            <option value="">All Levels</option>
                            <option value="info">Info</option>
                            <option value="warning">Warning</option>
                            <option value="error">Error</option>
                            <option value="critical">Critical</option>
                        </select>
                    </div>
                    <div class="flex-1 min-w-[160px]">
                        <label class="block text-xs font-medium text-slate-500 mb-1">Event</label>
                        <input v-model="f.event" type="text" placeholder="e.g. source_lost" class="form-input" />
                    </div>
                    <div class="flex gap-2">
                        <button type="submit"
                                class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium rounded-lg transition-colors">
                            Filter
                        </button>
                        <button type="button" @click="clearFilters"
                                class="px-4 py-2 text-slate-400 border border-slate-700 hover:border-slate-500 text-sm rounded-lg transition-colors">
                            Clear
                        </button>
                    </div>
                </form>
            </div>

            <!-- Log table -->
            <div class="bg-slate-900 border border-slate-800 rounded-xl overflow-hidden">
                <div class="px-6 py-4 border-b border-slate-800 flex items-center justify-between">
                    <h2 class="text-sm font-semibold text-white">Events</h2>
                    <span class="text-xs text-slate-500">{{ logs.total }} entries</span>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full">
                        <thead>
                            <tr class="border-b border-slate-800">
                                <th class="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider w-36">Time</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">Channel</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider w-20">Category</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider w-24">Level</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">Event</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">Message</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-800/50">
                            <tr v-for="log in logs.data" :key="log.id" class="hover:bg-slate-800/20 transition-colors">
                                <td class="px-6 py-3 text-xs text-slate-500 whitespace-nowrap font-mono">
                                    {{ new Date(log.created_at).toLocaleString() }}
                                </td>
                                <td class="px-6 py-3 text-xs text-slate-300">
                                    <Link v-if="log.channel" :href="route('channels.show', log.channel.id)"
                                          class="text-indigo-400 hover:text-indigo-300 transition-colors">
                                        {{ log.channel.name }}
                                    </Link>
                                    <span v-else class="text-slate-600">—</span>
                                </td>
                                <td class="px-6 py-3">
                                    <span class="px-2 py-0.5 rounded text-xs font-semibold"
                                          :class="{
                                              'bg-green-500/10 text-green-400':   log.metadata?.category === 'source',
                                              'bg-yellow-500/10 text-yellow-400': log.metadata?.category === 'dvr',
                                              'bg-indigo-500/10 text-indigo-400': log.metadata?.category === 'push',
                                              'bg-slate-500/10 text-slate-400':   !log.metadata?.category || log.metadata?.category === 'system',
                                          }">
                                        {{ log.metadata?.category ?? 'system' }}
                                    </span>
                                </td>
                                <td class="px-6 py-3">
                                    <span class="px-2 py-0.5 rounded text-xs font-semibold"
                                          :class="{
                                              'bg-blue-500/10 text-blue-400':   log.level === 'info',
                                              'bg-yellow-500/10 text-yellow-400': log.level === 'warning',
                                              'bg-red-500/10 text-red-400':     log.level === 'error',
                                              'bg-red-600/20 text-red-300':     log.level === 'critical',
                                          }">
                                        {{ log.level }}
                                    </span>
                                </td>
                                <td class="px-6 py-3 text-xs font-mono text-slate-400">{{ log.event }}</td>
                                <td class="px-6 py-3 text-xs text-slate-300">{{ log.message }}</td>
                            </tr>
                            <tr v-if="logs.data.length === 0">
                                <td colspan="6" class="px-6 py-12 text-center text-slate-500 text-sm">No log entries match your filters</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <Pagination :links="logs.links" />
        </div>
    </AppLayout>
</template>

<script setup>
import { reactive } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import Pagination from '@/Components/Pagination.vue'

const props = defineProps({
    logs:     Object,
    channels: Array,
    filters:  Object,
})

const f = reactive({
    channel_id: props.filters?.channel_id ?? '',
    level:      props.filters?.level ?? '',
    event:      props.filters?.event ?? '',
    category:   props.filters?.category ?? '',
})

function applyFilters() {
    router.get(route('logs.index'), { ...f }, { preserveState: true, replace: true })
}

function clearFilters() {
    f.channel_id = ''
    f.level      = ''
    f.event      = ''
    applyFilters()
}
</script>
