<?php

namespace App\Traits;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Throwable;

trait UploadTrait
{
    protected function uploadFile(
        UploadedFile $file,
        string $directory,
        string $disk = 'public'
    ): string {
        return $file->store($directory, $disk);
    }

    protected function removeFile(
        ?string $path,
        string $disk = 'public'
    ): bool {
        if (empty($path)) {
            return false;
        }

        try {
            if (! Storage::disk($disk)->exists($path)) {
                return false;
            }

            return Storage::disk($disk)->delete($path);
        } catch (Throwable $exception) {
            report($exception);

            return false;
        }
    }
}
