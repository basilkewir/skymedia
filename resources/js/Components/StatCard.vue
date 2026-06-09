<template>
    <div class="bg-slate-900 border border-slate-800 rounded-xl p-5">
        <p class="text-xs font-medium text-slate-500 uppercase tracking-wider">{{ title }}</p>
        <p class="mt-2 text-3xl font-bold" :class="valueClass">{{ value }}</p>
        <p v-if="sub" class="mt-1 text-xs text-slate-500">{{ sub }}</p>
    </div>
</template>

<script setup>
import { computed } from 'vue'
const props = defineProps({
    title: String,
    value: [Number, String],
    color: { type: String, default: 'slate' },
    sub:   { type: String, default: null },
})
const palette = { green: 'text-green-400', yellow: 'text-yellow-400', red: 'text-red-400', blue: 'text-blue-400', indigo: 'text-indigo-400', slate: 'text-white' }
const valueClass = computed(() => palette[props.color] ?? 'text-white')
</script>
