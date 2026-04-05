@extends('layouts.app')
@section('title', 'Upload Portal Settings')
@section('header', 'Upload Portal Settings')

@section('content')
<div class="max-w-3xl space-y-4" x-data="uploadPortalSettings()">

    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
        <div class="flex items-center justify-between mb-4">
            <h3 class="font-semibold text-gray-800">Upload Portal</h3>
            <span class="text-xs text-gray-400">v{{ config('upload-portal.version', '?') }}</span>
        </div>

        <div class="space-y-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Upload Directory</label>
                <input type="text" x-model="uploadDir" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm font-mono" placeholder="uploads">
                <p class="text-xs text-gray-400 mt-1">Relative to storage/app/</p>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Temp Directory</label>
                <input type="text" x-model="tempDir" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm font-mono" placeholder="uploads/temp">
                <p class="text-xs text-gray-400 mt-1">Files here are cleaned up after publishing</p>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Allowed File Types</label>
                <input type="text" x-model="allowedTypes" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" placeholder="jpg, jpeg, png, gif, webp, svg, pdf">
                <p class="text-xs text-gray-400 mt-1">Comma-separated extensions</p>
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Max File Size (KB)</label>
                    <input type="number" x-model="maxFileSize" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" min="1">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Max Files Per Upload</label>
                    <input type="number" x-model="maxFiles" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" min="1">
                </div>
            </div>
        </div>

        <div class="mt-4 flex items-center gap-3">
            <button @click="saveAll()" :disabled="saving" class="bg-blue-600 text-white px-4 py-2 rounded-lg text-sm hover:bg-blue-700 disabled:opacity-50 inline-flex items-center gap-2">
                <svg x-show="saving" x-cloak class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg>
                <span x-text="saving ? 'Saving...' : (saved ? 'Saved!' : 'Save Settings')"></span>
            </button>
        </div>
        <p x-show="error" x-cloak class="text-xs text-red-600 mt-2" x-text="error"></p>
    </div>

    {{-- Test Upload --}}
    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
        <h3 class="font-semibold text-gray-800 mb-3">Test Upload</h3>
        <input type="file" @change="testUpload($event)" multiple class="text-sm">
        <div x-show="testResult" x-cloak class="mt-3 px-3 py-2 rounded-lg text-sm" :class="testSuccess ? 'bg-green-50 text-green-700' : 'bg-red-50 text-red-700'" x-text="testResult"></div>
    </div>

    <div class="text-xs text-gray-400">
        <a href="{{ route('upload-portal.raw') }}" class="hover:text-blue-600">Raw Test Page</a>
    </div>
</div>

@push('scripts')
<script>
function uploadPortalSettings() {
    return {
        uploadDir: @json(config('upload-portal.upload_dir', 'uploads')),
        tempDir: @json(config('upload-portal.temp_dir', 'uploads/temp')),
        allowedTypes: @json(implode(', ', config('upload-portal.allowed_types', []))),
        maxFileSize: @json(config('upload-portal.max_file_size', 10240)),
        maxFiles: @json(config('upload-portal.max_files_per_upload', 20)),
        saving: false, saved: false, error: '',
        testResult: '', testSuccess: false,

        async saveAll() {
            this.saving = true; this.saved = false; this.error = '';
            const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
            const settings = {
                'upload_portal_upload_dir': this.uploadDir,
                'upload_portal_temp_dir': this.tempDir,
                'upload_portal_allowed_types': this.allowedTypes,
                'upload_portal_max_file_size': String(this.maxFileSize),
                'upload_portal_max_files': String(this.maxFiles),
            };
            try {
                for (const [key, val] of Object.entries(settings)) {
                    await fetch('{{ route("upload-portal.settings.save") }}', {
                        method: 'POST', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf },
                        body: JSON.stringify({ setting_key: key, setting_value: val })
                    });
                }
                this.saved = true;
                setTimeout(() => this.saved = false, 3000);
            } catch (e) { this.error = e.message; }
            this.saving = false;
        },

        async testUpload(event) {
            this.testResult = ''; this.testSuccess = false;
            const files = event.target.files;
            if (!files.length) return;
            const form = new FormData();
            for (const f of files) form.append('files[]', f);
            form.append('context', 'test');
            form.append('context_id', '0');
            form.append('temp', '1');
            try {
                const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
                const resp = await fetch('{{ route("upload-portal.upload") }}', { method: 'POST', headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' }, body: form });
                const data = await resp.json();
                this.testSuccess = data.success;
                this.testResult = data.message;
            } catch (e) { this.testResult = 'Error: ' + e.message; }
        }
    };
}
</script>
@endpush
@endsection
