<template>
    <AppLayout>
        <template #header>
            <div class="flex items-center gap-2 text-sm text-slate-400">
                <Link :href="route('users.index')" class="hover:text-white transition-colors">Users</Link>
                <span>/</span>
                <span class="text-white">Edit {{ user.name }}</span>
            </div>
        </template>

        <div class="max-w-xl">
            <form @submit.prevent="submit" class="space-y-5">
                <Section title="User Details">
                    <div class="grid grid-cols-1 gap-5">
                        <FormField label="Name" :error="form.errors.name">
                            <input v-model="form.name" type="text" required class="form-input" />
                        </FormField>
                        <FormField label="Email" :error="form.errors.email">
                            <input v-model="form.email" type="email" required class="form-input" />
                        </FormField>
                        <FormField label="New Password" :error="form.errors.password">
                            <input v-model="form.password" type="password" class="form-input" />
                            <p class="mt-1 text-xs text-slate-500">Leave blank to keep the current password.</p>
                        </FormField>
                        <FormField label="Admin">
                            <label class="flex items-center gap-3 cursor-pointer">
                                <input v-model="form.is_admin" type="checkbox" class="w-4 h-4 text-indigo-600 border-slate-600 rounded focus:ring-indigo-500" />
                                <span class="text-sm text-slate-300">Administrator access (sees all channels)</span>
                            </label>
                        </FormField>
                    </div>
                </Section>

                <div class="flex justify-end gap-3 pb-4">
                    <Link :href="route('users.index')"
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
import Section from '@/Components/Section.vue'

const props = defineProps({ user: Object })

const form = useForm({
    name: props.user.name,
    email: props.user.email,
    password: '',
    is_admin: props.user.is_admin ?? false,
})

function submit() { form.put(route('users.update', props.user.id)) }
</script>
