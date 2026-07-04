<template>
    <div class="min-h-screen bg-slate-950 flex">
        <!-- Sidebar -->
        <aside class="w-64 bg-slate-900 border-r border-slate-800 flex flex-col fixed inset-y-0 left-0 z-50">
            <div class="h-16 flex items-center px-6 border-b border-slate-800">
                <div class="flex items-center gap-2">
                    <div class="w-8 h-8 bg-indigo-500 rounded-lg flex items-center justify-center">
                        <svg class="w-5 h-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                  d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"/>
                        </svg>
                    </div>
                    <span class="text-white font-bold text-lg tracking-tight">SkyMedia</span>
                </div>
            </div>

            <nav class="flex-1 px-3 py-4 space-y-1 overflow-y-auto">
                <SideNavLink v-if="isAdmin" :href="route('dashboard')" :active="$page.component === 'Dashboard'">
                    <template #icon><IconDashboard /></template>
                    Dashboard
                </SideNavLink>
                <SideNavLink :href="route('channels.index')" :active="$page.component?.startsWith('Channels')">
                    <template #icon><IconChannels /></template>
                    Channels
                </SideNavLink>
                <SideNavLink v-if="isAdmin" :href="route('dvr.index')" :active="$page.component?.startsWith('DVR')">
                    <template #icon><IconDvr /></template>
                    DVR Storage
                </SideNavLink>
                <SideNavLink v-if="isAdmin" :href="route('logs.index')" :active="$page.component?.startsWith('Logs')">
                    <template #icon><IconLogs /></template>
                    Event Logs
                </SideNavLink>
                <SideNavLink v-if="isAdmin" :href="route('settings.index')" :active="$page.component?.startsWith('Settings')">
                    <template #icon><IconSettings /></template>
                    Settings
                </SideNavLink>
                <SideNavLink v-if="isAdmin" :href="route('users.index')" :active="$page.component?.startsWith('Users')">
                    <template #icon><IconUsers /></template>
                    Users
                </SideNavLink>
            </nav>

            <div class="p-4 border-t border-slate-800">
                <div class="flex items-center gap-3">
                    <div class="w-8 h-8 bg-indigo-600 rounded-full flex items-center justify-center text-white text-sm font-semibold">
                        {{ $page.props.auth?.user?.name?.charAt(0)?.toUpperCase() }}
                    </div>
                    <div class="flex-1 min-w-0">
                        <p class="text-sm font-medium text-white truncate">{{ $page.props.auth?.user?.name }}</p>
                        <Link :href="route('logout')" method="post" as="button" class="text-xs text-slate-400 hover:text-white transition-colors">
                            Sign out
                        </Link>
                    </div>
                </div>
            </div>
        </aside>

        <!-- Main -->
        <div class="flex-1 ml-64 flex flex-col min-h-screen">
            <header class="h-16 bg-slate-900 border-b border-slate-800 flex items-center px-6 gap-4">
                <div class="flex-1"><slot name="header" /></div>
                <div class="flex items-center gap-2 text-xs text-slate-500">
                    <span class="w-2 h-2 rounded-full bg-green-500 animate-pulse inline-block"></span>
                    System Online
                </div>
            </header>

            <div class="px-6 pt-4 space-y-2" v-if="$page.props.flash?.success || $page.props.flash?.error">
                <div v-if="$page.props.flash?.success"
                     class="flex items-center gap-2 bg-green-500/10 border border-green-500/30 text-green-400 px-4 py-3 rounded-lg text-sm">
                    ✓ {{ $page.props.flash.success }}
                </div>
                <div v-if="$page.props.flash?.error"
                     class="flex items-center gap-2 bg-red-500/10 border border-red-500/30 text-red-400 px-4 py-3 rounded-lg text-sm">
                    ✗ {{ $page.props.flash.error }}
                </div>
            </div>

            <main class="flex-1 p-6">
                <slot />
            </main>
        </div>
    </div>
</template>

<script setup>
import { Link } from '@inertiajs/vue3'
import SideNavLink from '@/Components/SideNavLink.vue'
import { computed } from 'vue'
import { usePage } from '@inertiajs/vue3'

const page = usePage()
const isAdmin = computed(() => Boolean(page.props.auth?.user?.is_admin))

const IconDashboard = { template: `<svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>` }
const IconChannels  = { template: `<svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.111 16.404a5.5 5.5 0 017.778 0M12 20h.01m-7.08-7.071c3.904-3.905 10.236-3.905 14.141 0M1.394 9.393c5.857-5.857 15.355-5.857 21.213 0"/></svg>` }
const IconDvr       = { template: `<svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/></svg>` }
const IconLogs      = { template: `<svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>` }
const IconSettings  = { template: `<svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>` }
const IconUsers     = { template: `<svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197m13.5-9a2.5 2.5 0 11-5 0 2.5 2.5 0 015 0z"/></svg>` }
</script>
