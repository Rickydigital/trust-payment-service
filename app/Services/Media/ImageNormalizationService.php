<?php

namespace App\Services\Media;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class ImageNormalizationService
{
    /** @return array{path:string,variants:array<string,string>,width:int,height:int,mime:string,size:int} */
    public function store(
        UploadedFile $file,
        string $directory,
        string $profile = 'default',
        string $disk = 'public'
    ): array {
        $this->ensureGd();

        if (! $this->supportsMime((string) $file->getMimeType())) {
            throw ValidationException::withMessages([
                'image' => 'Use a JPEG, PNG, or WebP image.',
            ]);
        }

        if (! config('image_processing.enabled', true)) {
            $path = $file->store($directory, $disk);

            return [
                'path' => $path,
                'variants' => [],
                'width' => 0,
                'height' => 0,
                'mime' => (string) $file->getMimeType(),
                'size' => (int) $file->getSize(),
            ];
        }

        $sourcePath = $file->getRealPath();
        if (! is_string($sourcePath) || $sourcePath === '') {
            throw ValidationException::withMessages(['image' => 'The uploaded image could not be read.']);
        }

        $source = $this->decodeFile($sourcePath);
        $source = $this->orientFromExif($source, $sourcePath, (string) $file->getMimeType());
        $extension = function_exists('imagewebp') ? 'webp' : 'jpg';
        $mime = $extension === 'webp' ? 'image/webp' : 'image/jpeg';
        $directory = trim($directory, '/');
        $basename = Str::uuid()->toString();
        $path = $directory . '/' . $basename . '.' . $extension;

        try {
            $originalSpec = $this->profile($profile)['original'];
            $primary = $this->resize($source, (int) $originalSpec[0], (int) $originalSpec[1]);
            $primaryBytes = $this->encode($primary, $extension, (int) $originalSpec[2]);
            $primaryWidth = imagesx($primary);
            $primaryHeight = imagesy($primary);
            $this->put($disk, $path, $primaryBytes);
            imagedestroy($primary);
            unset($primary);

            $variants = $this->writeVariants($source, $path, $profile, $disk, $extension);

            return [
                'path' => $path,
                'variants' => $variants,
                'width' => $primaryWidth,
                'height' => $primaryHeight,
                'mime' => $mime,
                'size' => strlen($primaryBytes),
            ];
        } catch (Throwable $exception) {
            Storage::disk($disk)->delete($path);
            throw $exception;
        } finally {
            if (isset($primary)) {
                imagedestroy($primary);
            }
            imagedestroy($source);
        }
    }

    /** @return array{path:string,variants:array<string,string>,width:int,height:int,mime:string,size:int} */
    public function normalizeExisting(
        string $path,
        string $profile = 'auto',
        string $disk = 'public',
        bool $force = false
    ): array {
        $this->ensureGd();

        $storage = Storage::disk($disk);
        if (! $storage->exists($path)) {
            throw new RuntimeException("Image does not exist on {$disk}: {$path}");
        }

        $profile = $profile === 'auto' ? $this->profileForPath($path) : $profile;
        $bytes = $storage->get($path);
        $source = $this->decodeBytes($bytes);
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $extension = in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true) ? $extension : 'jpg';

        try {
            $spec = $this->profile($profile)['original'];
            $primary = $this->resize($source, (int) $spec[0], (int) $spec[1]);
            $primaryBytes = $this->encode($primary, $extension, (int) $spec[2]);
            $primaryWidth = imagesx($primary);
            $primaryHeight = imagesy($primary);

            if ($force || strlen($primaryBytes) < strlen($bytes) || $primaryWidth < imagesx($source) || $primaryHeight < imagesy($source)) {
                $this->put($disk, $path, $primaryBytes);
            } else {
                $primaryBytes = $bytes;
            }
            imagedestroy($primary);
            unset($primary);

            $variantExtension = function_exists('imagewebp') ? 'webp' : $extension;
            $variants = $this->writeVariants($source, $path, $profile, $disk, $variantExtension, $force);

            return [
                'path' => $path,
                'variants' => $variants,
                'width' => $primaryWidth,
                'height' => $primaryHeight,
                'mime' => $this->mimeForExtension($extension),
                'size' => strlen($primaryBytes),
            ];
        } finally {
            if (isset($primary)) {
                imagedestroy($primary);
            }
            imagedestroy($source);
        }
    }

    /** @return array{url:string,thumbnail_url:string,card_url:string,detail_url:string} */
    public function urls(string $path, string $disk = 'public'): array
    {
        $originalUrl = $this->absoluteStorageUrl($path, $disk);

        return [
            'url' => $originalUrl,
            'thumbnail_url' => $this->existingVariantUrl($path, 'thumbnail', $disk) ?? $originalUrl,
            'card_url' => $this->existingVariantUrl($path, 'card', $disk) ?? $originalUrl,
            'detail_url' => $this->existingVariantUrl($path, 'detail', $disk) ?? $originalUrl,
        ];
    }

    public function deleteWithVariants(string $path, string $disk = 'public'): void
    {
        $paths = [$path];
        foreach (['thumbnail', 'card', 'detail'] as $variant) {
            foreach (['webp', 'jpg', 'jpeg', 'png'] as $extension) {
                $paths[] = $this->variantPath($path, $variant, $extension);
            }
        }

        Storage::disk($disk)->delete(array_values(array_unique($paths)));
    }

    public function profileForPath(string $path): string
    {
        $normalized = ltrim(str_replace('\\', '/', $path), '/');
        foreach ((array) config('image_processing.path_profiles', []) as $prefix => $profile) {
            if (str_starts_with($normalized, (string) $prefix)) {
                return (string) $profile;
            }
        }

        return 'default';
    }

    public function isProcessable(string $path): bool
    {
        if (str_contains(str_replace('\\', '/', $path), '/variants/')) {
            return false;
        }

        return in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), ['jpg', 'jpeg', 'png', 'webp'], true);
    }

    public function supportsMime(string $mime): bool
    {
        return in_array(strtolower($mime), ['image/jpeg', 'image/png', 'image/webp'], true);
    }

    public function hasAllVariants(string $path, string $disk = 'public'): bool
    {
        foreach (['thumbnail', 'card', 'detail'] as $variant) {
            if ($this->existingVariantUrl($path, $variant, $disk) === null) {
                return false;
            }
        }

        return true;
    }

    /** @return array<string,string> */
    private function writeVariants(
        mixed $source,
        string $path,
        string $profile,
        string $disk,
        string $extension,
        bool $force = true
    ): array {
        $variants = [];
        foreach ($this->profile($profile) as $name => $spec) {
            if ($name === 'original') {
                continue;
            }

            $variantPath = $this->variantPath($path, $name, $extension);
            if (! $force && Storage::disk($disk)->exists($variantPath)) {
                $variants[$name] = $variantPath;
                continue;
            }

            $image = $this->resize($source, (int) $spec[0], (int) $spec[1]);
            try {
                $this->put($disk, $variantPath, $this->encode($image, $extension, (int) $spec[2]));
            } finally {
                imagedestroy($image);
            }
            $variants[$name] = $variantPath;
        }

        return $variants;
    }

    private function existingVariantUrl(string $path, string $variant, string $disk): ?string
    {
        foreach (['webp', strtolower(pathinfo($path, PATHINFO_EXTENSION)), 'jpg', 'png'] as $extension) {
            $candidate = $this->variantPath($path, $variant, $extension);
            if (Storage::disk($disk)->exists($candidate)) {
                return $this->absoluteStorageUrl($candidate, $disk);
            }
        }

        return null;
    }

    private function variantPath(string $path, string $variant, string $extension): string
    {
        $directory = trim(str_replace('\\', '/', dirname($path)), './');
        $basename = pathinfo($path, PATHINFO_FILENAME);
        $prefix = $directory === '' ? '' : $directory . '/';

        return $prefix . 'variants/' . $basename . '_' . $variant . '.' . ltrim($extension, '.');
    }

    private function absoluteStorageUrl(string $path, string $disk): string
    {
        $url = Storage::disk($disk)->url($path);
        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return $url;
        }

        return rtrim((string) config('app.url'), '/') . '/' . ltrim($url, '/');
    }

    /** @return array<string,array{0:int,1:int,2:int}> */
    private function profile(string $profile): array
    {
        $profiles = (array) config('image_processing.profiles', []);
        $selected = $profiles[$profile] ?? $profiles['default'] ?? null;
        if (! is_array($selected) || ! isset($selected['original'])) {
            throw new RuntimeException("Unknown image profile: {$profile}");
        }

        return $selected;
    }

    private function decodeFile(string $path): mixed
    {
        $bytes = file_get_contents($path);
        if ($bytes === false) {
            throw ValidationException::withMessages(['image' => 'The uploaded image could not be read.']);
        }

        return $this->decodeBytes($bytes);
    }

    private function decodeBytes(string $bytes): mixed
    {
        $size = @getimagesizefromstring($bytes);
        if (! is_array($size) || empty($size[0]) || empty($size[1])) {
            throw ValidationException::withMessages(['image' => 'The uploaded file is not a supported image.']);
        }

        if (((int) $size[0] * (int) $size[1]) > (int) config('image_processing.max_pixels', 16000000)) {
            throw ValidationException::withMessages(['image' => 'The image dimensions are too large.']);
        }

        $image = @imagecreatefromstring($bytes);
        if ($image === false) {
            throw ValidationException::withMessages(['image' => 'The uploaded image could not be decoded.']);
        }

        imagealphablending($image, true);
        imagesavealpha($image, true);

        return $image;
    }

    private function orientFromExif(mixed $image, string $path, string $mime): mixed
    {
        if ($mime !== 'image/jpeg' || ! function_exists('exif_read_data')) {
            return $image;
        }

        $orientation = @exif_read_data($path)['Orientation'] ?? null;
        $angle = match ($orientation) {
            3 => 180,
            6 => -90,
            8 => 90,
            default => 0,
        };
        if ($angle === 0) {
            return $image;
        }

        $rotated = imagerotate($image, $angle, 0);
        if ($rotated === false) {
            return $image;
        }

        imagesavealpha($rotated, true);
        imagedestroy($image);

        return $rotated;
    }

    private function resize(mixed $source, int $maxWidth, int $maxHeight): mixed
    {
        $width = imagesx($source);
        $height = imagesy($source);
        $ratio = min($maxWidth / $width, $maxHeight / $height, 1);
        $targetWidth = max(1, (int) round($width * $ratio));
        $targetHeight = max(1, (int) round($height * $ratio));
        $target = imagecreatetruecolor($targetWidth, $targetHeight);
        imagealphablending($target, false);
        imagesavealpha($target, true);
        $transparent = imagecolorallocatealpha($target, 0, 0, 0, 127);
        imagefill($target, 0, 0, $transparent);
        imagecopyresampled($target, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);

        return $target;
    }

    private function encode(mixed $image, string $extension, int $quality): string
    {
        ob_start();
        $ok = match ($extension) {
            'webp' => function_exists('imagewebp') && imagewebp($image, null, $quality),
            'png' => imagepng($image, null, max(0, min(9, (int) round((100 - $quality) / 11.111)))),
            default => $this->encodeJpeg($image, $quality),
        };
        $bytes = ob_get_clean();

        if (! $ok || ! is_string($bytes) || $bytes === '') {
            throw new RuntimeException('The normalized image could not be encoded.');
        }

        return $bytes;
    }

    private function encodeJpeg(mixed $image, int $quality): bool
    {
        $canvas = imagecreatetruecolor(imagesx($image), imagesy($image));
        $white = imagecolorallocate($canvas, 255, 255, 255);
        imagefill($canvas, 0, 0, $white);
        imagecopy($canvas, $image, 0, 0, 0, 0, imagesx($image), imagesy($image));
        try {
            return imagejpeg($canvas, null, $quality);
        } finally {
            imagedestroy($canvas);
        }
    }

    private function put(string $disk, string $path, string $bytes): void
    {
        if (! Storage::disk($disk)->put($path, $bytes)) {
            throw new RuntimeException("Could not store normalized image: {$path}");
        }
    }

    private function mimeForExtension(string $extension): string
    {
        return match ($extension) {
            'png' => 'image/png',
            'webp' => 'image/webp',
            default => 'image/jpeg',
        };
    }

    private function ensureGd(): void
    {
        if (! extension_loaded('gd') || ! function_exists('imagecreatefromstring')) {
            throw new RuntimeException('PHP GD is required for image normalization.');
        }
    }
}
