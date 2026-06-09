<template>
    <div class="min-h-screen bg-slate-950 flex items-center justify-center p-4">
        <div class="w-full max-w-md">
            <div class="bg-slate-900 border border-slate-800 rounded-2xl p-8">
                <h2 class="text-lg font-semibold text-white mb-2">Forgot Password</h2>
                <p class="text-slate-400 text-sm mb-6">Enter your email and we'll send a reset link.</p>
                <div v-if="status" class="mb-4 bg-green-500/10 border border-green-500/30 text-green-400 rounded-lg px-4 py-3 text-sm">{{ status }}</div>
                <form @submit.prevent="submit" class="space-y-5">
                    <div>
                        <label class="block text-sm font-medium text-slate-300 mb-1.5">Email</label>
                        <input v-model="form.email" type="email" required autofocus
                               class="w-full bg-slate-800 border border-slate-700 text-white rounded-lg px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"/>
                        <p v-if="form.errors.email" class="mt-1 text-xs text-red-400">{{ form.errors.email }}</p>
                    </div>
                    <button type="submit" :disabled="form.processing"
                            class="w-full bg-indigo-600 hover:bg-indigo-500 disabled:opacity-50 text-white font-medium rounded-lg px-4 py-2.5 text-sm transition-colors">
                        Send Reset Link
                    </button>
                </form>
            </div>
        </div>
    </div>
</template>

<script setup>
import { useForm } from '@inertiajs/vue3'
defineProps({ status: String })
const form = useForm({ email: '' })
const submit = () => form.post(route('password.email'))
</script>
