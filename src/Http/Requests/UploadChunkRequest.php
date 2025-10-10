<?php

declare(strict_types=1);

namespace Xakki\LaravelFileUploader\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * @property-read \Illuminate\Http\UploadedFile $fileChunk
 * @property-read int $chunkIndex
 * @property-read int $totalChunks
 * @property-read int $fileSize
 * @property-read string $uploadId
 * @property-read string $fileName
 * @property-read string $mimeType
 * @property-read ?string $fileHash
 * @property-read int $fileLastModified
 * @property-read string|null $locale
 */
class UploadChunkRequest extends FormRequest
{
    /**
     * @var array<string, mixed>|null
     */
    private ?array $cachedPayload = null;

    /**
     * @var array<string, string>
     */
    protected array $casts = [
        'chunkIndex' => 'integer',
        'totalChunks' => 'integer',
        'fileSize' => 'integer',
        'fileLastModified' => 'integer',
    ];

    /**
     * @return array<string, array<int, \Illuminate\Contracts\Validation\ValidationRule|string>>
     */
    public function rules(): array
    {
        $chunkSize = (int) config('file-uploader.chunk_size', 1024 * 1024);
        $maxSize = (int) config('file-uploader.max_size', $chunkSize * 1024);

        return [
            'fileChunk' => ['required', 'file', 'max:'.(int) ceil($chunkSize / 1024)],
            'chunkIndex' => ['required', 'integer', 'min:0'],
            'totalChunks' => ['required', 'integer', 'min:1'],
            'fileSize' => ['required', 'integer', 'min:1', 'max:'.$maxSize],
            'uploadId' => ['required', 'string', 'max:60', 'regex:/^upload-[0-9]{13}-[a-z0-9]{8}$/'],
            'fileName' => ['required', 'string', 'max:255'],
            'mimeType' => ['required', 'string', 'max:150'],
            'fileHash' => ['nullable', 'string', 'max:128'],
            'locale' => ['nullable', Rule::in(config('file-uploader.locales', ['en']))],
            'fileLastModified' => ['required', 'integer', 'min:0'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        if ($this->cachedPayload === null) {
            $this->cachedPayload = array_merge($this->validated(), $this->allFiles());
            foreach ($this->casts as $cast => $type) {
                if ($type === 'integer') {
                    $this->cachedPayload[$cast] = (int) $this->cachedPayload[$cast];
                }
            }

        }

        return $this->cachedPayload;
    }

    public function __get(mixed $key): mixed
    {
        if (! is_string($key)) {
            return null;
        }

        $payload = $this->payload();

        if (array_key_exists($key, $payload)) {
            return $payload[$key];
        }

        return $this->input($key);
    }
}
