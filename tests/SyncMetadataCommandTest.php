<?php

declare(strict_types=1);

namespace Xakki\LaravelFileUploader\Tests;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Storage;

class SyncMetadataCommandTest extends TestCase
{
    public function test_it_synchronizes_metadata_with_existing_files(): void
    {
        Date::setTestNow('2024-01-01 12:00:00');

        $disk = 'files';
        Storage::fake($disk);

        config([
            'file-uploader.disk' => $disk,
            'file-uploader.directory' => 'uploads',
            'file-uploader.metadata_directory' => '.meta',
            'file-uploader.trash_directory' => '.trash',
            'file-uploader.temporary_directory' => '.chunks',
        ]);

        $txtExist = 'Existing content';
        $storage = Storage::disk($disk);
        $storage->put('uploads/existing.txt', $txtExist);
        $hashExist = hash('sha256', $txtExist);
        $txtNew = 'Fresh file';
        $storage->put('uploads/new.txt', $txtNew);
        $hashNew = hash('sha256', $txtNew);
        $metadataDirectory = '.meta';
        $metaExistPath = $metadataDirectory.'/'.$hashExist.'.json';

        $storage->put($metaExistPath, json_encode([
            'id' => 'existing-id',
            'name' => 'existing.txt',
            'size' => 1,
            'mime' => 'application/octet-stream',
            'path' => 'uploads/existing.txt',
            'disk' => 'legacy-disk',
            'url' => 'http://example.com/old',
            'createdAt' => Date::now()->toIso8601String(),
            'deletedAt' => null,
            'hash' => 'outdated',
        ], JSON_PRETTY_PRINT));

        $storage->put($metadataDirectory.'/missing-id.json', json_encode([
            'id' => 'missing-id',
            'name' => 'missing.txt',
            'size' => 10,
            'mime' => 'text/plain',
            'path' => 'uploads/missing.txt',
            'disk' => $disk,
            'url' => 'http://example.com/missing',
            'createdAt' => Date::now()->toIso8601String(),
            'deletedAt' => null,
            'hash' => hash('sha256', 'missing'),
        ], JSON_PRETTY_PRINT));

        $exitCode = Artisan::call('file-uploader:sync-metadata');

        $this->assertSame(0, $exitCode);

        $this->assertTrue($storage->exists($metaExistPath));
        $existingMetadata = json_decode(
            $storage->get($metaExistPath),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        $this->assertSame('uploads/existing.txt', $existingMetadata['path']);
        $this->assertSame(strlen($txtExist), $existingMetadata['size']);
        $this->assertSame('text/plain', $existingMetadata['mime']);
        $this->assertSame($hashExist, $existingMetadata['hash']);
        $this->assertSame($disk, $existingMetadata['disk']);
        $this->assertNotEmpty($existingMetadata['url']);

        $this->assertFalse($storage->exists($metadataDirectory.'/missing-id.json'));

        $metadataFiles = $storage->files($metadataDirectory);
        $newMetadataPath = null;
        foreach ($metadataFiles as $file) {
            if ($file !== $metadataDirectory.'/'.$hashExist.'.json') {
                $newMetadataPath = $file;
            }
        }

        $this->assertNotNull($newMetadataPath);

        $newMetadata = json_decode(
            $storage->get($newMetadataPath),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        $this->assertSame('uploads/new.txt', $newMetadata['path']);
        $this->assertSame('new.txt', $newMetadata['name']);
        $this->assertSame(strlen('Fresh file'), $newMetadata['size']);
        $this->assertSame('text/plain', $newMetadata['mime']);
        $this->assertSame(hash('sha256', 'Fresh file'), $newMetadata['hash']);
        $this->assertSame($disk, $newMetadata['disk']);
        $this->assertNotEmpty($newMetadata['url']);
        $this->assertNull($newMetadata['deletedAt']);
        $this->assertSame(Date::now()->toIso8601String(), $newMetadata['createdAt']);

        Date::setTestNow();
    }
}
