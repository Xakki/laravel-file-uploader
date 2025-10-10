<?php

declare(strict_types=1);

namespace Xakki\LaravelFileUploader\DTO;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Validator;

class FileMetadata
{
    public function __construct(
        public string $id,
        public string $name,
        public int $size,
        public string $mime,
        public ?string $path,
        public string $disk,
        public string $hash,
        public string $createdAt,
        public ?int $lastModified,
        public ?string $url,
        public ?string $userId,
        public ?string $deletedAt = null,
        public ?string $trashPath = null,
        /** @var array<string, mixed> */
        public array $extra = [],
    ) {}

    public function setDeleted(string $trashPath): void
    {
        $this->deletedAt = Date::now()->toIso8601String();
        $this->trashPath = $trashPath;
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public static function fromArray(array $data): ?self
    {
        $validator = Validator::make($data, [
            'id' => ['required', 'string'],
            'name' => ['required', 'string'],
            'size' => ['required', 'integer'],
            'mime' => ['required', 'string'],
            'path' => ['nullable', 'string'],
            'disk' => ['required', 'string'],
            'hash' => ['nullable', 'string'],
            'createdAt' => ['required', 'string'],
            'lastModified' => ['nullable', 'integer'],
            'url' => ['nullable', 'string'],
            'deletedAt' => ['nullable', 'string'],
            'userId' => ['nullable', 'string'],
            'trashPath' => ['nullable', 'string'],
        ]);

        /** @var array<string, mixed> $validated */
        $validated = $validator->validated();

        $validated = array_merge([
            'path' => null,
            'hash' => null,
            'lastModified' => null,
            'url' => null,
            'userId' => null,
        ], $validated);

        $knownKeys = array_keys($validator->getRules());
        $validated['extra'] = Arr::except($data, $knownKeys);

        return new self(...$validated);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = get_object_vars($this);
        $extra = $data['extra'];
        unset($data['extra']);

        if (count($extra)) {
            $data = array_merge($extra, $data);
        }

        return $data;
    }
}
