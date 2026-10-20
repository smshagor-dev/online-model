<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

class AvatarImage
{
    public function fromBytes(string $raw): string
    {
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($raw);
        $size = @getimagesizefromstring($raw);
        if (! $raw || strlen($raw) > 96000 || ! in_array($mime, ['image/png', 'image/jpeg', 'image/webp'], true) || ! $size || $size[0] > 512 || $size[1] > 512) {
            throw ValidationException::withMessages(['avatar' => 'Choose a PNG, JPEG or WebP up to 512 × 512 pixels and 96 KB.']);
        }

        return 'data:'.$mime.';base64,'.base64_encode($raw);
    }
}
