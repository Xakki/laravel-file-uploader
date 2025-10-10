<?php

declare(strict_types=1);

namespace Xakki\LaravelFileUploader\Commands;

use Illuminate\Console\Command;
use Xakki\LaravelFileUploader\Services\FileWidget;

class CleanupTrashCommand extends Command
{
    protected $signature = 'file-uploader:cleanup';

    protected $description = 'Remove expired files from the file uploader trash bin.';

    public function handle(FileWidget $uploader): int
    {
        $count = $uploader->cleanupTrash();

        $this->info("Removed {$count} expired file(s) from trash.");

        return self::SUCCESS;
    }
}
