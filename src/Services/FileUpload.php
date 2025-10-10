<?php

declare(strict_types=1);

namespace Xakki\LaravelFileUploader\Services;

use Error;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use LogicException;
use PHPUnit\Metadata\Metadata;
use Throwable;
use Xakki\LaravelFileUploader\DTO\FileMetadata;
use Xakki\LaravelFileUploader\Http\Requests\UploadChunkRequest;

class FileUpload
{
    public const ROUTE_PARAM_PLACEHOLDER = '__ID__';

    protected FilesystemAdapter $disk;

    protected string $diskName;

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(protected array $config)
    {
        $this->diskName = $config['disk'] ?? config('filesystems.default');
        $this->disk = Storage::disk($this->diskName);
    }

    public function handleChunk(UploadChunkRequest $payload): true|FileMetadata
    {
        if ($payload->fileHash) {
            try {
                return $this->readMetadata($payload->fileHash);
            } catch (\Error $e) {
                // skipp
            }
        }
        $this->guardFile($payload->fileName, $payload->mimeType, $payload->fileSize, $payload->fileChunk);

        $temporaryDirectory = $this->temporaryDirectoryUpload($payload->uploadId);
        $this->ensureDirectory($temporaryDirectory);

        $pathChunk = $this->disk->putFileAs($temporaryDirectory, $payload->fileChunk, (string) $payload->chunkIndex);
        if (! $pathChunk) {
            throw new Error('Failed to persist chunk.');
        }

        $completed = ($payload->chunkIndex + 1) >= $payload->totalChunks;

        if ($completed) {
            return $this->assembleFile($payload);
        }

        return true;
    }

    protected function assembleFile(UploadChunkRequest $payload): FileMetadata
    {
        $userId = $this->currentUserId();
        $temporaryDirectory = $this->temporaryDirectoryUpload($payload->uploadId);
        $finalDirectory = $this->uploadDirectory();
        $fileName = $payload->fileName;
        $i = 0;
        while (true) {
            $path = $this->normalizeStoragePath($finalDirectory.'/'.$fileName);
            if ($this->disk->exists($path)) {
                $fileName = ++$i.'-'.$payload->fileName;
            } else {
                break;
            }
        }

        $resource = fopen('php://temp', 'w+b');
        if ($resource === false) {
            throw new LogicException('Failed to open temporary stream.');
        }

        $calculatedHash = null;

        try {
            $hashContext = hash_init('sha256');
            for ($i = 0; $i < $payload->totalChunks; $i++) {
                $chunkPath = $temporaryDirectory.'/'.$i;
                if (! $this->disk->exists($chunkPath)) {
                    throw new LogicException("Missing chunk {$i} for {$payload->uploadId}.");
                }

                $stream = $this->disk->readStream($chunkPath);
                if (! $stream) {
                    throw new LogicException("Unable to read chunk {$i} for {$payload->uploadId}.");
                }

                while (! feof($stream)) {
                    $buffer = fread($stream, 1048576);
                    if ($buffer === false) {
                        fclose($stream);

                        throw new LogicException("Unable to read data from chunk {$i} for {$payload->uploadId}.");
                    }
                    if ($buffer === '') {
                        break;
                    }
                    hash_update($hashContext, $buffer);
                    fwrite($resource, $buffer);
                }
                fclose($stream);
            }

            rewind($resource);
            $written = $this->disk->writeStream($path, $resource);
            if ($written === false) {
                throw new LogicException('Failed to persist assembled file.');
            }

            $calculatedHash = hash_final($hashContext);
            if ($payload->fileHash && ! hash_equals($payload->fileHash, $calculatedHash)) {
                throw new LogicException('File hash mismatch.');
            }
        } finally {
            fclose($resource);
            $this->disk->deleteDirectory($temporaryDirectory);
        }

        try {
            $metadata = $this->readMetadata($calculatedHash);
            $this->disk->delete($path);
            if ($metadata->trashPath) {
                $this->disk->move($metadata->trashPath, $path);
            } else {
                return $metadata;
            }
            $metadata->name = $payload->fileName;
            $metadata->size = $payload->fileSize;
            $metadata->mime = $payload->mimeType;
            $metadata->path = $path;
            $metadata->disk = $this->diskName;
            $metadata->url = $this->resolvePublicUrl($path);
            $metadata->deletedAt = null;
            $metadata->trashPath = null;
            $metadata->hash = $calculatedHash;
            $metadata->lastModified = $payload->fileLastModified;
            if ($userId) {
                $metadata->userId = $userId;
            }
        } catch (Throwable) {
            $metadata = new FileMetadata(
                id: $payload->uploadId,
                name: $payload->fileName,
                size: $payload->fileSize,
                mime: $payload->mimeType,
                path: $path,
                disk: $this->diskName,
                hash: $calculatedHash,
                createdAt: Date::now()->toIso8601String(),
                lastModified: $payload->fileLastModified,
                url: $this->resolvePublicUrl($path),
                deletedAt: null,
                userId: $userId,
            );
        }
        $this->writeMetadata($metadata);

        return $metadata;
    }

    protected function guardFile(string $fileName, ?string $mimeType, int $size, UploadedFile $chunk): void
    {
        $maxSize = (int) ($this->config['max_size'] ?? 0);
        if ($maxSize > 0 && $size > $maxSize) {
            throw new Error('File exceeds maximum allowed size.');
        }

        $allowedExtensions = $this->config['allowed_extensions'] ?? [];
        if (! empty($allowedExtensions)) {
            $extension = $this->sanitizeExtension(pathinfo($fileName, PATHINFO_EXTENSION));
            $detectedMime = $chunk->getMimeType();
            $mime = $mimeType ?: $detectedMime;

            $extensionAllowList = [];
            $mimeAllowList = [];

            if (is_string($allowedExtensions)) {
                $allowedExtensions = explode(',', $allowedExtensions);
            }

            foreach ($allowedExtensions as $key => $value) {
                $value = trim($value);
                if ($value === '') {
                    continue;
                }
                if (str_contains($value, ':')) {
                    [$key, $value] = array_map('trim', explode(':', $value, 2));
                    if ($key === '') {
                        continue;
                    }
                    if (! $value) {
                        $value = '*';
                    }
                }
                if (is_string($key)) {
                    $mimeAllowList[$key] = $value;

                    continue;
                }
                if ($value === '*') {
                    // Allow all extension
                    return;
                }
                $extensionAllowList[] = (string) $value;
            }

            if ($mime !== null && array_key_exists($mime, $mimeAllowList)) {
                $allowedExtension = $mimeAllowList[$mime];

                if ($allowedExtension === '*') {
                    return;
                }

                if ($extension === $this->sanitizeExtension((string) $allowedExtension)) {
                    return;
                }

                throw new Error('Extension `'.$extension.'` is not allowed for MIME type `'.$mime.'`.');
            }

            if (! empty($extensionAllowList)) {
                $normalizedExtensions = array_map(fn ($ext) => $this->sanitizeExtension((string) $ext), $extensionAllowList);

                if (in_array($extension, $normalizedExtensions, true)) {
                    return;
                }

                throw new Error('Extension `'.$extension.'` is not allowed.');
            }

            if ($mime !== null) {
                throw new Error('MIME type `'.$mime.'` is not allowed.');
            }

            throw new Error('MIME type is not allowed.');
        }
    }

    protected function ensureDirectory(string $path): void
    {
        $directory = trim($path, '/');
        if ($directory === '') {
            return;
        }

        if (! $this->disk->exists($directory)) {
            $this->disk->makeDirectory($directory);
        }
    }

    public function uploadDirectory(): string
    {
        $path = trim($this->config['directory'] ?? '', '/');
        $this->ensureDirectory($path);

        return $path;
    }

    protected function temporaryDirectoryUpload(string $uploadId): string
    {
        return $this->temporaryDirectory().'/'.$uploadId;
    }

    protected function temporaryDirectory(): string
    {
        return trim($this->config['temporary_directory'] ?? '.chunks', '/');
    }

    public function metadataDirectory(): string
    {
        return trim($this->config['metadata_directory'] ?? '.meta', '/');
    }

    protected function trashDirectory(): string
    {
        return trim($this->config['trash_directory'] ?? '.trash', '/');
    }

    public function metadataPath(string $fileHash): string
    {
        return $this->metadataDirectory().'/'.$fileHash.'.json';
    }

    /**
     * @throws \Illuminate\Validation\ValidationException|\Error
     */
    public function readMetadata(string $fileHash): FileMetadata
    {
        return $this->readMetadataByPath($this->metadataPath($fileHash));
    }

    /**
     * @throws \Illuminate\Validation\ValidationException|\Error
     */
    protected function readMetadataByPath(string $path): FileMetadata
    {
        if (! $this->disk->exists($path)) {
            throw new \Error('Metadata file cant read or not exist: '.$path);
        }

        $content = $this->disk->get($path);
        $data = json_decode($content, true);

        if (! is_array($data)) {
            throw new \Error('Metadata has wrong data: '.$path);
        }

        return FileMetadata::fromArray($data);
    }

    protected function writeMetadata(FileMetadata $metadata): void
    {
        $this->ensureDirectory($this->metadataDirectory());
        $this->disk->put($this->metadataPath($metadata->hash), json_encode($metadata->toArray(), JSON_PRETTY_PRINT));
    }

    protected function deleteMetadata(string $fileHash): void
    {
        $path = $this->metadataPath($fileHash);
        if ($this->disk->exists($path)) {
            $this->disk->delete($path);
        }
    }

    /**
     * Synchronize metadata files with the actual files stored on disk.
     *
     * @return array{created: int, updated: int, deleted: int}
     */
    public function syncMetadata(): array
    {
        $created = 0;
        $updated = 0;
        $deleted = 0;
        $knownFileHash = [];

        $metadataDirectory = $this->metadataDirectory();
        if ($this->disk->exists($metadataDirectory)) {
            foreach ($this->disk->files($metadataDirectory) as $metadataPath) {
                if (! Str::endsWith($metadataPath, '.json')) {
                    continue;
                }

                try {
                    $metadata = $this->readMetadataByPath($metadataPath);
                } catch (\Throwable $e) {
                    logger()->error($e);
                    $this->disk->delete($metadataPath);
                    $deleted++;

                    continue;
                }

                $needsUpdate = false;
                $filePath = $this->normalizeStoragePath((string) $metadata->path);
                $fileTrashPath = $this->normalizeStoragePath((string) $metadata->trashPath);

                if ($filePath && $this->disk->exists($filePath)) {
                    if ($fileTrashPath) {
                        $metadata->trashPath = null;
                        $metadata->deletedAt = null;
                        if ($this->disk->exists($fileTrashPath)) {
                            $this->disk->delete($fileTrashPath);
                        }
                        $needsUpdate = true;
                    }

                    if ($metadata->path !== $filePath) {
                        $metadata->path = $filePath;
                        $needsUpdate = true;
                    }
                } elseif ($fileTrashPath && $this->disk->exists($fileTrashPath)) {
                    if ($metadata->path) {
                        $metadata->path = null;
                        $needsUpdate = true;
                    }
                    if ($metadata->trashPath !== $fileTrashPath) {
                        $metadata->setDeleted($fileTrashPath);
                        $needsUpdate = true;
                    }
                    $filePath = $fileTrashPath;
                } else {
                    $this->disk->delete($metadataPath);
                    $deleted++;

                    continue;
                }

                $knownFileHash[$metadata->hash] = $filePath;

                $size = $this->disk->size($filePath);
                if ($metadata->size !== $size) {
                    $metadata->size = $size;
                    $needsUpdate = true;
                }

                $mime = $this->detectMimeType($filePath);
                if ($metadata->mime !== $mime) {
                    $metadata->mime = $mime;
                    $needsUpdate = true;
                }

                $hash = $this->calculateHashForPath($filePath);
                if ($metadata->hash !== $hash) {
                    $metadata->hash = $hash;
                    $needsUpdate = true;
                }

                if ($metadata->disk !== $this->diskName) {
                    $metadata->disk = $this->diskName;
                    $needsUpdate = true;
                }

                $url = $this->resolvePublicUrl($filePath);
                if ($metadata->url !== $url) {
                    $metadata->url = $url;
                    $needsUpdate = true;
                }

                if ($needsUpdate) {
                    $this->writeMetadata($metadata);
                    $updated++;
                }
            }
        }

        $ignoredDirectories = array_values(array_filter([
            $this->metadataDirectory(),
            $this->temporaryDirectory(),
            $this->trashDirectory(),
        ]));

        foreach ($this->disk->allFiles($this->uploadDirectory()) as $path) {
            $normalizedPath = $this->normalizeStoragePath($path);
            if ($this->shouldIgnorePath($normalizedPath, $ignoredDirectories)) {
                continue;
            }

            $metadata = $this->buildMetadataForFile($normalizedPath);
            if (isset($knownFileHash[$metadata->hash])) {
                continue;
            }
            $knownFileHash[$metadata->hash] = $normalizedPath;

            $this->writeMetadata($metadata);
            $created++;
        }

        foreach ($this->disk->allFiles($this->trashDirectory()) as $path) {
            $normalizedPath = $this->normalizeStoragePath($path);
            if (! $this->disk->fileExists($normalizedPath)) {
                continue;
            }

            $metadata = $this->buildMetadataForFile($normalizedPath);
            if (isset($knownFileHash[$metadata->hash])) {
                $this->disk->delete($normalizedPath);

                continue;
            }
            $knownFileHash[$metadata->hash] = $normalizedPath;

            $metadata->setDeleted($metadata->path);
            $metadata->path = null;
            $this->writeMetadata($metadata);
            $updated++;
        }

        return [
            'created' => $created,
            'updated' => $updated,
            'deleted' => $deleted,
        ];
    }

    protected function normalizeStoragePath(string $path): string
    {
        return ltrim($path, '/');
    }

    /**
     * @param  array<int, string>  $ignoredDirectories
     */
    protected function shouldIgnorePath(string $path, array $ignoredDirectories): bool
    {
        foreach ($ignoredDirectories as $directory) {
            if ($directory === '') {
                continue;
            }

            if ($path === $directory || Str::startsWith($path, $directory.'/')) {
                return true;
            }
        }

        return false;
    }

    protected function buildMetadataForFile(string $path): FileMetadata
    {
        $size = $this->disk->size($path);
        $mime = $this->detectMimeType($path);
        $hash = $this->calculateHashForPath($path);

        return new FileMetadata(
            id: (string) Str::uuid(),
            name: basename($path),
            size: $size,
            mime: $mime,
            path: $path,
            disk: $this->diskName,
            hash: $hash,
            createdAt: Date::now()->toIso8601String(),
            lastModified: null,
            url: $this->resolvePublicUrl($path),
            deletedAt: null,
            userId: null,
        );
    }

    protected function detectMimeType(string $path): string
    {
        try {
            return $this->disk->mimeType($path);
        } catch (Throwable $e) {
            logger()->notice($e);

            return '';
        }
    }

    protected function calculateHashForPath(string $path): string
    {
        $stream = $this->disk->readStream($path);
        if (! $stream) {
            logger()->error('Unable to read stream: '.$path);

            return md5($path);
        }

        try {
            $context = hash_init('sha256');
            while (! feof($stream)) {
                $buffer = fread($stream, 1048576);
                if ($buffer === false) {
                    logger()->error('Cant fread: '.$path);

                    return md5($path);
                }

                if ($buffer === '') {
                    break;
                }

                hash_update($context, $buffer);
            }

            return hash_final($context);
        } finally {
            fclose($stream);
        }
    }

    protected function resolvePublicUrl(string $path): ?string
    {
        $resolver = $this->config['public_url_resolver'] ?? null;
        if ($resolver && is_callable($resolver)) {
            return $resolver($path, $this->disk);
        }

        return $this->disk->url($path);
    }

    /**
     * @return array<string, mixed>
     */
    public function formatFileForResponse(FileMetadata $metadata): array
    {
        $metadata->url = $metadata->path ? $this->resolvePublicUrl($metadata->path) : null;

        return [
            'id' => $metadata->hash,
            'name' => $metadata->name,
            'size' => $metadata->size,
            'mime' => $metadata->mime,
            'url' => $metadata->url,
            'createdAt' => $metadata->createdAt,
            'deletedAt' => $metadata->deletedAt,
            'lastModified' => $metadata->lastModified,
        ];
    }

    protected function currentUserId(): ?string
    {
        $id = Auth::id();

        return $id === null ? null : (string) $id;
    }

    protected function sanitizeExtension(?string $extension): string
    {
        return $extension ? preg_replace('/[^a-z0-9]+/i', '', Str::lower($extension)) : '';
    }
}
