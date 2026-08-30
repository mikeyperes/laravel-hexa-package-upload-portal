<?php

return [

    'version' => '2.0.9',

    /*
    |--------------------------------------------------------------------------
    | Master Toggle
    |--------------------------------------------------------------------------
    */
    'enabled' => true,

    /*
    |--------------------------------------------------------------------------
    | Storage Disk
    |--------------------------------------------------------------------------
    |
    | Upload portal files must be browser-accessible because users preview them
    | immediately and later push them into WordPress or Google Docs.
    |
    */
    'disk' => env('UPLOAD_PORTAL_DISK', 'public'),

    /*
    |--------------------------------------------------------------------------
    | Upload Directory
    |--------------------------------------------------------------------------
    |
    | Base directory for permanent uploads (relative to storage/app).
    |
    */
    'upload_dir' => 'uploads',

    /*
    |--------------------------------------------------------------------------
    | Temp Directory
    |--------------------------------------------------------------------------
    |
    | Directory for temporary uploads (relative to storage/app).
    | Files here are cleaned up after publishing or on schedule.
    |
    */
    'temp_dir' => 'uploads/temp',

    /*
    |--------------------------------------------------------------------------
    | Allowed File Types
    |--------------------------------------------------------------------------
    */
    'allowed_types' => ['jpg', 'jpeg', 'png', 'gif', 'webp'],

    /*
    |--------------------------------------------------------------------------
    | Server-detected MIME Types by Extension
    |--------------------------------------------------------------------------
    */
    'allowed_mime_types' => [
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
        'gif' => ['image/gif'],
        'webp' => ['image/webp'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Max File Size (KB)
    |--------------------------------------------------------------------------
    */
    'max_file_size' => 10240, // 10 MB

    /*
    |--------------------------------------------------------------------------
    | Max Files Per Upload
    |--------------------------------------------------------------------------
    */
    'max_files_per_upload' => 20,

    /*
    |--------------------------------------------------------------------------
    | Active Context Quotas
    |--------------------------------------------------------------------------
    */
    'max_active_files' => 100,

    // Aggregate size in KB for one context + context ID + owner.
    'max_aggregate_size' => 102400,

    // Locks serialize quota preflight and lifecycle writes for one owner/context.
    'lock_seconds' => 300,
    'lock_wait_seconds' => 10,
];
