<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Stores customer photos/videos on the public disk. The type is decided from
 * the file's real content (MIME type), not from the name the phone sent.
 */
class MediaStorage
{
    public function typeOf(UploadedFile $file): string
    {
        return str_starts_with((string) $file->getMimeType(), 'video/') ? 'video' : 'photo';
    }

    /** @return array{type: string, file_path: string} */
    public function store(UploadedFile $file, string $baseFolder): array
    {
        $type = $this->typeOf($file);
        $folder = $baseFolder.'/'.($type === 'video' ? 'videos' : 'photos');

        return ['type' => $type, 'file_path' => $file->store($folder, 'public')];
    }

    public function url(?string $path): ?string
    {
        return $path ? Storage::disk('public')->url($path) : null;
    }

    public function delete(array $paths): void
    {
        $paths = array_values(array_filter($paths, fn ($path) => is_string($path) && $path !== ''));

        if ($paths !== []) {
            Storage::disk('public')->delete($paths);
        }
    }
}
