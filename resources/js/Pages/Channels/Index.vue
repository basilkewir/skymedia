<template>
    <AppLayout>
        <template #header>
            <h1 class="text-lg font-semibold text-white">Channels</h1>
        </template>

        <div class="space-y-4">
            <!-- Toolbar -->
            <div class="flex items-center justify-between">
                <p class="text-sm text-slate-400">{{ channels.total }} channel{{ channels.total !== 1 ? 's' : '' }} configured</p>
                <Link :href="route('channels.create')"
                      class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium rounded-lg transition-colors">
                    + Add Channel
                </Link>
            </div>

            <!-- Table -->
            <div class="bg-slate-900 border border-slate-800 rounded-xl overflow-hidden">
                <table class="min-w-full">
                    <thead>
                        <tr class="border-b border-slate-800">
                            <th class="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">Channel</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">Source</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">Push Target</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">Status</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">DVR Window</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">Segments</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">Active</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-slate-500 uppercase tracking-wider">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-800/50">
                        <tr v-for="ch in channels.data" :key="ch.id" class="hover:bg-slate-800/20 transition-colors">
                            <td class="px-6 py-4">
                                <Link :href="route('channels.show', ch.id)"
                                      class="text-sm font-medium text-indigo-400 hover:text-indigo-300 transition-colors">
                                    {{ ch.name }}
                                </Link>
                                <div class="text-xs text-slate-500 font-mono mt-0.5">{{ ch.slug }}</div>
                            </td>
                            <td class="px-6 py-4">
                                <span class="px-2 py-0.5 bg-slate-800 text-slate-300 text-xs font-mono rounded">
                                    {{ ch.source_type.toUpperCase() }}
                                </span>
                            </td>
                            <td class="px-6 py-4 text-xs text-slate-400">
                                <span class="px-2 py-0.5 bg-slate-800 text-slate-300 text-xs font-mono rounded mr-1">
                                    {{ ch.push_protocol.toUpperCase() }}
                                </span>
                                <span class="truncate max-w-[140px] inline-block align-bottom" :title="ch.push_url">{{ ch.push_url }}</span>
                            </td>
                            <td class="px-6 py-4"><StatusBadge :status="ch.stream_status" /></td>
                            <td class="px-6 py-4 text-sm text-slate-400">{{ formatDuration(ch.dvr_duration) }}</td>
                            <td class="px-6 py-4 text-sm text-slate-400">{{ ch.dvr_segments_count }}</td>
                            <td class="px-6 py-4">
                                <span class="text-xs font-medium" :class="ch.is_active ? 'text-green-400' : 'text-slate-500'">
                                    {{ ch.is_active ? 'Yes' : 'No' }}
                                </span>
                            </td>
                            <td class="px-6 py-4">
                                <div class="flex items-center justify-end gap-3">
                                    <Link :href="route('channels.edit', ch.id)"
                                          class="text-xs text-slate-400 hover:text-white transition-colors">Edit</Link>
                                    <Link :href="route('channels.toggle', ch.id)" method="post" as="button"
                                          class="text-xs font-medium transition-colors"
                                          :class="ch.is_active ? 'text-red-400 hover:text-red-300' : 'text-green-400 hover:text-green-300'">
                                        {{ ch.is_active ? 'Stop' : 'Start' }}
                                    </Link>
                                    <Link :href="route('channels.destroy', ch.id)" method="delete" as="button"
                                          class="text-xs text-slate-600 hover:text-red-400 transition-colors"
                                          @click.prevent="confirmDelete(ch)">
                                        Delete
                                    </Link>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="channels.data.length === 0">
                            <td colspan="8" class="px-6 py-16 text-center text-slate-500 text-sm">
                                No channels configured.
                                <Link :href="route('channels.create')" class="text-indigo-400 hover:underline ml-1">Add one</Link>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <Pagination :links="channels.links" />
        </div>

        <!-- Delete confirm modal -->
        <div v-if="deleting" class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-sm">
            <div class="bg-slate-900 border border-slate-700 rounded-xl p-6 w-full max-w-sm shadow-2xl">
                <h3 class="text-base font-semibold text-white mb-2">Delete "{{ deleting.name }}"?</h3>
                <p class="text-sm text-slate-400 mb-6">This will stop the stream and permanently delete all settings. DVR files on disk will remain.</p>
                <div class="flex justify-end gap-3">
                    <button @click="deleting = null"
                            class="px-4 py-2 text-sm text-slate-400 border border-slate-700 rounded-lg hover:border-slate-500 transition-colors">
                        Cancel
                    </button>
                    <Link :href="route('channels.destroy', deleting.id)" method="delete" as="button"
                          class="px-4 py-2 bg-red-600 hover:bg-red-700 text-white text-sm font-medium rounded-lg transition-colors">
                        Delete
                    </Link>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { ref } from 'vue'
import { Link } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import StatusBadge from '@/Components/StatusBadge.vue'
import Pagination from '@/Components/Pagination.vue'

defineProps({ channels: Object })

const deleting = ref(null)
function confirmDelete(ch) { deleting.value = ch }

function formatDuration(s) {
    const h = Math.floor(s / 3600), m = Math.floor((s % 3600) / 60)
    return h > 0 ? `${h}h ${m}m` : `${m}m`
}
</script>
