<template>
    <AppLayout>
        <template #header>
            <div class="flex items-center gap-2 text-sm text-slate-400">
                <Link :href="route('users.index')" class="hover:text-white transition-colors">Users</Link>
                <span>/</span>
                <Link :href="route('users.show', user.id)" class="hover:text-white transition-colors">{{ user.name }}</Link>
                <span>/</span>
                <span class="text-white">Channels</span>
            </div>
        </template>

        <div class="space-y-6">
            <!-- User Info -->
            <div class="bg-slate-900 border border-slate-800 rounded-xl p-4">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-white font-medium">{{ user.name }}</p>
                        <p class="text-sm text-slate-400">{{ user.channels_count }} channel{{ user.channels_count !== 1 ? 's' : '' }} assigned</p>
                    </div>
                    <Link :href="route('channels.create')"
                          class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium rounded-lg transition-colors">
                        + Create New Channel
                    </Link>
                </div>
            </div>

            <!-- Assign Existing Channel Modal -->
            <div v-if="showAssignModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-sm">
                <div class="bg-slate-900 border border-slate-700 rounded-xl p-6 w-full max-w-md shadow-2xl">
                    <h3 class="text-base font-semibold text-white mb-4">Assign Channel to {{ user.name }}</h3>

                    <div class="space-y-4 mb-6">
                        <div>
                            <label class="block text-xs text-slate-400 mb-1">Select Channel</label>
                            <select v-model="assignForm.channel_id"
                                    @change="onChannelSelect"
                                    class="w-full form-input">
                                <option value="">-- Select a channel --</option>
                                <option v-for="c in unassignedChannels" :key="c.id" :value="c.id">
                                    {{ c.name }} ({{ c.push_target }})
                                </option>
                            </select>
                            <p v-if="assignForm.errors.channel_id" class="mt-1 text-xs text-red-400">{{ assignForm.errors.channel_id }}</p>
                        </div>

                        <div>
                            <label class="block text-xs text-slate-400 mb-1">Storage Quota (optional)</label>
                            <div class="flex gap-2">
                                <input v-model="assignForm.storage_quota_gb" type="number"
                                       min="1" max="10000" step="1"
                                       placeholder="GB"
                                       class="form-input flex-1" />
                                <span class="flex items-center text-slate-500 text-sm">GB</span>
                            </div>
                            <p class="mt-1 text-xs text-slate-500">Leave empty for unlimited storage</p>
                        </div>
                    </div>

                    <div class="flex justify-end gap-3">
                        <button @click="showAssignModal = false; assignForm.reset()"
                                class="px-4 py-2 text-sm text-slate-400 border border-slate-700 rounded-lg hover:border-slate-500 hover:text-white transition-colors">
                            Cancel
                        </button>
                        <button @click="assignChannel"
                                :disabled="assignForm.processing || !assignForm.channel_id"
                                class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 disabled:opacity-50 text-white text-sm font-medium rounded-lg transition-colors">
                            {{ assignForm.processing ? 'Assigning…' : 'Assign Channel' }}
                        </button>
                    </div>
                </div>
            </div>

            <!-- Channels Table -->
            <div class="bg-slate-900 border border-slate-800 rounded-xl overflow-hidden">
                <table class="min-w-full">
                    <thead>
                        <tr class="border-b border-slate-800">
                            <th class="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">Channel</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">Push Target</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">Status</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">Storage</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-slate-500 uppercase tracking-wider">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-800/50">
                        <tr v-for="c in channels.data" :key="c.id" class="hover:bg-slate-800/20 transition-colors">
                            <td class="px-6 py-4">
                                <div>
                                    <p class="text-white font-medium">{{ c.name }}</p>
                                    <p class="text-xs text-slate-500">{{ c.slug }}</p>
                                </div>
                            </td>
                            <td class="px-6 py-4 text-sm text-slate-400 font-mono truncate max-w-xs">{{ c.push_target }}</td>
                            <td class="px-6 py-4">
                                <StatusBadge :status="c.stream_status" />
                            </td>
                            <td class="px-6 py-4">
                                <div v-if="c.storage_quota_bytes" class="flex items-center gap-2">
                                    <div class="flex-1 h-2 bg-slate-800 rounded overflow-hidden" style="max-width: 150px;">
                                        <div class="h-full bg-indigo-500 transition-all"
                                             :style="{ width: storagePercent(c) + '%' }"></div>
                                    </div>
                                    <span class="text-xs text-slate-400 whitespace-nowrap">
                                        {{ formatBytes(c.storage_used_bytes) }} / {{ formatBytes(c.storage_quota_bytes) }}
                                    </span>
                                </div>
                                <span v-else class="text-xs text-slate-500">Unlimited</span>
                            </td>
                            <td class="px-6 py-4">
                                <div class="flex items-center justify-end gap-2">
                                    <Link :href="route('channels.show', c.id)"
                                          class="text-xs text-slate-400 hover:text-white transition-colors">View</Link>
                                    <Link :href="route('channels.edit', c.id)"
                                          class="text-xs text-indigo-400 hover:text-indigo-300 transition-colors">Edit</Link>
                                    <button @click="confirmDetach(c)"
                                            class="text-xs text-red-400 hover:text-red-300 transition-colors">Detach</button>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="channels.data.length === 0">
                            <td colspan="5" class="px-6 py-16 text-center text-slate-500 text-sm">
                                No channels assigned yet.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <Pagination :links="channels.links" />

            <!-- Detach Confirmation -->
            <div v-if="detaching" class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-sm">
                <div class="bg-slate-900 border border-slate-700 rounded-xl p-6 w-full max-w-sm shadow-2xl">
                    <h3 class="text-base font-semibold text-white mb-2">Detach "{{ detaching.name }}"?</h3>
                    <p class="text-sm text-slate-400 mb-6">The channel will become unassigned and can be assigned to another user.</p>
                    <div class="flex justify-end gap-3">
                        <button @click="detaching = null"
                                class="px-4 py-2 text-sm text-slate-400 border border-slate-700 rounded-lg hover:border-slate-500 hover:text-white transition-colors">
                            Cancel
                        </button>
                        <button @click="detachChannel"
                                :disabled="detachLoading"
                                class="px-4 py-2 bg-red-600 hover:bg-red-700 disabled:opacity-50 text-white text-sm font-medium rounded-lg transition-colors">
                            {{ detachLoading ? 'Detaching…' : 'Detach' }}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { ref, computed } from 'vue'
import { Link, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import StatusBadge from '@/Components/StatusBadge.vue'
import Pagination from '@/Components/Pagination.vue'

defineProps({ user: Object, channels: Object, unassignedChannels: Array })

const showAssignModal = ref(false)
const detaching = ref(null)
const detachLoading = ref(false)

const assignForm = useForm({
    channel_id: '',
    storage_quota_gb: '',
})

const unassignedChannels = computed(() => {
    // This would need to be passed from the controller or fetched
    // For now, we'll assume channels without user_id are passed in the page props
    return props.channels.unassigned || []
})

function formatBytes(bytes) {
    if (!bytes) return '0 B'
    const units = ['B', 'KB', 'MB', 'GB', 'TB']
    let i = 0
    while (bytes >= 1024 && i < units.length - 1) {
        bytes /= 1024
        i++
    }
    return `${bytes.toFixed(1)} ${units[i]}`
}

function storagePercent(c) {
    if (!c.storage_quota_bytes) return 0
    return Math.min(100, Math.round((c.storage_used_bytes / c.storage_quota_bytes) * 100))
}

function onChannelSelect() {
    assignForm.clearErrors('channel_id')
}

function confirmDetach(c) { detaching.value = c }

async function detachChannel() {
    if (!detaching.value) return
    detachLoading.value = true

    try {
        await useForm({}).post(route('users.channels.detach', [props.user.id, detaching.value.id]))
        detaching.value = null
    } catch (e) {
        detachLoading.value = false
    }
}

function assignChannel() {
    const quotaBytes = assignForm.storage_quota_gb ? assignForm.storage_quota_gb * 1024 * 1024 * 1024 : null
    assignForm.post(route('users.channels.attach', [props.user.id, assignForm.channel_id]), {
        data: { storage_quota_bytes: quotaBytes },
        onSuccess: () => {
            showAssignModal.value = false
            assignForm.reset()
        }
    })
}
</script>