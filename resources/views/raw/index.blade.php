@extends('layouts.app')
@section('title', 'Raw — Upload Portal')
@section('header', 'Raw — Upload Portal')

@section('content')
<div class="space-y-6" x-data="{ testResult: '', testFiles: [] }">

    {{-- Functions Index --}}
    <div class="bg-gray-900 rounded-xl p-6 text-sm font-mono">
        <h2 class="text-white font-semibold mb-3">Upload Portal Functions</h2>
        <table class="w-full text-left">
            <thead>
                <tr class="text-gray-400 border-b border-gray-700">
                    <th class="py-1.5 px-2">Function</th>
                    <th class="py-1.5 px-2">Method</th>
                    <th class="py-1.5 px-2">Route</th>
                    <th class="py-1.5 px-2">Status</th>
                </tr>
            </thead>
            <tbody class="text-gray-300">
                <tr class="border-b border-gray-800">
                    <td class="py-1.5 px-2">Upload file(s)</td>
                    <td class="py-1.5 px-2 text-blue-400">upload()</td>
                    <td class="py-1.5 px-2 text-green-400">POST /upload-portal/upload</td>
                    <td class="py-1.5 px-2 text-green-400">LIVE</td>
                </tr>
                <tr class="border-b border-gray-800">
                    <td class="py-1.5 px-2">List files</td>
                    <td class="py-1.5 px-2 text-blue-400">files()</td>
                    <td class="py-1.5 px-2 text-green-400">GET /upload-portal/files</td>
                    <td class="py-1.5 px-2 text-green-400">LIVE</td>
                </tr>
                <tr class="border-b border-gray-800">
                    <td class="py-1.5 px-2">Delete file</td>
                    <td class="py-1.5 px-2 text-blue-400">delete()</td>
                    <td class="py-1.5 px-2 text-green-400">DELETE /upload-portal/delete/{id}</td>
                    <td class="py-1.5 px-2 text-green-400">LIVE</td>
                </tr>
                <tr class="border-b border-gray-800">
                    <td class="py-1.5 px-2">Cleanup temp files</td>
                    <td class="py-1.5 px-2 text-blue-400">cleanup()</td>
                    <td class="py-1.5 px-2 text-green-400">POST /upload-portal/cleanup</td>
                    <td class="py-1.5 px-2 text-green-400">LIVE</td>
                </tr>
                <tr class="border-b border-gray-800">
                    <td class="py-1.5 px-2">Settings page</td>
                    <td class="py-1.5 px-2 text-blue-400">settings()</td>
                    <td class="py-1.5 px-2 text-green-400">GET /upload-portal/settings</td>
                    <td class="py-1.5 px-2 text-green-400">LIVE</td>
                </tr>
            </tbody>
        </table>
    </div>

    {{-- Test Upload --}}
    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
        <h3 class="font-semibold text-gray-800 mb-3">Test Upload</h3>
        <input type="file" multiple @change="
            const form = new FormData();
            for (const f of $event.target.files) form.append('files[]', f);
            form.append('context', 'test');
            form.append('context_id', '0');
            const csrf = document.querySelector('meta[name=csrf-token]')?.content;
            fetch('/upload-portal/upload', { method: 'POST', headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' }, body: form })
                .then(r => r.json()).then(d => { testResult = JSON.stringify(d, null, 2); })
                .catch(e => { testResult = 'Error: ' + e.message; });
        " class="text-sm mb-3">
        <pre x-show="testResult" x-cloak class="bg-gray-900 text-green-400 p-4 rounded-lg text-xs font-mono whitespace-pre-wrap break-words" x-text="testResult"></pre>
    </div>

    {{-- Config Display --}}
    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
        <h3 class="font-semibold text-gray-800 mb-3">Current Config</h3>
        <div class="text-sm space-y-1">
            <p><span class="text-gray-400">upload_dir:</span> {{ config('upload-portal.upload_dir') }}</p>
            <p><span class="text-gray-400">temp_dir:</span> {{ config('upload-portal.temp_dir') }}</p>
            <p><span class="text-gray-400">allowed_types:</span> {{ implode(', ', config('upload-portal.allowed_types', [])) }}</p>
            <p><span class="text-gray-400">max_file_size:</span> {{ config('upload-portal.max_file_size') }} KB</p>
            <p><span class="text-gray-400">max_files_per_upload:</span> {{ config('upload-portal.max_files_per_upload') }}</p>
        </div>
    </div>
</div>
@endsection
