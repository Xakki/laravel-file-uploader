<?php

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Support\Facades\Facade;

$container = new Container;

Container::setInstance($container);
Facade::setFacadeApplication($container);

$storagePath = __DIR__.'/storage';
$defaultDiskPath = $storagePath.'/app';
$filesDiskPath = $storagePath.'/files';

foreach ([$defaultDiskPath, $filesDiskPath] as $path) {
    if (! is_dir($path)) {
        mkdir($path, 0777, true);
    }
}

$container->instance('config', new Repository([
    'filesystems' => [
        'default' => 'local',
        'cloud' => 'local',
        'disks' => [
            'public' => [
                'driver' => 'local',
                'root' => $defaultDiskPath,
                'throw' => false,
            ],
        ],
        'links' => [],
    ],
]));

$container->singleton('files', function () {
    return new Filesystem;
});

$container->singleton('filesystem', function ($app) {
    return new FilesystemManager($app);
});

$container->bind('filesystem.disk', function ($app) {
    return $app['filesystem']->disk($app['config']->get('filesystems.default'));
});

$container->bind('filesystem.cloud', function ($app) {
    return $app['filesystem']->disk($app['config']->get('filesystems.cloud'));
});
