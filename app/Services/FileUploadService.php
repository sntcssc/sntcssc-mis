<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Throwable;

class FileUploadService
{
    /**
     * Store an uploaded file in a folder with descriptive, timestamped renaming.
     * Preserves the original uploaded filename (slugified) alongside the field prefix
     * and a timestamp for uniqueness and traceability.
     *
     * Format: {folder}/{prefix}_{original_name}_{Ymd_His}.{ext}
     * Example: settings/general/site_logo_company-brand-white_20260823_143022.png
     *
     * @param  string  $folder  Target folder path (e.g. 'settings/general')
     * @param  string|null  $prefix  Filename prefix / field name (e.g. 'site_logo')
     * @param  string  $disk  Storage disk (default 'public')
     * @param  string|null  $oldPath  Previous file path to delete if provided
     * @return string Relative storage path
     *
     * @throws \RuntimeException
     */
    public static function store(
        UploadedFile|TemporaryUploadedFile $file,
        string $folder = 'settings',
        ?string $prefix = null,
        string $disk = 'public',
        ?string $oldPath = null,
    ): string {
        try {
            $folder = trim($folder, '/');
            $extension = strtolower($file->getClientOriginalExtension() ?: 'bin');

            // Slugify original client filename (without extension) for readability
            $originalName = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
            $originalSlug = Str::slug($originalName, '-');
            // Limit to 40 chars to avoid overly long filenames
            $originalSlug = Str::limit($originalSlug, 40, '');

            $cleanPrefix = $prefix ? Str::slug($prefix, '_') : 'file';
            $filename = sprintf(
                '%s_%s_%s.%s',
                $cleanPrefix,
                $originalSlug,
                now()->format('Ymd_His'),
                $extension
            );

            $path = $file->storeAs($folder, $filename, $disk);

            if (! $path) {
                throw new \RuntimeException("Failed to store file in {$folder}.");
            }

            // Remove old file if specified and not the same
            if ($oldPath && $oldPath !== $path) {
                self::delete($oldPath, $disk);
            }

            return $path;
        } catch (Throwable $e) {
            Log::error('FileUploadService error: '.$e->getMessage(), [
                'folder' => $folder,
                'prefix' => $prefix,
                'disk' => $disk,
                'exception' => $e,
            ]);

            throw $e;
        }
    }

    /**
     * Alias for store() for backward compatibility and generic file uploads.
     */
    public static function upload(
        UploadedFile|TemporaryUploadedFile $file,
        string $folder = 'uploads',
        ?string $prefix = null,
        string $disk = 'public',
        ?string $oldPath = null
    ): string {
        return self::store($file, $folder, $prefix, $disk, $oldPath);
    }

    /**
     * Safely delete a file from storage.
     */
    public static function delete(?string $path, string $disk = 'public'): bool
    {
        if (! $path || str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return false;
        }

        try {
            if (Storage::disk($disk)->exists($path)) {
                return Storage::disk($disk)->delete($path);
            }
        } catch (Throwable $e) {
            Log::warning("Failed to delete file from {$disk}: {$path}", ['error' => $e->getMessage()]);
        }

        return false;
    }

    /**
     * Resolve a public URL for a storage path.
     */
    public static function url(?string $path, string $disk = 'public'): ?string
    {
        if (! $path) {
            return null;
        }

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://') || str_starts_with($path, '//')) {
            return $path;
        }

        return Storage::disk($disk)->url($path);
    }

    /**
     * Convert bytes to human readable format.
     */
    public static function humanSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $power = $bytes > 0 ? (int) floor(log($bytes, 1024)) : 0;
        $power = min($power, count($units) - 1);

        return number_format($bytes / pow(1024, $power), 1).' '.$units[$power];
    }
}
