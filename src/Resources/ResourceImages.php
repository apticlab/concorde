<?php

namespace Aptic\Concorde\Resources;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class ResourceImages
{
    public function store(Model $model, string $field, string $payload): void
    {
        $connection = $model->getConnection();
        if ($connection->transactionLevel() === 0) {
            throw new RuntimeException('Resource image writes require a database transaction.');
        }
        $content = $this->decode($field, $payload);
        $info = getimagesizefromstring($content);
        $extension = image_type_to_extension($info[2], false);
        $name = $field . '_' . $model->getKey() . '_' . Str::uuid() . '.' . $extension;
        $diskName = config('concorde.storageDisk', 'local');
        $disk = Storage::disk($diskName);
        $oldUrl = $model->getRawOriginal($field);

        if (!$disk->put($name, $content, 'public')) {
            throw new RuntimeException('Unable to save resource image.');
        }
        $connection->afterRollBack(fn () => $disk->delete($name));
        $connection->afterCommit(function () use ($oldUrl, $disk) {
            // Delete only URLs belonging to this disk, never arbitrary external paths.
            $prefix = rtrim($disk->url(''), '/') . '/';
            if (is_string($oldUrl) && str_starts_with($oldUrl, $prefix)) {
                $oldPath = substr($oldUrl, strlen($prefix));
                if ($oldPath !== '' && !str_contains($oldPath, '..')) {
                    $disk->delete($oldPath);
                }
            }
        });

        $model->{$field} = $disk->url($name);
        $model->saveOrFail();
    }

    protected function decode(string $field, string $payload): string
    {
        $limit = config('concorde.maxImageBytes', 10 * 1024 * 1024);
        if (strlen($payload) > (int) ceil($limit * 4 / 3) + 100
            || !preg_match('/^data:image\/(?:png|jpe?g|gif|webp);base64,(.*)$/sD', $payload, $matches)) {
            throw ValidationException::withMessages([$field => ['The image payload is invalid or too large.']]);
        }
        $content = base64_decode($matches[1], true);
        $info = $content === false ? false : @getimagesizefromstring($content);
        if (!$info || strlen($content) > $limit || !in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_GIF, IMAGETYPE_WEBP], true)) {
            throw ValidationException::withMessages([$field => ['The image payload is invalid or too large.']]);
        }
        return $content;
    }
}
