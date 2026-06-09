<template>
    <a :href="href"
       class="inline-flex items-center px-3 py-2 text-sm font-medium border-b-2 transition-colors"
       :class="active ? 'border-indigo-500 text-gray-900' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'">
        <slot />
    </a>
</template>

<script setup>
defineProps({
    href: { type: String, required: true },
    active: { type: Boolean, default: false },
})
</script>
