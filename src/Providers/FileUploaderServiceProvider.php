<?php

declare(strict_types=1);

namespace Xakki\LaravelFileUploader\Providers;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Xakki\LaravelFileUploader\Commands\CleanupTrashCommand;
use Xakki\LaravelFileUploader\Commands\SyncMetadataCommand;
use Xakki\LaravelFileUploader\Services\FileUpload;
use Xakki\LaravelFileUploader\Services\FileWidget;

class FileUploaderServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/file-uploader.php', 'file-uploader');

        $this->app->singleton(FileUpload::class, function (Application $app) {
            return new FileUpload(
                config('file-uploader')
            );
        });

        $this->app->singleton(FileWidget::class, function (Application $app) {
            return new FileWidget(
                config('file-uploader')
            );
        });
    }

    public function boot(): void
    {
        $this->registerPublishing();
        $this->registerRoutes();
        $this->loadTranslationsFrom(__DIR__.'/../../lang', 'file-uploader');
        $this->commands([CleanupTrashCommand::class, SyncMetadataCommand::class]);
        $this->scheduleAutomaticPublishing();
    }

    protected function registerRoutes(): void
    {
        if (! Route::hasMacro('fileUploader')) {
            Route::macro('fileUploader', function (): void {
                $config = config('file-uploader');
                Route::prefix($config['route_prefix'])
                    ->middleware($config['middleware'])
                    ->name($config['route_name'])
                    ->group(__DIR__.'/../../routes/file-uploader.php');
            });
        }

        /** @phpstan-ignore-next-line Laravel macro is registered above. */
        Route::fileUploader();
    }

    protected function registerPublishing(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../../config/file-uploader.php' => config_path('file-uploader.php'),
        ], 'file-uploader-config');

        $this->publishes([
            __DIR__.'/../../resources/assets/js/file-upload.js' => public_path('vendor/file-uploader/file-upload.js'),
            __DIR__.'/../../resources/assets/js/file-widget.js' => public_path('vendor/file-uploader/file-widget.js'),
        ], 'file-uploader-assets');

        $this->publishes([
            __DIR__.'/../../lang' => lang_path('vendor/file-uploader'),
        ], 'file-uploader-translations');
    }

    protected function scheduleAutomaticPublishing(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->app->booted(function (): void {
            if (! $this->isPackageDiscoverCommand()) {
                return;
            }

            $this->runPublishCommands();
        });
    }

    protected function isPackageDiscoverCommand(): bool
    {
        $command = $_SERVER['argv'][1] ?? null;

        return $command === 'package:discover';
    }

    protected function runPublishCommands(): void
    {
        foreach ([
            'file-uploader-config',
            'file-uploader-assets',
            'file-uploader-translations',
        ] as $tag) {
            Artisan::call('vendor:publish', [
                '--tag' => $tag,
                '--force' => true,
            ]);
        }
    }

    /**
     * @return array<int, class-string>
     */
    public function provides(): array
    {
        return [FileUpload::class, FileWidget::class];
    }
}
