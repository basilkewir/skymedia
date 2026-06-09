<template>
    <div :class="className">
        <label class="block text-xs font-medium text-slate-400 mb-1.5">{{ label }}</label>
        <slot />
        <p v-if="error" class="mt-1.5 text-xs text-red-400">{{ error }}</p>
    </div>
</template>

<script setup>
defineProps({
    label:     String,
    error:     { type: String, default: null },
    className: { type: String, default: '' },
})
</script>
