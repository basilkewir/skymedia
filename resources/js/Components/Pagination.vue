<template>
    <div v-if="links.length > 3" class="flex justify-center gap-1 mt-6">
        <template v-for="(link, i) in links" :key="i">
            <span v-if="!link.url"
                  class="px-3 py-1.5 text-sm text-slate-600 border border-slate-700 rounded-lg"
                  v-html="link.label" />
            <Link v-else :href="link.url"
                  class="px-3 py-1.5 text-sm border rounded-lg transition-colors"
                  :class="link.active
                      ? 'bg-indigo-600 text-white border-indigo-600'
                      : 'text-slate-400 border-slate-700 hover:border-slate-500 hover:text-white'"
                  v-html="link.label" />
        </template>
    </div>
</template>

<script setup>
import { Link } from '@inertiajs/vue3'
defineProps({ links: Array })
</script>
