<?php

declare(strict_types=1);

namespace App\Domains\Website\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * An image in the practice's website media library. Stored as "large"
 * (max 1600 px) and "thumb" (max 400 px) on the practice's own disk.
 *
 * @property string $id
 * @property string $filename
 * @property string $mime
 * @property int $size
 * @property int|null $width
 * @property int|null $height
 * @property string $alt
 * @property int|null $uploaded_by
 */
class Media extends Model
{
    use HasUlids;

    protected $table = 'media';

    protected $guarded = [];

    public function url(string $size = 'large'): string
    {
        return "/media/{$this->id}".($size === 'thumb' ? '/thumb' : '');
    }

    public function path(string $size): string
    {
        return "media/{$this->id}-{$size}.".($this->mime === 'image/png' ? 'png' : ($this->mime === 'image/webp' ? 'webp' : 'jpg'));
    }
}
