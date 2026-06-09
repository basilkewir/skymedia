<template>
    <Link :href="href"
          class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium transition-colors"
          :class="active
              ? 'bg-indigo-600/20 text-indigo-400 border border-indigo-500/30'
              : 'text-slate-400 hover:text-white hover:bg-slate-800'">
        <span class="flex-shrink-0"><slot name="icon" /></span>
        <span class="flex-1"><slot /></span>
        <slot name="badge" />
    </Link>
</template>

<script setup>
import { Link } from '@inertiajs/vue3'
defineProps({
    href:   { type: String, required: true },
    active: { type: Boolean, default: false },
})
</script>
