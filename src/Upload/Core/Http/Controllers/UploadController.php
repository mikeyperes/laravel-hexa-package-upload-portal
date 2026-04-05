<?php

namespace hexa_package_upload_portal\Upload\Core\Http\Controllers;

use hexa_package_upload_portal\Upload\Core\Services\UploadService;
use hexa_package_upload_portal\Upload\Storage\Services\StorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class UploadController extends Controller
{
    public function __construct(
        private UploadService $uploadService,
        private StorageService $storageService
    ) {}

    /**
     * Upload one or more files.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function upload(Request $request): JsonResponse
    {
        $request->validate([
            'files' => 'required|array|max:' . $this->storageService->getMaxFilesPerUpload(),
            'files.*' => 'file|max:' . $this->storageService->getMaxFileSize(),
            'context' => 'required|string|max:50',
            'context_id' => 'required|integer',
            'temp' => 'nullable|boolean',
        ]);

        $allowed = $this->storageService->getAllowedTypes();
        $uploaded = [];
        $errors = [];

        foreach ($request->file('files') as $file) {
            $ext = strtolower($file->getClientOriginalExtension());
            if (!in_array($ext, $allowed)) {
                $errors[] = $file->getClientOriginalName() . ': type not allowed (' . $ext . ')';
                continue;
            }

            $record = $this->uploadService->upload(
                $file,
                $request->input('context'),
                (int) $request->input('context_id'),
                auth()->id(),
                (bool) $request->input('temp', true)
            );

            $uploaded[] = [
                'id' => $record->id,
                'filename' => $record->filename,
                'original_name' => $record->original_name,
                'size' => $record->size,
                'mime_type' => $record->mime_type,
                'url' => asset('storage/' . $record->path),
                'path' => $record->path,
            ];
        }

        return response()->json([
            'success' => count($uploaded) > 0,
            'uploaded' => $uploaded,
            'errors' => $errors,
            'message' => count($uploaded) . ' file(s) uploaded' . (count($errors) ? ', ' . count($errors) . ' error(s)' : ''),
        ]);
    }

    /**
     * List files by context.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function files(Request $request): JsonResponse
    {
        $request->validate([
            'context' => 'required|string',
            'context_id' => 'required|integer',
        ]);

        $files = $this->uploadService->getFiles(
            $request->input('context'),
            (int) $request->input('context_id')
        );

        return response()->json([
            'success' => true,
            'files' => $files->map(fn($f) => [
                'id' => $f->id,
                'filename' => $f->filename,
                'original_name' => $f->original_name,
                'size' => $f->size,
                'mime_type' => $f->mime_type,
                'url' => asset('storage/' . $f->path),
                'status' => $f->status,
                'created_at' => $f->created_at->toIso8601String(),
            ]),
        ]);
    }

    /**
     * Delete a single file.
     *
     * @param int $id
     * @return JsonResponse
     */
    public function delete(int $id): JsonResponse
    {
        $deleted = $this->uploadService->delete($id);

        return response()->json([
            'success' => $deleted,
            'message' => $deleted ? 'File deleted.' : 'File not found.',
        ]);
    }

    /**
     * Cleanup temp files for a context.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function cleanup(Request $request): JsonResponse
    {
        $request->validate([
            'context' => 'required|string',
            'context_id' => 'required|integer',
        ]);

        $count = $this->uploadService->cleanup(
            $request->input('context'),
            (int) $request->input('context_id')
        );

        return response()->json([
            'success' => true,
            'cleaned' => $count,
            'message' => $count . ' temp file(s) cleaned up.',
        ]);
    }
}
