<!-- v2 -->
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
                    <div v-if="isRunning && nowPlayingTitle" class="bg-slate-800/60 rounded-lg px-4 py-3 col-span-2 sm:col-span-3">
                        <div class="text-xs text-slate-500 uppercase tracking-wider">Now Playing</div>
                        <div class="text-sm font-mono text-yellow-300 font-bold mt-1 truncate" :title="nowPlayingTitle">▶ {{ nowPlayingTitle }}</div>
                    </div>
                    <div class="bg-slate-800/60 rounded-lg px-4 py-3">
                        <div class="text-xs text-slate-500 uppercase tracking-wider">Starts At</div>
                        <div class="text-lg font-mono text-amber-400 font-bold mt-1">{{ formatTime(summary.anchor_start) }}</div>
                    </div>
                    <div class="bg-slate-800/60 rounded-lg px-4 py-3">
                        <div class="text-xs text-slate-500 uppercase tracking-wider">Ends At</div>
                        <div class="text-lg font-mono text-rose-400 font-bold mt-1">{{ formatTime(summary.end_anchor) }}</div>
                    </div>
                    <div class="bg-slate-800/60 rounded-lg px-4 py-3">
                        <div class="text-xs text-slate-500 uppercase tracking-wider">Output</div>
                        <div class="text-lg font-mono text-cyan-400 font-bold mt-1">HLS → MediaMTX</div>
                    </div>
                    <div v-if="channel.push_url" class="bg-slate-800/60 rounded-lg px-4 py-3">
                        <div class="text-xs text-slate-500 uppercase tracking-wider">RTMP Push</div>
                        <div class="text-lg font-mono mt-1" :class="pushRunning ? 'text-green-400' : 'text-red-400'">
                            {{ pushRunning ? 'Pushing' : 'Stopped' }}
                        </div>
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
                                <label class="px-3 py-1.5 text-xs bg-indigo-600/20 text-indigo-400 border border-indigo-500/30 rounded-lg hover:bg-indigo-600/30 transition-colors cursor-pointer" :class="uploading ? 'opacity-60 pointer-events-none' : ''">
                                    {{ uploading ? `Uploading ${uploadProgress}%` : '+ Add Media' }}
                                    <input type="file" accept="video/*,.mkv,.ts,.mov,.webm" @change="uploadMedia"
                                           class="hidden" :disabled="uploading" />
                                </label>
                            </div>
                            <div v-if="uploading" class="mt-2">
                                <div class="w-full bg-slate-800 rounded-full h-2 overflow-hidden">
                                    <div class="bg-indigo-500 h-full rounded-full transition-all duration-300" :style="{ width: uploadProgress + '%' }"></div>
                                </div>
                                <p class="text-[10px] text-slate-500 mt-1">{{ uploadProgress }}% uploaded</p>
                            </div>
                            <!-- URL input (HLS / MP4 / YouTube) -->
                            <div class="flex gap-2">
                                <input v-model="mediaUrl" type="text"
                                       placeholder="Paste URL: HLS (.m3u8), MP4, YouTube, or direct stream"
                                       class="flex-1 form-input text-xs font-mono" :disabled="addingUrl || previewLoading" maxlength="8000"
                                       @keydown.enter="previewMediaUrl" />
                                <button @click="previewMediaUrl" :disabled="!mediaUrl || addingUrl || previewLoading"
                                        class="px-3 py-1.5 text-xs bg-indigo-600/20 text-indigo-400 border border-indigo-500/30 rounded-lg hover:bg-indigo-600/30 transition-colors disabled:opacity-40 whitespace-nowrap">
                                    {{ previewLoading ? 'Checking…' : '+ Add URL' }}
                                </button>
                            </div>
                            <p v-if="urlError" class="mt-1 text-xs text-red-400">{{ urlError }}</p>
                            <p v-if="urlSuccess" class="mt-1 text-xs text-green-400">{{ urlSuccess }}</p>

                            <!-- Preview modal -->
                            <div v-if="urlPreview" class="mt-3 p-3 bg-slate-800 border border-slate-700 rounded-lg">
                                <div class="flex gap-3">
                                    <img v-if="urlPreview.thumbnail" :src="urlPreview.thumbnail" class="w-24 h-14 object-cover rounded flex-shrink-0" />
                                    <div v-else class="w-24 h-14 bg-slate-700 rounded flex-shrink-0 flex items-center justify-center text-slate-500 text-xs">No preview</div>
                                    <div class="flex-1 min-w-0">
                                        <p class="text-xs text-white font-medium truncate">{{ urlPreview.title }}</p>
                                        <p class="text-xs text-slate-400 mt-0.5">{{ urlPreview.duration > 0 ? formatDuration(urlPreview.duration) : 'Duration unknown' }}</p>
                                        <p v-if="urlPreview.playable" class="text-xs text-green-400 mt-0.5">✓ Reachable</p>
                                        <p v-else-if="urlPreview.type === 'hls'" class="text-xs text-red-400 mt-0.5">⚠ Could not probe HLS stream</p>
                                        <p v-else class="text-xs text-yellow-400 mt-0.5">⚠ Duration unknown — ffmpeg will stream directly</p>
                                    </div>
                                </div>
                                <div class="flex gap-2 mt-3">
                                    <button @click="confirmAddUrl" :disabled="addingUrl"
                                            class="px-3 py-1.5 text-xs bg-green-600/20 text-green-400 border border-green-500/30 rounded-lg hover:bg-green-600/30 transition-colors disabled:opacity-40">
                                        {{ addingUrl ? 'Adding…' : '✓ Add to Playlist' }}
                                    </button>
                                    <button @click="urlPreview = null; urlError = ''"
                                            class="px-3 py-1.5 text-xs text-slate-400 border border-slate-700 rounded-lg hover:bg-slate-700 transition-colors">
                                        Cancel
                                    </button>
                                </div>
                            </div>
                            <!-- Loop control -->
                            <div class="flex items-center gap-3 mt-3 pt-3 border-t border-slate-800">
                                <label class="text-xs text-slate-500">Loop</label>
                                <div class="flex items-center gap-1.5">
                                    <button v-for="opt in loopOptions" :key="opt.value" type="button"
                                            @click="setLoop(opt.value)"
                                            :class="['px-2.5 py-1 text-xs rounded-lg border transition-colors',
                                                     playlistLoop === opt.value
                                                         ? 'bg-indigo-600/30 border-indigo-500/50 text-indigo-300'
                                                         : 'bg-slate-800 border-slate-700 text-slate-400 hover:bg-slate-700']">
                                        {{ opt.label }}
                                    </button>
                                    <div class="flex items-center gap-1 ml-1">
                                        <input v-model.number="customLoopValue" type="number" min="1" max="10000"
                                               placeholder="N"
                                               class="w-16 form-input text-xs font-mono text-center"
                                               :disabled="playlistLoop !== 0"
                                               @change="setLoop(customLoopValue || 0)" />
                                        <span class="text-[10px] text-slate-600">×</span>
                                    </div>
                                </div>
                                <span class="text-[10px] text-slate-600 ml-1">{{ loopDescription }}</span>
                            </div>
                        </div>

                        <!-- Table header -->
                        <div class="grid grid-cols-12 gap-2 bg-slate-800/50 px-6 py-2.5 text-xs font-semibold text-slate-500 uppercase tracking-wider">
                            <div class="col-span-1 text-center">#</div>
                            <div class="col-span-4">Title (lower-third)</div>
                            <div class="col-span-2 font-mono">Duration</div>
                            <div class="col-span-2 font-mono">Air Start</div>
                            <div class="col-span-3 text-right">Actions</div>
                        </div>

                        <!-- Playlist items -->
                        <div class="divide-y divide-slate-800/50">
                            <div v-for="(item, index) in items" :key="item.id"
                                 class="hover:bg-slate-800/20 transition-colors"
                                 draggable="true"
                                 @dragstart="dragStart(index, $event)"
                                 @dragover.prevent="dragOver(index)"
                                 @drop="drop(index)"
                                 @dragend="dragEnd">
                                <!-- Normal row -->
                                <div v-if="editingItem?.id !== item.id" class="px-6 py-3 grid grid-cols-12 gap-2 items-center">
                                    <div class="col-span-1 flex items-center justify-center">
                                        <span class="text-xs font-mono text-slate-500">{{ index + 1 }}</span>
                                    </div>
                                    <div class="col-span-4 text-sm text-slate-200 font-medium min-w-0">
                                        <div class="flex items-center gap-1.5">
                                            <span v-if="item.filepath?.startsWith('youtube:')"
                                                  class="inline-block px-1.5 py-0.5 text-xs rounded font-mono flex-shrink-0"
                                                  :class="{
                                                      'bg-red-500/20 text-red-400 border border-red-500/30': downloadStatuses[item.id] === 'ready',
                                                      'bg-amber-500/20 text-amber-400 border border-amber-500/30 animate-pulse': downloadStatuses[item.id] === 'downloading',
                                                      'bg-yellow-500/20 text-yellow-400 border border-yellow-500/30': downloadStatuses[item.id] === 'queued',
                                                      'bg-slate-600/40 text-slate-400 border border-slate-500/30': downloadStatuses[item.id] === 'failed' || !downloadStatuses[item.id],
                                                  }">
                                                {{ downloadStatuses[item.id] === 'ready' ? 'YT ✓' : downloadStatuses[item.id] === 'downloading' ? 'YT ⬇' : downloadStatuses[item.id] === 'queued' ? 'YT …' : downloadStatuses[item.id] === 'failed' ? 'YT ✕' : 'YT' }}
                                            </span>
                                            <span v-if="item.media_group === 'clean'"
                                                  class="inline-block px-1.5 py-0.5 bg-slate-600/60 text-slate-400 text-[10px] rounded flex-shrink-0" title="No overlays">CLEAN</span>
                                            <span class="truncate">{{ item.display_title || item.custom_title || item.title }}</span>
                                        </div>
                                        <div v-if="item.custom_title && item.custom_title !== item.title" class="text-[10px] text-slate-600 truncate mt-0.5">
                                            Original: {{ item.title }}
                                        </div>
                                    </div>
                                    <div class="col-span-2 font-mono text-xs text-cyan-400">
                                        {{ item.formatted_duration }}
                                    </div>
                                    <div class="col-span-2 font-mono text-xs text-emerald-400">
                                        {{ formatTime(item.scheduled_start) }}
                                    </div>
                                    <div class="col-span-3 flex items-center justify-end gap-1">
                                        <button @click="editItemTitle(item)"
                                                class="px-1.5 py-1 text-xs text-slate-500 hover:text-indigo-400 transition-colors" title="Edit title / group">✎</button>
                                        <button @click="probeItem(item)"
                                                :disabled="probeResults[item.id]?.loading"
                                                class="px-1.5 py-1 text-xs transition-colors"
                                                :class="{
                                                    'text-green-400': probeResults[item.id]?.playable === true,
                                                    'text-red-400':   probeResults[item.id]?.playable === false,
                                                    'text-amber-400 animate-pulse': probeResults[item.id]?.loading,
                                                    'text-slate-500 hover:text-cyan-400': !probeResults[item.id],
                                                }"
                                                :title="probeResults[item.id]?.playable === true ? probeResults[item.id].summary : probeResults[item.id]?.playable === false ? probeResults[item.id].error : 'Test if this media can play on the server'">
                                            {{ probeResults[item.id]?.loading ? '…' : probeResults[item.id]?.playable === true ? '✓' : probeResults[item.id]?.playable === false ? '✕' : '▶' }}
                                        </button>
                                        <button v-if="item.filepath?.startsWith('youtube:')" @click="triggerYouTubeDownload(item)"
                                                :disabled="downloadStatuses[item.id] === 'downloading' || downloadStatuses[item.id] === 'ready'"
                                                class="px-1.5 py-1 text-xs transition-colors"
                                                :class="{
                                                    'text-green-400': downloadStatuses[item.id] === 'ready',
                                                    'text-amber-400 animate-pulse': downloadStatuses[item.id] === 'downloading',
                                                    'text-slate-500 hover:text-green-400': downloadStatuses[item.id] !== 'ready' && downloadStatuses[item.id] !== 'downloading',
                                                }"
                                                :title="downloadStatuses[item.id] === 'ready' ? 'Downloaded' : downloadStatuses[item.id] === 'downloading' ? 'Downloading…' : 'Download to disk'">
                                            {{ downloadStatuses[item.id] === 'ready' ? '✓' : downloadStatuses[item.id] === 'downloading' ? '⏳' : '⬇' }}</button>
                                        <button v-if="index > 0" @click="moveUp(index)"
                                                class="p-1 text-slate-500 hover:text-white transition-colors" title="Move up">↑</button>
                                        <button v-if="index < items.length - 1" @click="moveDown(index)"
                                                class="p-1 text-slate-500 hover:text-white transition-colors" title="Move down">↓</button>
                                        <button @click="removeItem(item)" class="p-1 text-slate-500 hover:text-red-400 transition-colors" title="Remove">✕</button>
                                    </div>
                                    <!-- Probe result inline -->
                                    <div v-if="probeResults[item.id] && !probeResults[item.id].loading"
                                         class="col-span-12 px-6 pb-2 -mt-1">
                                        <div :class="probeResults[item.id].playable ? 'text-green-400' : 'text-red-400'" class="text-[10px] font-mono">
                                            <span v-if="probeResults[item.id].playable">✓ OK — {{ probeResults[item.id].summary }}</span>
                                            <span v-else>✕ {{ probeResults[item.id].error }}</span>
                                        </div>
                                    </div>
                                </div>
                                <!-- Inline edit row -->
                                <div v-else class="px-6 py-3 bg-slate-800/40 border-l-2 border-indigo-500">
                                    <div class="flex items-center gap-2 mb-2">
                                        <span class="text-[10px] text-slate-500 truncate flex-1">Original: <span class="text-slate-400 font-mono">{{ item.title }}</span></span>
                                        <button @click="cancelEdit" class="text-xs text-slate-500 hover:text-white flex-shrink-0">✕ Cancel</button>
                                    </div>
                                    <div class="flex items-center gap-2 mb-2">
                                        <input v-model="editTitle" type="text"
                                               placeholder="Display title"
                                               class="flex-1 form-input text-xs"
                                               @keydown.enter="saveItemEdit"
                                               @keydown.escape="cancelEdit"
                                               @vue:mounted="el => { el.focus(); el.select() }" />
                                        <button @click="editTitle = item.title" type="button"
                                                class="px-2 py-1 text-[10px] text-slate-500 hover:text-slate-300 border border-slate-700 rounded flex-shrink-0" title="Reset to original filename">
                                            Reset
                                        </button>
                                    </div>
                                    <div class="flex items-center gap-2">
                                        <span class="text-xs text-slate-500">Group:</span>
                                        <button v-for="g in mediaGroups" :key="g.value"
                                                type="button" @click="editGroup = g.value"
                                                :class="['px-2.5 py-1 text-xs rounded-lg border transition-colors',
                                                         editGroup === g.value
                                                             ? 'bg-indigo-600/30 border-indigo-500/50 text-indigo-300'
                                                             : 'bg-slate-800 border-slate-700 text-slate-400 hover:bg-slate-700']">
                                            {{ g.label }}
                                        </button>
                                        <span class="text-[10px] text-slate-600 ml-1">{{ mediaGroups.find(g => g.value === editGroup)?.hint }}</span>
                                        <button @click="saveItemEdit" :disabled="editSaving"
                                                class="ml-auto px-3 py-1 text-xs bg-indigo-600 hover:bg-indigo-500 text-white rounded-lg disabled:opacity-50 transition-colors">
                                            {{ editSaving ? 'Saving…' : '✓ Save' }}
                                        </button>
                                    </div>
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
                        <div class="flex items-center justify-between mb-4">
                            <h2 class="text-sm font-semibold text-white">Logo Overlay</h2>
                            <button @click="toggleLogo"
                                    :class="['px-2 py-1 text-xs rounded-lg transition-colors border',
                                             logoEnabled ? 'bg-green-500/20 text-green-400 border-green-500/30' : 'bg-slate-700 text-slate-400 border-slate-600']">
                                {{ logoEnabled ? 'ON' : 'OFF' }}
                            </button>
                        </div>

                        <!-- Logo preview + upload -->
                        <div class="flex items-start gap-3 mb-4">
                            <div class="w-20 h-20 rounded-lg bg-slate-800 border border-slate-700 flex items-center justify-center flex-shrink-0 overflow-hidden">
                                <img v-if="logoPreviewUrl" :src="logoPreviewUrl" class="w-full h-full object-contain" />
                                <span v-else class="text-[10px] text-slate-500 text-center px-1">No logo</span>
                            </div>
                            <div class="flex-1 space-y-2">
                                <input type="file" accept="image/png,image/jpeg,image/webp" @change="onLogoFileChange"
                                       class="form-input text-xs" />
                                <div class="flex gap-2">
                                    <button @click="uploadLogo" :disabled="!logoFile || logoUploading"
                                            class="flex-1 px-3 py-1.5 bg-indigo-600 hover:bg-indigo-500 text-white text-xs rounded-lg disabled:opacity-40 transition-colors">
                                        {{ logoUploading ? 'Uploading…' : 'Upload Logo' }}
                                    </button>
                                    <button v-if="channel.logo_media_id" @click="removeLogo"
                                            class="px-3 py-1.5 bg-red-600/20 text-red-400 border border-red-500/30 text-xs rounded-lg hover:bg-red-600/30 transition-colors">
                                        Remove
                                    </button>
                                </div>
                                <p v-if="logoUploadMessage" class="text-xs" :class="logoUploadError ? 'text-red-400' : 'text-green-400'">{{ logoUploadMessage }}</p>
                            </div>
                        </div>

                        <!-- Logo size slider -->
                        <div class="mb-4">
                            <label class="text-[10px] text-slate-500 uppercase tracking-wider">
                                Size: <span class="font-mono text-slate-300">{{ logoScale }}% of video width</span>
                            </label>
                            <input v-model.number="logoScale" type="range" min="1" max="50" step="1"
                                   class="w-full accent-indigo-500 mt-1" />
                            <div class="flex justify-between text-[9px] text-slate-600 mt-0.5">
                                <span>1%</span><span>25%</span><span>50%</span>
                            </div>
                        </div>

                        <!-- Visual canvas position picker -->
                        <div class="space-y-3">
                            <p class="text-[10px] text-slate-500 uppercase tracking-wider">Position</p>
                            <div
                                ref="posCanvas"
                                @click="onCanvasClick"
                                @mousemove="onCanvasHover"
                                @mouseleave="canvasHover = null"
                                class="relative w-full aspect-video bg-slate-950 border border-slate-700 rounded-lg cursor-crosshair overflow-hidden select-none">
                                <!-- Grid lines -->
                                <div class="absolute inset-0 pointer-events-none">
                                    <div class="absolute left-1/3 top-0 bottom-0 border-l border-slate-800/60"></div>
                                    <div class="absolute left-2/3 top-0 bottom-0 border-l border-slate-800/60"></div>
                                    <div class="absolute top-1/3 left-0 right-0 border-t border-slate-800/60"></div>
                                    <div class="absolute top-2/3 left-0 right-0 border-t border-slate-800/60"></div>
                                </div>
                                <span class="absolute top-1 left-1.5 text-[8px] text-slate-700 font-mono pointer-events-none">0,0</span>
                                <span class="absolute top-1 right-1.5 text-[8px] text-slate-700 font-mono pointer-events-none">W,0</span>
                                <span class="absolute bottom-1 left-1.5 text-[8px] text-slate-700 font-mono pointer-events-none">0,H</span>
                                <span class="absolute bottom-1 right-1.5 text-[8px] text-slate-700 font-mono pointer-events-none">W,H</span>
                                <!-- Hover indicator -->
                                <div v-if="canvasHover" class="absolute pointer-events-none"
                                     :style="{ left: canvasHover.pct.x * 100 + '%', top: canvasHover.pct.y * 100 + '%', transform: 'translate(-50%,-50%)' }">
                                    <div class="w-2.5 h-2.5 border border-indigo-400/70 rounded-full"></div>
                                </div>
                                <div v-if="canvasHover" class="absolute bottom-1 left-1/2 -translate-x-1/2 text-[8px] font-mono text-indigo-400 pointer-events-none bg-slate-950/80 px-1 rounded">
                                    {{ canvasHover.label }}
                                </div>
                                <!-- Current position marker -->
                                <div class="absolute pointer-events-none" :style="logoMarkerStyle">
                                    <div class="w-3.5 h-3.5 bg-indigo-500 border-2 border-white rounded-full shadow-lg -translate-x-1/2 -translate-y-1/2"></div>
                                    <div class="absolute top-4 left-1/2 -translate-x-1/2 whitespace-nowrap text-[8px] font-mono text-white bg-indigo-600/90 px-1 rounded">
                                        {{ logoMarkerLabel }}
                                    </div>
                                </div>
                            </div>

                            <!-- X / Y fine-tune with sliders -->
                            <div class="grid grid-cols-2 gap-2">
                                <div>
                                    <label class="text-[10px] text-slate-500">X <span class="text-slate-600">(neg = from right)</span></label>
                                    <div class="flex items-center gap-2 mt-1">
                                        <input v-model.number="logoX" type="range" :min="logoXRange.min" :max="logoXRange.max" step="1"
                                               class="flex-1 accent-indigo-500" />
                                        <input v-model.number="logoX" type="number" :min="logoXRange.min" :max="logoXRange.max"
                                               class="w-20 form-input text-xs font-mono text-center" />
                                    </div>
                                </div>
                                <div>
                                    <label class="text-[10px] text-slate-500">Y <span class="text-slate-600">(neg = from bottom)</span></label>
                                    <div class="flex items-center gap-2 mt-1">
                                        <input v-model.number="logoY" type="range" :min="logoYRange.min" :max="logoYRange.max" step="1"
                                               class="flex-1 accent-indigo-500" />
                                        <input v-model.number="logoY" type="number" :min="logoYRange.min" :max="logoYRange.max"
                                               class="w-20 form-input text-xs font-mono text-center" />
                                    </div>
                                </div>
                            </div>

                            <!-- Margin + corner presets -->
                            <div>
                                <label class="text-[10px] text-slate-500 uppercase tracking-wider">Margin: <span class="font-mono text-slate-300">{{ presetMargin }}px</span></label>
                                <input v-model.number="presetMargin" type="range" min="0" max="200" step="5"
                                       class="w-full accent-indigo-500 mt-1" />
                            </div>
                            <div class="grid grid-cols-2 gap-1.5">
                                <button v-for="p in presets" :key="p.key" type="button" @click="applyPreset(p.key)"
                                        :class="['px-2 py-1.5 text-xs rounded-lg border transition-colors flex items-center gap-1.5',
                                                 p.key === 'c' ? 'col-span-2 justify-center' : '',
                                                 activePreset === p.key ? 'bg-indigo-600/30 border-indigo-500/50 text-indigo-300' : 'bg-slate-800 border-slate-700 text-slate-400 hover:bg-slate-700']">
                                    <span>{{ p.icon }}</span><span>{{ p.label }}</span>
                                </button>
                            </div>

                            <!-- Apply button -->
                            <button type="button" @click="saveLogoSettings"
                                    :disabled="logoSaving"
                                    class="px-4 py-2 bg-indigo-600 hover:bg-indigo-500 text-white text-xs font-semibold rounded-lg w-full transition-colors disabled:opacity-50">
                                {{ logoSaving ? 'Applying…' : '✓ Apply Position & Size' }}
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

                        <!-- Label prefix -->
                        <div class="mb-3">
                            <label class="text-xs text-slate-500 mb-1 block">Label prefix <span class="text-slate-600">(e.g. BREAKING NEWS — shown static before scroll)</span></label>
                            <div class="flex gap-2">
                                <input v-model="tickerLabel" type="text" placeholder="BREAKING NEWS"
                                       class="flex-1 form-input text-xs" maxlength="200" />
                                <input v-model="tickerLabelColor" type="color" class="w-8 h-8 rounded cursor-pointer border border-slate-600" title="Label text color" @change="saveTickerItems" />
                                <input v-model="tickerLabelBg" type="color" class="w-8 h-8 rounded cursor-pointer border border-slate-600" title="Label background color" @change="saveTickerItems" />
                            </div>
                        </div>

                        <!-- Ticker items list -->
                        <div class="space-y-1.5 mb-3">
                            <div class="flex items-center justify-between">
                                <label class="text-xs text-slate-500">Ticker items <span class="text-slate-600">(each scrolls as one line, joined by •)</span></label>
                                <div class="flex gap-1.5 flex-wrap justify-end">
                                    <div class="flex items-center rounded border border-slate-700 overflow-hidden flex-shrink-0">
                                        <button @click="newsLang = 'en'" type="button"
                                                :class="['px-1.5 py-1 text-[10px] transition-colors', newsLang === 'en' ? 'bg-indigo-600 text-white' : 'bg-slate-800 text-slate-400 hover:bg-slate-700']">
                                            EN
                                        </button>
                                        <button @click="newsLang = 'fr'" type="button"
                                                :class="['px-1.5 py-1 text-[10px] transition-colors', newsLang === 'fr' ? 'bg-indigo-600 text-white' : 'bg-slate-800 text-slate-400 hover:bg-slate-700']">
                                            FR
                                        </button>
                                    </div>
                                    <button @click="fetchNews" type="button" :disabled="fetchingNews"
                                            class="px-2 py-1 text-[10px] bg-green-600/20 text-green-300 border border-green-500/40 rounded hover:bg-green-600/40 transition-colors disabled:opacity-50 font-semibold" title="Fetch live Cameroon/Africa news">
                                        {{ fetchingNews ? 'Fetching…' : '🌍 Fetch News' }}
                                    </button>
                                    <button @click="downloadSampleCsv" type="button"
                                            class="px-2 py-1 text-[10px] bg-slate-700/50 text-slate-400 border border-slate-700 rounded hover:bg-slate-700 transition-colors" title="Download sample CSV">
                                        ↓ Sample
                                    </button>
                                    <label class="px-2 py-1 text-[10px] bg-slate-700 text-slate-300 rounded cursor-pointer hover:bg-slate-600 transition-colors" title="Upload .txt or .csv file">
                                        ↑ Import
                                        <input type="file" accept=".txt,.csv,text/plain,text/csv" @change="importTickerFile" class="hidden" />
                                    </label>
                                    <button @click="addTickerItem" type="button"
                                            class="px-2 py-1 text-[10px] bg-indigo-600/20 text-indigo-400 border border-indigo-500/30 rounded hover:bg-indigo-600/30 transition-colors">
                                        + Add
                                    </button>
                                </div>
                            </div>
                            <p class="text-[10px] text-slate-600">CSV: text,#fontcolor,#bgcolor — colors optional</p>
                            <div v-if="tickerItems.length === 0" class="text-xs text-slate-600 py-2 text-center border border-dashed border-slate-700 rounded-lg">
                                No items — add lines or import a file
                            </div>
                            <div v-for="(item, i) in tickerItems" :key="i"
                                 class="flex items-center gap-1.5 bg-slate-800/50 rounded-lg px-2 py-1.5">
                                <span class="text-[10px] text-slate-600 w-4 text-right flex-shrink-0">{{ i+1 }}</span>
                                <input v-model="item.text" type="text" placeholder="Ticker text…"
                                       class="flex-1 bg-transparent text-xs text-slate-200 outline-none min-w-0" />
                                <input v-model="item.color" type="color"
                                       class="w-6 h-6 rounded cursor-pointer border-0 bg-transparent flex-shrink-0"
                                       title="Text color" />
                                <input v-model="item.bg_color" type="color"
                                       class="w-6 h-6 rounded cursor-pointer border-0 bg-transparent flex-shrink-0"
                                       title="Background color" />
                                <button @click="tickerItems.splice(i,1)" class="text-slate-600 hover:text-red-400 text-xs flex-shrink-0">✕</button>
                            </div>
                        </div>

                        <button @click="saveTickerItems" :disabled="!channel.ticker_enabled"
                                class="w-full px-4 py-2 bg-blue-600 text-white text-xs rounded-lg disabled:opacity-50 mb-3">
                            Push to Air
                        </button>
                        <p v-if="tickerMessage" class="mb-2 text-xs text-green-400">{{ tickerMessage }}</p>

                        <!-- Ticker Style Settings -->
                        <div class="pt-3 border-t border-slate-800 space-y-3">
                            <h3 class="text-xs font-semibold text-slate-400 uppercase tracking-wider">Style</h3>
                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <label class="text-xs text-slate-500 mb-1 block">Font Size: {{ tickerFontSize }}px</label>
                                    <input v-model.number="tickerFontSize" type="range" min="10" max="72" step="1"
                                           @change="saveTickerSettings()" class="w-full accent-indigo-500" />
                                </div>
                                <div>
                                    <label class="text-xs text-slate-500 mb-1 block">Speed: {{ tickerSpeed }}px/s</label>
                                    <input v-model.number="tickerSpeed" type="range" min="10" max="500" step="5"
                                           @change="saveTickerSettings()" class="w-full accent-indigo-500" />
                                </div>
                            </div>
                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <label class="text-xs text-slate-500 mb-1 block">Font Color</label>
                                    <div class="flex items-center gap-2">
                                        <input v-model="tickerFontColor" type="color" class="w-8 h-8 rounded cursor-pointer bg-transparent border border-slate-600" @change="saveTickerSettings()" />
                                        <input v-model="tickerFontColor" type="text" class="flex-1 form-input text-xs font-mono" @change="saveTickerSettings()" />
                                    </div>
                                </div>
                                <div>
                                    <label class="text-xs text-slate-500 mb-1 block">Background</label>
                                    <div class="flex items-center gap-2">
                                        <input v-model="tickerBgColor" type="color" class="w-8 h-8 rounded cursor-pointer bg-transparent border border-slate-600" @change="saveTickerSettings()" />
                                        <input v-model="tickerBgColor" type="text" class="flex-1 form-input text-xs font-mono" @change="saveTickerSettings()" />
                                    </div>
                                </div>
                            </div>
                            <div>
                                <label class="text-xs text-slate-500 mb-1 block">Background Opacity: {{ tickerBgOpacity }}%</label>
                                <input v-model.number="tickerBgOpacity" type="range" min="0" max="100" step="5"
                                       @change="saveTickerSettings()" class="w-full accent-indigo-500" />
                            </div>
                            <div>
                                <label class="text-xs text-slate-500 mb-1 block">Position</label>
                                <div class="flex gap-2">
                                    <button v-for="tp in tickerPositions" :key="tp.value" type="button"
                                            @click="tickerPosition = tp.value; saveTickerSettings()"
                                            :class="['px-3 py-1 text-xs rounded-lg border transition-colors flex-1 text-center',
                                                     tickerPosition === tp.value
                                                         ? 'bg-indigo-600/30 border-indigo-500/50 text-indigo-300'
                                                         : 'bg-slate-800 border-slate-700 text-slate-400 hover:bg-slate-700']">
                                        {{ tp.label }}
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Channel Timezone -->
                    <div class="bg-slate-900 border border-slate-800 rounded-xl p-4">
                        <div class="flex items-center gap-2 mb-2">
                            <span class="text-sm font-semibold text-white">Channel Timezone</span>
                            <span class="text-[10px] text-slate-500">(used by clock overlay)</span>
                        </div>
                        <select v-model="clockTimezone" @change="saveClockSettings()"
                                class="w-full form-input text-xs font-mono">
                            <optgroup v-for="group in timezones" :key="group.group" :label="group.group">
                                <option v-for="tz in group.zones" :key="tz.value" :value="tz.value">{{ tz.label }}</option>
                            </optgroup>
                        </select>
                    </div>

                    <!-- Clock Settings -->
                    <div class="bg-slate-900 border border-slate-800 rounded-xl p-6">
                        <div class="flex items-center justify-between mb-3">
                            <h2 class="text-sm font-semibold text-white">Clock Overlay</h2>
                            <button @click="clockEnabled = !clockEnabled; saveClockSettings()"
                                    :class="['px-2 py-1 text-xs rounded-lg transition-colors border',
                                             clockEnabled ? 'bg-green-500/20 text-green-400 border-green-500/30' : 'bg-slate-700 text-slate-400 border-slate-600']">
                                {{ clockEnabled ? 'ON' : 'OFF' }}
                            </button>
                        </div>
                        <div class="space-y-3" :class="{ 'opacity-50 pointer-events-none': !clockEnabled }">
                            <!-- Canvas position picker -->
                            <p class="text-[10px] text-slate-500 uppercase tracking-wider">Position <span class="text-slate-600 normal-case">(click canvas or use sliders)</span></p>
                            <div ref="clockCanvas"
                                 @click="onClockCanvasClick"
                                 @mousemove="onClockCanvasHover"
                                 @mouseleave="clockCanvasHover = null"
                                 class="relative w-full aspect-video bg-slate-950 border border-slate-700 rounded-lg cursor-crosshair overflow-hidden select-none">
                                <div class="absolute inset-0 pointer-events-none">
                                    <div class="absolute left-1/3 top-0 bottom-0 border-l border-slate-800/60"></div>
                                    <div class="absolute left-2/3 top-0 bottom-0 border-l border-slate-800/60"></div>
                                    <div class="absolute top-1/3 left-0 right-0 border-t border-slate-800/60"></div>
                                    <div class="absolute top-2/3 left-0 right-0 border-t border-slate-800/60"></div>
                                </div>
                                <div v-if="clockCanvasHover" class="absolute pointer-events-none"
                                     :style="{ left: clockCanvasHover.pct.x*100+'%', top: clockCanvasHover.pct.y*100+'%', transform:'translate(-50%,-50%)' }">
                                    <div class="w-2.5 h-2.5 border border-amber-400/70 rounded-full"></div>
                                </div>
                                <div v-if="clockCanvasHover" class="absolute bottom-1 left-1/2 -translate-x-1/2 text-[8px] font-mono text-amber-400 pointer-events-none bg-slate-950/80 px-1 rounded">
                                    {{ clockCanvasHover.label }}
                                </div>
                                <!-- Current marker -->
                                <div class="absolute pointer-events-none" :style="clockMarkerStyle">
                                    <div class="w-3.5 h-3.5 bg-amber-500 border-2 border-white rounded-full shadow-lg -translate-x-1/2 -translate-y-1/2"></div>
                                    <div class="absolute top-4 left-1/2 -translate-x-1/2 whitespace-nowrap text-[8px] font-mono text-white bg-amber-600/90 px-1 rounded">
                                        {{ clockMarkerLabel }}
                                    </div>
                                </div>
                            </div>
                            <!-- X/Y sliders -->
                            <div class="grid grid-cols-2 gap-2">
                                <div>
                                    <label class="text-[10px] text-slate-500">X <span class="text-slate-600">(neg=from right)</span></label>
                                    <div class="flex items-center gap-1 mt-1">
                                        <input v-model.number="clockX" type="range" :min="-CANVAS_W" :max="CANVAS_W" step="1" class="flex-1 accent-amber-500" />
                                        <input v-model.number="clockX" type="number" :min="-CANVAS_W" :max="CANVAS_W" class="w-16 form-input text-xs font-mono text-center" />
                                    </div>
                                </div>
                                <div>
                                    <label class="text-[10px] text-slate-500">Y <span class="text-slate-600">(neg=from bottom)</span></label>
                                    <div class="flex items-center gap-1 mt-1">
                                        <input v-model.number="clockY" type="range" :min="-CANVAS_H" :max="CANVAS_H" step="1" class="flex-1 accent-amber-500" />
                                        <input v-model.number="clockY" type="number" :min="-CANVAS_H" :max="CANVAS_H" class="w-16 form-input text-xs font-mono text-center" />
                                    </div>
                                </div>
                            </div>
                            <!-- Corner presets -->
                            <div class="grid grid-cols-2 gap-1.5">
                                <button v-for="p in clockPresets" :key="p.key" type="button" @click="applyClockPreset(p.key)"
                                        :class="['px-2 py-1 text-xs rounded-lg border transition-colors flex items-center gap-1',
                                                 activeClockPreset === p.key ? 'bg-amber-600/30 border-amber-500/50 text-amber-300' : 'bg-slate-800 border-slate-700 text-slate-400 hover:bg-slate-700']">
                                    {{ p.icon }} {{ p.label }}
                                </button>
                            </div>
                            <div>
                                <label class="text-xs text-slate-500 mb-2 block">Format</label>
                                <div class="flex flex-wrap gap-1.5 mb-2">
                                    <button v-for="fp in clockFormatPresets" :key="fp.value" type="button"
                                            @click="clockFormat = fp.value; saveClockSettings()"
                                            :class="['px-2 py-1 text-xs rounded-lg border transition-colors',
                                                     clockFormat === fp.value
                                                         ? 'bg-indigo-600/30 border-indigo-500/50 text-indigo-300'
                                                         : 'bg-slate-800 border-slate-700 text-slate-400 hover:bg-slate-700']">
                                        {{ fp.label }}
                                    </button>
                                </div>
                                <div class="flex items-center gap-2">
                                    <input v-model="clockFormat" type="text"
                                           placeholder="%H\:%M\:%S"
                                           class="flex-1 form-input text-xs font-mono"
                                           @change="saveClockSettings()" />
                                    <span class="text-[10px] text-slate-600 whitespace-nowrap">strftime</span>
                                </div>
                                <p class="text-[10px] text-slate-600 mt-1">%H=hour %M=min %S=sec %I=12h %p=AM/PM %d=day %m=month %Y=year</p>
                            </div>
                            <div>
                                <label class="text-xs text-slate-500 mb-1 block">Font Size: {{ clockFontsize }}px</label>
                                <input v-model.number="clockFontsize" type="range" min="12" max="72" step="2"
                                       @change="saveClockSettings()"
                                       class="w-full accent-indigo-500" />
                            </div>
                            <div>
                                <label class="text-xs text-slate-500 mb-1 block">Color</label>
                                <div class="flex items-center gap-2">
                                    <input v-model="clockColor" type="color" class="w-8 h-8 rounded cursor-pointer bg-transparent border border-slate-600" />
                                    <input v-model="clockColor" type="text" class="flex-1 form-input text-xs font-mono"
                                           @change="saveClockSettings()" />
                                </div>
                            </div>
                            <button @click="saveClockSettings()"
                                    class="w-full px-3 py-1.5 text-xs bg-amber-600/20 text-amber-400 border border-amber-500/30 rounded-lg hover:bg-amber-600/30 transition-colors">
                                ✓ Apply Clock Settings
                            </button>
                        </div>
                        <p v-if="clockMessage" class="mt-1 text-xs text-green-400">{{ clockMessage }}</p>
                    </div>

                    <!-- NOW PLAYING / Lowerthird Settings -->
                    <div class="bg-slate-900 border border-slate-800 rounded-xl p-6">
                        <div class="flex items-center justify-between mb-3">
                            <h2 class="text-sm font-semibold text-white">NOW PLAYING Overlay</h2>
                            <button @click="lowerthirdEnabled = !lowerthirdEnabled; saveLowerthirdSettings()"
                                    :class="['px-2 py-1 text-xs rounded-lg transition-colors border',
                                             lowerthirdEnabled ? 'bg-green-500/20 text-green-400 border-green-500/30' : 'bg-slate-700 text-slate-400 border-slate-600']">
                                {{ lowerthirdEnabled ? 'ON' : 'OFF' }}
                            </button>
                        </div>
                        <div class="space-y-3" :class="{ 'opacity-50 pointer-events-none': !lowerthirdEnabled }">
                            <!-- Canvas position picker -->
                            <p class="text-[10px] text-slate-500 uppercase tracking-wider">Position <span class="text-slate-600 normal-case">(click canvas or use sliders)</span></p>
                            <div ref="ltCanvas"
                                 @click="onLtCanvasClick"
                                 @mousemove="onLtCanvasHover"
                                 @mouseleave="ltCanvasHover = null"
                                 class="relative w-full aspect-video bg-slate-950 border border-slate-700 rounded-lg cursor-crosshair overflow-hidden select-none">
                                <div class="absolute inset-0 pointer-events-none">
                                    <div class="absolute left-1/3 top-0 bottom-0 border-l border-slate-800/60"></div>
                                    <div class="absolute left-2/3 top-0 bottom-0 border-l border-slate-800/60"></div>
                                    <div class="absolute top-1/3 left-0 right-0 border-t border-slate-800/60"></div>
                                    <div class="absolute top-2/3 left-0 right-0 border-t border-slate-800/60"></div>
                                </div>
                                <div v-if="ltCanvasHover" class="absolute pointer-events-none"
                                     :style="{ left: ltCanvasHover.pct.x*100+'%', top: ltCanvasHover.pct.y*100+'%', transform:'translate(-50%,-50%)' }">
                                    <div class="w-2.5 h-2.5 border border-emerald-400/70 rounded-full"></div>
                                </div>
                                <div v-if="ltCanvasHover" class="absolute bottom-1 left-1/2 -translate-x-1/2 text-[8px] font-mono text-emerald-400 pointer-events-none bg-slate-950/80 px-1 rounded">
                                    {{ ltCanvasHover.label }}
                                </div>
                                <!-- Current marker -->
                                <div class="absolute pointer-events-none" :style="ltMarkerStyle">
                                    <div class="w-3.5 h-3.5 bg-emerald-500 border-2 border-white rounded-full shadow-lg -translate-x-1/2 -translate-y-1/2"></div>
                                    <div class="absolute top-4 left-1/2 -translate-x-1/2 whitespace-nowrap text-[8px] font-mono text-white bg-emerald-600/90 px-1 rounded">
                                        {{ ltMarkerLabel }}
                                    </div>
                                </div>
                            </div>
                            <!-- X/Y sliders -->
                            <div class="grid grid-cols-2 gap-2">
                                <div>
                                    <label class="text-[10px] text-slate-500">X <span class="text-slate-600">(neg=from right)</span></label>
                                    <div class="flex items-center gap-1 mt-1">
                                        <input v-model.number="ltX" type="range" :min="-CANVAS_W" :max="CANVAS_W" step="1" class="flex-1 accent-emerald-500" />
                                        <input v-model.number="ltX" type="number" :min="-CANVAS_W" :max="CANVAS_W" class="w-16 form-input text-xs font-mono text-center" />
                                    </div>
                                </div>
                                <div>
                                    <label class="text-[10px] text-slate-500">Y <span class="text-slate-600">(neg=from bottom)</span></label>
                                    <div class="flex items-center gap-1 mt-1">
                                        <input v-model.number="ltY" type="range" :min="-CANVAS_H" :max="CANVAS_H" step="1" class="flex-1 accent-emerald-500" />
                                        <input v-model.number="ltY" type="number" :min="-CANVAS_H" :max="CANVAS_H" class="w-16 form-input text-xs font-mono text-center" />
                                    </div>
                                </div>
                            </div>
                            <!-- Corner presets -->
                            <div class="grid grid-cols-2 gap-1.5">
                                <button v-for="p in ltPresets" :key="p.key" type="button" @click="applyLtPreset(p.key)"
                                        :class="['px-2 py-1 text-xs rounded-lg border transition-colors flex items-center gap-1',
                                                 activeLtPreset === p.key ? 'bg-emerald-600/30 border-emerald-500/50 text-emerald-300' : 'bg-slate-800 border-slate-700 text-slate-400 hover:bg-slate-700']">
                                    {{ p.icon }} {{ p.label }}
                                </button>
                            </div>
                            <div>
                                <label class="text-xs text-slate-500 mb-1 block">Font Size: {{ lowerthirdFontsize }}px</label>
                                <input v-model.number="lowerthirdFontsize" type="range" min="10" max="72" step="2"
                                       @change="saveLowerthirdSettings()"
                                       class="w-full accent-indigo-500" />
                            </div>
                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <label class="text-xs text-slate-500 mb-1 block">Font Color</label>
                                    <div class="flex items-center gap-2">
                                        <input v-model="lowerthirdFontColor" type="color" class="w-8 h-8 rounded cursor-pointer bg-transparent border border-slate-600" @change="saveLowerthirdSettings()" />
                                        <input v-model="lowerthirdFontColor" type="text" class="flex-1 form-input text-xs font-mono" @change="saveLowerthirdSettings()" />
                                    </div>
                                </div>
                                <div>
                                    <label class="text-xs text-slate-500 mb-1 block">Background</label>
                                    <div class="flex items-center gap-2">
                                        <input v-model="lowerthirdBgColor" type="color" class="w-8 h-8 rounded cursor-pointer bg-transparent border border-slate-600" @change="saveLowerthirdSettings()" />
                                        <input v-model="lowerthirdBgColor" type="text" class="flex-1 form-input text-xs font-mono" @change="saveLowerthirdSettings()" />
                                    </div>
                                </div>
                            </div>
                            <div>
                                <label class="text-xs text-slate-500 mb-1 block">Background Opacity: {{ lowerthirdBgOpacity }}%</label>
                                <input v-model.number="lowerthirdBgOpacity" type="range" min="0" max="100" step="5"
                                       @change="saveLowerthirdSettings()"
                                       class="w-full accent-indigo-500" />
                            </div>
                            <button @click="saveLowerthirdSettings()"
                                    class="w-full px-3 py-1.5 text-xs bg-emerald-600/20 text-emerald-400 border border-emerald-500/30 rounded-lg hover:bg-emerald-600/30 transition-colors">
                                ✓ Apply Position &amp; Style
                            </button>
                        </div>
                        <p v-if="lowerthirdMessage" class="mt-1 text-xs text-green-400">{{ lowerthirdMessage }}</p>
                    </div>

                    <!-- Encoding Settings -->
                    <div class="bg-slate-900 border border-slate-800 rounded-xl p-6">
                        <h2 class="text-sm font-semibold text-white mb-3">Output Settings</h2>
                        <div class="space-y-3">
                            <div>
                                <label class="text-xs text-slate-500 mb-1 block">Output Resolution</label>
                                <div class="grid grid-cols-2 gap-2">
                                    <button v-for="res in resolutions" :key="res.value" type="button"
                                            @click="outputResolution = res.value; saveResolution()"
                                            :class="['px-3 py-1.5 text-xs rounded-lg border transition-colors text-left font-mono',
                                                     outputResolution === res.value
                                                         ? 'bg-indigo-600/30 border-indigo-500/50 text-indigo-300'
                                                         : 'bg-slate-800 border-slate-700 text-slate-400 hover:bg-slate-700']">
                                        {{ res.label }}
                                    </button>
                                </div>
                                <p v-if="resolutionMessage" class="mt-1 text-xs text-green-400">{{ resolutionMessage }}</p>
                            </div>
                            <div>
                                <div class="text-xs text-slate-500">Video</div>
                                <div class="text-sm font-mono text-slate-300">H.264 · {{ channel.push_video_bitrate || 3000 }} kbps · {{ channel.push_framerate || 25 }} fps</div>
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
import { ref, reactive, computed, onMounted, onUnmounted } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'

const props = defineProps({
    channel: Object,
    items: Array,
    summary: Object,
    isRunning: Boolean,
    previewUrl: String,
    downloadStatuses: Object,
    isAdmin: Boolean,
})

const items = ref([...props.items])
const isRunning = ref(props.isRunning)
const pushRunning = ref(props.channel.push_status === 'live')
const downloadStatuses = ref({ ...props.downloadStatuses })
const probeResults = ref({})
const summary = ref({ ...props.summary })
const tickerText = ref(props.channel.ticker_text || '')
const tickerMessage = ref('')

// Ticker style settings
const tickerFontSize = ref(props.channel.ticker_font_size ?? 24)
const tickerSpeed = ref(props.channel.ticker_speed ?? 80)
const namedColors = { white: '#ffffff', black: '#000000', red: '#ff0000', green: '#008000', blue: '#0000ff', yellow: '#ffff00', cyan: '#00ffff', magenta: '#ff00ff', gray: '#808080', grey: '#808080' }
const toHex = (c) => (c && !c.startsWith('#') ? (namedColors[c.toLowerCase()] ?? '#ffffff') : (c || '#ffffff'))

const tickerFontColor = ref(toHex(props.channel.ticker_font_color))
const tickerBgColor = ref(toHex(props.channel.ticker_bg_color) ?? '#000000')
const tickerBgOpacity = ref(props.channel.ticker_bg_opacity ?? 65)
const tickerPosition = ref(props.channel.ticker_position ?? 'bottom')
const tickerPositions = [
    { value: 'top', label: 'Top' },
    { value: 'center', label: 'Center' },
    { value: 'bottom', label: 'Bottom' },
]

// Resolution settings
const outputResolution = ref(props.channel.output_resolution ?? '1920x1080')
const resolutionMessage = ref('')
const resolutions = [
    { value: '1920x1080', label: '1920×1080 (Full HD)' },
    { value: '1280x720', label: '1280×720 (HD)' },
    { value: '854x480', label: '854×480 (SD)' },
    { value: '640x360', label: '640×360 (Low)' },
    { value: 'auto', label: 'Auto (no scale)' },
]

// Clock settings
const clockPosition = ref(props.channel.clock_position || 'top-left')
const clockFontsize = ref(props.channel.clock_fontsize || 28)
const clockColor = ref(toHex(props.channel.clock_color))
const clockEnabled = ref(props.channel.clock_enabled !== false)
const clockFormat = ref(props.channel.clock_format || '%H\:%M\:%S')
const clockMessage = ref('')
const clockTimezone = ref(props.channel.timezone || Intl.DateTimeFormat().resolvedOptions().timeZone)
const clockX = ref(props.channel.clock_x ?? null)
const clockY = ref(props.channel.clock_y ?? null)
const clockPositions = [
    { value: 'top-left', icon: '↖', label: 'Top Left' },
    { value: 'top-right', icon: '↗', label: 'Top Right' },
    { value: 'bottom-left', icon: '↙', label: 'Bottom Left' },
    { value: 'bottom-right', icon: '↘', label: 'Bottom Right' },
]
const clockFormatPresets = [
    { label: 'HH:mm:ss', value: '%H\:%M\:%S' },
    { label: 'HH:mm', value: '%H\:%M' },
    { label: 'hh:mm:ss AM/PM', value: '%I\:%M\:%S %p' },
    { label: 'hh:mm AM/PM', value: '%I\:%M %p' },
    { label: 'DD/MM HH:mm', value: '%d/%m %H\:%M' },
    { label: 'MM/DD HH:mm', value: '%m/%d %H\:%M' },
    { label: 'Full Date+Time', value: '%d/%m/%Y %H\:%M\:%S' },
]
const timezones = [
    { group: 'UTC', zones: [
        { value: 'UTC', label: 'UTC +00:00' },
    ]},
    { group: 'Africa', zones: [
        { value: 'Africa/Abidjan',      label: 'Abidjan (GMT+0)' },
        { value: 'Africa/Accra',        label: 'Accra (GMT+0)' },
        { value: 'Africa/Addis_Ababa',  label: 'Addis Ababa (EAT+3)' },
        { value: 'Africa/Algiers',      label: 'Algiers (CET+1)' },
        { value: 'Africa/Cairo',        label: 'Cairo (EET+2)' },
        { value: 'Africa/Casablanca',   label: 'Casablanca (WET+0/+1)' },
        { value: 'Africa/Johannesburg', label: 'Johannesburg (SAST+2)' },
        { value: 'Africa/Khartoum',     label: 'Khartoum (CAT+3)' },
        { value: 'Africa/Lagos',        label: 'Lagos (WAT+1)' },
        { value: 'Africa/Nairobi',      label: 'Nairobi (EAT+3)' },
        { value: 'Africa/Tripoli',      label: 'Tripoli (EET+2)' },
        { value: 'Africa/Tunis',        label: 'Tunis (CET+1)' },
    ]},
    { group: 'Americas', zones: [
        { value: 'America/Anchorage',      label: 'Anchorage (AKST-9)' },
        { value: 'America/Argentina/Buenos_Aires', label: 'Buenos Aires (ART-3)' },
        { value: 'America/Bogota',         label: 'Bogota (COT-5)' },
        { value: 'America/Caracas',        label: 'Caracas (VET-4)' },
        { value: 'America/Chicago',        label: 'Chicago (CST-6)' },
        { value: 'America/Denver',         label: 'Denver (MST-7)' },
        { value: 'America/Halifax',        label: 'Halifax (AST-4)' },
        { value: 'America/Lima',           label: 'Lima (PET-5)' },
        { value: 'America/Los_Angeles',    label: 'Los Angeles (PST-8)' },
        { value: 'America/Mexico_City',    label: 'Mexico City (CST-6)' },
        { value: 'America/New_York',       label: 'New York (EST-5)' },
        { value: 'America/Phoenix',        label: 'Phoenix (MST-7)' },
        { value: 'America/Santiago',       label: 'Santiago (CLT-3)' },
        { value: 'America/Sao_Paulo',      label: 'São Paulo (BRT-3)' },
        { value: 'America/St_Johns',       label: 'St. Johns (NST-3:30)' },
        { value: 'America/Toronto',        label: 'Toronto (EST-5)' },
        { value: 'America/Vancouver',      label: 'Vancouver (PST-8)' },
        { value: 'Pacific/Honolulu',       label: 'Honolulu (HST-10)' },
    ]},
    { group: 'Asia', zones: [
        { value: 'Asia/Almaty',     label: 'Almaty (ALMT+6)' },
        { value: 'Asia/Baghdad',    label: 'Baghdad (AST+3)' },
        { value: 'Asia/Baku',       label: 'Baku (AZT+4)' },
        { value: 'Asia/Bangkok',    label: 'Bangkok (ICT+7)' },
        { value: 'Asia/Colombo',    label: 'Colombo (IST+5:30)' },
        { value: 'Asia/Dhaka',      label: 'Dhaka (BST+6)' },
        { value: 'Asia/Dubai',      label: 'Dubai (GST+4)' },
        { value: 'Asia/Ho_Chi_Minh',label: 'Ho Chi Minh (ICT+7)' },
        { value: 'Asia/Hong_Kong',  label: 'Hong Kong (HKT+8)' },
        { value: 'Asia/Jakarta',    label: 'Jakarta (WIB+7)' },
        { value: 'Asia/Jerusalem',  label: 'Jerusalem (IST+2)' },
        { value: 'Asia/Karachi',    label: 'Karachi (PKT+5)' },
        { value: 'Asia/Kathmandu',  label: 'Kathmandu (NPT+5:45)' },
        { value: 'Asia/Kolkata',    label: 'Kolkata (IST+5:30)' },
        { value: 'Asia/Kuala_Lumpur', label: 'Kuala Lumpur (MYT+8)' },
        { value: 'Asia/Kuwait',     label: 'Kuwait (AST+3)' },
        { value: 'Asia/Manila',     label: 'Manila (PHT+8)' },
        { value: 'Asia/Muscat',     label: 'Muscat (GST+4)' },
        { value: 'Asia/Riyadh',     label: 'Riyadh (AST+3)' },
        { value: 'Asia/Seoul',      label: 'Seoul (KST+9)' },
        { value: 'Asia/Shanghai',   label: 'Shanghai (CST+8)' },
        { value: 'Asia/Singapore',  label: 'Singapore (SGT+8)' },
        { value: 'Asia/Taipei',     label: 'Taipei (CST+8)' },
        { value: 'Asia/Tashkent',   label: 'Tashkent (UZT+5)' },
        { value: 'Asia/Tehran',     label: 'Tehran (IRST+3:30)' },
        { value: 'Asia/Tokyo',      label: 'Tokyo (JST+9)' },
        { value: 'Asia/Yangon',     label: 'Yangon (MMT+6:30)' },
    ]},
    { group: 'Atlantic / Pacific', zones: [
        { value: 'Atlantic/Azores',       label: 'Azores (AZOT-1)' },
        { value: 'Atlantic/Cape_Verde',   label: 'Cape Verde (CVT-1)' },
        { value: 'Pacific/Auckland',      label: 'Auckland (NZST+12)' },
        { value: 'Pacific/Fiji',          label: 'Fiji (FJT+12)' },
        { value: 'Pacific/Guam',          label: 'Guam (ChST+10)' },
        { value: 'Pacific/Midway',        label: 'Midway (SST-11)' },
        { value: 'Pacific/Tongatapu',     label: 'Tonga (TOT+13)' },
    ]},
    { group: 'Australia', zones: [
        { value: 'Australia/Adelaide',  label: 'Adelaide (ACST+9:30)' },
        { value: 'Australia/Brisbane',  label: 'Brisbane (AEST+10)' },
        { value: 'Australia/Darwin',    label: 'Darwin (ACST+9:30)' },
        { value: 'Australia/Hobart',    label: 'Hobart (AEST+10)' },
        { value: 'Australia/Melbourne', label: 'Melbourne (AEST+10)' },
        { value: 'Australia/Perth',     label: 'Perth (AWST+8)' },
        { value: 'Australia/Sydney',    label: 'Sydney (AEST+10)' },
    ]},
    { group: 'Europe', zones: [
        { value: 'Europe/Amsterdam',  label: 'Amsterdam (CET+1)' },
        { value: 'Europe/Athens',     label: 'Athens (EET+2)' },
        { value: 'Europe/Belgrade',   label: 'Belgrade (CET+1)' },
        { value: 'Europe/Berlin',     label: 'Berlin (CET+1)' },
        { value: 'Europe/Brussels',   label: 'Brussels (CET+1)' },
        { value: 'Europe/Bucharest',  label: 'Bucharest (EET+2)' },
        { value: 'Europe/Budapest',   label: 'Budapest (CET+1)' },
        { value: 'Europe/Copenhagen', label: 'Copenhagen (CET+1)' },
        { value: 'Europe/Dublin',     label: 'Dublin (GMT+0)' },
        { value: 'Europe/Helsinki',   label: 'Helsinki (EET+2)' },
        { value: 'Europe/Istanbul',   label: 'Istanbul (TRT+3)' },
        { value: 'Europe/Kiev',       label: 'Kyiv (EET+2)' },
        { value: 'Europe/Lisbon',     label: 'Lisbon (WET+0)' },
        { value: 'Europe/London',     label: 'London (GMT+0)' },
        { value: 'Europe/Madrid',     label: 'Madrid (CET+1)' },
        { value: 'Europe/Minsk',      label: 'Minsk (FET+3)' },
        { value: 'Europe/Moscow',     label: 'Moscow (MSK+3)' },
        { value: 'Europe/Oslo',       label: 'Oslo (CET+1)' },
        { value: 'Europe/Paris',      label: 'Paris (CET+1)' },
        { value: 'Europe/Prague',     label: 'Prague (CET+1)' },
        { value: 'Europe/Rome',       label: 'Rome (CET+1)' },
        { value: 'Europe/Sofia',      label: 'Sofia (EET+2)' },
        { value: 'Europe/Stockholm',  label: 'Stockholm (CET+1)' },
        { value: 'Europe/Vienna',     label: 'Vienna (CET+1)' },
        { value: 'Europe/Warsaw',     label: 'Warsaw (CET+1)' },
        { value: 'Europe/Zurich',     label: 'Zurich (CET+1)' },
    ]},
]
const clockCanvas = ref(null)
const clockCanvasHover = ref(null)
const activeClockPreset = ref(null)
const clockPresets = [
    { key: 'tl', icon: '↖', label: 'Top Left' },
    { key: 'tr', icon: '↗', label: 'Top Right' },
    { key: 'bl', icon: '↙', label: 'Bottom Left' },
    { key: 'br', icon: '↘', label: 'Bottom Right' },
]

function parseClockPosition() {
    const x = props.channel.clock_x
    const y = props.channel.clock_y
    if (x !== null && x !== undefined && y !== null && y !== undefined) return { x, y }
    const pos = props.channel.clock_position ?? 'top-left'
    const m = 15
    const map = { 'top-left': [m,m], 'top-right': [-m,m], 'bottom-left': [m,-m], 'bottom-right': [-m,-m] }
    const [px, py] = map[pos] || [m, m]
    return { x: px, y: py }
}
const parsedClock = parseClockPosition()
clockX.value = parsedClock.x
clockY.value = parsedClock.y

const clockMarkerStyle = computed(() => {
    const ax = clockX.value < 0 ? CANVAS_W + clockX.value : clockX.value
    const ay = clockY.value < 0 ? CANVAS_H + clockY.value : clockY.value
    return {
        left: Math.max(0, Math.min(1, ax / CANVAS_W)) * 100 + '%',
        top:  Math.max(0, Math.min(1, ay / CANVAS_H)) * 100 + '%',
    }
})
const clockMarkerLabel = computed(() => `${clockX.value}, ${clockY.value}`)

function onClockCanvasClick(e) {
    const rect = clockCanvas.value.getBoundingClientRect()
    const pctX = (e.clientX - rect.left) / rect.width
    const pctY = (e.clientY - rect.top) / rect.height
    let x = Math.round(pctX * CANVAS_W)
    let y = Math.round(pctY * CANVAS_H)
    if (pctX > 0.85) x = -(CANVAS_W - x)
    if (pctY > 0.85) y = -(CANVAS_H - y)
    clockX.value = x
    clockY.value = y
    activeClockPreset.value = null
}

function onClockCanvasHover(e) {
    const rect = clockCanvas.value.getBoundingClientRect()
    const pctX = (e.clientX - rect.left) / rect.width
    const pctY = (e.clientY - rect.top) / rect.height
    let x = Math.round(pctX * CANVAS_W)
    let y = Math.round(pctY * CANVAS_H)
    if (pctX > 0.85) x = -(CANVAS_W - x)
    if (pctY > 0.85) y = -(CANVAS_H - y)
    clockCanvasHover.value = { pct: { x: pctX, y: pctY }, label: `${x}, ${y}` }
}

function applyClockPreset(key) {
    const m = 15
    const map = { tl: [m,m], tr: [-m,m], bl: [m,-m], br: [-m,-m] }
    const [x, y] = map[key]
    clockX.value = x
    clockY.value = y
    activeClockPreset.value = key
}

// Lowerthird / NOW PLAYING settings
const lowerthirdPosition = ref(props.channel.lowerthird_position ?? 'bottom-left')
const lowerthirdFontsize = ref(props.channel.lowerthird_fontsize ?? 20)
const lowerthirdFontColor = ref(toHex(props.channel.lowerthird_font_color) ?? '#ffffff')
const lowerthirdBgColor = ref(toHex(props.channel.lowerthird_bg_color) ?? '#334155')
const lowerthirdBgOpacity = ref(props.channel.lowerthird_bg_opacity ?? 80)
const lowerthirdEnabled = ref(props.channel.lowerthird_enabled !== false)
const lowerthirdMessage = ref('')
const lowerthirdPositions = [
    { value: 'top-left', icon: '↖', label: 'Top Left' },
    { value: 'top-right', icon: '↗', label: 'Top Right' },
    { value: 'bottom-left', icon: '↙', label: 'Bottom Left' },
    { value: 'bottom-right', icon: '↘', label: 'Bottom Right' },
]

// Parse stored "x:y" or named preset into x/y numbers
function parseLogoPosition(pos) {
    if (!pos) return { x: 20, y: 20 }
    const match = pos.match(/^(-?\d+):(-?\d+)$/)
    if (match) return { x: parseInt(match[1]), y: parseInt(match[2]) }
    const _presets = { 'top-left': [20,20], 'top-right': [-20,20], 'bottom-left': [20,-20], 'bottom-right': [-20,-20] }
    const [x, y] = _presets[pos] || [20, 20]
    return { x, y }
}
const parsedPos = parseLogoPosition(props.channel.logo_position)
const logoX = ref(parsedPos.x)
const logoY = ref(parsedPos.y)
const logoFile = ref(null)
const logoUploading = ref(false)
const logoUploadMessage = ref('')
const logoUploadError = ref(false)
const logoPositionMessage = ref('')
const logoSaving = ref(false)
const logoEnabled = ref(props.channel.logo_enabled ?? true)
const logoScale = ref(props.channel.logo_scale ?? 12)
const logoPreviewUrl = ref(
    props.channel.logo_media_id
        ? `/storage/channel-logos/${props.channel.id}/logo` // resolved below
        : null
)

// If a logo is set, load its preview via the dedicated endpoint
if (props.channel.logo_media_id) {
    logoPreviewUrl.value = `/channels/${props.channel.id}/playout/logo-preview`
}
const uploading = ref(false)
const uploadProgress = ref(0)
const mediaUrl = ref('')

function formatDuration(seconds) {
    if (!seconds || seconds <= 0) return ''
    const h = Math.floor(seconds / 3600)
    const m = Math.floor((seconds % 3600) / 60)
    const s = Math.floor(seconds % 60)
    return h > 0 ? `${h}:${String(m).padStart(2,'0')}:${String(s).padStart(2,'0')}` : `${m}:${String(s).padStart(2,'0')}`
}
const addingUrl = ref(false)
const previewLoading = ref(false)
const urlPreview = ref(null)
const urlError = ref('')
const urlSuccess = ref('')
const engineLog = ref('')
const previewPlayer = ref(null)
const customStartTime = ref('')
const recalculating = ref(false)
const recalcMessage = ref('')
const recalcError = ref('')
const nowPlayingTitle = ref('')
let hlsPlayer = null
let statusTimer = null

// Playlist loop
const playlistLoop = ref(props.channel.playlist_loop ?? 0)
const customLoopValue = ref(props.channel.playlist_loop > 0 ? props.channel.playlist_loop : 10)
const loopOptions = [
    { value: 0, label: 'Auto 24h' },
    { value: 1, label: '1×' },
    { value: 2, label: '2×' },
    { value: 5, label: '5×' },
    { value: 10, label: '10×' },
]
const loopDescription = computed(() => {
    if (playlistLoop.value === 0) return 'Fills 24 hours automatically'
    return `Play playlist ${playlistLoop.value}× then stop`
})

async function setLoop(val) {
    playlistLoop.value = val
    if (val === 0) {
        customLoopValue.value = 10
    } else {
        customLoopValue.value = val
    }
    try {
        const csrfToken = document.cookie.split('; ').find(row => row.startsWith('XSRF-TOKEN='))?.split('=')[1]
        await fetch(route('channels.playout.loop', props.channel.id), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': csrfToken ? decodeURIComponent(csrfToken) : '',
            },
            body: JSON.stringify({ playlist_loop: val }),
        })
    } catch (e) {
        console.error('Loop update failed', e)
    }
}

const mediaGroups = [
    { value: 'default', label: 'Default', hint: 'All overlays shown' },
    { value: 'clean',   label: 'Clean',   hint: 'No overlays (logo, ticker, clock, lower-third hidden)' },
]

// Inline item editor
const editingItem = ref(null)  // item being edited
const editTitle = ref('')
const editGroup = ref('default')
const editSaving = ref(false)

function editItemTitle(item) {
    editingItem.value = item
    editTitle.value = item.custom_title || item.title
    editGroup.value = item.media_group || 'default'
}

function cancelEdit() {
    editingItem.value = null
}

async function saveItemEdit() {
    if (!editingItem.value) return
    editSaving.value = true
    try {
        const csrfToken = document.cookie.split('; ').find(row => row.startsWith('XSRF-TOKEN='))?.split('=')[1]
        // If the entered title matches the original, clear the custom_title
        const customTitle = editTitle.value.trim() === editingItem.value.title ? null : (editTitle.value.trim() || null)
        const res = await fetch(route('channels.playout.items.title', [props.channel.id, editingItem.value.id]), {
            method: 'PUT',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': csrfToken ? decodeURIComponent(csrfToken) : '',
            },
            body: JSON.stringify({ custom_title: customTitle, media_group: editGroup.value }),
        })
        const data = await res.json()
        if (data.success) {
            editingItem.value.custom_title = customTitle
            editingItem.value.display_title = data.display_title
            editingItem.value.media_group = data.media_group
            editingItem.value = null
        }
    } finally {
        editSaving.value = false
    }
}

// Ticker items
const tickerItems = ref((props.channel.ticker_items ?? []).map(i => ({ text: i.text ?? '', color: i.color ?? '#ffffff', bg_color: i.bg_color ?? '#000000' })))
const tickerLabel = ref(props.channel.ticker_label ?? '')
const tickerLabelColor = ref(props.channel.ticker_label_color ?? '#ff0000')
const tickerLabelBg = ref(props.channel.ticker_label_bg ?? '#ffffff')
const fetchingNews = ref(false)
const newsLang = ref('en')

function addTickerItem() {
    tickerItems.value.push({ text: '', color: '#ffffff', bg_color: '#000000' })
}

function downloadSampleCsv() {
    const label = tickerLabel.value || 'BREAKING NEWS'
    const lc = tickerLabelColor.value || '#ffffff'
    const lb = tickerLabelBg.value || '#cc0000'
    const rows = [
        '# SkyMedia Ticker CSV',
        '# Format: text, #fontcolor, #bgcolor',
        '# Color columns are optional — omit to use channel defaults.',
        '# Lines starting with # are ignored.',
        `# Label prefix set to: "${label}"  (font ${lc} / bg ${lb})`,
        '# The label is a static badge — set it in the Label prefix field, not here.',
        '',
        `${label}: This is your first ticker item,#ffffff,${lb}`,
        `${label}: Replace these lines with your own content,#ffffff,${lb}`,
        'Weather update: Sunny skies expected throughout the week,#ffffff,#1a56db',
        'Sports: Home team wins 2-0 in last night match,#ffff00,#006400',
        'Markets: Exchange closes up 1.8% — energy sector leads gains,#ffffff,#7c3aed',
        'Traffic: Delays reported on the main highway — allow extra time,#000000,#f59e0b',
        'This line uses channel default colors',
        'This line sets only font color,#00ffcc',
    ]
    const blob = new Blob([rows.join('\n')], { type: 'text/csv' })
    const a = document.createElement('a')
    a.href = URL.createObjectURL(blob)
    a.download = 'ticker_sample.csv'
    a.click()
    URL.revokeObjectURL(a.href)
}

async function importTickerFile(e) {
    const file = e.target.files[0]
    if (!file) return
    const csrfToken = document.cookie.split('; ').find(r => r.startsWith('XSRF-TOKEN='))?.split('=')[1]
    const form = new FormData()
    form.append('file', file)
    try {
        const res = await fetch(route('channels.playout.ticker-upload', props.channel.id), {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': csrfToken ? decodeURIComponent(csrfToken) : '' },
            body: form,
        })
        const data = await res.json()
        if (data.success) {
            tickerItems.value = data.items.map(i => ({ text: i.text, color: i.color ?? '#ffffff', bg_color: i.bg_color ?? '#000000' }))
            tickerMessage.value = `Imported ${data.count} items`
            setTimeout(() => tickerMessage.value = '', 3000)
        } else {
            tickerMessage.value = data.error || 'Import failed'
        }
    } catch (err) {
        tickerMessage.value = 'Import error: ' + err.message
    }
    e.target.value = ''
}

async function saveTickerItems() {
    try {
        const csrfToken = document.cookie.split('; ').find(r => r.startsWith('XSRF-TOKEN='))?.split('=')[1]
        const res = await fetch(route('channels.playout.ticker-items', props.channel.id), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': csrfToken ? decodeURIComponent(csrfToken) : '' },
            body: JSON.stringify({
                items: tickerItems.value.filter(i => i.text.trim()),
                label: tickerLabel.value || null,
                label_color: tickerLabelColor.value,
                label_bg: tickerLabelBg.value,
            }),
        })
        const data = await res.json()
        if (data.success) {
            tickerMessage.value = 'Ticker pushed to air'
            setTimeout(() => tickerMessage.value = '', 3000)
        }
    } catch (err) {
        tickerMessage.value = 'Error: ' + err.message
    }
}

async function fetchNews() {
    fetchingNews.value = true
    tickerMessage.value = 'Fetching news…'
    try {
        const csrfToken = document.cookie.split('; ').find(r => r.startsWith('XSRF-TOKEN='))?.split('=')[1]
        const res = await fetch(route('channels.playout.fetch-news', props.channel.id) + '?lang=' + newsLang.value, {
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': csrfToken ? decodeURIComponent(csrfToken) : '' },
        })
        const data = await res.json()
        if (data.success) {
            const fetched = data.items.map(item => ({
                text: `[${item.label}] ${item.text}`,
                color: item.color,
                bg_color: item.bg_color ?? '#000000',
            }))
            // Remove any previously fetched news items (they start with [LABEL]) before prepending fresh ones
            const manual = tickerItems.value.filter(i => i.text.trim() && !i.text.match(/^\[[A-Z]+\]/));
            tickerItems.value = [...fetched, ...manual]
            // Auto-set label + ticker style to Cameroon palette
            if (data.label_colors?.CAMEROON) {
                tickerLabelBg.value = data.label_colors.CAMEROON.bg   // #007a5e green
                tickerLabelColor.value = data.label_colors.CAMEROON.fg // #ffffff
            }
            // Apply Cameroon-palette ticker style: dark green bg, yellow text
            tickerBgColor.value = '#003d2e'
            tickerFontColor.value = '#fcd116'
            await saveTickerSettings()
            tickerMessage.value = `Fetched ${data.count} headlines — review and push to air`
            setTimeout(() => tickerMessage.value = '', 6000)
        } else {
            tickerMessage.value = data.error || 'Fetch failed'
            setTimeout(() => tickerMessage.value = '', 4000)
        }
    } catch (err) {
        tickerMessage.value = 'Fetch error: ' + err.message
        setTimeout(() => tickerMessage.value = '', 4000)
    } finally {
        fetchingNews.value = false
    }
}

// Lower-third canvas
const ltCanvas = ref(null)
const ltCanvasHover = ref(null)
const activeLtPreset = ref(null)
const ltPresets = [
    { key: 'tl', icon: '↖', label: 'Top Left' },
    { key: 'tr', icon: '↗', label: 'Top Right' },
    { key: 'bl', icon: '↙', label: 'Bottom Left' },
    { key: 'br', icon: '↘', label: 'Bottom Right' },
]

function parseLtPosition() {
    const x = props.channel.lowerthird_x
    const y = props.channel.lowerthird_y
    if (x !== null && x !== undefined && y !== null && y !== undefined) return { x, y }
    const pos = props.channel.lowerthird_position ?? 'bottom-left'
    const m = 15
    const map = { 'top-left': [m,m], 'top-right': [-m,m], 'bottom-left': [m,-m], 'bottom-right': [-m,-m] }
    const [px, py] = map[pos] || [m, -m]
    return { x: px, y: py }
}
const parsedLt = parseLtPosition()
const ltX = ref(parsedLt.x)
const ltY = ref(parsedLt.y)

const ltMarkerStyle = computed(() => {
    const ax = ltX.value < 0 ? CANVAS_W + ltX.value : ltX.value
    const ay = ltY.value < 0 ? CANVAS_H + ltY.value : ltY.value
    return {
        left: Math.max(0, Math.min(1, ax / CANVAS_W)) * 100 + '%',
        top:  Math.max(0, Math.min(1, ay / CANVAS_H)) * 100 + '%',
    }
})
const ltMarkerLabel = computed(() => `${ltX.value}, ${ltY.value}`)

function onLtCanvasClick(e) {
    const rect = ltCanvas.value.getBoundingClientRect()
    const pctX = (e.clientX - rect.left) / rect.width
    const pctY = (e.clientY - rect.top) / rect.height
    let x = Math.round(pctX * CANVAS_W)
    let y = Math.round(pctY * CANVAS_H)
    if (pctX > 0.85) x = -(CANVAS_W - x)
    if (pctY > 0.85) y = -(CANVAS_H - y)
    ltX.value = x
    ltY.value = y
    activeLtPreset.value = null
}

function onLtCanvasHover(e) {
    const rect = ltCanvas.value.getBoundingClientRect()
    const pctX = (e.clientX - rect.left) / rect.width
    const pctY = (e.clientY - rect.top) / rect.height
    let x = Math.round(pctX * CANVAS_W)
    let y = Math.round(pctY * CANVAS_H)
    if (pctX > 0.85) x = -(CANVAS_W - x)
    if (pctY > 0.85) y = -(CANVAS_H - y)
    ltCanvasHover.value = { pct: { x: pctX, y: pctY }, label: `${x}, ${y}` }
}

function applyLtPreset(key) {
    const m = 15
    const map = { tl: [m,m], tr: [-m,m], bl: [m,-m], br: [-m,-m] }
    const [x, y] = map[key]
    ltX.value = x
    ltY.value = y
    activeLtPreset.value = key
}

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
            if (data.summary) summary.value = data.summary
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
            if (data.summary) summary.value = data.summary
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
    uploadProgress.value = 0
    try {
        const form = new FormData()
        form.append('media', file)
        const csrfToken = document.cookie.split('; ').find(row => row.startsWith('XSRF-TOKEN='))?.split('=')[1]
        await new Promise((resolve, reject) => {
            const xhr = new XMLHttpRequest()
            xhr.open('POST', route('channels.playout.items.store', props.channel.id))
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest')
            if (csrfToken) xhr.setRequestHeader('X-XSRF-TOKEN', decodeURIComponent(csrfToken))
            xhr.upload.addEventListener('progress', (e) => {
                if (e.lengthComputable) {
                    uploadProgress.value = Math.round((e.loaded / e.total) * 100)
                }
            })
            xhr.onload = () => {
                if (xhr.status >= 200 && xhr.status < 300) resolve()
                else reject(new Error(xhr.statusText))
            }
            xhr.onerror = () => reject(new Error('Network error'))
            xhr.send(form)
        })
        router.reload({ only: ['items', 'summary'] })
    } catch (e) {
        console.error('Upload failed', e)
    } finally {
        uploading.value = false
        uploadProgress.value = 0
        event.target.value = ''
    }
}

async function previewMediaUrl() {
    if (!mediaUrl.value) return
    urlError.value = ''
    urlPreview.value = null
    previewLoading.value = true
    try {
        const csrfToken = document.cookie.split('; ').find(row => row.startsWith('XSRF-TOKEN='))?.split('=')[1]
        const res = await fetch(route('channels.playout.preview-url', props.channel.id), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': csrfToken ? decodeURIComponent(csrfToken) : '' },
            body: JSON.stringify({ url: mediaUrl.value }),
        })
        const data = await res.json()
        if (res.ok) {
            urlPreview.value = data
        } else {
            urlError.value = data.errors?.url?.[0] || data.error || 'Failed to preview URL'
        }
    } catch (e) {
        urlError.value = 'Network error: ' + e.message
    } finally {
        previewLoading.value = false
    }
}

async function confirmAddUrl() {
    if (!mediaUrl.value) return
    addingUrl.value = true
    urlError.value = ''
    urlSuccess.value = ''
    try {
        const csrfToken = document.cookie.split('; ').find(row => row.startsWith('XSRF-TOKEN='))?.split('=')[1]
        const res = await fetch(route('channels.playout.url', props.channel.id), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': csrfToken ? decodeURIComponent(csrfToken) : '' },
            body: JSON.stringify({ url: mediaUrl.value }),
        })
        const data = await res.json()
        if (res.ok) {
            urlSuccess.value = data.message || 'URL added!'
            mediaUrl.value = ''
            urlPreview.value = null
            router.reload({ only: ['items', 'summary', 'downloadStatuses'] })
            setTimeout(() => urlSuccess.value = '', 4000)
        } else {
            urlError.value = data.errors?.url?.[0] || data.error || 'Failed to add URL'
        }
    } catch (e) {
        urlError.value = 'Network error: ' + e.message
    } finally {
        addingUrl.value = false
    }
}


async function probeItem(item) {
    probeResults.value[item.id] = { loading: true }
    try {
        const res = await fetch(route('channels.playout.items.probe', [props.channel.id, item.id]))
        const data = await res.json()
        if (data.playable) {
            const parts = []
            if (data.duration > 0) parts.push(formatDuration(data.duration))
            if (data.video) parts.push(data.video)
            if (data.audio) parts.push(data.audio)
            probeResults.value[item.id] = { playable: true, summary: parts.join(' · ') || 'OK' }
        } else {
            probeResults.value[item.id] = { playable: false, error: data.error || 'Cannot play' }
        }
    } catch (e) {
        probeResults.value[item.id] = { playable: false, error: 'Network error' }
    }
}

async function triggerYouTubeDownload(item) {    try {
        const csrfToken = document.cookie.split('; ').find(row => row.startsWith('XSRF-TOKEN='))?.split('=')[1]
        const res = await fetch(route('channels.playout.items.trigger-download', [props.channel.id, item.id]), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': csrfToken ? decodeURIComponent(csrfToken) : '',
            },
        })
        const data = await res.json()
        if (data.success) {
            downloadStatuses.value[item.id] = 'downloading'
        }
    } catch (e) {
        console.error('Trigger download failed:', e)
    }
}

// Poll download statuses every 5 seconds
let pollInterval = null
onMounted(() => {
    pollInterval = setInterval(async () => {
        const hasActive = Object.values(downloadStatuses.value).some(s => s === 'downloading' || s === 'queued')
        if (!hasActive) return
        try {
            const res = await fetch(route('channels.playout.download-status', props.channel.id))
            const data = await res.json()
            if (data.statuses) {
                downloadStatuses.value = { ...downloadStatuses.value, ...data.statuses }
            }
        } catch (e) { /* ignore */ }
    }, 5000)
})
onUnmounted(() => {
    if (pollInterval) clearInterval(pollInterval)
})

async function removeItem(item) {
    if (!confirm(`Remove "${item.custom_title || item.title}" from playlist?`)) return
    try {
        const csrfToken = document.cookie.split('; ').find(row => row.startsWith('XSRF-TOKEN='))?.split('=')[1]
        await fetch(route('channels.playout.items.destroy', [props.channel.id, item.id]), {
            method: 'DELETE',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': csrfToken ? decodeURIComponent(csrfToken) : '',
            },
        })
        items.value = items.value.filter(i => i.id !== item.id)
        router.reload({ only: ['summary'] })
    } catch (e) {
        console.error('Remove failed', e)
    }
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
        if (data.push_running !== undefined) pushRunning.value = data.push_running
        if (data.current_item?.title) nowPlayingTitle.value = data.current_item.title
        else if (!data.is_running) nowPlayingTitle.value = ''
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

async function saveClockSettings() {
    try {
        const csrfToken = document.cookie.split('; ').find(row => row.startsWith('XSRF-TOKEN='))?.split('=')[1]
        const res = await fetch(route('channels.playout.clock', props.channel.id), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': csrfToken ? decodeURIComponent(csrfToken) : '',
            },
            body: JSON.stringify({
                position: clockPosition.value,
                fontsize: clockFontsize.value,
                color: clockColor.value,
                format: clockFormat.value,
                enabled: clockEnabled.value,
                x: clockX.value,
                y: clockY.value,
                timezone: clockTimezone.value,
            }),
        })
        const data = await res.json()
        if (data.success) {
            clockMessage.value = 'Clock updated — playout will restart'
            setTimeout(() => clockMessage.value = '', 4000)
        }
    } catch (e) {
        clockMessage.value = 'Error: ' + e.message
    }
}

async function saveTickerSettings() {
    try {
        const csrfToken = document.cookie.split('; ').find(row => row.startsWith('XSRF-TOKEN='))?.split('=')[1]
        await fetch(route('channels.playout.ticker-settings', props.channel.id), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': csrfToken ? decodeURIComponent(csrfToken) : '',
            },
            body: JSON.stringify({
                bg_color: tickerBgColor.value,
                bg_opacity: tickerBgOpacity.value,
                font_size: tickerFontSize.value,
                font_color: tickerFontColor.value,
                speed: tickerSpeed.value,
                position: tickerPosition.value,
            }),
        })
    } catch (e) {
        console.error('Ticker settings save failed', e)
    }
}

async function saveResolution() {
    try {
        const csrfToken = document.cookie.split('; ').find(row => row.startsWith('XSRF-TOKEN='))?.split('=')[1]
        const res = await fetch(route('channels.playout.resolution', props.channel.id), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': csrfToken ? decodeURIComponent(csrfToken) : '',
            },
            body: JSON.stringify({ resolution: outputResolution.value }),
        })
        const data = await res.json()
        if (data.success) {
            resolutionMessage.value = 'Resolution updated — playout will restart'
            setTimeout(() => resolutionMessage.value = '', 4000)
        }
    } catch (e) {
        resolutionMessage.value = 'Error: ' + e.message
    }
}

async function saveLowerthirdSettings() {
    try {
        const csrfToken = document.cookie.split('; ').find(row => row.startsWith('XSRF-TOKEN='))?.split('=')[1]
        const res = await fetch(route('channels.playout.lowerthird', props.channel.id), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': csrfToken ? decodeURIComponent(csrfToken) : '' },
            body: JSON.stringify({
                x: ltX.value,
                y: ltY.value,
                fontsize: lowerthirdFontsize.value,
                font_color: lowerthirdFontColor.value,
                bg_color: lowerthirdBgColor.value,
                bg_opacity: lowerthirdBgOpacity.value,
                enabled: lowerthirdEnabled.value,
            }),
        })
        const data = await res.json()
        if (data.success) {
            lowerthirdMessage.value = 'NOW PLAYING updated — playout will restart'
            setTimeout(() => lowerthirdMessage.value = '', 4000)
        }
    } catch (e) {
        lowerthirdMessage.value = 'Error: ' + e.message
    }
}

const posCanvas = ref(null)
const canvasHover = ref(null)
const presetMargin = ref(20)
const activePreset = ref(null)
const presets = [
    { key: 'tl', icon: '↖', label: 'Top Left' },
    { key: 'tr', icon: '↗', label: 'Top Right' },
    { key: 'bl', icon: '↙', label: 'Bottom Left' },
    { key: 'br', icon: '↘', label: 'Bottom Right' },
    { key: 'c',  icon: '⊕', label: 'Center' },
]

// Canvas dimensions assumed 1920x1080 for coordinate mapping
const CANVAS_W = 1920
const CANVAS_H = 1080

function canvasPctFromXY(x, y) {
    // x negative = from right, y negative = from bottom
    const ax = x < 0 ? CANVAS_W + x : x
    const ay = y < 0 ? CANVAS_H + y : y
    return {
        x: Math.max(0, Math.min(1, ax / CANVAS_W)),
        y: Math.max(0, Math.min(1, ay / CANVAS_H)),
    }
}

const logoMarkerStyle = computed(() => {
    const pct = canvasPctFromXY(logoX.value, logoY.value)
    return { left: pct.x * 100 + '%', top: pct.y * 100 + '%' }
})

const logoMarkerLabel = computed(() => `${logoX.value}, ${logoY.value}`)

// Dynamic slider ranges based on canvas dimensions
const logoXRange = computed(() => ({ min: -CANVAS_W, max: CANVAS_W }))
const logoYRange = computed(() => ({ min: -CANVAS_H, max: CANVAS_H }))

function onCanvasClick(e) {
    const rect = posCanvas.value.getBoundingClientRect()
    const pctX = (e.clientX - rect.left) / rect.width
    const pctY = (e.clientY - rect.top) / rect.height
    // Snap to nearest edge anchor if within 15% of edge
    const snapThreshold = 0.15
    let x = Math.round(pctX * CANVAS_W)
    let y = Math.round(pctY * CANVAS_H)
    if (pctX > 1 - snapThreshold) x = -(CANVAS_W - x)
    if (pctY > 1 - snapThreshold) y = -(CANVAS_H - y)
    logoX.value = x
    logoY.value = y
    activePreset.value = null
}

function onCanvasHover(e) {
    const rect = posCanvas.value.getBoundingClientRect()
    const pctX = (e.clientX - rect.left) / rect.width
    const pctY = (e.clientY - rect.top) / rect.height
    const snapThreshold = 0.15
    let x = Math.round(pctX * CANVAS_W)
    let y = Math.round(pctY * CANVAS_H)
    if (pctX > 1 - snapThreshold) x = -(CANVAS_W - x)
    if (pctY > 1 - snapThreshold) y = -(CANVAS_H - y)
    canvasHover.value = { pct: { x: pctX, y: pctY }, label: `${x}, ${y}` }
}

function applyPreset(corner) {
    const m = presetMargin.value
    const presets = {
        tl: [m, m],
        tr: [-m, m],
        bl: [m, -m],
        br: [-m, -m],
        c:  [Math.round(CANVAS_W / 2), Math.round(CANVAS_H / 2)],
    }
    const [x, y] = presets[corner]
    logoX.value = x
    logoY.value = y
    activePreset.value = corner
}

function onLogoFileChange(e) {
    logoFile.value = e.target.files[0] || null
    if (logoFile.value) {
        logoPreviewUrl.value = URL.createObjectURL(logoFile.value)
    }
}

async function uploadLogo() {
    if (!logoFile.value || logoUploading.value) return
    logoUploading.value = true
    logoUploadMessage.value = ''
    logoUploadError.value = false
    try {
        const form = new FormData()
        form.append('logo', logoFile.value)
        const csrfToken = document.cookie.split('; ').find(r => r.startsWith('XSRF-TOKEN='))?.split('=')[1]
        const res = await fetch(route('channels.playout.logo', props.channel.id), {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': csrfToken ? decodeURIComponent(csrfToken) : '' },
            body: form,
        })
        const data = await res.json()
        if (data.success) {
            logoUploadMessage.value = 'Logo uploaded'
            props.channel.logo_media_id = data.logo_media_id
            logoPreviewUrl.value = `/channels/${props.channel.id}/playout/logo-preview?t=${Date.now()}`
            logoFile.value = null
            setTimeout(() => logoUploadMessage.value = '', 3000)
        } else {
            logoUploadError.value = true
            logoUploadMessage.value = data.message || 'Upload failed'
        }
    } catch (e) {
        logoUploadError.value = true
        logoUploadMessage.value = 'Network error'
    } finally {
        logoUploading.value = false
    }
}

async function removeLogo() {
    if (!confirm('Remove logo overlay?')) return
    const csrfToken = document.cookie.split('; ').find(r => r.startsWith('XSRF-TOKEN='))?.split('=')[1]
    await fetch(route('channels.playout.logo.remove', props.channel.id), {
        method: 'DELETE',
        headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': csrfToken ? decodeURIComponent(csrfToken) : '' },
    })
    props.channel.logo_media_id = null
    logoPreviewUrl.value = null
}

async function toggleLogo() {
    const csrfToken = document.cookie.split('; ').find(r => r.startsWith('XSRF-TOKEN='))?.split('=')[1]
    const res = await fetch(route('channels.playout.logo-toggle', props.channel.id), {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': csrfToken ? decodeURIComponent(csrfToken) : '' },
    })
    const data = await res.json()
    if (data.success) logoEnabled.value = data.logo_enabled
}

async function saveLogoSettings() {
    logoSaving.value = true
    logoPositionMessage.value = ''
    try {
        const csrfToken = document.cookie.split('; ').find(r => r.startsWith('XSRF-TOKEN='))?.split('=')[1]
        const headers = { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': csrfToken ? decodeURIComponent(csrfToken) : '' }
        // Save position and scale in parallel
        await Promise.all([
            fetch(route('channels.playout.logo-position', props.channel.id), {
                method: 'POST', headers,
                body: JSON.stringify({ x: logoX.value, y: logoY.value }),
            }),
            fetch(route('channels.playout.logo-scale', props.channel.id), {
                method: 'POST', headers,
                body: JSON.stringify({ scale: logoScale.value }),
            }),
        ])
        logoPositionMessage.value = 'Applied'
        setTimeout(() => logoPositionMessage.value = '', 3000)
    } catch (e) {
        logoPositionMessage.value = 'Error: ' + e.message
    } finally {
        logoSaving.value = false
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
