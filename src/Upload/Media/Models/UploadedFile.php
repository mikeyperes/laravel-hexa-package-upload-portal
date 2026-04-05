<?php

namespace hexa_package_upload_portal\Upload\Media\Models;

use Illuminate\Database\Eloquent\Model;

class UploadedFile extends Model
{
    protected $table = 'uploaded_files';

    protected $fillable = [
        'filename',
        'original_name',
        'path',
        'disk',
        'size',
        'mime_type',
        'context',
        'context_id',
        'uploaded_by',
        'status',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
        'size' => 'integer',
        'context_id' => 'integer',
        'uploaded_by' => 'integer',
    ];
}
