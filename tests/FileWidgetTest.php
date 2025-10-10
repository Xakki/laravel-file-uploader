<?php

declare(strict_types=1);

namespace Xakki\LaravelFileUploader\Tests;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Xakki\LaravelFileUploader\DTO\FileMetadata;
use Xakki\LaravelFileUploader\Services\FileWidget;

class FileWidgetTest extends TestCase
{
    public function test_get_widget_includes_widget_assets(): void
    {
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        $this->app['session']->start();

        $html = app(FileWidget::class)->getWidget();

        $this->assertStringContainsString('file-upload.js', $html);
        $this->assertStringContainsString('file-widget.js', $html);
    }

    public function test_list_returns_files_with_ownership_flag(): void
    {
        $disk = 'files';
        Storage::fake($disk);

        config(['file-uploader.disk' => $disk]);

        $widget = new FileWidget(config('file-uploader'));

        $chunk = UploadedFile::fake()->createWithContent('chunk-0.part', 'payload');
        $fileHash = hash_file('sha256', $chunk->getRealPath());
        $fileMetadata = $widget->handleChunk($this->makeUploadChunkRequest([
            'fileChunk' => $chunk,
            'chunkIndex' => 0,
            'totalChunks' => 1,
            'fileSize' => strlen('payload'),
            'uploadId' => $this->makeUploadId(),
            'fileName' => 'document.txt',
            'mimeType' => 'text/plain',
            'fileHash' => $fileHash,
            'fileLastModified' => time(),
        ]));

        $this->assertInstanceOf(FileMetadata::class, $fileMetadata);
        $files = $widget->list();
        $this->assertCount(1, $files);
        $this->assertArrayHasKey('own', $files[0]);
        $this->assertFalse($files[0]['own']);
    }
}
