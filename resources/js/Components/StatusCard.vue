<template>
    <div class="bg-slate-900 border border-slate-800 rounded-xl p-4">
        <p class="text-xs font-medium text-slate-500 uppercase tracking-wider mb-2">{{ label }}</p>
        <StatusBadge :status="mappedStatus" />
        <p v-if="pid" class="mt-1.5 text-xs text-slate-500 font-mono">PID {{ pid }}</p>
    </div>
</template>

<script setup>
import { computed } from 'vue'
import StatusBadge from './StatusBadge.vue'

const props = defineProps({
    label:  String,
    status: String,
    pid:    { type: Number, default: null },
})

// Map dvr/record-specific statuses to badge-compatible values
const mappedStatus = computed(() => {
    const map = {
        recording:  'live',
        finishing:  'starting',
        idle:       'idle',
        starting:   'starting',
        live:       'live',
        fallback:   'dvr_playback',
        offline:    'stopped',
        stopped:    'stopped',
        error:      'error',
    }
    return map[props.status] ?? props.status ?? 'idle'
})
</script>
