<?php

declare(strict_types=1);

namespace Xakki\LaravelFileUploader\Tests;

use Illuminate\Http\UploadedFile;
use Orchestra\Testbench\TestCase as OrchestraTestCase;
use Xakki\LaravelFileUploader\Http\Requests\UploadChunkRequest;
use Xakki\LaravelFileUploader\Providers\FileUploaderServiceProvider;

abstract class TestCase extends OrchestraTestCase
{
    protected function getPackageProviders($app)
    {
        return [FileUploaderServiceProvider::class];
    }

    protected function defineEnvironment($app)
    {
        $app['config']->set('file-uploader.middleware', []);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function makeUploadChunkRequest(array $payload): UploadChunkRequest
    {
        $files = [];

        if (isset($payload['fileChunk']) && $payload['fileChunk'] instanceof UploadedFile) {
            $files['fileChunk'] = $payload['fileChunk'];
            unset($payload['fileChunk']);
        }

        $request = UploadChunkRequest::create('/', 'POST', $payload, [], $files);
        $request->setContainer($this->app)->setRedirector($this->app->make('redirect'));
        $request->validateResolved();

        if (isset($files['fileChunk'])) {
            $request->files->set('fileChunk', $files['fileChunk']);
        }

        return $request;
    }

    protected function makeUploadId(): string
    {
        $timestamp = (string) random_int(1000000000000, 9999999999999);
        $suffix = bin2hex(random_bytes(4));

        return sprintf('upload-%s-%s', $timestamp, $suffix);
    }
}
