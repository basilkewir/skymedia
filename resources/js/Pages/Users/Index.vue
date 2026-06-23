<template>
    <AppLayout>
        <template #header>
            <h1 class="text-lg font-semibold text-white">Users</h1>
        </template>

        <div class="space-y-4">
            <div class="flex items-center justify-between">
                <p class="text-sm text-slate-400">{{ users.total }} user{{ users.total !== 1 ? 's' : '' }}</p>
                <Link :href="route('users.create')"
                      class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium rounded-lg transition-colors">
                    + Add User
                </Link>
            </div>

            <div class="bg-slate-900 border border-slate-800 rounded-xl overflow-hidden">
                <table class="min-w-full">
                    <thead>
                        <tr class="border-b border-slate-800">
                            <th class="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">Name</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">Email</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">Created</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-slate-500 uppercase tracking-wider">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-800/50">
                        <tr v-for="u in users.data" :key="u.id" class="hover:bg-slate-800/20 transition-colors">
                            <td class="px-6 py-4 text-sm font-medium text-white">{{ u.name }}</td>
                            <td class="px-6 py-4 text-sm text-slate-400">{{ u.email }}</td>
                            <td class="px-6 py-4 text-sm text-slate-500">{{ new Date(u.created_at).toLocaleDateString() }}</td>
                            <td class="px-6 py-4">
                                <div class="flex items-center justify-end gap-3">
                                    <Link :href="route('users.edit', u.id)"
                                          class="text-xs text-slate-400 hover:text-white transition-colors">Edit / Password</Link>
                                    <button @click="confirmDelete(u)"
                                            class="text-xs text-slate-600 hover:text-red-400 transition-colors">
                                        Delete
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="users.data.length === 0">
                            <td colspan="4" class="px-6 py-16 text-center text-slate-500 text-sm">
                                No users configured.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <Pagination :links="users.links" />
        </div>

        <div v-if="deleting" class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-sm">
            <div class="bg-slate-900 border border-slate-700 rounded-xl p-6 w-full max-w-sm shadow-2xl">
                <h3 class="text-base font-semibold text-white mb-2">Delete "{{ deleting.name }}"?</h3>
                <p class="text-sm text-slate-400 mb-6">This action cannot be undone.</p>
                <div class="flex justify-end gap-3">
                    <button @click="deleting = null"
                            class="px-4 py-2 text-sm text-slate-400 border border-slate-700 rounded-lg hover:border-slate-500 transition-colors">
                        Cancel
                    </button>
                    <Link :href="route('users.destroy', deleting.id)" method="delete" as="button"
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
import Pagination from '@/Components/Pagination.vue'

defineProps({ users: Object })

const deleting = ref(null)
function confirmDelete(u) { deleting.value = u }
</script>
