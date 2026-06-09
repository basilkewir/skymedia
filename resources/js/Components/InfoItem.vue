<template>
    <div class="bg-slate-800/60 border border-slate-700/50 rounded-lg p-3">
        <dt class="text-xs font-medium text-slate-500 uppercase tracking-wider">{{ label }}</dt>
        <dd class="mt-1 text-sm font-semibold text-white">{{ value }}</dd>
        <dd v-if="sub" class="text-xs text-slate-400 truncate mt-0.5" :title="sub">{{ sub }}</dd>
    </div>
</template>

<script setup>
defineProps({ label: String, value: String, sub: { type: String, default: null } })
</script>
