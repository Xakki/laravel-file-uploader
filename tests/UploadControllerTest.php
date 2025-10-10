<?php

declare(strict_types=1);

namespace Xakki\LaravelFileUploader\Tests;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Xakki\LaravelFileUploader\Services\FileUpload;

class UploadControllerTest extends TestCase
{
    public function test_it_handles_chunked_upload_and_persists_file_with_matching_checksum(): void
    {
        $disk = 'files';
        Storage::fake($disk);

        $chunkSize = 500 * 1024;
        config([
            'file-uploader.disk' => $disk,
            'file-uploader.chunk_size' => $chunkSize,
        ]);

        $fileSize = 2 * 1024 * 1024;
        $originalContent = random_bytes($fileSize);
        $fileHash = hash('sha256', $originalContent);
        $totalChunks = (int) ceil($fileSize / $chunkSize);
        $fileName = 'functional-test.txt';
        $uploadId = sprintf('upload-%013d-%s', now()->valueOf(), Str::lower(Str::random(8)));
        $mimeType = 'text/plain';
        $finalResponse = null;

        for ($index = 0; $index < $totalChunks; $index++) {
            $offset = $index * $chunkSize;
            $chunkContent = substr($originalContent, $offset, min($chunkSize, $fileSize - $offset));
            $chunk = UploadedFile::fake()->createWithContent("chunk-{$index}.part", $chunkContent);

            $response = $this->post(route('file-uploader.chunks.store'), [
                'fileChunk' => $chunk,
                'chunkIndex' => $index,
                'totalChunks' => $totalChunks,
                'fileSize' => $fileSize,
                'uploadId' => $uploadId,
                'fileName' => $fileName,
                'mimeType' => $mimeType,
                'fileHash' => $fileHash,
                'fileLastModified' => time(),
                'locale' => 'en',
            ]);

            $response->assertOk();
            $response->assertJsonPath('success', true);
            $response->assertJsonPath('data.completed', $index + 1 >= $totalChunks);

            $isFinalChunk = $index + 1 >= $totalChunks;
            $messageKey = $isFinalChunk
                ? 'file-uploader::messages.upload_completed'
                : 'file-uploader::messages.chunk_received';

            $response->assertJsonPath('message', Lang::get($messageKey, [
                'current' => $index + 1,
                'total' => $totalChunks,
                'name' => $fileName,
            ]));

            $finalResponse = $response;
        }

        $this->assertNotNull($finalResponse);
        $finalResponse->assertJsonPath('data.completed', true);
        $finalResponse->assertJsonPath('data.metadata.id', $fileHash);
        $finalResponse->assertJsonPath('data.metadata.name', $fileName);
        $finalResponse->assertJsonPath('data.metadata.size', $fileSize);
        $finalResponse->assertJsonPath('data.metadata.mime', $mimeType);

        $uploader = new FileUpload(config('file-uploader'));
        $metadata = $uploader->readMetadata($fileHash);
        $this->assertNotNull($metadata);
        $this->assertSame($fileSize, $metadata->size);
        $this->assertSame($mimeType, $metadata->mime);
        $this->assertNotEmpty($metadata->path);
        $this->assertTrue(Storage::disk($disk)->exists($metadata->path));

        $storedChecksum = hash('sha256', Storage::disk($disk)->get($metadata->path));
        $this->assertSame($fileHash, $storedChecksum);
    }

    public function test_it_returns_validation_errors_using_send_error_response(): void
    {
        $uploadId = sprintf('upload-%013d-%s', now()->valueOf(), Str::lower(Str::random(8)));

        $response = $this->postJson(route('file-uploader.chunks.store'), [
            'fileChunk' => UploadedFile::fake()->create('chunk-0.part', 10),
            'totalChunks' => 1,
            'fileSize' => 512,
            'uploadId' => $uploadId,
            'fileName' => 'missing-index.txt',
            'mimeType' => 'text/plain',
            'fileLastModified' => time(),
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('success', false);
        $requiredMessage = Lang::get('validation.required', ['attribute' => 'chunk index']);
        $response->assertJsonPath('message', Lang::get('file-uploader::messages.attention').$requiredMessage);
        $response->assertJsonPath('errors.chunkIndex.0', $requiredMessage);
    }
}
