@props([
    'name' => 'image-editor-modal',
    'title' => 'Edit Image',
    'aspectRatio' => 'free', // 'free', '1:1', '4:3', '16:9', 'circle'
])

<div
    x-data="alpineImageEditor({ aspectRatio: '{{ $aspectRatio }}' })"
    @open-image-editor.window="open($event.detail)"
    x-show="isOpen"
    x-cloak
    class="fixed inset-0 z-50 overflow-y-auto"
    role="dialog"
    aria-modal="true"
>
    <!-- Backdrop -->
    <div
        x-show="isOpen"
        x-transition:enter="ease-out duration-200"
        x-transition:enter-start="opacity-0"
        x-transition:enter-end="opacity-100"
        x-transition:leave="ease-in duration-150"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0"
        class="fixed inset-0 bg-background/80 backdrop-blur-md"
        @click="close()"
    ></div>

    <!-- Modal Box -->
    <div class="flex min-h-full items-center justify-center p-3 sm:p-4">
        <div
            x-show="isOpen"
            x-transition:enter="ease-out duration-200"
            x-transition:enter-start="opacity-0 scale-95"
            x-transition:enter-end="opacity-100 scale-100"
            x-transition:leave="ease-in duration-150"
            x-transition:leave-start="opacity-100 scale-100"
            x-transition:leave-end="opacity-0 scale-95"
            class="relative w-full max-w-3xl rounded-2xl border border-border bg-card shadow-2xl overflow-hidden flex flex-col max-h-[90vh]"
            @click.stop
        >
            <!-- Header -->
            <div class="px-5 py-3.5 border-b border-border flex items-center justify-between bg-card shrink-0">
                <div class="flex items-center gap-2">
                    <div class="p-1.5 rounded-lg bg-primary/10 text-primary">
                        <x-icon name="crop" class="h-4 w-4" />
                    </div>
                    <div>
                        <h3 class="text-sm font-bold text-foreground">{{ __($title) }}</h3>
                        <p class="text-[11px] text-muted-foreground">{{ __('Crop, rotate, doodle, add text, or stamp stickers') }}</p>
                    </div>
                </div>

                <div class="flex items-center gap-1.5">
                    <!-- Undo Button -->
                    <button
                        type="button"
                        @click="undo()"
                        class="p-1.5 rounded-lg text-muted-foreground hover:text-foreground hover:bg-secondary transition-colors cursor-pointer"
                        title="{{ __('Undo last modification') }}"
                    >
                        <x-icon name="undo-2" class="h-4 w-4" />
                    </button>

                    <!-- Close Button -->
                    <button
                        type="button"
                        @click="close()"
                        class="p-1.5 rounded-lg text-muted-foreground hover:text-foreground hover:bg-secondary transition-colors cursor-pointer"
                    >
                        <x-icon name="x" class="h-4 w-4" />
                    </button>
                </div>
            </div>

            <!-- Toolbar Tabs -->
            <div class="px-4 py-2 border-b border-border/80 bg-secondary/30 flex items-center gap-1 shrink-0 overflow-x-auto text-xs">
                <button
                    type="button"
                    @click="activeTab = 'crop'"
                    :class="activeTab === 'crop' ? 'bg-background text-foreground shadow-xs font-semibold' : 'text-muted-foreground hover:text-foreground'"
                    class="px-3 py-1.5 rounded-lg flex items-center gap-1.5 transition-colors cursor-pointer shrink-0"
                >
                    <x-icon name="crop" class="h-3.5 w-3.5" />
                    <span>{{ __('Crop') }}</span>
                </button>

                <button
                    type="button"
                    @click="activeTab = 'rotate'"
                    :class="activeTab === 'rotate' ? 'bg-background text-foreground shadow-xs font-semibold' : 'text-muted-foreground hover:text-foreground'"
                    class="px-3 py-1.5 rounded-lg flex items-center gap-1.5 transition-colors cursor-pointer shrink-0"
                >
                    <x-icon name="rotate-cw" class="h-3.5 w-3.5" />
                    <span>{{ __('Rotate & Flip') }}</span>
                </button>

                <button
                    type="button"
                    @click="activeTab = 'draw'"
                    :class="activeTab === 'draw' ? 'bg-background text-foreground shadow-xs font-semibold' : 'text-muted-foreground hover:text-foreground'"
                    class="px-3 py-1.5 rounded-lg flex items-center gap-1.5 transition-colors cursor-pointer shrink-0"
                >
                    <x-icon name="brush" class="h-3.5 w-3.5" />
                    <span>{{ __('Draw') }}</span>
                </button>

                <button
                    type="button"
                    @click="activeTab = 'text'"
                    :class="activeTab === 'text' ? 'bg-background text-foreground shadow-xs font-semibold' : 'text-muted-foreground hover:text-foreground'"
                    class="px-3 py-1.5 rounded-lg flex items-center gap-1.5 transition-colors cursor-pointer shrink-0"
                >
                    <x-icon name="type" class="h-3.5 w-3.5" />
                    <span>{{ __('Add Text') }}</span>
                </button>

                <button
                    type="button"
                    @click="activeTab = 'emoji'"
                    :class="activeTab === 'emoji' ? 'bg-background text-foreground shadow-xs font-semibold' : 'text-muted-foreground hover:text-foreground'"
                    class="px-3 py-1.5 rounded-lg flex items-center gap-1.5 transition-colors cursor-pointer shrink-0"
                >
                    <x-icon name="sticker" class="h-3.5 w-3.5" />
                    <span>{{ __('Stickers') }}</span>
                </button>
            </div>

            <!-- Tab Context Bar -->
            <div class="px-4 py-2.5 border-b border-border bg-card flex flex-wrap items-center justify-between gap-3 text-xs shrink-0">
                <!-- Crop Tab Options -->
                <div x-show="activeTab === 'crop'" class="flex flex-wrap items-center gap-1.5">
                    <span class="text-muted-foreground font-medium mr-1">{{ __('Ratio:') }}</span>
                    <button
                        type="button"
                        @click="setAspectRatio('free')"
                        :class="aspectRatio === 'free' ? 'bg-primary text-primary-foreground font-bold' : 'bg-background border border-border text-foreground hover:bg-secondary'"
                        class="px-2.5 py-1 rounded-md transition-colors cursor-pointer"
                    >
                        {{ __('Freeform') }}
                    </button>
                    <button
                        type="button"
                        @click="setAspectRatio('1:1')"
                        :class="aspectRatio === '1:1' ? 'bg-primary text-primary-foreground font-bold' : 'bg-background border border-border text-foreground hover:bg-secondary'"
                        class="px-2.5 py-1 rounded-md transition-colors cursor-pointer"
                    >
                        1:1 (Square)
                    </button>
                    <button
                        type="button"
                        @click="setAspectRatio('circle')"
                        :class="aspectRatio === 'circle' ? 'bg-primary text-primary-foreground font-bold' : 'bg-background border border-border text-foreground hover:bg-secondary'"
                        class="px-2.5 py-1 rounded-md transition-colors cursor-pointer"
                    >
                        {{ __('Avatar Circle') }}
                    </button>
                    <button
                        type="button"
                        @click="setAspectRatio('4:3')"
                        :class="aspectRatio === '4:3' ? 'bg-primary text-primary-foreground font-bold' : 'bg-background border border-border text-foreground hover:bg-secondary'"
                        class="px-2.5 py-1 rounded-md transition-colors cursor-pointer"
                    >
                        4:3
                    </button>
                    <button
                        type="button"
                        @click="setAspectRatio('16:9')"
                        :class="aspectRatio === '16:9' ? 'bg-primary text-primary-foreground font-bold' : 'bg-background border border-border text-foreground hover:bg-secondary'"
                        class="px-2.5 py-1 rounded-md transition-colors cursor-pointer"
                    >
                        16:9
                    </button>
                </div>

                <!-- Rotate Tab Options -->
                <div x-show="activeTab === 'rotate'" class="flex flex-wrap items-center gap-2">
                    <button
                        type="button"
                        @click="rotateRight()"
                        class="px-3 py-1.5 rounded-lg bg-secondary hover:bg-secondary/80 text-foreground font-medium flex items-center gap-1.5 transition-colors cursor-pointer"
                    >
                        <x-icon name="rotate-cw" class="h-3.5 w-3.5" />
                        <span>{{ __('Rotate 90°') }}</span>
                    </button>
                    <button
                        type="button"
                        @click="toggleFlipH()"
                        :class="flipH ? 'bg-primary text-primary-foreground' : 'bg-background border border-border text-foreground hover:bg-secondary'"
                        class="px-3 py-1.5 rounded-lg flex items-center gap-1.5 transition-colors cursor-pointer"
                    >
                        <x-icon name="flip-horizontal" class="h-3.5 w-3.5" />
                        <span>{{ __('Flip Horizontal') }}</span>
                    </button>
                    <button
                        type="button"
                        @click="toggleFlipV()"
                        :class="flipV ? 'bg-primary text-primary-foreground' : 'bg-background border border-border text-foreground hover:bg-secondary'"
                        class="px-3 py-1.5 rounded-lg flex items-center gap-1.5 transition-colors cursor-pointer"
                    >
                        <x-icon name="flip-vertical" class="h-3.5 w-3.5" />
                        <span>{{ __('Flip Vertical') }}</span>
                    </button>
                </div>

                <!-- Draw Tab Options -->
                <div x-show="activeTab === 'draw'" class="flex flex-wrap items-center gap-3">
                    <div class="flex items-center gap-1.5">
                        <span class="text-muted-foreground font-medium">{{ __('Color:') }}</span>
                        @foreach (['#ef4444', '#f97316', '#eab308', '#22c55e', '#3b82f6', '#8b5cf6', '#ec4899', '#ffffff', '#000000'] as $c)
                            <button
                                type="button"
                                @click="drawColor = '{{ $c }}'"
                                class="h-5 w-5 rounded-full border-2 transition-transform cursor-pointer {{ $c === '#ffffff' ? 'border-border' : '' }}"
                                :class="drawColor === '{{ $c }}' ? 'scale-125 ring-2 ring-primary' : 'hover:scale-110'"
                                style="background-color: {{ $c }}"
                            ></button>
                        @endforeach
                    </div>
                    <div class="flex items-center gap-1.5 ml-2">
                        <span class="text-muted-foreground font-medium">{{ __('Brush Size:') }}</span>
                        <input type="range" x-model="brushSize" min="2" max="24" class="w-24 accent-primary" />
                        <span x-text="brushSize + 'px'" class="text-muted-foreground font-mono"></span>
                    </div>
                </div>

                <!-- Text Tab Options -->
                <div x-show="activeTab === 'text'" class="flex flex-wrap items-center gap-2 flex-1">
                    <input
                        type="text"
                        x-model="newText"
                        @keydown.enter.prevent="addTextOverlay()"
                        placeholder="{{ __('Type text to overlay…') }}"
                        class="rounded-lg border border-border bg-background px-3 py-1 text-xs text-foreground flex-1 min-w-[140px] focus:border-primary focus:outline-none"
                    />
                    <select x-model="textColor" class="rounded-lg border border-border bg-background px-2 py-1 text-xs">
                        <option value="#ffffff">{{ __('White') }}</option>
                        <option value="#000000">{{ __('Black') }}</option>
                        <option value="#ef4444">{{ __('Red') }}</option>
                        <option value="#3b82f6">{{ __('Blue') }}</option>
                        <option value="#22c55e">{{ __('Green') }}</option>
                        <option value="#eab308">{{ __('Yellow') }}</option>
                    </select>
                    <select x-model="textSize" class="rounded-lg border border-border bg-background px-2 py-1 text-xs">
                        <option value="18">18px</option>
                        <option value="24">24px</option>
                        <option value="32">32px</option>
                        <option value="48">48px</option>
                    </select>
                    <button
                        type="button"
                        @click="addTextOverlay()"
                        class="px-3 py-1 rounded-lg bg-primary text-primary-foreground font-semibold hover:bg-primary/90 cursor-pointer"
                    >
                        {{ __('Add Text') }}
                    </button>
                </div>

                <!-- Emoji Tab Options -->
                <div x-show="activeTab === 'emoji'" class="flex flex-wrap items-center gap-1.5">
                    <span class="text-muted-foreground font-medium mr-1">{{ __('Add Sticker:') }}</span>
                    @foreach (['😀', '😂', '😍', '🔥', '🎉', '🚀', '👍', '❤️', '👏', '⭐', '💡', '🛡️', '⚡', '💯'] as $em)
                        <button
                            type="button"
                            @click="addEmojiOverlay('{{ $em }}')"
                            class="text-xl p-1 hover:scale-125 transition-transform cursor-pointer"
                        >
                            {{ $em }}
                        </button>
                    @endforeach
                </div>
            </div>

            <!-- Canvas Work Area -->
            <div
                x-ref="canvasContainer"
                class="p-4 bg-muted/40 flex-1 flex items-center justify-center overflow-auto min-h-[280px] max-h-[55vh] relative select-none"
            >
                <div class="relative shadow-xl rounded-lg overflow-hidden border border-border bg-black/10">
                    <canvas
                        x-ref="mainCanvas"
                        @mousedown="startDrawing($event)"
                        @mousemove="draw($event)"
                        @mouseup="stopDrawing()"
                        @mouseleave="stopDrawing()"
                        :class="activeTab === 'draw' ? 'cursor-crosshair' : 'cursor-default'"
                    ></canvas>

                    <!-- Crop Overlay Box -->
                    <div
                        x-show="activeTab === 'crop'"
                        class="absolute border-2 border-primary shadow-2xl pointer-events-none transition-all"
                        :class="aspectRatio === 'circle' ? 'rounded-full' : 'rounded-xs'"
                        :style="`left: ${cropX}px; top: ${cropY}px; width: ${cropW}px; height: ${cropH}px; box-shadow: 0 0 0 9999px rgba(0,0,0,0.55);`"
                    >
                        <div class="absolute inset-0 grid grid-cols-3 grid-rows-3 opacity-30 border border-primary/40 pointer-events-none">
                            <div class="border-r border-b border-primary/30"></div>
                            <div class="border-r border-b border-primary/30"></div>
                            <div class="border-b border-primary/30"></div>
                            <div class="border-r border-b border-primary/30"></div>
                            <div class="border-r border-b border-primary/30"></div>
                            <div class="border-b border-primary/30"></div>
                            <div class="border-r border-primary/30"></div>
                            <div class="border-r border-primary/30"></div>
                            <div></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Footer -->
            <div class="px-5 py-3.5 border-t border-border flex items-center justify-between bg-card">
                <div class="text-xs text-muted-foreground">
                    <span x-show="activeTab === 'crop'">{{ __('Adjust aspect ratio to set crop boundaries') }}</span>
                    <span x-show="activeTab === 'rotate'">{{ __('Rotate or flip the image') }}</span>
                    <span x-show="activeTab === 'draw'">{{ __('Click and drag on image to sketch') }}</span>
                    <span x-show="activeTab === 'text'">{{ __('Click Add Text to place text on image') }}</span>
                    <span x-show="activeTab === 'emoji'">{{ __('Click stickers to stamp on image') }}</span>
                </div>

                <div class="flex items-center gap-2">
                    <x-ui.button type="button" @click="close()" variant="outline" size="sm">
                        {{ __('Cancel') }}
                    </x-ui.button>
                    <x-ui.button type="button" @click="applyAndExport()" variant="default" size="sm" icon="check">
                        {{ __('Apply & Use Image') }}
                    </x-ui.button>
                </div>
            </div>
        </div>
    </div>
</div>
