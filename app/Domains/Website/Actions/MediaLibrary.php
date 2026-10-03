<?php

declare(strict_types=1);

namespace App\Domains\Website\Actions;

use App\Domains\Platform\Models\SitePage;
use App\Domains\Website\Models\Media;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Upload, resize, describe and remove website images. An image used on a page cannot be deleted.
 */
class MediaLibrary
{
    public const MAX_BYTES = 5 * 1024 * 1024;

    public function upload(UploadedFile $file, string $alt, ?int $by): Media
    {
        $mime = (string) $file->getMimeType();
        if (! in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true) || $file->getSize() > self::MAX_BYTES) {
            throw ValidationException::withMessages(['file' => 'Upload a JPG, PNG or WebP image up to 5 MB.']);
        }
        $bytes = (string) file_get_contents((string) $file->getRealPath());
        $info = @getimagesizefromstring($bytes);
        if ($info === false) {
            throw ValidationException::withMessages(['file' => 'That file is not a readable image.']);
        }

        $media = Media::create(['filename' => mb_substr($file->getClientOriginalName(), 0, 200), 'mime' => $mime, 'size' => strlen($bytes),
            'width' => $info[0], 'height' => $info[1], 'alt' => trim($alt), 'uploaded_by' => $by]);
        foreach (['large' => 1600, 'thumb' => 400] as $size => $max) {
            Storage::disk('local')->put($media->path($size), $this->resize($bytes, $mime, $max));
        }
        [$w, $h] = $this->fit($info[0], $info[1], 1600);
        $media->forceFill(['width' => $w, 'height' => $h])->save();

        return $media;
    }

    /**
     * Pages that use this image.
     *
     * @return list<string>
     */
    public function usedOn(Media $media): array
    {
        $needle = "/media/{$media->id}";

        return array_values(SitePage::query()->get()->filter(fn (SitePage $p) => str_contains((string) json_encode($p->sections), $needle))->pluck('title')->all());
    }

    public function delete(Media $media): void
    {
        $pages = $this->usedOn($media);
        if ($pages !== []) {
            throw ValidationException::withMessages(['media' => 'This image is used on: '.implode(', ', $pages).'. Remove it there first.']);
        }
        Storage::disk('local')->delete([$media->path('large'), $media->path('thumb')]);
        $media->delete();
    }

    /**
     * @return array{0: int<1, max>, 1: int<1, max>}
     */
    private function fit(int $w, int $h, int $max): array
    {
        $scale = min(1, $max / max($w, $h));

        return [max(1, (int) round($w * $scale)), max(1, (int) round($h * $scale))];
    }

    /**
     * Re-encodes the image (also strips camera metadata such as location) at no more than $max px.
     */
    private function resize(string $bytes, string $mime, int $max): string
    {
        if (! function_exists('imagecreatefromstring')) {
            return $bytes;
        }
        $src = @imagecreatefromstring($bytes);
        if ($src === false) {
            return $bytes;
        }
        [$w, $h] = $this->fit(imagesx($src), imagesy($src), $max);
        $dst = imagecreatetruecolor(max(1, $w), max(1, $h));
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $w, $h, imagesx($src), imagesy($src));
        ob_start();
        match ($mime) {
            'image/png' => imagepng($dst, null, 6),
            'image/webp' => imagewebp($dst, null, 82),
            default => imagejpeg($dst, null, 82),
        };

        return (string) ob_get_clean();
    }
}
