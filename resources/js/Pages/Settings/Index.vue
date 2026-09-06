<template>
    <AppLayout>
        <template #header>
            <h1 class="text-lg font-semibold text-white">Settings</h1>
        </template>

        <div class="max-w-2xl space-y-6">
            <form @submit.prevent="submit">
                <div v-for="(group, groupName) in settings" :key="groupName"
                     class="bg-slate-900 border border-slate-800 rounded-xl p-6 mb-5">
                    <h2 class="text-sm font-semibold text-white capitalize mb-4">{{ groupName }} Settings</h2>
                    <div class="space-y-4">
                        <div v-for="s in group" :key="s.key">
                            <label class="block text-xs font-medium text-slate-400 mb-1.5">
                                {{ s.label || s.key }}
                                <span class="text-slate-600 font-mono ml-1">({{ s.key }})</span>
                            </label>
                            <div class="flex items-center gap-2">
                                <input v-if="s.type !== 'boolean'" v-model="form[s.key]"
                                       :type="s.type === 'integer' || s.type === 'float' ? 'number' : 'text'"
                                       class="form-input flex-1" />
                                <select v-else v-model="form[s.key]" class="form-input flex-1">
                                    <option value="1">Enabled</option>
                                    <option value="0">Disabled</option>
                                </select>

                                <!-- YouTube API test button -->
                                <button v-if="s.key === 'youtube_api_key'"
                                        type="button"
                                        @click="testYoutubeKey"
                                        :disabled="testingYoutube"
                                        class="px-3 py-2 text-xs font-medium rounded-lg transition-colors whitespace-nowrap"
                                        :class="youtubeTestResult?.success
                                            ? 'bg-green-600 hover:bg-green-700 text-white'
                                            : youtubeTestResult && !youtubeTestResult.success
                                                ? 'bg-red-600 hover:bg-red-700 text-white'
                                                : 'bg-slate-700 hover:bg-slate-600 text-slate-300'">
                                    {{ testingYoutube ? 'Testing…' : youtubeTestResult?.success ? '✓ Valid' : youtubeTestResult ? '✗ Failed' : 'Test Key' }}
                                </button>
                            </div>
                            <!-- YouTube test result message -->
                            <p v-if="s.key === 'youtube_api_key' && youtubeTestResult"
                               class="mt-1.5 text-xs"
                               :class="youtubeTestResult.success ? 'text-green-400' : 'text-red-400'">
                                {{ youtubeTestResult.message || youtubeTestResult.error }}
                            </p>
                        </div>
                    </div>
                </div>

                <div class="flex justify-end">
                    <button type="submit" :disabled="saving"
                            class="px-6 py-2 bg-indigo-600 hover:bg-indigo-700 disabled:opacity-50 text-white text-sm font-medium rounded-lg transition-colors">
                        {{ saving ? 'Saving…' : 'Save Settings' }}
                    </button>
                </div>
            </form>

            <!-- System commands reference -->
            <div class="bg-slate-900 border border-slate-800 rounded-xl p-6">
                <h2 class="text-sm font-semibold text-white mb-4">System Commands</h2>
                <div class="bg-slate-800/60 rounded-lg p-4 space-y-2 text-xs font-mono text-slate-300">
                    <p class="text-green-400"># Start the stream monitor daemon (keep running via supervisord)</p>
                    <p>php artisan streams:monitor</p>
                    <p class="mt-2 text-green-400"># Start all active channels</p>
                    <p>php artisan streams:activate-all</p>
                    <p class="mt-2 text-green-400"># Manual DVR cleanup / enforce rolling windows</p>
                    <p>php artisan dvr:cleanup</p>
                    <p class="mt-2 text-green-400"># Start or stop a specific channel</p>
                    <p>php artisan streams:start &lt;id-or-slug&gt;</p>
                    <p>php artisan streams:stop &lt;id-or-slug&gt;</p>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { reactive, ref } from 'vue'
import { router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'

const props = defineProps({ settings: Object })

const form = reactive({})
Object.values(props.settings).forEach(group => {
    group.forEach(s => { form[s.key] = s.value })
})

const saving = ref(false)
const testingYoutube = ref(false)
const youtubeTestResult = ref(null)

function submit() {
    saving.value = true
    const payload = Object.entries(form).map(([key, value]) => ({ key, value }))
    router.put(route('settings.update'), { settings: payload }, {
        onFinish: () => { saving.value = false },
    })
}

function testYoutubeKey() {
    testingYoutube.value = true
    youtubeTestResult.value = null

    // Save first so the test uses the latest key
    const payload = Object.entries(form).map(([key, value]) => ({ key, value }))
    router.put(route('settings.update'), { settings: payload }, {
        preserveScroll: true,
        onFinish: () => {
            fetch(route('settings.test-youtube'), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-XSRF-TOKEN': decodeURIComponent(document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] || ''),
                },
            })
                .then(r => r.json())
                .then(data => { youtubeTestResult.value = data })
                .catch(() => { youtubeTestResult.value = { success: false, error: 'Network error — could not reach server.' } })
                .finally(() => { testingYoutube.value = false })
        },
    })
}
</script>
