<template>
    <AppLayout>
        <template #header>
            <div class="flex items-center gap-2 text-sm text-slate-400">
                <Link :href="route('channels.index')" class="hover:text-white transition-colors">Channels</Link>
                <span>/</span>
                <Link :href="route('channels.show', channel.id)" class="hover:text-white transition-colors">{{ channel.name }}</Link>
                <span>/</span>
                <span class="text-white">TV Playout</span>
            </div>
        </template>

        <div class="space-y-5">

            <!-- Header card -->
            <div class="bg-slate-900 border border-slate-800 rounded-xl p-6">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <div class="flex items-center gap-3 flex-wrap">
                            <h1 class="text-xl font-bold text-white">{{ channel.name }}</h1>
                            <span class="px-2 py-0.5 text-xs font-bold uppercase tracking-wide rounded"
                                  :class="isRunning ? 'bg-green-500/20 text-green-400 border border-green-500/30' : 'bg-slate-700 text-slate-400 border border-slate-600'">
                                {{ isRunning ? 'ON AIR' : 'OFFLINE' }}
                            </span>
                            <span class="px-2 py-0.5 text-xs font-medium text-slate-400 bg-slate-800 rounded border border-slate-700">
                                TV Playout
                            </span>
                        </div>
                        <p class="text-xs text-slate-500 font-mono mt-1">{{ channel.slug }} — Local VPS playout, no ingest, no push</p>
                    </div>
                    <div class="flex items-center gap-2">
                        <button v-if="!isRunning" @click="startPlayout"
                                class="px-4 py-1.5 text-xs font-semibold bg-green-600/20 text-green-400 border border-green-500/30 rounded-lg hover:bg-green-600/30 transition-colors">
                            ▶ Start Playout
                        </button>
                        <button v-else @click="stopPlayout"
                                class="px-4 py-1.5 text-xs font-semibold bg-red-600/20 text-red-400 border border-red-500/30 rounded-lg hover:bg-red-600/30 transition-colors">
                            ■ Stop Playout
                        </button>
                        <Link :href="route('channels.show', channel.id)"
                              class="px-3 py-1.5 text-xs text-slate-300 border border-slate-700 rounded-lg hover:border-slate-500 transition-colors">
                            ← Back
                        </Link>
                    </div>
                </div>

                <!-- Summary stats -->
                <div class="mt-6 grid grid-cols-2 sm:grid-cols-5 gap-3">
                    <div class="bg-slate-800/60 rounded-lg px-4 py-3">
                        <div class="text-xs text-slate-500 uppercase tracking-wider">Total Runtime</div>
                        <div class="text-lg font-mono text-emerald-400 font-bold mt-1">{{ summary.formatted_total || '00:00:00' }}</div>
                    </div>
                    <div class="bg-slate-800/60 rounded-lg px-4 py-3">
                        <div class="text-xs text-slate-500 uppercase tracking-wider">Playlist Items</div>
                        <div class="text-lg font-mono text-blue-400 font-bold mt-1">{{ items.length }} clips</div>
                    </div>
                    <div class="bg-slate-800/60 rounded-lg px-4 py-3">
                        <div class="text-xs text-slate-500 uppercase tracking-wider">Status</div>
                        <div class="text-lg font-mono mt-1" :class="isRunning ? 'text-green-400' : 'text-slate-500'">
                            {{ isRunning ? 'Streaming' : 'Stopped' }}
                        </div>
                    </div>
                    <div class="bg-slate-800/60 rounded-lg px-4 py-3">
                        <div class="text-xs text-slate-500 uppercase tracking-wider">Ends At</div>
                        <div class="text-lg font-mono text-rose-400 font-bold mt-1">{{ formatTime(summary.end_anchor) }}</div>
                    </div>
                    <div class="bg-slate-800/60 rounded-lg px-4 py-3">
                        <div class="text-xs text-slate-500 uppercase tracking-wider">Output</div>
                        <div class="text-lg font-mono text-cyan-400 font-bold mt-1">HLS → MediaMTX</div>
                    </div>
                </div>

                <!-- Schedule Controls -->
                <div class="mt-4 flex items-end gap-3 flex-wrap">
                    <div v-if="isAdmin" class="flex items-center gap-2">
                        <label class="text-xs text-slate-500">Start Time (optional)</label>
                        <input v-model="customStartTime" type="datetime-local" step="1"
                               class="form-input text-xs font-mono w-56" />
                    </div>
                    <button @click="recalculateSchedule"
                            :disabled="recalculating"
                            class="px-4 py-1.5 text-xs font-semibold bg-amber-600/20 text-amber-400 border border-amber-500/30 rounded-lg hover:bg-amber-600/30 transition-colors disabled:opacity-50">
                        {{ recalculating ? 'Updating…' : '↻ Update Playlist' }}
                    </button>
                    <span v-if="recalcMessage" class="text-xs text-green-400">{{ recalcMessage }}</span>
                    <span v-if="recalcError" class="text-xs text-red-400">{{ recalcError }}</span>
                </div>
            </div>

            <!-- Channel Preview -->
            <div class="bg-slate-900 border border-slate-800 rounded-xl p-6">
                <div class="flex items-center justify-between mb-3">
                    <h2 class="text-sm font-semibold text-white">Live Preview</h2>
                    <span class="text-xs text-slate-500">HLS from MediaMTX</span>
                </div>
                <video ref="previewPlayer" controls autoplay muted playsinline
                       class="w-full aspect-video bg-black rounded-lg" />
                <p class="mt-2 text-xs text-slate-500">
                    Preview becomes available after playout starts and MediaMTX receives segments.
                </p>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-5">

                <!-- Playlist Manager (2 cols) -->
                <div class="lg:col-span-2 space-y-5">

                    <!-- Playlist Rundown -->
                    <div class="bg-slate-900 border border-slate-800 rounded-xl overflow-hidden">
                        <div class="px-6 py-4 border-b border-slate-800">
                            <div class="flex items-center justify-between mb-3">
                                <div>
                                    <h2 class="text-sm font-semibold text-white">Playlist Rundown</h2>
                                    <p class="text-xs text-slate-500 mt-0.5">Drag to reorder. FFmpeg reads this sequence continuously.</p>
                                </div>
                                <label class="px-3 py-1.5 text-xs bg-indigo-600/20 text-indigo-400 border border-indigo-500/30 rounded-lg hover:bg-indigo-600/30 transition-colors cursor-pointer">
                                    + Add Media
                                    <input type="file" accept="video/*,.mkv,.ts,.mov,.webm" @change="uploadMedia"
                                           class="hidden" :disabled="uploading" />
                                </label>
                            </div>
                            <!-- YouTube URL input -->
                            <form @submit.prevent="addYouTube" class="flex gap-2">
                                <input v-model="youtubeUrl" type="url" placeholder="https://www.youtube.com/watch?v=..."
                                       class="flex-1 form-input text-xs font-mono" :disabled="addingYouTube" />
                                <button type="submit" :disabled="!youtubeUrl || addingYouTube"
                                        class="px-3 py-1.5 text-xs bg-red-600/20 text-red-400 border border-red-500/30 rounded-lg hover:bg-red-600/30 transition-colors disabled:opacity-40 whitespace-nowrap">
                                    {{ addingYouTube ? 'Adding…' : '🔗 Add YouTube' }}
                                </button>
                            </form>
                            <p v-if="youtubeError" class="mt-1 text-xs text-red-400">{{ youtubeError }}</p>
                            <p v-if="youtubeSuccess" class="mt-1 text-xs text-green-400">{{ youtubeSuccess }}</p>
                        </div>

                        <!-- Table header -->
                        <div class="grid grid-cols-12 gap-2 bg-slate-800/50 px-6 py-2.5 text-xs font-semibold text-slate-500 uppercase tracking-wider">
                            <div class="col-span-1 text-center">#</div>
                            <div class="col-span-4">Title</div>
                            <div class="col-span-2 font-mono">Duration</div>
                            <div class="col-span-2 font-mono">Air Start</div>
                            <div class="col-span-2 font-mono">Air End</div>
                            <div class="col-span-1"></div>
                        </div>

                        <!-- Playlist items -->
                        <div class="divide-y divide-slate-800/50">
                            <div v-for="(item, index) in items" :key="item.id"
                                 class="grid grid-cols-12 gap-2 px-6 py-3 items-center hover:bg-slate-800/20 transition-colors"
                                 draggable="true"
                                 @dragstart="dragStart(index, $event)"
                                 @dragover.prevent="dragOver(index)"
                                 @drop="drop(index)"
                                 @dragend="dragEnd">
                                <div class="col-span-1 flex items-center justify-center">
                                    <span class="text-xs font-mono text-slate-500">{{ index + 1 }}</span>
                                </div>
                                <div class="col-span-4 truncate text-sm text-slate-200 font-medium">
                                    <span v-if="item.filepath?.startsWith('youtube:')"
                                          class="inline-block px-1.5 py-0.5 bg-red-500/20 text-red-400 text-xs rounded mr-1.5 font-mono">YT</span>
                                    {{ item.title }}
                                </div>
                                <div class="col-span-2 font-mono text-xs text-cyan-400">
                                    {{ item.formatted_duration }}
                                </div>
                                <div class="col-span-2 font-mono text-xs text-emerald-400">
                                    {{ formatTime(item.scheduled_start) }}
                                </div>
                                <div class="col-span-2 font-mono text-xs text-rose-400">
                                    {{ formatTime(item.scheduled_end) }}
                                </div>
                                <div class="col-span-1 flex items-center justify-end gap-1">
                                    <button v-if="index > 0" @click="moveUp(index)"
                                            class="p-1 text-slate-500 hover:text-white transition-colors" title="Move up">↑</button>
                                    <button v-if="index < items.length - 1" @click="moveDown(index)"
                                            class="p-1 text-slate-500 hover:text-white transition-colors" title="Move down">↓</button>
                                    <button @click="removeItem(item)" class="p-1 text-slate-500 hover:text-red-400 transition-colors" title="Remove">✕</button>
                                </div>
                            </div>
                            <div v-if="items.length === 0" class="px-6 py-16 text-center text-slate-500 text-sm">
                                <p>No items in playlist.</p>
                                <p class="mt-1 text-xs text-slate-600">Upload video files above to build your rundown.</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- CG Controls (1 col) -->
                <div class="space-y-5">

                    <!-- Logo -->
                    <div class="bg-slate-900 border border-slate-800 rounded-xl p-6">
                        <h2 class="text-sm font-semibold text-white mb-3">Logo Overlay</h2>
                        <form @submit.prevent="uploadLogo" class="space-y-3">
                            <div class="flex items-center gap-3">
                                <div v-if="channel.logo_media_id" class="w-16 h-16 rounded-lg bg-slate-800 border border-slate-700 flex items-center justify-center">
                                    <span class="text-xs text-green-400">✓ Set</span>
                                </div>
                                <div v-else class="w-16 h-16 rounded-lg bg-slate-800 border border-slate-700 flex items-center justify-center">
                                    <span class="text-xs text-slate-500">No logo</span>
                                </div>
                                <div class="flex-1">
                                    <input type="file" accept="image/*" @change="logoFile = $event.target.files[0]"
                                           class="form-input text-xs" />
                                </div>
                            </div>
                            <button type="submit" :disabled="!logoFile"
                                    class="px-4 py-2 bg-indigo-600 text-white text-xs rounded-lg disabled:opacity-50 w-full">
                                Update Logo
                            </button>
                        </form>
                        <!-- Logo position x/y -->
                        <div class="mt-4 space-y-2">
                            <p class="text-xs text-slate-500">Position (pixels from top-left)</p>
                            <div class="flex gap-2">
                                <div class="flex-1">
                                    <label class="text-xs text-slate-500">X</label>
                                    <input v-model.number="logoX" type="number" min="0" max="3840"
                                           class="form-input text-xs mt-1" placeholder="20" />
                                </div>
                                <div class="flex-1">
                                    <label class="text-xs text-slate-500">Y</label>
                                    <input v-model.number="logoY" type="number" min="0" max="2160"
                                           class="form-input text-xs mt-1" placeholder="20" />
                                </div>
                            </div>
                            <div class="grid grid-cols-2 gap-1">
                                <button type="button" @click="setLogoPreset(20, 20)" class="px-2 py-1 text-xs bg-slate-800 text-slate-400 rounded hover:bg-slate-700">↖ Top Left</button>
                                <button type="button" @click="setLogoPreset(-20, 20)" class="px-2 py-1 text-xs bg-slate-800 text-slate-400 rounded hover:bg-slate-700">↗ Top Right</button>
                                <button type="button" @click="setLogoPreset(20, -20)" class="px-2 py-1 text-xs bg-slate-800 text-slate-400 rounded hover:bg-slate-700">↙ Bottom Left</button>
                                <button type="button" @click="setLogoPreset(-20, -20)" class="px-2 py-1 text-xs bg-slate-800 text-slate-400 rounded hover:bg-slate-700">↘ Bottom Right</button>
                            </div>
                            <button type="button" @click="saveLogoPosition"
                                    class="px-4 py-2 bg-slate-700 hover:bg-slate-600 text-white text-xs rounded-lg w-full">
                                Apply Position
                            </button>
                            <p v-if="logoPositionMessage" class="text-xs text-green-400">{{ logoPositionMessage }}</p>
                        </div>
                    </div>

                    <!-- Ticker -->
                    <div class="bg-slate-900 border border-slate-800 rounded-xl p-6">
                        <div class="flex items-center justify-between mb-3">
                            <h2 class="text-sm font-semibold text-white">Scrolling Ticker</h2>
                            <button @click="toggleTicker"
                                    class="px-2 py-1 text-xs rounded-lg transition-colors"
                                    :class="channel.ticker_enabled ? 'bg-green-500/20 text-green-400 border border-green-500/30' : 'bg-slate-700 text-slate-400 border border-slate-600'">
                                {{ channel.ticker_enabled ? 'ON' : 'OFF' }}
                            </button>
                        </div>
                        <textarea v-model="tickerText" rows="3" placeholder="Breaking news ticker text…"
                                  class="form-input text-xs resize-none"
                                  :disabled="!channel.ticker_enabled" />
                        <button @click="pushTicker" :disabled="!channel.ticker_enabled"
                                class="mt-2 px-4 py-2 bg-blue-600 text-white text-xs rounded-lg disabled:opacity-50 w-full">
                            Push to Air
                        </button>
                        <p v-if="tickerMessage" class="mt-1 text-xs text-green-400">{{ tickerMessage }}</p>
                    </div>

                    <!-- Encoding Settings -->
                    <div class="bg-slate-900 border border-slate-800 rounded-xl p-6">
                        <h2 class="text-sm font-semibold text-white mb-3">Output Encoding</h2>
                        <div class="space-y-3">
                            <div>
                                <div class="text-xs text-slate-500">Video</div>
                                <div class="text-sm font-mono text-slate-300">H.264 · {{ channel.push_video_bitrate || 3000 }} kbps</div>
                            </div>
                            <div>
                                <div class="text-xs text-slate-500">Audio</div>
                                <div class="text-sm font-mono text-slate-300">AAC · {{ channel.push_audio_bitrate || 128 }} kbps · {{ channel.push_audio_samplerate || 48000 }} Hz</div>
                            </div>
                            <div>
                                <div class="text-xs text-slate-500">Segment Duration</div>
                                <div class="text-sm font-mono text-slate-300">{{ channel.segment_duration || 2 }}s</div>
                            </div>
                            <div>
                                <div class="text-xs text-slate-500">HLS Output</div>
                                <div class="text-xs font-mono text-cyan-400 break-all">live.m3u8 → MediaMTX</div>
                            </div>
                        </div>
                    </div>

                    <!-- Playout Log -->
                    <div class="bg-slate-900 border border-slate-800 rounded-xl p-6">
                        <div class="flex items-center justify-between mb-3">
                            <h2 class="text-sm font-semibold text-white">Engine Log</h2>
                            <button @click="fetchLog" class="text-xs text-slate-400 hover:text-white">Refresh</button>
                        </div>
                        <pre v-if="engineLog" class="bg-slate-950 rounded-lg p-3 text-xs text-slate-300 font-mono whitespace-pre-wrap overflow-auto max-h-48 border border-slate-800">{{ engineLog }}</pre>
                        <p v-else class="text-xs text-slate-500">Click refresh to load the FFmpeg log.</p>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { ref, reactive, onMounted, onUnmounted } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'

const props = defineProps({
    channel: Object,
    items: Array,
    summary: Object,
    isRunning: Boolean,
    previewUrl: String,
    isAdmin: Boolean,
})

const items = ref([...props.items])
const isRunning = ref(props.isRunning)
const tickerText = ref(props.channel.ticker_text || '')
const tickerMessage = ref('')

// Parse stored "x:y" or named preset into x/y numbers
function parseLogoPosition(pos) {
    if (!pos) return { x: 20, y: 20 }
    const match = pos.match(/^(-?\d+):(-?\d+)$/)
    if (match) return { x: parseInt(match[1]), y: parseInt(match[2]) }
    const presets = { 'top-left': [20,20], 'top-right': [-20,20], 'bottom-left': [20,-20], 'bottom-right': [-20,-20] }
    const [x, y] = presets[pos] || [20, 20]
    return { x, y }
}
const parsedPos = parseLogoPosition(props.channel.logo_position)
const logoX = ref(parsedPos.x)
const logoY = ref(parsedPos.y)
const logoFile = ref(null)
const logoPositionMessage = ref('')
const uploading = ref(false)
const youtubeUrl = ref('')
const addingYouTube = ref(false)
const youtubeError = ref('')
const youtubeSuccess = ref('')
const engineLog = ref('')
const previewPlayer = ref(null)
const customStartTime = ref('')
const recalculating = ref(false)
const recalcMessage = ref('')
const recalcError = ref('')
let hlsPlayer = null
let statusTimer = null

// Drag and drop
const dragIndex = ref(null)

function dragStart(index, event) {
    dragIndex.value = index
    event.dataTransfer.effectAllowed = 'move'
}

function dragOver(index) {
    // Visual feedback handled by CSS
}

function drop(index) {
    if (dragIndex.value === null || dragIndex.value === index) return
    const moved = items.value.splice(dragIndex.value, 1)[0]
    items.value.splice(index, 0, moved)
    saveReorder()
    dragIndex.value = null
}

function dragEnd() {
    dragIndex.value = null
}

function moveUp(index) {
    if (index <= 0) return
    const arr = [...items.value]
    ;[arr[index - 1], arr[index]] = [arr[index], arr[index - 1]]
    items.value = arr
    saveReorder()
}

function moveDown(index) {
    if (index >= items.value.length - 1) return
    const arr = [...items.value]
    ;[arr[index], arr[index + 1]] = [arr[index + 1], arr[index]]
    items.value = arr
    saveReorder()
}

async function saveReorder() {
    try {
        const csrfToken = document.cookie.split('; ').find(row => row.startsWith('XSRF-TOKEN='))?.split('=')[1]
        const res = await fetch(route('channels.playout.reorder', props.channel.id), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': csrfToken ? decodeURIComponent(csrfToken) : '',
            },
            body: JSON.stringify({
                items: items.value.map((item, idx) => ({ id: item.id, sort_order: idx + 1 })),
            }),
        })
        const data = await res.json()
        if (data.success) {
            items.value = data.items
            Object.assign(props.summary, data.summary)
        }
    } catch (e) {
        console.error('Reorder failed', e)
    }
}

async function recalculateSchedule() {
    recalculating.value = true
    recalcMessage.value = ''
    recalcError.value = ''
    try {
        const csrfToken = document.cookie.split('; ').find(row => row.startsWith('XSRF-TOKEN='))?.split('=')[1]
        const body = {}
        if (customStartTime.value) {
            body.start_time = customStartTime.value
        }
        const res = await fetch(route('channels.playout.recalculate', props.channel.id), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': csrfToken ? decodeURIComponent(csrfToken) : '',
            },
            body: JSON.stringify(body),
        })
        const data = await res.json()
        if (data.success) {
            items.value = data.items
            Object.assign(props.summary, data.summary)
            recalcMessage.value = data.message || 'Playlist updated!'
            setTimeout(() => recalcMessage.value = '', 4000)
        } else {
            recalcError.value = data.error || 'Failed to recalculate'
        }
    } catch (e) {
        recalcError.value = 'Network error: ' + e.message
    } finally {
        recalculating.value = false
    }
}

async function uploadMedia(event) {
    const file = event.target.files[0]
    if (!file) return
    uploading.value = true
    try {
        const form = new FormData()
        form.append('media', file)
        const csrfToken = document.cookie.split('; ').find(row => row.startsWith('XSRF-TOKEN='))?.split('=')[1]
        await fetch(route('channels.playout.items.store', props.channel.id), {
            method: 'POST',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': csrfToken ? decodeURIComponent(csrfToken) : '',
            },
            body: form,
        })
        router.reload({ only: ['items', 'summary'] })
    } catch (e) {
        console.error('Upload failed', e)
    } finally {
        uploading.value = false
        event.target.value = ''
    }
}

async function addYouTube() {
    if (!youtubeUrl.value) return
    addingYouTube.value = true
    youtubeError.value = ''
    youtubeSuccess.value = ''
    try {
        const csrfToken = document.cookie.split('; ').find(row => row.startsWith('XSRF-TOKEN='))?.split('=')[1]
        const res = await fetch(route('channels.playout.youtube', props.channel.id), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': csrfToken ? decodeURIComponent(csrfToken) : '',
            },
            body: JSON.stringify({ youtube_url: youtubeUrl.value }),
        })
        const data = await res.json()
        if (res.ok) {
            youtubeSuccess.value = data.success || 'YouTube video added!'
            youtubeUrl.value = ''
            router.reload({ only: ['items', 'summary'] })
            setTimeout(() => youtubeSuccess.value = '', 4000)
        } else {
            youtubeError.value = data.errors?.youtube_url?.[0] || data.error || 'Failed to add YouTube video'
        }
    } catch (e) {
        youtubeError.value = 'Network error: ' + e.message
    } finally {
        addingYouTube.value = false
    }
}

function removeItem(item) {
    if (!confirm(`Remove "${item.title}" from playlist?`)) return
    router.delete(route('channels.playout.items.destroy', [props.channel.id, item.id]), {
        onSuccess: () => {
            items.value = items.value.filter(i => i.id !== item.id)
        },
    })
}

async function startPlayout() {
    try {
        const csrfToken = document.cookie.split('; ').find(row => row.startsWith('XSRF-TOKEN='))?.split('=')[1]
        const res = await fetch(route('channels.playout.start', props.channel.id), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': csrfToken ? decodeURIComponent(csrfToken) : '',
            },
        })
        const data = await res.json()
        if (data.success) {
            isRunning.value = true
            pollStatus()
        } else {
            alert(data.error || 'Failed to start')
        }
    } catch (e) {
        console.error('Start failed', e)
    }
}

async function stopPlayout() {
    if (!confirm('Stop the TV playout engine?')) return
    try {
        const csrfToken = document.cookie.split('; ').find(row => row.startsWith('XSRF-TOKEN='))?.split('=')[1]
        await fetch(route('channels.playout.stop', props.channel.id), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': csrfToken ? decodeURIComponent(csrfToken) : '',
            },
        })
        isRunning.value = false
    } catch (e) {
        console.error('Stop failed', e)
    }
}

async function pollStatus() {
    try {
        const res = await fetch(route('channels.playout.status', props.channel.id))
        const data = await res.json()
        isRunning.value = data.is_running
    } catch {}
}

async function pushTicker() {
    try {
        const csrfToken = document.cookie.split('; ').find(row => row.startsWith('XSRF-TOKEN='))?.split('=')[1]
        const res = await fetch(route('channels.playout.ticker', props.channel.id), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': csrfToken ? decodeURIComponent(csrfToken) : '',
            },
            body: JSON.stringify({ ticker: tickerText.value }),
        })
        const data = await res.json()
        if (data.success) {
            tickerMessage.value = 'Ticker updated!'
            setTimeout(() => tickerMessage.value = '', 3000)
        }
    } catch (e) {
        console.error('Ticker update failed', e)
    }
}

async function toggleTicker() {
    try {
        const csrfToken = document.cookie.split('; ').find(row => row.startsWith('XSRF-TOKEN='))?.split('=')[1]
        const res = await fetch(route('channels.playout.toggle-ticker', props.channel.id), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': csrfToken ? decodeURIComponent(csrfToken) : '',
            },
        })
        const data = await res.json()
        if (data.success) {
            props.channel.ticker_enabled = data.ticker_enabled
        }
    } catch (e) {
        console.error('Toggle ticker failed', e)
    }
}

function setLogoPreset(x, y) {
    logoX.value = x
    logoY.value = y
}

async function saveLogoPosition() {
    try {
        const csrfToken = document.cookie.split('; ').find(r => r.startsWith('XSRF-TOKEN='))?.split('=')[1]
        const res = await fetch(route('channels.playout.logo-position', props.channel.id), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': csrfToken ? decodeURIComponent(csrfToken) : '',
            },
            body: JSON.stringify({ x: logoX.value, y: logoY.value }),
        })
        const data = await res.json()
        if (data.success) {
            logoPositionMessage.value = 'Position applied'
            setTimeout(() => logoPositionMessage.value = '', 3000)
        }
    } catch (e) { console.error(e) }
}

async function uploadLogo() {
    if (!logoFile.value) return
    try {
        const form = new FormData()
        form.append('logo', logoFile.value)
        const csrfToken = document.cookie.split('; ').find(row => row.startsWith('XSRF-TOKEN='))?.split('=')[1]
        await fetch(route('channels.playout.logo', props.channel.id), {
            method: 'POST',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': csrfToken ? decodeURIComponent(csrfToken) : '',
            },
            body: form,
        })
        logoFile.value = null
        router.reload({ only: ['channel'] })
    } catch (e) {
        console.error('Logo upload failed', e)
    }
}

async function fetchLog() {
    try {
        const res = await fetch(route('channels.logs', props.channel.id))
        const data = await res.json()
        engineLog.value = data.map(l => `[${l.created_at}] ${l.event}: ${l.message}`).join('\n')
    } catch (e) {
        engineLog.value = 'Failed to load log'
    }
}

function formatTime(dt) {
    if (!dt) return '--:--:--'
    return new Date(dt).toLocaleTimeString('en-US', { hour12: false, hour: '2-digit', minute: '2-digit', second: '2-digit' })
}

function setupPreview() {
    if (!previewPlayer.value || !props.previewUrl) return

    if (previewPlayer.value.canPlayType('application/vnd.apple.mpegurl')) {
        previewPlayer.value.src = props.previewUrl
        return
    }

    import('hls.js').then(({ default: Hls }) => {
        if (!Hls.isSupported()) return
        hlsPlayer = new Hls({
            liveSyncDurationCount: 3,
            maxBufferLength: 30,
            enableWorker: true,
            manifestLoadingMaxRetry: 10,
            fragLoadingMaxRetry: 10,
        })
        hlsPlayer.loadSource(props.previewUrl)
        hlsPlayer.attachMedia(previewPlayer.value)
        hlsPlayer.on(Hls.Events.ERROR, (_evt, data) => {
            if (!data.fatal) return
            if (data.type === Hls.ErrorTypes.NETWORK_ERROR) {
                hlsPlayer.startLoad()
            } else if (data.type === Hls.ErrorTypes.MEDIA_ERROR) {
                hlsPlayer.recoverMediaError()
            } else {
                hlsPlayer.loadSource(props.previewUrl)
            }
        })
    })
}

onMounted(() => {
    setupPreview()
    statusTimer = setInterval(pollStatus, 5000)
})

onUnmounted(() => {
    clearInterval(statusTimer)
    hlsPlayer?.destroy()
})
</script>
