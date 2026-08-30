<?php

namespace hexa_package_upload_portal\Upload\Core\Http\Controllers;

use hexa_package_upload_portal\Upload\Core\Authorization\UploadContextRegistry;
use hexa_package_upload_portal\Upload\Core\Exceptions\UploadPolicyViolation;
use hexa_package_upload_portal\Upload\Core\Services\UploadService;
use hexa_package_upload_portal\Upload\Media\Models\UploadedFile;
use hexa_package_upload_portal\Upload\Storage\Services\StorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\View\View;
use Throwable;

class UploadController extends Controller
{
    public function __construct(
        private readonly UploadService $uploadService,
        private readonly StorageService $storageService,
        private readonly UploadContextRegistry $contexts
    ) {}

    public function raw(): View
    {
        return view('upload-portal::raw.index');
    }

    public function upload(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'files' => 'required|array|max:'.$this->storageService->getMaxFilesPerUpload(),
            'files.*' => 'required|file',
            'context' => ['required', 'string', 'max:50', 'regex:/\A[a-z0-9][a-z0-9._-]*\z/D'],
            'context_id' => 'required|integer|min:0',
            'temp' => 'nullable|boolean',
        ]);

        $userId = auth()->id();

        try {
            $policy = $this->contexts->authorize(
                $validated['context'],
                'upload',
                (int) $validated['context_id'],
                $userId,
                $request
            );
            $records = $this->uploadService->uploadBatch(
                array_values($request->file('files')),
                $validated['context'],
                (int) $validated['context_id'],
                $userId,
                (bool) ($validated['temp'] ?? true),
                $policy
            );

            return response()->json([
                'success' => true,
                'uploaded' => $records->map(fn (UploadedFile $file): array => $this->filePayload($file))->values(),
                'errors' => [],
                'message' => $records->count().' file(s) uploaded.',
            ]);
        } catch (UploadPolicyViolation $exception) {
            return $this->policyError($exception);
        } catch (Throwable) {
            return response()->json([
                'success' => false,
                'uploaded' => [],
                'errors' => ['Upload could not be completed.'],
                'message' => 'Upload could not be completed.',
            ], 500);
        }
    }

    public function files(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'context' => ['required', 'string', 'max:50', 'regex:/\A[a-z0-9][a-z0-9._-]*\z/D'],
            'context_id' => 'required|integer|min:0',
        ]);
        $userId = auth()->id();

        try {
            $this->contexts->authorize(
                $validated['context'],
                'list',
                (int) $validated['context_id'],
                $userId,
                $request
            );
            $files = $this->uploadService->getFiles(
                $validated['context'],
                (int) $validated['context_id'],
                $userId
            );

            return response()->json([
                'success' => true,
                'files' => $files->map(fn (UploadedFile $file): array => $this->filePayload($file, true))->values(),
            ]);
        } catch (UploadPolicyViolation $exception) {
            return $this->policyError($exception);
        } catch (Throwable) {
            return response()->json([
                'success' => false,
                'message' => 'Files could not be loaded.',
            ], 500);
        }
    }

    public function delete(Request $request, int $id): JsonResponse
    {
        $userId = auth()->id();

        try {
            $file = $this->uploadService->findFile($id, $userId);
            if (! $file) {
                return response()->json([
                    'success' => false,
                    'message' => 'File not found or access denied.',
                ], 404);
            }

            $this->contexts->authorize(
                (string) $file->context,
                'delete',
                (int) $file->context_id,
                $userId,
                $request
            );
            $deleted = $this->uploadService->delete($id, $userId);

            return response()->json([
                'success' => $deleted,
                'message' => $deleted ? 'File deleted.' : 'File not found or access denied.',
            ], $deleted ? 200 : 404);
        } catch (UploadPolicyViolation $exception) {
            return $this->policyError($exception);
        } catch (Throwable) {
            return response()->json([
                'success' => false,
                'message' => 'File could not be deleted.',
            ], 500);
        }
    }

    public function cleanup(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'context' => ['required', 'string', 'max:50', 'regex:/\A[a-z0-9][a-z0-9._-]*\z/D'],
            'context_id' => 'required|integer|min:0',
            'limit' => 'nullable|integer|min:1|max:500',
            'cursor' => 'nullable|integer|min:0',
        ]);
        $userId = auth()->id();

        try {
            $this->contexts->authorize(
                $validated['context'],
                'cleanup',
                (int) $validated['context_id'],
                $userId,
                $request
            );
            $result = $this->uploadService->cleanupBatch(
                $validated['context'],
                (int) $validated['context_id'],
                $userId,
                (int) ($validated['limit'] ?? 100),
                (int) ($validated['cursor'] ?? 0)
            );

            return response()->json([
                'success' => $result['failed'] === 0,
                'cleaned' => $result['cleaned'],
                'failed' => $result['failed'],
                'next_cursor' => $result['next_cursor'],
                'has_more' => $result['has_more'],
                'message' => $result['failed'] === 0
                    ? $result['cleaned'].' temp file(s) cleaned up.'
                    : 'Some files could not be cleaned up.',
            ], $result['failed'] === 0 ? 200 : 500);
        } catch (UploadPolicyViolation $exception) {
            return $this->policyError($exception);
        } catch (Throwable) {
            return response()->json([
                'success' => false,
                'message' => 'Files could not be cleaned up.',
            ], 500);
        }
    }

    /** @return array<string, mixed> */
    private function filePayload(UploadedFile $file, bool $includeLifecycle = false): array
    {
        $payload = [
            'id' => $file->id,
            'filename' => $file->filename,
            'original_name' => $file->original_name,
            'size' => $file->size,
            'mime_type' => $file->mime_type,
            'url' => url($this->storageService->url((string) $file->path, $file->disk ?: null)),
        ];

        if ($includeLifecycle) {
            $payload['status'] = $file->status;
            $payload['created_at'] = $file->created_at?->toIso8601String();
        }

        return $payload;
    }

    private function policyError(UploadPolicyViolation $exception): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $exception->getMessage(),
        ], $exception->status());
    }
}
