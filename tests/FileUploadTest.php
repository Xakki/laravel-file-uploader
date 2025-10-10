<?php

declare(strict_types=1);

namespace Xakki\LaravelFileUploader\Tests;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Storage;
use Xakki\LaravelFileUploader\DTO\FileMetadata;
use Xakki\LaravelFileUploader\Services\FileUpload;

class FileUploadTest extends TestCase
{
    public function test_load_parallel_file(): void
    {
        $disk = 'files';
        Storage::fake($disk);
        config([
            'file-uploader.disk' => $disk,
            'file-uploader.chunk_size' => 1024 * 10,
        ]);

        $uploadId1 = $this->makeUploadId();
        $fileName1 = 'TestReport1.pdf';
        $file1 = UploadedFile::fake()->createWithContent($fileName1, str_repeat('A', 1024 * 86));
        $file1Hash = hash_file('sha256', $file1->getRealPath());
        $file1Chunks = $this->splitFile($file1->getRealPath(), config('file-uploader.chunk_size'));
        $file1ChunksCount = count($file1Chunks);
        $this->assertGreaterThan(0, $file1ChunksCount);

        $uploadId2 = $this->makeUploadId();
        $fileName2 = 'TestReport2.txt';
        $file2 = UploadedFile::fake()->createWithContent($fileName2, str_repeat('B', 1024 * 99));
        $file2Hash = hash_file('sha256', $file2->getRealPath());
        $file2Chunks = $this->splitFile($file2->getRealPath(), config('file-uploader.chunk_size'));
        $file2ChunksCount = count($file2Chunks);
        $this->assertGreaterThan(0, $file2ChunksCount);

        $uploader = new FileUpload(config('file-uploader'));

        $next = true;
        $i = 0;
        $chunk1Metadata = $chunk2Metadata = false;

        while ($next) {
            $next = false;
            if ($chunk1Path = array_shift($file1Chunks)) {
                $chunk1 = new UploadedFile($chunk1Path, basename($chunk1Path), null, null, true);
                $chunk1Metadata = $uploader->handleChunk($this->makeUploadChunkRequest([
                    'fileChunk' => $chunk1,
                    'chunkIndex' => $i,
                    'totalChunks' => $file1ChunksCount,
                    'fileSize' => filesize($chunk1Path),
                    'uploadId' => $uploadId1,
                    'fileName' => $fileName1,
                    'mimeType' => 'application/pdf',
                    'fileHash' => $file1Hash,
                    'fileLastModified' => time(),
                ]));
                $next = true;
            }
            if ($chunk2Path = array_shift($file2Chunks)) {
                $chunk2 = new UploadedFile($chunk2Path, basename($chunk2Path), null, null, true);
                $chunk2Metadata = $uploader->handleChunk($this->makeUploadChunkRequest([
                    'fileChunk' => $chunk2,
                    'chunkIndex' => $i,
                    'totalChunks' => $file2ChunksCount,
                    'fileSize' => filesize($chunk2Path),
                    'uploadId' => $uploadId2,
                    'fileName' => $fileName2,
                    'mimeType' => 'text/plain',
                    'fileHash' => $file2Hash,
                    'fileLastModified' => time(),
                ]));
                $next = true;
            }
            $i++;
        }

        $this->assertInstanceOf(FileMetadata::class, $chunk1Metadata);
        $this->assertInstanceOf(FileMetadata::class, $chunk2Metadata);

        $path = trim($uploader->uploadDirectory(), '/');
        $pathPrefix = $path === '' ? '' : $path.'/';
        $metadata1 = $uploader->readMetadata($file1Hash);
        $this->assertNotNull($metadata1);
        $this->assertSame($fileName1, $metadata1->name);
        $this->assertTrue(Storage::disk($disk)->exists($pathPrefix.$fileName1));
        $this->assertSame($file1Hash, $metadata1->hash);
        $this->assertNotNull($metadata1->path);
        $storedFile1 = Storage::disk($disk)->get($metadata1->path);
        $this->assertSame($file1Hash, hash('sha256', $storedFile1));

        $metadata2 = $uploader->readMetadata($file2Hash);
        $this->assertNotNull($metadata2);
        $this->assertSame($fileName2, $metadata2->name);
        $this->assertTrue(Storage::disk($disk)->exists($pathPrefix.$fileName2));
        $this->assertSame($file2Hash, $metadata2->hash);
        $this->assertNotNull($metadata2->path);
        $storedFile2 = Storage::disk($disk)->get($metadata2->path);
        $this->assertSame($file2Hash, hash('sha256', $storedFile2));
    }

    public function test_sync_metadata_updates_trashed_files(): void
    {
        Date::setTestNow('2024-01-10 12:00:00');

        try {
            $disk = 'files';
            Storage::fake($disk);

            config([
                'file-uploader.disk' => $disk,
                'file-uploader.directory' => 'uploads',
                'file-uploader.metadata_directory' => '.meta',
                'file-uploader.trash_directory' => '.trash',
                'file-uploader.temporary_directory' => '.chunks',
            ]);

            $txt = 'Trashed file content';
            $storage = Storage::disk($disk);
            $storage->put('.trash/trashed.txt', $txt);
            $hash = hash('sha256', $txt);
            $deletedAt = Date::now()->subDay()->toIso8601String();
            $metapath = '.meta/'.$hash.'.json';
            $storage->put($metapath, json_encode([
                'id' => 'trashed-id',
                'name' => 'trashed.txt',
                'size' => 1,
                'mime' => 'application/octet-stream',
                'path' => 'uploads/trashed.txt',
                'disk' => 'legacy-disk',
                'url' => 'http://example.com/old',
                'createdAt' => Date::now()->toIso8601String(),
                'deletedAt' => $deletedAt,
                'hash' => $hash,
                'trashPath' => '.trash/trashed.txt',
            ], JSON_PRETTY_PRINT));

            $uploader = new FileUpload(config('file-uploader'));
            $result = $uploader->syncMetadata();

            $this->assertSame(0, $result['created']);
            $this->assertSame(1, $result['updated']);
            $this->assertSame(0, $result['deleted']);

            $metadata = json_decode($storage->get($metapath), true, 512, JSON_THROW_ON_ERROR);

            $this->assertSame(null, $metadata['path']);
            $this->assertSame('.trash/trashed.txt', $metadata['trashPath']);
            $this->assertSame(strlen($txt), $metadata['size']);
            $this->assertSame('text/plain', $metadata['mime']);
            $this->assertSame($hash, $metadata['hash']);
            $this->assertSame($disk, $metadata['disk']);
            $this->assertNotEmpty($metadata['url']);
            $this->assertSame($deletedAt, $metadata['deletedAt']);
        } finally {
            Date::setTestNow();
        }
    }

    /**
     * Разбивает файл на части фиксированного размера.
     *
     * @param  string  $filePath  Полный путь к исходному файлу.
     * @param  int  $chunkSize  Размер части в байтах (по умолчанию 1 МиБ).
     * @param  string|null  $outDir  Каталог для кусков; по умолчанию рядом с файлом.
     * @return string[] Пути к созданным файлам-частям.
     */
    protected function splitFile(string $filePath, int $chunkSize = 1_048_576, ?string $outDir = null): array
    {
        if (! is_file($filePath) || ! is_readable($filePath)) {
            throw new \RuntimeException("Файл '$filePath' не существует или недоступен для чтения.");
        }

        if ($chunkSize < 1) {
            throw new \InvalidArgumentException('Размер части должен быть положительным.');
        }

        $dir = $outDir ?? dirname($filePath);
        if (! is_dir($dir) && ! mkdir($dir, 0777, true)) {
            throw new \RuntimeException("Не удалось создать каталог вывода '$dir'.");
        }

        $name = pathinfo($filePath, PATHINFO_FILENAME);
        $ext = pathinfo($filePath, PATHINFO_EXTENSION);
        $prefix = $ext === '' ? $name : ($name.'.'.$ext);

        $in = fopen($filePath, 'rb');
        if ($in === false) {
            throw new \RuntimeException("Не удалось открыть файл '$filePath' для чтения.");
        }

        $parts = [];
        $index = 0;

        try {
            while (! feof($in)) {
                $partPath = sprintf('%s/%s.part%04d', rtrim($dir, '/\\'), $prefix, $index);

                $out = fopen($partPath, 'wb');
                if ($out === false) {
                    throw new \RuntimeException("Не удалось открыть '$partPath' для записи.");
                }

                // Копируем до $chunkSize байт из входного потока в текущую часть.
                $copied = stream_copy_to_stream($in, $out, $chunkSize);
                fclose($out);

                // Если ничего не скопировано — достигли EOF перед созданием части.
                if ($copied === 0) {
                    // Удалим пустой файл на всякий случай
                    @unlink($partPath);
                    break;
                }

                $parts[] = $partPath;
                $index++;
            }
        } finally {
            fclose($in);
        }

        return $parts;
    }
}
