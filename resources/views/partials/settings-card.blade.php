@push('settings-cards')
@if(Route::has('upload-portal.settings'))
<a href="{{ route('upload-portal.settings') }}" class="group block bg-white rounded-xl border border-gray-200 p-6 shadow-sm hover:shadow-md hover:border-indigo-300 transition-all duration-200">
    <div class="flex items-start justify-between">
        <div class="w-12 h-12 bg-indigo-100 rounded-lg flex items-center justify-center group-hover:bg-indigo-200 transition-colors">
            <svg class="w-6 h-6 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/>
            </svg>
        </div>
        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-indigo-100 text-indigo-800">v{{ config('upload-portal.version', '?') }}</span>
    </div>
    <h3 class="mt-4 text-lg font-semibold text-gray-900 group-hover:text-indigo-700 transition-colors">Upload Portal</h3>
    <p class="mt-1 text-sm text-gray-500">Multi-file upload with progress, temp storage, and gallery.</p>
</a>
@endif
@endpush
