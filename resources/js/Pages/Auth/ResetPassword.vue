<template>
    <div class="min-h-screen bg-slate-950 flex items-center justify-center p-4">
        <div class="w-full max-w-md">
            <div class="bg-slate-900 border border-slate-800 rounded-2xl p-8">
                <h2 class="text-lg font-semibold text-white mb-6">Reset Password</h2>
                <form @submit.prevent="submit" class="space-y-5">
                    <div>
                        <label class="block text-sm font-medium text-slate-300 mb-1.5">Email</label>
                        <input v-model="form.email" type="email" required autofocus
                               class="w-full bg-slate-800 border border-slate-700 text-white rounded-lg px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"/>
                        <p v-if="form.errors.email" class="mt-1 text-xs text-red-400">{{ form.errors.email }}</p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-300 mb-1.5">New Password</label>
                        <input v-model="form.password" type="password" required autocomplete="new-password"
                               class="w-full bg-slate-800 border border-slate-700 text-white rounded-lg px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"/>
                        <p v-if="form.errors.password" class="mt-1 text-xs text-red-400">{{ form.errors.password }}</p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-300 mb-1.5">Confirm Password</label>
                        <input v-model="form.password_confirmation" type="password" required autocomplete="new-password"
                               class="w-full bg-slate-800 border border-slate-700 text-white rounded-lg px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"/>
                    </div>
                    <button type="submit" :disabled="form.processing"
                            class="w-full bg-indigo-600 hover:bg-indigo-500 disabled:opacity-50 text-white font-medium rounded-lg px-4 py-2.5 text-sm transition-colors">
                        Reset Password
                    </button>
                </form>
            </div>
        </div>
    </div>
</template>

<script setup>
import { useForm } from '@inertiajs/vue3'
const props = defineProps({ token: String, email: String })
const form = useForm({ token: props.token, email: props.email, password: '', password_confirmation: '' })
const submit = () => form.post(route('password.update'), { onFinish: () => form.reset('password', 'password_confirmation') })
</script>
