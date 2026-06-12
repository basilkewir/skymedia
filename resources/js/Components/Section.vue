<template>
    <div class="bg-slate-900 border border-slate-800 rounded-xl p-6">
        <h2 class="text-sm font-semibold text-white mb-4">{{ title }}</h2>
        <slot />
    </div>
</template>

<script setup>
defineProps({ title: String })
</script>
