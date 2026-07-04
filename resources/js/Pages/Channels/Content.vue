<template>
    <AppLayout>
        <template #header>
            <div class="flex items-center gap-2 text-sm text-slate-400">
                <Link :href="route('channels.index')">Channels</Link><span>/</span>
                <Link :href="route('channels.show', channel.id)">{{ channel.name }}</Link><span>/</span>
                <span class="text-white">Content Manager</span>
            </div>
        </template>

        <div class="space-y-5">
            <div class="grid grid-cols-1 xl:grid-cols-2 gap-5">
                <Section title="Live Preview">
                    <video ref="player" controls autoplay muted playsinline class="w-full aspect-video bg-black rounded-lg" />
                </Section>
                <Section title="Upload Media">
                    <div class="space-y-4">
                        <form @submit.prevent="uploadVod" class="space-y-2">
                            <label class="text-xs text-slate-400">Add VOD to library</label>
                            <div class="flex gap-2"><input type="file" accept="video/*,.mkv,.ts" @change="vodForm.file=$event.target.files[0]" class="form-input flex-1" required /><button class="btn">Upload VOD</button></div>
                            <p v-if="vodForm.errors.file" class="text-xs text-red-400">{{ vodForm.errors.file }}</p>
                        </form>
                        <form @submit.prevent="uploadLogo" class="space-y-2">
                            <label class="text-xs text-slate-400">Add transparent logo</label>
                            <div class="flex gap-2"><input type="file" accept="image/png,image/webp,image/jpeg" @change="logoForm.file=$event.target.files[0]" class="form-input flex-1" required /><button class="btn">Upload Logo</button></div>
                        </form>
                    </div>
                </Section>
            </div>

            <Section title="Fallback Playlist">
                <p class="text-xs text-slate-500 mb-4">Enabled VODs play from top to bottom and loop while the live publisher is offline.</p>
                <div class="space-y-2">
                    <div v-for="(item,i) in form.playlist" :key="item.id" class="flex items-center gap-3 bg-slate-800/60 rounded-lg px-3 py-2">
                        <input v-model="item.is_active" type="checkbox" class="rounded border-slate-600 bg-slate-900" />
                        <span class="flex-1 text-sm text-slate-200 truncate">{{ item.name }}</span>
                        <span class="text-xs text-slate-500">{{ formatBytes(item.filesize) }}</span>
                        <button @click="move(i,-1)" :disabled="i===0" class="text-slate-400 disabled:opacity-30">↑</button>
                        <button @click="move(i,1)" :disabled="i===form.playlist.length-1" class="text-slate-400 disabled:opacity-30">↓</button>
                        <Link :href="route('channels.content.destroy',[channel.id,item.id])" method="delete" as="button" class="text-xs text-red-400">Remove</Link>
                    </div>
                    <p v-if="!form.playlist.length" class="text-sm text-slate-500 py-5 text-center">Upload VODs to create the fallback playlist.</p>
                </div>
            </Section>

            <Section title="On-air Branding">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                    <FormField label="Channel Logo">
                        <select v-model="form.logo_media_id" class="form-input"><option :value="null">No logo</option><option v-for="logo in logos" :key="logo.id" :value="logo.id">{{ logo.name }}</option></select>
                    </FormField>
                    <FormField label="Logo Position"><select v-model="form.logo_position" class="form-input"><option value="top-left">Top left</option><option value="top-right">Top right</option><option value="bottom-left">Bottom left</option><option value="bottom-right">Bottom right</option></select></FormField>
                    <FormField label="Ticker"><select v-model="form.ticker_enabled" class="form-input"><option :value="false">Disabled</option><option :value="true">Enabled</option></select></FormField>
                    <FormField label="Ticker Text"><input v-model="form.ticker_text" :disabled="!form.ticker_enabled" maxlength="500" class="form-input disabled:opacity-50" placeholder="Breaking news…" /></FormField>
                </div>
            </Section>

            <div class="flex justify-end"><button @click="save" :disabled="form.processing" class="btn px-6">Save Playlist & Branding</button></div>
        </div>
    </AppLayout>
</template>

<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { Link, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import Section from '@/Components/Section.vue'
import FormField from '@/Components/FormField.vue'

const props = defineProps({ channel:Object, previewUrl:String })
const vods = props.channel.media.filter(x => x.type === 'vod')
const logos = computed(() => props.channel.media.filter(x => x.type === 'logo'))
const form = useForm({ playlist: vods.map(x => ({...x})), logo_media_id: props.channel.logo_media_id, logo_position: props.channel.logo_position || 'top-right', ticker_enabled: Boolean(props.channel.ticker_enabled), ticker_text: props.channel.ticker_text || '' })
const vodForm = useForm({ type:'vod', file:null })
const logoForm = useForm({ type:'logo', file:null })
const player = ref(null); let hls = null
function uploadVod(){ vodForm.post(route('channels.content.upload',props.channel.id),{forceFormData:true}) }
function uploadLogo(){ logoForm.post(route('channels.content.upload',props.channel.id),{forceFormData:true}) }
function move(i,d){ const n=i+d; if(n<0||n>=form.playlist.length)return; [form.playlist[i],form.playlist[n]]=[form.playlist[n],form.playlist[i]] }
function save(){ form.put(route('channels.content.update',props.channel.id)) }
function formatBytes(n){ if(!n)return '0 B'; const u=['B','KB','MB','GB']; const i=Math.min(Math.floor(Math.log(n)/Math.log(1024)),3); return `${(n/1024**i).toFixed(1)} ${u[i]}` }
onMounted(async()=>{ if(player.value.canPlayType('application/vnd.apple.mpegurl')) player.value.src=props.previewUrl; else { const {default:Hls}=await import('hls.js'); if(Hls.isSupported()){hls=new Hls();hls.loadSource(props.previewUrl);hls.attachMedia(player.value)} } })
onUnmounted(()=>hls?.destroy())
</script>

<style scoped>.btn{@apply px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm rounded-lg disabled:opacity-50}</style>
