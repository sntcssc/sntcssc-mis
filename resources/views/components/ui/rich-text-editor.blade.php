@props([
    'label' => null,
    'error' => null,
    'hint' => null,
    'placeholder' => 'Write your content here…',
    'minHeight' => '240px',
])

<div
    x-data="{
        content: @entangle($attributes->wire('model')),
        quill: null,
        isUpdating: false,
        initQuill() {
            if (this.quill) return;

            const container = this.$refs.editorContainer;
            if (!container) return;

            // Load Quill if not already present on the page
            if (typeof Quill === 'undefined') {
                if (!document.getElementById('quill-css')) {
                    const link = document.createElement('link');
                    link.id = 'quill-css';
                    link.rel = 'stylesheet';
                    link.href = 'https://cdn.jsdelivr.net/npm/quill@2.0.3/dist/quill.snow.css';
                    document.head.appendChild(link);
                }

                if (!document.getElementById('quill-js')) {
                    const script = document.createElement('script');
                    script.id = 'quill-js';
                    script.src = 'https://cdn.jsdelivr.net/npm/quill@2.0.3/dist/quill.js';
                    script.onload = () => this.mountEditor();
                    document.head.appendChild(script);
                    return;
                }
            }

            this.mountEditor();
        },
        mountEditor() {
            if (this.quill || typeof Quill === 'undefined') return;

            const toolbarOptions = [
                [{ 'header': [1, 2, 3, false] }],
                ['bold', 'italic', 'underline', 'strike'],
                [{ 'color': [] }, { 'background': [] }],
                [{ 'list': 'ordered'}, { 'list': 'bullet' }],
                [{ 'align': [] }],
                ['blockquote', 'code-block'],
                ['link', 'clean']
            ];

            this.quill = new Quill(this.$refs.editorContainer, {
                theme: 'snow',
                placeholder: '{{ $placeholder }}',
                modules: {
                    toolbar: toolbarOptions
                }
            });

            // Set initial content
            if (this.content) {
                this.quill.root.innerHTML = this.content;
            }

            // Sync changes to Livewire
            this.quill.on('text-change', () => {
                if (this.isUpdating) return;
                const html = this.quill.root.innerHTML === '<p><br></p>' ? '' : this.quill.root.innerHTML;
                this.content = html;
            });

            // Watch external content changes
            this.$watch('content', (value) => {
                if (!this.quill) return;
                const currentHtml = this.quill.root.innerHTML === '<p><br></p>' ? '' : this.quill.root.innerHTML;
                if ((value || '') !== currentHtml) {
                    this.isUpdating = true;
                    this.quill.root.innerHTML = value || '';
                    this.isUpdating = false;
                }
            });
        }
    }"
    x-init="initQuill()"
    wire:ignore
    {{ $attributes->only(['class', 'data-test']) }}
    class="rich-text-wrapper space-y-1.5"
>
    @if ($label)
        <label class="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground block">
            {{ $label }}@if ($attributes->get('required')) <span class="text-destructive">*</span>@endif
        </label>
    @endif

    <div class="rounded-xl border border-input bg-card shadow-2xs overflow-hidden focus-within:border-ring focus-within:ring-[3px] focus-within:ring-ring/50 transition-all">
        <div x-ref="editorContainer" style="min-height: {{ $minHeight }};" class="quill-editor-body text-foreground"></div>
    </div>

    @if ($error)
        <p class="mt-1.5 text-xs text-destructive">{{ $error }}</p>
    @elseif ($hint)
        <p class="mt-1.5 text-xs text-muted-foreground">{{ $hint }}</p>
    @endif
</div>

<style>
    .rich-text-wrapper .ql-toolbar.ql-snow {
        border: none;
        border-bottom: 1px solid var(--border);
        background: var(--muted);
        padding: 8px 12px;
        display: flex;
        flex-wrap: wrap;
        gap: 4px;
    }
    .rich-text-wrapper .ql-container.ql-snow {
        border: none;
        font-family: inherit;
        font-size: 0.925rem;
    }
    .rich-text-wrapper .ql-editor {
        min-height: {{ $minHeight }};
        padding: 14px 16px;
        color: var(--foreground);
        line-height: 1.65;
    }
    .rich-text-wrapper .ql-editor.ql-blank::before {
        color: var(--muted-foreground);
        font-style: normal;
        left: 16px;
    }
    .dark .rich-text-wrapper .ql-stroke {
        stroke: #a1a1aa !important;
    }
    .dark .rich-text-wrapper .ql-fill {
        fill: #a1a1aa !important;
    }
    .dark .rich-text-wrapper .ql-picker {
        color: #d4d4d8 !important;
    }
    .dark .rich-text-wrapper .ql-picker-options {
        background-color: #18181b !important;
        border-color: rgba(255,255,255,0.1) !important;
    }
</style>
