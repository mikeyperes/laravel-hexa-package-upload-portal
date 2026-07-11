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
