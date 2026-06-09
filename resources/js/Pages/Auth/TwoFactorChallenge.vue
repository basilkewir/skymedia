<template>
    <div class="min-h-screen bg-slate-950 flex items-center justify-center p-4">
        <div class="w-full max-w-md">
            <div class="text-center mb-8">
                <div class="inline-flex items-center justify-center w-16 h-16 bg-indigo-500 rounded-2xl mb-4">
                    <svg class="w-9 h-9 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
                    </svg>
                </div>
                <h1 class="text-2xl font-bold text-white">Two Factor Auth</h1>
                <p class="text-slate-400 text-sm mt-1">Enter your authentication code</p>
            </div>
            <div class="bg-slate-900 border border-slate-800 rounded-2xl p-8">
                <form @submit.prevent="submit" class="space-y-5">
                    <div v-if="!recovery">
                        <label class="block text-sm font-medium text-slate-300 mb-1.5">Authentication Code</label>
                        <input v-model="form.code" type="text" inputmode="numeric" autofocus autocomplete="one-time-code"
                               class="w-full bg-slate-800 border border-slate-700 text-white rounded-lg px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"/>
                    </div>
                    <div v-else>
                        <label class="block text-sm font-medium text-slate-300 mb-1.5">Recovery Code</label>
                        <input v-model="form.recovery_code" type="text" autocomplete="one-time-code"
                               class="w-full bg-slate-800 border border-slate-700 text-white rounded-lg px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"/>
                    </div>
                    <button type="button" @click="toggleRecovery" class="text-sm text-indigo-400 hover:text-indigo-300">
                        {{ recovery ? 'Use authentication code' : 'Use recovery code' }}
                    </button>
                    <button type="submit" :disabled="form.processing"
                            class="w-full bg-indigo-600 hover:bg-indigo-500 disabled:opacity-50 text-white font-medium rounded-lg px-4 py-2.5 text-sm transition-colors">
                        Verify
                    </button>
                </form>
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref } from 'vue'
import { useForm } from '@inertiajs/vue3'

const recovery = ref(false)
const form = useForm({ code: '', recovery_code: '' })

const toggleRecovery = () => {
    recovery.value = !recovery.value
    form.reset('code', 'recovery_code')
}

const submit = () => {
    form.post(route('two-factor.login'))
}
</script>
