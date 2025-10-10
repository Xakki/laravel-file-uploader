<?php

declare(strict_types=1);

namespace Xakki\LaravelFileUploader\Tests;

class RoutesTest extends TestCase
{
    public function test_routes_are_registered_by_service_provider(): void
    {
        $this->assertTrue($this->app['router']->has('file-uploader.chunks.store'));
        $this->assertTrue($this->app['router']->has('file-uploader.files.index'));

        $this->get(route('file-uploader.files.index'))
            ->assertStatus(200);
    }
}
