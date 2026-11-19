<?php

namespace App\Concerns;

use Illuminate\Support\Str;

trait RenamesUploads
{
    protected function generateFileName($type, $file, $userId)
    {
        // Clean type name (lowercase + underscore)
        $type = Str::slug(strtolower($type), '_');

        // Get extension
        $extension = $file->getClientOriginalExtension();

        // Timestamp
        $timestamp = now()->format('Ymd_His');

        return "{$type}_user_{$userId}_{$timestamp}.{$extension}";
    }
}
