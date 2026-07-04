<template>
    <AppLayout>
        <template #header>
            <div class="flex items-center gap-2 text-sm text-slate-400">
                <Link :href="route('users.index')" class="hover:text-white transition-colors">Users</Link>
                <span>/</span>
                <span class="text-white">{{ user.name }}</span>
                <span v-if="user.is_admin" class="px-2 py-0.5 bg-indigo-600/20 text-indigo-400 text-xs rounded">Admin</span>
            </div>
        </template>

        <div class="space-y-6">
            <!-- User Info Card -->
            <div class="bg-slate-900 border border-slate-800 rounded-xl p-6">
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <div>
                        <p class="text-xs text-slate-500 uppercase tracking-wide">Name</p>
                        <p class="text-white font-medium">{{ user.name }}</p>
                    </div>
                    <div>
                        <p class="text-xs text-slate-500 uppercase tracking-wide">Email</p>
                        <p class="text-slate-400">{{ user.email }}</p>
                    </div>
                    <div>
                        <p class="text-xs text-slate-500 uppercase tracking-wide">Created</p>
                        <p class="text-slate-400">{{ new Date(user.created_at).toLocaleDateString() }}</p>
                    </div>
                </div>

                <div class="flex items-center gap-4 mt-6 pt-4 border-t border-slate-800">
                    <Link :href="route('users.edit', user.id)"
                          class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium rounded-lg transition-colors">
                        Edit User
                    </Link>
                    <Link :href="route('users.channels', user.id)"
                          class="px-4 py-2 bg-slate-800 hover:bg-slate-700 text-white text-sm font-medium rounded-lg transition-colors border border-slate-700">
                        Manage Channels
                    </Link>
                    <button @click="confirmDelete"
                            class="px-4 py-2 bg-red-600/20 hover:bg-red-600/30 text-red-400 border border-red-600/30 text-sm font-medium rounded-lg transition-colors">
                        Delete User
                    </button>
                </div>
            </div>

            <!-- Channels Overview -->
            <div class="bg-slate-900 border border-slate-800 rounded-xl p-6">
                <div class="flex items-center justify-between mb-4">
                    <h2 class="text-lg font-semibold text-white">Assigned Channels ({{ channels.total }})</h2>
                    <Link :href="route('users.channels', user.id)"
                          class="px-3 py-1.5 text-xs text-slate-400 hover:text-white border border-slate-700 rounded-lg transition-colors">
                        View All
                    </Link>
                </div>

                <div v-if="channels.data.length === 0" class="text-center py-8 text-slate-500">
                    No channels assigned to this user yet.
                </div>

                <div v-else class="space-y-3" style="max-height: 300px; overflow-y: auto;">
                    <div v-for="c in channels.data" :key="c.id" class="flex items-center justify-between p-3 bg-slate-800/50 rounded-lg">
                        <div class="flex items-center gap-3 min-w-0">
                            <div class="w-3 h-3 rounded-full"
                                 :class="c.stream_status === 'live' ? 'bg-green-500' : c.stream_status === 'offline' ? 'bg-red-500' : 'bg-slate-500'"></div>
                            <div>
                                <p class="text-white font-medium truncate">{{ c.name }}</p>
                                <p class="text-xs text-slate-500 truncate max-w-xs">{{ c.push_target }}</p>
                            </div>
                        </div>
                        <div class="flex items-center gap-3 text-sm">
                            <StatusBadge :status="c.stream_status" />
                            <span v-if="c.storage_quota_bytes" class="text-slate-400">
                                {{ formatBytes(c.storage_used_bytes) }} / {{ formatBytes(c.storage_quota_bytes) }}
                            </span>
                            <Link :href="route('channels.show', c.id)"
                                  class="text-xs text-indigo-400 hover:text-indigo-300 transition-colors">
                                View
                            </Link>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Delete Confirmation -->
        <div v-if="deleting" class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-sm">
            <div class="bg-slate-900 border border-slate-700 rounded-xl p-6 w-full max-w-sm shadow-2xl">
                <h3 class="text-base font-semibold text-white mb-2">Delete "{{ user.name }}"?</h3>
                <p class="text-sm text-slate-400 mb-6">This will also delete all their channels. This action cannot be undone.</p>
                <div class="flex justify-end gap-3">
                    <button @click="deleting = false"
                            class="px-4 py-2 text-sm text-slate-400 border border-slate-700 rounded-lg hover:border-slate-500 hover:text-white transition-colors">
                        Cancel
                    </button>
                    <Link :href="route('users.destroy', user.id)" method="delete" as="button"
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

defineProps({ user: Object, channels: Object })

const deleting = ref(false)

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

function confirmDelete() { deleting.value = true }
</script>