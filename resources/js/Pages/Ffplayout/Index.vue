<template>
  <AppLayout title="ffplayout">
    <div class="max-w-5xl mx-auto px-4 py-6 space-y-6">

      <!-- Header -->
      <div class="flex items-center justify-between">
        <div>
          <h1 class="text-2xl font-bold text-white">ffplayout</h1>
          <p class="text-sm text-slate-400 mt-0.5">24/7 broadcast playout — overlays, downloads &amp; playlists</p>
        </div>
        <a href="http://158.69.0.203:8787" target="_blank"
           class="px-4 py-2 bg-indigo-600 hover:bg-indigo-500 text-white text-sm rounded-lg transition">
          Open ffplayout UI ↗
        </a>
      </div>

      <!-- Offline banner -->
      <div v-if="!available" class="bg-red-900/40 border border-red-700 rounded-xl p-5 text-center space-y-2">
        <p class="text-red-300 font-semibold">ffplayout is unreachable</p>
        <p class="text-red-400 text-sm">The ffplayout database at <code class="text-red-300">/home/ffpu/db/ffplayout.db</code> could not be found.<br>Check that the ffplayout service is running on the server.</p>
        <code class="block text-xs text-slate-400 mt-2">systemctl status ffplayout</code>
      </div>

      <!-- Channel tabs -->
      <div v-if="available" class="flex gap-2">
        <button v-for="ch in channels" :key="ch.id"
                @click="switchChannel(ch.id)"
                :class="activeChannel === ch.id ? 'bg-indigo-600 text-white' : 'bg-slate-700 text-slate-300 hover:bg-slate-600'"
                class="px-5 py-2 rounded-lg text-sm font-medium transition">
          {{ ch.name }}
          <span :class="ch.active ? 'bg-green-500' : 'bg-slate-500'"
                class="ml-2 inline-block w-2 h-2 rounded-full"></span>
        </button>
      </div>

      <!-- Section tabs -->
      <div v-if="available" class="flex gap-1 border-b border-slate-700">
        <button v-for="tab in ['Overlays', 'Download', 'Playlist', 'Media']" :key="tab"
                @click="activeTab = tab"
                :class="activeTab === tab ? 'border-b-2 border-indigo-500 text-white' : 'text-slate-400 hover:text-slate-200'"
                class="px-4 py-2 text-sm font-medium transition -mb-px">
          {{ tab }}
        </button>
      </div>

      <!-- ── OVERLAYS TAB ── -->
      <div v-if="available && activeTab === 'Overlays'" class="space-y-5">

        <!-- Logo -->
        <div class="bg-slate-800 rounded-xl p-5 space-y-4">
          <div class="flex items-center justify-between">
            <h2 class="text-sm font-semibold text-slate-300 uppercase tracking-wide">Channel Logo</h2>
            <label class="flex items-center gap-2 cursor-pointer">
              <span class="text-xs text-slate-400">Enable</span>
              <div @click="ov.logo_enabled = !ov.logo_enabled"
                   :class="ov.logo_enabled ? 'bg-indigo-600' : 'bg-slate-600'"
                   class="relative w-10 h-5 rounded-full transition cursor-pointer">
                <span :class="ov.logo_enabled ? 'translate-x-5' : 'translate-x-0.5'"
                      class="absolute top-0.5 w-4 h-4 bg-white rounded-full shadow transition-transform duration-200 block"></span>
              </div>
            </label>
          </div>

          <div class="grid grid-cols-2 gap-4">
            <!-- Logo upload -->
            <div class="space-y-2">
              <label class="text-xs text-slate-400">Logo file (PNG recommended)</label>
              <div class="flex items-center gap-2">
                <label class="cursor-pointer px-3 py-2 bg-slate-700 hover:bg-slate-600 text-slate-300 text-xs rounded-lg transition">
                  Choose file
                  <input type="file" accept=".png,.jpg,.jpeg,.gif,.svg" class="hidden" @change="onLogoFile" />
                </label>
                <span class="text-xs text-slate-400 truncate">{{ logoFileName || ov.logo_path || 'No file' }}</span>
              </div>
              <div v-if="logoPreviewUrl" class="mt-2">
                <img :src="logoPreviewUrl" class="max-h-16 rounded border border-slate-600" />
              </div>
            </div>

            <!-- Opacity -->
            <div class="space-y-2">
              <label class="text-xs text-slate-400">Opacity — {{ Math.round(ov.logo_opacity * 100) }}%</label>
              <input type="range" min="0" max="1" step="0.05" v-model.number="ov.logo_opacity"
                     class="w-full accent-indigo-500" />
            </div>
          </div>

          <!-- Position picker -->
          <div class="space-y-2">
            <label class="text-xs text-slate-400">Position</label>
            <div class="grid grid-cols-3 gap-1 w-36">
              <button v-for="pos in logoPositions" :key="pos.label"
                      @click="ov.logo_position = pos.value"
                      :class="ov.logo_position === pos.value ? 'bg-indigo-600 text-white' : 'bg-slate-700 text-slate-400 hover:bg-slate-600'"
                      class="h-9 rounded text-xs transition flex items-center justify-center">
                {{ pos.label }}
              </button>
            </div>
            <div class="flex items-center gap-2 mt-1">
              <span class="text-xs text-slate-500">Custom:</span>
              <input v-model="ov.logo_position" type="text" placeholder="W-w-12:12"
                     class="flex-1 bg-slate-700 border border-slate-600 rounded px-2 py-1 text-xs text-white focus:outline-none focus:border-indigo-500" />
            </div>
          </div>

          <!-- Scale -->
          <div class="space-y-1">
            <label class="text-xs text-slate-400">Scale (e.g. <code class="text-indigo-400">100:-1</code> = 100px wide, keep ratio)</label>
            <input v-model="ov.logo_scale" type="text" placeholder="100:-1"
                   class="w-full bg-slate-700 border border-slate-600 rounded-lg px-3 py-2 text-xs text-white focus:outline-none focus:border-indigo-500" />
          </div>
        </div>

        <!-- Ticker Lines -->
        <div class="bg-slate-800 rounded-xl p-5 space-y-4">
          <div class="flex items-center justify-between">
            <div>
              <h2 class="text-sm font-semibold text-slate-300 uppercase tracking-wide">Ticker Lines</h2>
              <p class="text-xs text-slate-500 mt-0.5">Each line is a separate bar stacked from the bottom. Line 1 = bottom.</p>
            </div>
            <button @click="addTickerLine"
                    class="px-3 py-1.5 bg-indigo-600 hover:bg-indigo-500 text-white text-xs rounded-lg transition">+ Add line</button>
          </div>

          <div v-if="ov.ticker_lines.length === 0" class="text-slate-500 text-xs py-3 text-center border border-dashed border-slate-600 rounded-lg">
            No ticker lines. Click "+ Add line" to create one.
          </div>

          <!-- Line cards -->
          <div class="space-y-3">
            <div v-for="(line, idx) in ov.ticker_lines" :key="line.id"
                 class="border rounded-xl overflow-hidden"
                 :class="line.enabled ? 'border-slate-600' : 'border-slate-700 opacity-60'">

              <!-- Line header -->
              <div class="flex items-center gap-2 px-3 py-2 bg-slate-700/60">
                <span class="text-slate-400 text-xs font-mono w-5">{{ idx + 1 }}</span>
                <span v-if="line.source === 'rss'"
                      class="text-[10px] px-1.5 py-0.5 rounded bg-sky-500/20 text-sky-400 font-mono">RSS</span>
                <span v-else
                      class="text-[10px] px-1.5 py-0.5 rounded bg-slate-500/20 text-slate-400 font-mono">MANUAL</span>
                <div @click="line.enabled = !line.enabled"
                     :class="line.enabled ? 'bg-indigo-600' : 'bg-slate-600'"
                     class="relative w-8 h-4 rounded-full transition cursor-pointer flex-shrink-0">
                  <span :class="line.enabled ? 'translate-x-4' : 'translate-x-0.5'"
                        class="absolute top-0.5 w-3 h-3 bg-white rounded-full shadow transition-transform duration-200 block"></span>
                </div>
                <input v-model="line.label_text" type="text" placeholder="Label (e.g. BREAKING)"
                       class="flex-1 bg-slate-700 border border-slate-600 rounded px-2 py-1 text-xs text-white placeholder-slate-500 focus:outline-none focus:border-indigo-500" />
                <div class="flex gap-1">
                  <button @click="moveLineUp(idx)" :disabled="idx === 0"
                          class="px-1.5 py-1 text-slate-400 hover:text-white disabled:opacity-30 text-xs">↑</button>
                  <button @click="moveLineDown(idx)" :disabled="idx === ov.ticker_lines.length - 1"
                          class="px-1.5 py-1 text-slate-400 hover:text-white disabled:opacity-30 text-xs">↓</button>
                  <button @click="removeTickerLine(idx)"
                          class="px-1.5 py-1 text-rose-400 hover:text-rose-300 text-xs">✕</button>
                </div>
              </div>

              <!-- Live preview bar -->
              <div class="px-3 py-1 flex items-center gap-2 min-h-[36px]"
                   :style="{ background: line.bg_color, opacity: line.bg_opacity }">
                <span v-if="line.label_text"
                      class="flex-shrink-0 px-2 py-0.5 rounded text-xs font-bold"
                      :style="{ background: line.label_bg_color, color: line.label_color }">
                  {{ line.label_text }}
                </span>
                <img v-if="line.label_image" :src="labelImageUrl(line.label_image)"
                     class="h-6 w-auto flex-shrink-0 rounded" />
                <span class="truncate font-bold"
                      :style="{ color: line.text_color, fontSize: line.font_size + 'px' }">
                  {{ line.text || '(scrolling text preview)' }}
                </span>
              </div>

              <!-- Line body -->
              <div class="p-3 space-y-3 bg-slate-800">
                <!-- Text -->
                <div class="space-y-1">
                  <div class="flex items-center justify-between">
                    <label class="text-xs text-slate-400">Scrolling text</label>
                    <div class="flex items-center gap-2">
                      <span v-if="line.source === 'rss'" class="text-xs text-sky-400">
                        <template v-if="line.edited">Edited — kept on air</template>
                        <template v-else>Fetched headline</template>
                      </span>
                      <button @click="pushLineTextLive(line)" :disabled="lineUpdating === line.id"
                              class="px-2 py-0.5 bg-emerald-700 hover:bg-emerald-600 disabled:opacity-50 text-white text-xs rounded transition">
                        {{ lineUpdating === line.id ? '…' : '⚡ Push live' }}
                      </button>
                      <span v-if="lineUpdated === line.id" class="text-green-400 text-xs">✓</span>
                    </div>
                  </div>
                  <textarea v-model="line.text" rows="2" @input="markLineEdited(line)"
                          :placeholder="line.source === 'rss' ? 'Fetched headline — push live to send' : 'Enter scrolling text…'"
                            class="w-full bg-slate-700 border border-slate-600 rounded px-2 py-1.5 text-xs text-white placeholder-slate-500 resize-none focus:outline-none focus:border-indigo-500"></textarea>
                </div>

                <!-- Style grid -->
                <div class="grid grid-cols-2 gap-3">
                  <div class="space-y-1">
                    <label class="text-xs text-slate-400">Text color</label>
                    <div class="flex items-center gap-1.5">
                      <input type="color" v-model="line.text_color" class="w-7 h-7 rounded cursor-pointer border-0 bg-transparent" />
                      <input type="text" v-model="line.text_color" maxlength="7"
                             class="flex-1 bg-slate-700 border border-slate-600 rounded px-2 py-1 text-xs text-white focus:outline-none focus:border-indigo-500" />
                    </div>
                  </div>
                  <div class="space-y-1">
                    <label class="text-xs text-slate-400">Bar color</label>
                    <div class="flex items-center gap-1.5">
                      <input type="color" v-model="line.bg_color" class="w-7 h-7 rounded cursor-pointer border-0 bg-transparent" />
                      <input type="text" v-model="line.bg_color" maxlength="7"
                             class="flex-1 bg-slate-700 border border-slate-600 rounded px-2 py-1 text-xs text-white focus:outline-none focus:border-indigo-500" />
                    </div>
                  </div>
                  <div class="space-y-1">
                    <label class="text-xs text-slate-400">Bar opacity — {{ Math.round(line.bg_opacity * 100) }}%</label>
                    <input type="range" min="0" max="1" step="0.05" v-model.number="line.bg_opacity"
                           class="w-full accent-indigo-500" />
                  </div>
                  <div class="space-y-1">
                    <label class="text-xs text-slate-400">Font size — {{ line.font_size }}px</label>
                    <input type="range" min="12" max="56" step="1" v-model.number="line.font_size"
                           class="w-full accent-indigo-500" />
                  </div>
                </div>

                <!-- Label styling (shown when label_text is set) -->
                <div v-if="line.label_text" class="grid grid-cols-2 gap-3 pt-1 border-t border-slate-700">
                  <div class="space-y-1">
                    <label class="text-xs text-slate-400">Label text color</label>
                    <div class="flex items-center gap-1.5">
                      <input type="color" v-model="line.label_color" class="w-7 h-7 rounded cursor-pointer border-0 bg-transparent" />
                      <input type="text" v-model="line.label_color" maxlength="7"
                             class="flex-1 bg-slate-700 border border-slate-600 rounded px-2 py-1 text-xs text-white focus:outline-none focus:border-indigo-500" />
                    </div>
                  </div>
                  <div class="space-y-1">
                    <label class="text-xs text-slate-400">Label bg color</label>
                    <div class="flex items-center gap-1.5">
                      <input type="color" v-model="line.label_bg_color" class="w-7 h-7 rounded cursor-pointer border-0 bg-transparent" />
                      <input type="text" v-model="line.label_bg_color" maxlength="7"
                             class="flex-1 bg-slate-700 border border-slate-600 rounded px-2 py-1 text-xs text-white focus:outline-none focus:border-indigo-500" />
                    </div>
                  </div>
                </div>

                <!-- Label image upload -->
                <div class="flex items-center gap-3 pt-1 border-t border-slate-700">
                  <label class="text-xs text-slate-400">Label image</label>
                  <label class="cursor-pointer px-2 py-1 bg-slate-700 hover:bg-slate-600 text-slate-300 text-xs rounded transition">
                    {{ line.label_image ? 'Change image' : 'Upload image' }}
                    <input type="file" accept=".png,.jpg,.jpeg,.gif,.svg" class="hidden"
                           @change="onLabelImage($event, line)" />
                  </label>
                  <span v-if="line.label_image" class="text-xs text-slate-400 truncate max-w-[120px]">{{ line.label_image }}</span>
                  <button v-if="line.label_image" @click="line.label_image = ''"
                          class="text-rose-400 hover:text-rose-300 text-xs">✕ Remove</button>
                </div>

                <!-- Fetched line info (no manual fetch here — global controls below) -->
                <div v-if="line.source === 'rss'" class="flex items-center gap-3 pt-1 border-t border-slate-700">
                  <span class="text-xs text-slate-500">
                    Fetched from news feeds{{ line.edited ? ' · edits are kept across refreshes' : '' }} — delete below to keep it from returning.
                  </span>
                </div>
              </div>
            </div>
          </div>

          <!-- Fetched news management -->
          <div class="flex flex-wrap items-center gap-3 pt-3 border-t border-slate-700">
            <label class="text-xs text-slate-400">Max headlines on air</label>
            <input type="number" min="1" max="20" v-model.number="ov.rss_max_lines"
                   class="w-20 bg-slate-700 border border-slate-600 rounded px-2 py-1 text-xs text-white focus:outline-none focus:border-indigo-500" />
            <button @click="fetchRssNow" :disabled="rssFetching"
                    class="px-3 py-1 bg-sky-700 hover:bg-sky-600 disabled:opacity-50 text-white text-xs rounded-lg transition">
              {{ rssFetching ? 'Fetching…' : '🔄 Fetch news now' }}
            </button>
            <button @click="clearFetchedLines"
                    class="px-3 py-1 bg-rose-800 hover:bg-rose-700 text-white text-xs rounded-lg transition">🗑 Clear fetched lines</button>
            <span v-if="rssFetched" class="text-green-400 text-xs">✓ Headlines refreshed</span>
          </div>
          <p class="text-xs text-slate-500">Each fetched headline becomes its own editable RSS line. New headlines beyond the cap are added disabled. Deleted fetched lines stay deleted.</p>
        </div>

        <!-- Overlay Relay -->
        <div class="bg-slate-800 rounded-xl p-5 space-y-4">
          <div class="flex items-center justify-between">
            <div>
              <h2 class="text-sm font-semibold text-slate-300 uppercase tracking-wide">Overlay Relay Engine</h2>
              <p class="text-xs text-slate-500 mt-0.5">Burns ticker + title into the stream via a separate ffmpeg relay (drawtext)</p>
            </div>
            <span class="text-xs font-mono px-2 py-1 rounded-lg"
                  :class="relayRunning ? 'bg-green-500/20 text-green-400' : 'bg-red-500/20 text-red-400'">
              {{ relayRunning ? '● RUNNING' : '○ STOPPED' }}
            </span>
          </div>

          <div class="space-y-1">
            <label class="text-xs text-slate-400">RTMP / SRT push destination</label>
            <input v-model="ov.relay_push_url" type="text"
                   placeholder="rtmp://host/live/stream or srt://host:port?mode=caller"
                   class="w-full bg-slate-700 border border-slate-600 rounded-lg px-3 py-2 text-xs text-white placeholder-slate-500 focus:outline-none focus:border-indigo-500" />
          </div>

          <div class="flex items-center gap-3">
            <button @click="relayStart" :disabled="relayBusy || !ov.relay_push_url"
                    class="px-4 py-1.5 bg-emerald-700 hover:bg-emerald-600 disabled:opacity-50 text-white text-xs rounded-lg transition">
              {{ relayBusy ? 'Working…' : '▶ Start relay' }}
            </button>
            <button @click="relayStop" :disabled="relayBusy"
                    class="px-4 py-1.5 bg-rose-700 hover:bg-rose-600 disabled:opacity-50 text-white text-xs rounded-lg transition">
              {{ relayBusy ? 'Working…' : '■ Stop relay' }}
            </button>
            <span v-if="relayMsg" class="text-xs" :class="relayError ? 'text-red-400' : 'text-green-400'">{{ relayMsg }}</span>
          </div>

          <p class="text-xs text-slate-500">
            Reads ffplayout's HLS output, applies the ticker/title overlays with
            <code class="text-slate-400">/usr/bin/ffmpeg</code> (drawtext), and pushes to your destination.
            Text edits go live instantly; visual style changes need a relay restart.
          </p>
        </div>

        <!-- Save button -->
        <div class="flex items-center gap-3">
          <button @click="saveOverlay"
                  :disabled="overlaySaving"
                  class="px-6 py-2 bg-indigo-600 hover:bg-indigo-500 disabled:opacity-50 text-white text-sm rounded-lg transition font-medium">
            {{ overlaySaving ? 'Saving…' : 'Save & Apply' }}
          </button>
          <span v-if="overlaySaved" class="text-green-400 text-sm">✓ Saved</span>
          <span v-if="overlayError" class="text-red-400 text-sm">{{ overlayError }}</span>
        </div>
      </div>

      <!-- ── DOWNLOAD TAB ── -->
      <div v-if="available && activeTab === 'Download'" class="bg-slate-800 rounded-xl p-5 space-y-4">
        <h2 class="text-sm font-semibold text-slate-300 uppercase tracking-wide">Download video from URL</h2>

        <div class="flex gap-3">
          <input v-model="urlInput" type="url" placeholder="https://example.com/video.mp4 or HLS .m3u8"
                 @keydown.enter="startDownload"
                 class="flex-1 bg-slate-700 border border-slate-600 rounded-lg px-3 py-2 text-sm text-white placeholder-slate-400 focus:outline-none focus:border-indigo-500" />
          <input v-model="titleInput" type="text" placeholder="Title (optional)"
                 class="w-48 bg-slate-700 border border-slate-600 rounded-lg px-3 py-2 text-sm text-white placeholder-slate-400 focus:outline-none focus:border-indigo-500" />
          <button @click="startDownload" :disabled="!urlInput || downloading"
                  class="px-4 py-2 bg-indigo-600 hover:bg-indigo-500 disabled:opacity-50 text-white text-sm rounded-lg transition">
            {{ downloading ? 'Queuing…' : 'Download' }}
          </button>
        </div>

        <p v-if="downloadError" class="text-red-400 text-sm">{{ downloadError }}</p>

        <div v-if="queue.length" class="space-y-2">
          <div v-for="item in queue" :key="item.id"
               class="flex items-center gap-3 bg-slate-700/50 rounded-lg px-3 py-2 text-sm">
            <span class="text-lg">{{ item.status === 'ready' ? '✅' : item.status === 'failed' ? '❌' : '⬇️' }}</span>
            <span class="flex-1 text-slate-200 truncate">{{ item.title }}</span>
            <span :class="{ 'text-yellow-400': item.status === 'downloading' || item.status === 'queued', 'text-green-400': item.status === 'ready', 'text-red-400': item.status === 'failed' }"
                  class="text-xs font-medium capitalize">{{ item.status }}</span>
            <span v-if="item.status === 'ready' && item.duration" class="text-slate-400 text-xs">{{ formatDuration(item.duration) }}</span>
            <button v-if="item.status === 'ready' || item.status === 'failed'"
                    @click="removeFromQueue(item.id)" class="text-slate-500 hover:text-slate-300 text-xs">✕</button>
          </div>
        </div>
      </div>

      <!-- ── PLAYLIST TAB ── -->
      <div v-if="available && activeTab === 'Playlist'" class="bg-slate-800 rounded-xl p-5 space-y-3">
        <div class="flex items-center justify-between">
          <h2 class="text-sm font-semibold text-slate-300 uppercase tracking-wide">Today's playlist — {{ today }}</h2>
          <button @click="loadPlaylist" class="text-xs text-indigo-400 hover:text-indigo-300">↻ Refresh</button>
        </div>
        <div v-if="playlist.length === 0" class="text-slate-500 text-sm py-4 text-center">No items yet.</div>
        <div v-else class="space-y-1">
          <div v-for="(item, i) in playlist" :key="i"
               class="flex items-center gap-3 bg-slate-700/40 rounded-lg px-3 py-2 text-sm">
            <span class="text-slate-500 w-6 text-right text-xs">{{ i + 1 }}</span>
            <span class="text-slate-400 text-xs font-mono w-20">{{ item.in }}</span>
            <span class="flex-1 text-slate-200 truncate">{{ basename(item.source) }}</span>
            <span class="text-slate-400 text-xs font-mono">{{ item.duration }}</span>
          </div>
        </div>
      </div>

      <!-- ── MEDIA TAB ── -->
      <div v-if="available && activeTab === 'Media'" class="bg-slate-800 rounded-xl p-5 space-y-3">
        <div class="flex items-center justify-between">
          <h2 class="text-sm font-semibold text-slate-300 uppercase tracking-wide">Media library</h2>
          <button @click="loadMedia" class="text-xs text-indigo-400 hover:text-indigo-300">↻ Refresh</button>
        </div>
        <div v-if="mediaFiles.length === 0" class="text-slate-500 text-sm py-4 text-center">No media files yet.</div>
        <div v-else class="space-y-1">
          <div v-for="f in mediaFiles" :key="f.filename"
               class="flex items-center gap-3 bg-slate-700/40 rounded-lg px-3 py-2 text-sm">
            <span class="text-slate-400 text-lg">🎬</span>
            <span class="flex-1 text-slate-200 truncate text-xs">{{ f.filename }}</span>
            <span class="text-slate-400 text-xs">{{ f.size_human }}</span>
            <span class="text-slate-500 text-xs">{{ f.modified }}</span>
          </div>
        </div>
      </div>

    </div>
  </AppLayout>
</template>

<script setup>
import { ref, watch, onMounted, onUnmounted } from 'vue'
import AppLayout from '@/Layouts/AppLayout.vue'
import axios from 'axios'

const props = defineProps({
  channels:  { type: Array,   default: () => [] },
  available: { type: Boolean, default: true },
})

const activeChannel = ref(props.channels[0]?.id ?? 1)
const activeTab     = ref('Overlays')

// Overlay state
const ovDefaults = {
  logo_enabled:      true,
  logo_path:         '00-assets/logo.png',
  logo_scale:        '100:-1',
  logo_opacity:      0.7,
  logo_position:     'W-w-12:12',
  ticker_enabled:    false,
  ticker_text:       '',
  ticker_bg_color:   '#000000',
  ticker_bg_opacity: 0.75,
  ticker_text_color: '#fcd116',
  ticker_font_size:  22,
  title_enabled:     false,
  title_text:        '',
  title_text_color:  '#ffffff',
  title_bg_color:    '#1e293b',
  title_bg_opacity:  0.85,
  title_font_size:   26,
  relay_push_url:    '',
  ticker_lines:      [],
  rss_removed:       [],
  rss_max_lines:     4,
}
const ov = ref({ ...ovDefaults })
const logoFile       = ref(null)
const logoFileName   = ref('')
const logoPreviewUrl = ref('')
const overlaySaving  = ref(false)
const overlaySaved   = ref(false)
const overlayError   = ref('')
const restarting     = ref(false)
const restartMsg     = ref('')
const textUpdating   = ref('')
const textUpdated    = ref('')
const rssFetching    = ref(false)
const rssFetched     = ref(false)
const relayRunning   = ref(false)
const relayBusy      = ref(false)
const relayMsg       = ref('')
const relayError     = ref(false)
const lineUpdating   = ref('')   // line.id being pushed live
const lineUpdated    = ref('')   // line.id just updated

// Logo position presets (ffmpeg drawtext/overlay geometry)
const logoPositions = [
  { label: '↖', value: '12:12' },
  { label: '↑', value: '(W-w)/2:12' },
  { label: '↗', value: 'W-w-12:12' },
  { label: '←', value: '12:(H-h)/2' },
  { label: '·', value: '(W-w)/2:(H-h)/2' },
  { label: '→', value: 'W-w-12:(H-h)/2' },
  { label: '↙', value: '12:H-h-12' },
  { label: '↓', value: '(W-w)/2:H-h-12' },
  { label: '↘', value: 'W-w-12:H-h-12' },
]

// Download state
const urlInput      = ref('')
const titleInput    = ref('')
const downloading   = ref(false)
const downloadError = ref('')
const queue         = ref([])

// Playlist / media
const playlist   = ref([])
const mediaFiles = ref([])
const today      = new Date().toISOString().slice(0, 10)

// Live clock for preview (unused but kept for future use)
let pollTimer    = null
let tickerTimer  = null

onMounted(() => {
  if (!props.available) return
  loadOverlay()
  loadPlaylist()
  loadMedia()
  pollTimer     = setInterval(pollStatuses, 4000)
  relayPoller   = setInterval(refreshRelayStatus, 5000)
  tickerTimer = setInterval(refreshTickerText, 15 * 60 * 1000)
})

onUnmounted(() => {
  clearInterval(pollTimer)
  clearInterval(tickerTimer)
  clearInterval(relayPoller)
})

watch(activeChannel, () => {
  relayMsg.value = ''
  relayError.value = false
  loadOverlay()
  loadPlaylist()
  loadMedia()
  refreshRelayStatus()
})

function switchChannel(id) {
  if (activeChannel.value === id) return
  // Reset before switching so the watcher fires on a clean slate
  ov.value             = { ...ovDefaults }
  logoFile.value       = null
  logoFileName.value   = ''
  logoPreviewUrl.value = ''
  overlaySaved.value   = false
  overlayError.value   = ''
  activeChannel.value  = id
}

// ── Overlay ──────────────────────────────────────────────────────────────────

async function loadOverlay() {
  try {
    const { data } = await axios.get(`/ffplayout/${activeChannel.value}/overlay`)
    // Replace entirely — don't merge — so no stale keys from previous channel
    ov.value = { ...ovDefaults, ...data }
  } catch {}
}

function onLogoFile(e) {
  const file = e.target.files[0]
  if (!file) return
  logoFile.value     = file
  logoFileName.value = file.name
  logoPreviewUrl.value = URL.createObjectURL(file)
}

async function saveOverlay() {
  overlaySaving.value = true
  overlaySaved.value  = false
  overlayError.value  = ''

  try {
    const payload = {
      logo_enabled:      ov.value.logo_enabled,
      logo_position:     ov.value.logo_position,
      logo_opacity:      ov.value.logo_opacity,
      logo_scale:        ov.value.logo_scale || '100:-1',
      ticker_enabled:    ov.value.ticker_enabled,
      ticker_bg_color:   ov.value.ticker_bg_color   || '#000000',
      ticker_bg_opacity: ov.value.ticker_bg_opacity,
      ticker_text_color: ov.value.ticker_text_color || '#fcd116',
      ticker_font_size:  ov.value.ticker_font_size  || 22,
      title_enabled:     ov.value.title_enabled,
      title_text:        ov.value.title_text        || '',
      title_text_color:  ov.value.title_text_color  || '#ffffff',
      title_bg_color:    ov.value.title_bg_color    || '#1e293b',
      title_bg_opacity:  ov.value.title_bg_opacity,
      title_font_size:   ov.value.title_font_size   || 26,
      relay_push_url:    ov.value.relay_push_url    || '',
      ticker_lines:      JSON.stringify(ov.value.ticker_lines || []),
      rss_removed:       JSON.stringify(ov.value.rss_removed || []),
      rss_max_lines:     ov.value.rss_max_lines     || 4,
    }
    let response
    if (logoFile.value) {
      const form = new FormData()
      Object.entries(payload).forEach(([k, v]) => form.append(k, String(v)))
      form.append('logo_file', logoFile.value)
      response = await axios.post(`/ffplayout/${activeChannel.value}/overlay`, form, {
        headers: { 'Content-Type': 'multipart/form-data' },
      })
    } else {
      response = await axios.post(`/ffplayout/${activeChannel.value}/overlay`, payload)
    }
    Object.assign(ov.value, response.data.overlay)
    overlaySaved.value = true
    logoFile.value     = null
    logoFileName.value = ''
    setTimeout(() => { overlaySaved.value = false }, 4000)
  } catch (e) {
    overlayError.value = e.response?.data?.message ?? 'Save failed'
  } finally {
    overlaySaving.value = false
  }
}

async function refreshTickerText() {
  try {
    const { data } = await axios.get(`/ffplayout/${activeChannel.value}/overlay`)
    if (data.ticker_text) ov.value.ticker_text = data.ticker_text
    // Sync RSS line text
    if (data.ticker_lines) ov.value.ticker_lines = data.ticker_lines
  } catch {}
}

// ── Ticker line management ────────────────────────────────────────────────────

function newLineDefaults(id) {
  return {
    id,
    source:         'manual',
    origin_id:      '',
    enabled:        true,
    text:           '',
    text_color:     '#fcd116',
    bg_color:       '#000000',
    bg_opacity:     0.85,
    font_size:      22,
    label_text:     '',
    label_color:    '#ffffff',
    label_bg_color: '#c0392b',
    label_image:    '',
  }
}

function addTickerLine() {
  const id = 'line_' + Date.now().toString(36)
  ov.value.ticker_lines.push(newLineDefaults(id))
}

function removeTickerLine(idx) {
  const line = ov.value.ticker_lines[idx]
  // Remember deleted fetched headlines so a future fetch doesn't re-add them
  if (line && line.source === 'rss' && line.origin_id) {
    if (!ov.value.rss_removed.includes(line.origin_id)) {
      ov.value.rss_removed.push(line.origin_id)
    }
  }
  ov.value.ticker_lines.splice(idx, 1)
}

function clearFetchedLines() {
  const removed = []
  ov.value.ticker_lines = ov.value.ticker_lines.filter((l) => {
    if (l.source === 'rss') {
      if (l.origin_id) removed.push(l.origin_id)
      return false
    }
    return true
  })
  ov.value.rss_removed = [...new Set([...ov.value.rss_removed, ...removed])]
}

function markLineEdited(line) {
  if (line && line.source === 'rss') line.edited = true
}

function moveLineUp(idx) {
  if (idx === 0) return
  const lines = ov.value.ticker_lines
  ;[lines[idx - 1], lines[idx]] = [lines[idx], lines[idx - 1]]
}

function moveLineDown(idx) {
  const lines = ov.value.ticker_lines
  if (idx >= lines.length - 1) return
  ;[lines[idx], lines[idx + 1]] = [lines[idx + 1], lines[idx]]
}

async function pushLineTextLive(line) {
  lineUpdating.value = line.id
  lineUpdated.value  = ''
  try {
    await axios.post(`/ffplayout/${activeChannel.value}/ticker-lines/text`, {
      line_id: line.id,
      text:    line.text,
    })
    lineUpdated.value = line.id
    setTimeout(() => { lineUpdated.value = '' }, 3000)
  } catch {}
  finally { lineUpdating.value = '' }
}

async function onLabelImage(event, line) {
  const file = event.target.files[0]
  if (!file) return
  const form = new FormData()
  form.append('image', file)
  try {
    const { data } = await axios.post(
      `/ffplayout/${activeChannel.value}/ticker-lines/image`,
      form,
      { headers: { 'Content-Type': 'multipart/form-data' } }
    )
    line.label_image = data.filename
  } catch {}
}

function labelImageUrl(filename) {
  // Images are served from the ffplayout media assets dir via a public path
  // Adjust if your nginx serves this differently
  return `/ffplayout-assets/${activeChannel.value}/ticker_labels/${filename}`
}

async function fetchRssNow() {
  rssFetching.value = true
  rssFetched.value  = false
  try {
    await axios.post(`/ffplayout/${activeChannel.value}/rss-fetch`)
    rssFetched.value = true
    setTimeout(() => { rssFetched.value = false }, 4000)
    // Reload overlay to get the fresh ticker text
    await loadOverlay()
  } catch {}
  finally { rssFetching.value = false }
}

async function restartFfplayout() {
  restarting.value = true
  restartMsg.value = ''
  try {
    await axios.post(`/ffplayout/${activeChannel.value}/restart`)
    restartMsg.value = 'Restarted!'
    setTimeout(() => { restartMsg.value = '' }, 4000)
  } catch (e) {
    restartMsg.value = e.response?.data?.message ?? 'Restart failed'
  } finally {
    restarting.value = false
  }
}

// ── Overlay relay ─────────────────────────────────────────────────────────────────

let relayPoller = null

async function refreshRelayStatus() {
  try {
    const { data } = await axios.get(`/ffplayout/${activeChannel.value}/relay/status`)
    relayRunning.value = !!data.running
  } catch {}
}

async function relayStart() {
  relayBusy.value = true
  relayMsg.value  = ''
  relayError.value = false
  try {
    await saveOverlay()
    const { data } = await axios.post(`/ffplayout/${activeChannel.value}/relay/start`)
    relayMsg.value   = data.message
    relayError.value = false
    await refreshRelayStatus()
  } catch (e) {
    relayMsg.value   = e.response?.data?.message ?? 'Relay start failed'
    relayError.value = true
  } finally {
    relayBusy.value = false
  }
}

async function relayStop() {
  relayBusy.value = true
  relayMsg.value  = ''
  relayError.value = false
  try {
    const { data } = await axios.post(`/ffplayout/${activeChannel.value}/relay/stop`)
    relayMsg.value = data.message
    await refreshRelayStatus()
  } catch (e) {
    relayMsg.value   = e.response?.data?.message ?? 'Relay stop failed'
    relayError.value = true
  } finally {
    relayBusy.value = false
  }
}

// ── Download ─────────────────────────────────────────────────────────────────

async function startDownload() {
  if (!urlInput.value) return
  downloading.value   = true
  downloadError.value = ''
  try {
    const { data } = await axios.post(`/ffplayout/${activeChannel.value}/download`, {
      url:   urlInput.value,
      title: titleInput.value || undefined,
    })
    queue.value.unshift({ id: data.download_id, title: data.message.replace('Downloading: ', ''), status: 'queued' })
    urlInput.value   = ''
    titleInput.value = ''
  } catch (e) {
    downloadError.value = e.response?.data?.error ?? 'Download failed'
  } finally {
    downloading.value = false
  }
}

async function pollStatuses() {
  const pending = queue.value.filter(i => i.status === 'queued' || i.status === 'downloading')
  if (!pending.length) return
  try {
    const { data } = await axios.get(`/ffplayout/${activeChannel.value}/download-status`, {
      params: { ids: pending.map(i => i.id) },
    })
    let anyReady = false
    for (const [id, info] of Object.entries(data.statuses)) {
      const item = queue.value.find(i => i.id === id)
      if (item) { item.status = info.status; item.duration = info.duration; if (info.status === 'ready') anyReady = true }
    }
    if (anyReady) { loadPlaylist(); loadMedia() }
  } catch {}
}

// ── Playlist / Media ─────────────────────────────────────────────────────────

async function loadPlaylist() {
  try {
    const { data } = await axios.get(`/ffplayout/${activeChannel.value}/playlist`)
    playlist.value = data.program ?? []
  } catch { playlist.value = [] }
}

async function loadMedia() {
  try {
    const { data } = await axios.get(`/ffplayout/${activeChannel.value}/media`)
    mediaFiles.value = data.files ?? []
  } catch { mediaFiles.value = [] }
}

function removeFromQueue(id) { queue.value = queue.value.filter(i => i.id !== id) }
function basename(path) { return path ? path.split('/').pop() : '' }
function formatDuration(s) {
  const h = Math.floor(s / 3600), m = Math.floor((s % 3600) / 60), sec = Math.floor(s % 60)
  return h > 0 ? `${h}h ${m}m` : m > 0 ? `${m}m ${sec}s` : `${sec}s`
}
</script>
