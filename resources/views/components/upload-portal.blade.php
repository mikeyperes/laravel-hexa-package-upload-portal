{{--
    Upload Portal Component
    Usage: <x-upload-portal::upload-portal context="article" :context-id="$draftId" :multi="true" />

    Or include directly:
    @include('upload-portal::components.upload-portal', ['context' => 'article', 'contextId' => $draftId, 'multi' => true])
--}}

@php
    $context = $context ?? 'general';
    $contextId = $contextId ?? 0;
    $multi = $multi ?? true;
    $maxFiles = config('upload-portal.max_files_per_upload', 20);
    $maxSize = config('upload-portal.max_file_size', 10240);
    $allowed = implode(', ', array_map(fn($t) => '.' . $t, config('upload-portal.allowed_types', ['jpg','jpeg','png','gif','webp'])));
    $componentId = 'upload-portal-' . uniqid();
@endphp

<div x-data="uploadPortalModal('{{ $context }}', {{ $contextId }}, {{ $multi ? 'true' : 'false' }})" id="{{ $componentId }}">
    {{-- Trigger button (slot or default) --}}
    <button @click="open = true" type="button" class="bg-indigo-600 text-white px-4 py-2 rounded-lg text-sm hover:bg-indigo-700 inline-flex items-center gap-2">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/></svg>
        Upload Files
    </button>

    {{-- Modal --}}
    <div x-show="open" x-cloak class="fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-50" @click.self="open = false" @keydown.escape.window="open = false">
        <div class="bg-white rounded-2xl shadow-xl w-full max-w-3xl max-h-[85vh] overflow-hidden flex flex-col" @click.stop>
            {{-- Header --}}
            <div class="flex items-center justify-between px-6 py-4 border-b border-gray-200">
                <h3 class="font-semibold text-gray-800">Upload Files</h3>
                <button @click="open = false" class="text-gray-400 hover:text-gray-600"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg></button>
            </div>

            {{-- Body --}}
            <div class="flex-1 overflow-y-auto px-6 py-4 space-y-4">
                {{-- Drop zone --}}
                <div class="border-2 border-dashed border-gray-300 rounded-xl p-8 text-center hover:border-indigo-400 transition-colors cursor-pointer"
                     @dragover.prevent="dragOver = true" @dragleave="dragOver = false"
                     @drop.prevent="dragOver = false; handleDrop($event)"
                     :class="dragOver ? 'border-indigo-500 bg-indigo-50' : ''"
                     @click="$refs.fileInput.click()">
                    <svg class="w-10 h-10 text-gray-300 mx-auto mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/></svg>
                    <p class="text-sm text-gray-500">Drag & drop files here or <span class="text-indigo-600 font-medium">browse</span></p>
                    <p class="text-xs text-gray-400 mt-1">{{ $allowed }} | Max {{ round($maxSize / 1024, 1) }} MB per file | Up to {{ $maxFiles }} files</p>
                    <input type="file" x-ref="fileInput" class="hidden" {{ $multi ? 'multiple' : '' }} accept="{{ $allowed }}" @change="handleFiles($event.target.files)">
                </div>

                {{-- Upload queue --}}
                <div x-show="queue.length > 0" class="space-y-2">
                    <template x-for="(item, idx) in queue" :key="idx">
                        <div class="flex items-center gap-3 bg-gray-50 rounded-lg p-3">
                            <img x-show="item.preview" :src="item.preview" class="w-12 h-12 rounded object-cover flex-shrink-0">
                            <div class="flex-1 min-w-0">
                                <p class="text-sm text-gray-800 break-words" x-text="item.name"></p>
                                <p class="text-xs text-gray-400" x-text="(item.size / 1024).toFixed(0) + ' KB'"></p>
                                {{-- Progress bar --}}
                                <div class="w-full bg-gray-200 rounded-full h-1.5 mt-1">
                                    <div class="h-1.5 rounded-full transition-all duration-300" :class="item.status === 'done' ? 'bg-green-500' : (item.status === 'error' ? 'bg-red-500' : 'bg-indigo-500')" :style="'width: ' + item.progress + '%'"></div>
                                </div>
                            </div>
                            <span class="text-xs font-medium flex-shrink-0" :class="{ 'text-green-600': item.status === 'done', 'text-red-600': item.status === 'error', 'text-indigo-600': item.status === 'uploading', 'text-gray-400': item.status === 'pending' }" x-text="item.status === 'done' ? 'Done' : (item.status === 'error' ? 'Failed' : (item.status === 'uploading' ? item.progress + '%' : 'Pending'))"></span>
                            <button @click="removeFromQueue(idx)" class="text-gray-400 hover:text-red-500 flex-shrink-0"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg></button>
                        </div>
                    </template>
                </div>

                {{-- Uploaded files gallery --}}
                <div x-show="uploaded.length > 0" class="border-t border-gray-200 pt-4">
                    <h4 class="text-xs font-semibold text-gray-500 uppercase mb-2">Uploaded (<span x-text="uploaded.length"></span>)</h4>
                    <div class="grid grid-cols-4 gap-2">
                        <template x-for="(file, fidx) in uploaded" :key="file.id">
                            <div class="relative group rounded-lg overflow-hidden border border-gray-200">
                                <img :src="file.url" class="w-full h-24 object-cover">
                                <div class="absolute inset-0 bg-black bg-opacity-0 group-hover:bg-opacity-40 transition-all flex items-center justify-center">
                                    <button @click="deleteFile(file.id, fidx)" class="opacity-0 group-hover:opacity-100 bg-red-600 text-white p-1.5 rounded-full text-xs">
                                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                    </button>
                                </div>
                                <p class="text-[10px] text-gray-500 px-1 py-0.5 truncate" x-text="file.filename"></p>
                            </div>
                        </template>
                    </div>
                </div>
            </div>

            {{-- Footer --}}
            <div class="px-6 py-3 border-t border-gray-200 flex items-center justify-between">
                <span class="text-xs text-gray-400" x-text="queue.filter(q => q.status === 'done').length + '/' + queue.length + ' uploaded'"></span>
                <div class="flex gap-2">
                    <button @click="uploadAll()" :disabled="uploading || queue.filter(q => q.status === 'pending').length === 0" class="bg-indigo-600 text-white px-4 py-2 rounded-lg text-sm hover:bg-indigo-700 disabled:opacity-50 inline-flex items-center gap-2">
                        <svg x-show="uploading" x-cloak class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg>
                        <span x-text="uploading ? 'Uploading...' : 'Upload All'"></span>
                    </button>
                    <button @click="open = false" class="text-gray-500 hover:text-gray-700 px-3 py-2 text-sm">Close</button>
                </div>
            </div>
        </div>
    </div>
</div>

@once
@push('scripts')
<script>
function uploadPortalModal(context, contextId, multi) {
    return {
        open: false,
        dragOver: false,
        uploading: false,
        queue: [],
        uploaded: [],
        context,
        contextId,
        multi,

        handleDrop(event) {
            this.handleFiles(event.dataTransfer.files);
        },

        handleFiles(fileList) {
            for (const file of fileList) {
                const preview = file.type.startsWith('image/') ? URL.createObjectURL(file) : null;
                this.queue.push({ file, name: file.name, size: file.size, preview, progress: 0, status: 'pending', error: '' });
            }
        },

        removeFromQueue(idx) {
            this.queue.splice(idx, 1);
        },

        async uploadAll() {
            this.uploading = true;
            const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
            const pending = this.queue.filter(q => q.status === 'pending');

            for (const item of pending) {
                item.status = 'uploading';
                item.progress = 10;

                const form = new FormData();
                form.append('files[]', item.file);
                form.append('context', this.context);
                form.append('context_id', this.contextId);
                form.append('temp', '1');

                try {
                    const resp = await fetch('/upload-portal/upload', {
                        method: 'POST',
                        headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
                        body: form
                    });
                    item.progress = 90;
                    const data = await resp.json();
                    if (data.success && data.uploaded?.length > 0) {
                        item.status = 'done';
                        item.progress = 100;
                        data.uploaded.forEach(u => this.uploaded.push(u));
                    } else {
                        item.status = 'error';
                        item.error = data.errors?.[0] || data.message || 'Upload failed';
                    }
                } catch (e) {
                    item.status = 'error';
                    item.error = e.message;
                }
            }
            this.uploading = false;
        },

        async deleteFile(fileId, idx) {
            const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
            try {
                await fetch('/upload-portal/delete/' + fileId, {
                    method: 'DELETE',
                    headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' }
                });
                this.uploaded.splice(idx, 1);
            } catch (e) {}
        },

        async loadExisting() {
            try {
                const resp = await fetch('/upload-portal/files?context=' + this.context + '&context_id=' + this.contextId, {
                    headers: { 'Accept': 'application/json' }
                });
                const data = await resp.json();
                if (data.files) this.uploaded = data.files;
            } catch (e) {}
        },

        init() {
            if (this.contextId) this.loadExisting();
        }
    };
}
</script>
@endpush
@endonce
