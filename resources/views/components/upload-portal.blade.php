{{--
    Upload Portal Component
    Usage:
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
                    <p class="text-xs text-gray-400 mt-1">Paste a copied image, image URL, or HTML image while this window is open; it will be queued automatically.</p>
                    <input type="file" x-ref="fileInput" class="hidden" {{ $multi ? 'multiple' : '' }} accept="{{ $allowed }}" @change="handleFiles($event.target.files)">
                </div>

                {{-- Upload queue --}}
                <div x-show="queue.length > 0" class="space-y-2">
                    <template x-for="(item, idx) in queue" :key="item.id || idx">
                        <div class="flex items-center gap-3 bg-gray-50 rounded-lg p-3">
                            <img x-show="item.preview" :src="item.preview" class="w-12 h-12 rounded object-cover flex-shrink-0">
                            <div class="flex-1 min-w-0">
                                <p class="text-sm text-gray-800 break-words" x-text="item.name"></p>
                                <p class="text-xs text-gray-400" x-text="(item.size / 1024).toFixed(0) + ' KB'"></p>
                                <p x-show="item.error" x-cloak class="mt-1 text-xs font-medium text-red-600" x-text="item.error"></p>
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
                            <div class="relative group rounded-lg overflow-hidden border border-gray-200 bg-gray-50">
                                <template x-if="file.mime_type && file.mime_type.startsWith('image/')">
                                    <div>
                                        <img x-show="!file.preview_failed" x-cloak :src="file.url || file.client_preview" x-on:error="handlePreviewError($event, file)" class="w-full h-28 object-cover bg-gray-100">
                                        <div x-show="file.preview_failed" x-cloak class="w-full h-28 bg-gray-100 flex items-center justify-center px-2 text-center text-[10px] text-gray-500">Preview unavailable</div>
                                    </div>
                                </template>
                                <template x-if="!file.mime_type || !file.mime_type.startsWith('image/')">
                                    <div class="w-full h-24 bg-gray-100 flex items-center justify-center">
                                        <svg class="w-8 h-8 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"/></svg>
                                    </div>
                                </template>
                                <div class="absolute inset-0 bg-transparent group-hover:bg-black/40 transition-all flex items-center justify-center">
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
                <span class="text-xs text-gray-500" x-text="footerStatus()"></span>
                <div class="flex gap-2">
                    <button @click="uploadAll()" :disabled="uploading || pendingCount() === 0" class="bg-indigo-600 text-white px-4 py-2 rounded-lg text-sm hover:bg-indigo-700 disabled:opacity-50 inline-flex items-center gap-2">
                        <svg x-show="uploading" x-cloak class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg>
                        <span x-text="uploadButtonLabel()"></span>
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
        _boundPasteHandler: null,

        pendingCount() {
            return this.queue.filter((item) => item.status === "pending").length;
        },

        doneCount() {
            return this.queue.filter((item) => item.status === "done").length;
        },

        footerStatus() {
            if (!this.queue.length) return this.uploaded.length ? this.uploaded.length + " uploaded" : "No files queued";
            if (this.pendingCount() === 0 && this.doneCount() === this.queue.length) return "All files uploaded";
            return this.doneCount() + "/" + this.queue.length + " uploaded";
        },

        uploadButtonLabel() {
            if (this.uploading) return "Uploading...";
            if (this.queue.length && this.pendingCount() === 0) return "All Uploaded";
            return "Upload All";
        },

        handleDrop(event) {
            this.handleFiles(event.dataTransfer.files);
        },

        isImageFile(file) {
            if (!file) return false;
            const type = String(file.type || "").toLowerCase();
            const name = String(file.name || "").toLowerCase();
            return type.startsWith("image/") || /\.(jpe?g|png|gif|webp)$/i.test(name);
        },

        clipboardImageFiles(clipboard) {
            const found = [];
            Array.from(clipboard?.files || []).forEach((file) => {
                if (this.isImageFile(file)) found.push(file);
            });
            Array.from(clipboard?.items || []).forEach((item) => {
                if (item.kind !== "file") return;
                const file = item.getAsFile();
                if (this.isImageFile(file) && !found.some((candidate) => candidate === file)) found.push(file);
            });
            return found;
        },

        clipboardImageUrls(clipboard) {
            const urls = [];
            const add = (value) => {
                const url = String(value || "").trim();
                if (!url || urls.includes(url)) return;
                if (/^data:image\//i.test(url) || /^https?:\/\//i.test(url) || /^blob:/i.test(url)) urls.push(url);
            };
            const html = String(clipboard?.getData?.("text/html") || "");
            if (html) {
                const doc = new DOMParser().parseFromString(html, "text/html");
                doc.querySelectorAll("img[src]").forEach((img) => add(img.getAttribute("src")));
            }
            const text = String(clipboard?.getData?.("text/plain") || "").trim();
            if (/^data:image\//i.test(text) || /^https?:\/\/[^\s]+$/i.test(text)) add(text);
            return urls;
        },

        async fileFromImageUrl(url) {
            const response = await fetch(url, { credentials: "omit" });
            if (!response.ok) throw new Error("Could not read pasted image URL (" + response.status + ").");
            const blob = await response.blob();
            if (!String(blob.type || "").toLowerCase().startsWith("image/")) throw new Error("Pasted URL did not return an image.");
            const ext = (blob.type.split("/")[1] || "png").replace(/jpeg/i, "jpg").replace(/[^a-z0-9]/gi, "") || "png";
            return new File([blob], "clipboard-image." + ext, { type: blob.type || "image/" + ext });
        },

        async handleClipboardImageUrls(urls) {
            for (const url of urls) {
                try {
                    const file = await this.fileFromImageUrl(url);
                    this.handleFiles([file], "clipboard-url");
                } catch (error) {
                    this.registerQueueError(error.message || "Clipboard image URL could not be uploaded.");
                }
            }
        },

        validUploadedFile(file) {
            const url = String(file?.url || "").trim();
            const path = String(file?.path || "").trim();
            return !!url && url !== "0" && path !== "0" && !/\/storage\/0(?:$|[?#])/i.test(url);
        },

        normalizeUploadedFile(file, fallbackPreview = null) {
            const url = file?.url || file?.public_url || "";
            const mime = file?.mime_type || (/\.(jpe?g|png|gif|webp)$/i.test(String(file?.filename || file?.original_name || "")) ? "image/unknown" : "");
            return { ...file, mime_type: mime, preview_failed: false, client_preview: fallbackPreview, url: url || fallbackPreview || "" };
        },

        stateSafeUploadedFile(file) {
            if (!file || typeof file !== "object") return file;
            const { client_preview, preview_failed, ...safe } = file;
            return safe;
        },

        handlePreviewError(event, file) {
            const img = event?.target;
            if (!img || !file) return;
            const current = String(img.getAttribute("src") || img.src || "");
            if (file.client_preview && current !== file.client_preview) {
                img.src = file.client_preview;
                file.url = file.client_preview;
                file.preview_failed = false;
                return;
            }
            file.preview_failed = true;
        },

        registerQueueError(message) {
            this.queue.push({
                id: Date.now() + "-" + Math.random().toString(16).slice(2),
                file: null,
                name: "Clipboard image not queued",
                size: 0,
                source: "clipboard",
                preview: null,
                preview_failed: false,
                progress: 100,
                status: "error",
                error: message
            });
        },

        handlePaste(event) {
            if (!this.open) return;
            const clipboard = event.clipboardData || window.clipboardData;
            if (!clipboard) return;
            const imageFiles = this.clipboardImageFiles(clipboard);
            if (imageFiles.length) {
                event.preventDefault();
                this.handleFiles(imageFiles, "clipboard");
                return;
            }
            const imageUrls = this.clipboardImageUrls(clipboard);
            if (imageUrls.length) {
                event.preventDefault();
                this.handleClipboardImageUrls(imageUrls);
                return;
            }
            const pastedText = Array.from(clipboard.items || []).some((item) => item.kind === "string") || String(clipboard.getData?.("text/plain") || "").trim();
            if (pastedText) {
                event.preventDefault();
                this.registerQueueError("Clipboard did not contain image bytes or a readable image URL. Use Copy Image, drag the file, or browse.");
            }
        },

        handleFiles(fileList, source = "picker") {
            const files = Array.from(fileList || []);
            for (const file of files) {
                if (!this.multi && this.queue.some((item) => item.status !== "error")) break;
                if (!this.isImageFile(file)) {
                    this.registerQueueError((file?.name || "Selected file") + " is not an allowed image file.");
                    continue;
                }
                const name = file.name || (source === "clipboard" ? "clipboard-image.png" : "upload");
                const preview = this.isImageFile(file) ? URL.createObjectURL(file) : null;
                this.queue.push({
                    id: Date.now() + "-" + Math.random().toString(16).slice(2),
                    file,
                    name,
                    size: file.size || 0,
                    source,
                    preview,
                    preview_failed: false,
                    progress: 0,
                    status: "pending",
                    error: ""
                });
            }
            if (this["$refs"] && this["$refs"].fileInput) this["$refs"].fileInput.value = "";
            if (this.pendingCount() > 0 && !this.uploading) {
                if (typeof this.$nextTick === "function") {
                    this.$nextTick(() => this.uploadAll());
                } else {
                    setTimeout(() => this.uploadAll(), 0);
                }
            }
        },

        removeFromQueue(idx) {
            this.queue.splice(idx, 1);
        },

        async uploadAll() {
            if (this.pendingCount() === 0) return;
            this.uploading = true;
            const csrf = document.querySelector("meta[name=csrf-token]")?.content;
            const uploadHeaders = typeof window.hexaRequestHeaders === "function"
                ? window.hexaRequestHeaders({ "Accept": "application/json", "X-Requested-With": "XMLHttpRequest" })
                : { "X-CSRF-TOKEN": csrf, "Accept": "application/json", "X-Requested-With": "XMLHttpRequest" };
            const pending = this.queue.filter((item) => item.status === "pending" && item.file);

            try {
                for (const item of pending) {
                    item.status = "uploading";
                    item.progress = 10;
                    item.error = "";

                    const form = new FormData();
                    form.append("files[]", item.file, item.name);
                    form.append("context", this.context);
                    form.append("context_id", this.contextId);
                    form.append("temp", "1");

                    try {
                        const resp = await fetch("/upload-portal/upload", {
                            method: "POST",
                            credentials: "same-origin",
                            headers: uploadHeaders,
                            body: form
                        });
                        item.progress = 90;
                        const contentType = resp.headers.get("content-type") || "";
                        const data = contentType.includes("application/json") ? await resp.json() : { success: false, message: (await resp.text()).slice(0, 300) };
                        if (resp.ok && data.success && data.uploaded?.length > 0) {
                            item.status = "done";
                            item.progress = 100;
                            const uploadedNow = data.uploaded.map((file) => this.normalizeUploadedFile(file, item.preview || null)).filter((file) => this.validUploadedFile(file));
                            if (!uploadedNow.length) {
                                item.status = "error";
                                item.progress = 100;
                                item.error = "Upload completed but returned an invalid file URL.";
                                continue;
                            }
                            uploadedNow.forEach((file) => this.uploaded.push(file));
                            item.uploaded = uploadedNow[0] || null;
                            window.dispatchEvent(new CustomEvent("upload-portal:uploaded", { detail: { context: this.context, context_id: this.contextId, uploaded: uploadedNow.map((file) => this.stateSafeUploadedFile(file)) } }));
                        } else {
                            item.status = "error";
                            item.progress = 100;
                            item.error = data.errors?.[0] || data.message || "Upload failed";
                        }
                    } catch (e) {
                        item.status = "error";
                        item.progress = 100;
                        item.error = e.message || "Upload failed";
                    }
                }
                if (this.doneCount() > 0 && typeof this.loadExisting === "function") {
                    await this.loadExisting();
                }
            } finally {
                this.uploading = false;
            }
        },

        async deleteFile(fileId, idx) {
            const csrf = document.querySelector("meta[name=csrf-token]")?.content;
            const headers = typeof window.hexaRequestHeaders === "function"
                ? window.hexaRequestHeaders({ "Accept": "application/json", "X-Requested-With": "XMLHttpRequest" })
                : { "X-CSRF-TOKEN": csrf, "Accept": "application/json", "X-Requested-With": "XMLHttpRequest" };
            try {
                const response = await fetch("/upload-portal/delete/" + fileId, {
                    method: "DELETE",
                    credentials: "same-origin",
                    headers
                });
                const data = await response.json().catch(() => ({}));
                if (!response.ok || data.success === false) {
                    throw new Error(data.message || "File deletion failed.");
                }
                const removed = this.uploaded[idx] || null;
                this.uploaded.splice(idx, 1);
                window.dispatchEvent(new CustomEvent("upload-portal:deleted", { detail: { context: this.context, context_id: this.contextId, file: removed } }));
            } catch (e) {
                window.dispatchEvent(new CustomEvent("upload-portal:error", { detail: { context: this.context, context_id: this.contextId, message: e.message || "File deletion failed." } }));
            }
        },

        async loadExisting() {
            try {
                const resp = await fetch('/upload-portal/files?context=' + this.context + '&context_id=' + this.contextId, {
                    credentials: "same-origin",
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
                });
                const data = await resp.json();
                if (data.files) this.uploaded = data.files.map((file) => this.normalizeUploadedFile(file)).filter((file) => this.validUploadedFile(file));
            } catch (e) {}
        },

        init() {
            if (!this._boundPasteHandler) {
                this._boundPasteHandler = (event) => this.handlePaste(event);
                window.addEventListener("paste", this._boundPasteHandler);
            }
            if (this.contextId) this.loadExisting();
        }
    };
}
</script>
@endpush
@endonce
