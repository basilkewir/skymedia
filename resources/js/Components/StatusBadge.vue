<template>
    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold" :class="cls">
        <span v-if="pulse" class="w-1.5 h-1.5 rounded-full" :class="dotCls" />
        {{ label }}
    </span>
</template>

<script setup>
import { computed } from 'vue'
const props = defineProps({ status: String, pulse: { type: Boolean, default: true } })

const map = {
    live:         { cls: 'bg-green-500/15 text-green-400 ring-1 ring-green-500/30',   dot: 'bg-green-400 animate-pulse', label: 'Live' },
    dvr_playback: { cls: 'bg-yellow-500/15 text-yellow-400 ring-1 ring-yellow-500/30', dot: 'bg-yellow-400 animate-pulse', label: 'DVR Playback' },
    error:        { cls: 'bg-red-500/15 text-red-400 ring-1 ring-red-500/30',         dot: 'bg-red-400',                  label: 'Error' },
    starting:     { cls: 'bg-blue-500/15 text-blue-400 ring-1 ring-blue-500/30',       dot: 'bg-blue-400 animate-pulse',   label: 'Starting' },
    stopped:      { cls: 'bg-slate-500/15 text-slate-400 ring-1 ring-slate-500/30',   dot: 'bg-slate-500',                label: 'Stopped' },
    idle:         { cls: 'bg-slate-500/15 text-slate-400 ring-1 ring-slate-500/30',   dot: 'bg-slate-500',                label: 'Idle' },
}
const current = computed(() => map[props.status] ?? map.idle)
const cls     = computed(() => current.value.cls)
const dotCls  = computed(() => current.value.dot)
const label   = computed(() => current.value.label)
</script>
