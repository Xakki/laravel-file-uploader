<?php

declare(strict_types=1);

namespace Xakki\LaravelFileUploader\Commands;

use Illuminate\Console\Command;
use Xakki\LaravelFileUploader\Services\FileUpload;

class SyncMetadataCommand extends Command
{
    protected $signature = 'file-uploader:sync-metadata';

    protected $description = 'Synchronize metadata files with the stored uploads.';

    public function handle(FileUpload $uploader): int
    {
        $result = $uploader->syncMetadata();

        $this->info(sprintf(
            'Metadata synchronized. Created: %d, Updated: %d, Deleted: %d.',
            $result['created'],
            $result['updated'],
            $result['deleted'],
        ));

        return self::SUCCESS;
    }
}
