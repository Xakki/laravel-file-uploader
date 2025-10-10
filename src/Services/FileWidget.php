<?php

declare(strict_types=1);

namespace Xakki\LaravelFileUploader\Services;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;
use Xakki\LaravelFileUploader\DTO\FileMetadata;

class FileWidget extends FileUpload
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function getWidget(array $config = []): string
    {
        $config['endpointBase'] = '/'.$this->config['route_prefix'];
        $config['chunkSize'] = $this->config['chunk_size'];
        $config['allowList'] = (bool) $this->config['allow_list'];
        $config['allowDelete'] = (bool) $this->config['allow_delete'];
        $config['allowDeleteAllFiles'] = (bool) $this->config['allow_delete_all_files'];
        $config['allowCleanup'] = (bool) $this->config['allow_cleanup'];
        $config['locale'] = $this->config['locale'];
        $config['token'] = csrf_token();
        $config['routePlaceholder'] = self::ROUTE_PARAM_PLACEHOLDER;
        $config['routes'] = [
            'upload' => route($this->config['route_name'].'chunks.store'),
            'list' => route($this->config['route_name'].'files.index'),
            'delete' => route($this->config['route_name'].'files.destroy', ['id' => self::ROUTE_PARAM_PLACEHOLDER]),
            'restore' => route($this->config['route_name'].'files.restore', ['id' => self::ROUTE_PARAM_PLACEHOLDER]),
            'cleanup' => route($this->config['route_name'].'trash.cleanup'),
        ];

        return '
            <div id="file-upload-widget"></div>
            <script>
              window.FileUploadConfig = '.json_encode($config).';
            </script>
            <script src="/vendor/file-uploader/file-upload.js" defer></script>
            <script src="/vendor/file-uploader/file-widget.js" defer></script>
            ';
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function list(): array
    {
        $metadataDirectory = $this->metadataDirectory();
        if (! $this->disk->exists($metadataDirectory)) {
            return [];
        }

        $files = [];
        foreach ($this->disk->files($metadataDirectory) as $path) {
            if (! Str::endsWith($path, '.json')) {
                continue;
            }

            try {
                $metadata = $this->readMetadataByPath($path);
            } catch (\Throwable $e) {
                logger()->warning($e);

                continue;
            }

            if ($metadata->deletedAt) {
                continue;
            }

            if (! $this->disk->exists($metadata->path)) {
                $metadata->setDeleted('');
                $this->writeMetadata($metadata);
                logger()->notice('File not found: '.$metadata->path);

                continue;
            }

            $files[] = $this->formatFileForResponse($metadata);
        }

        usort($files, static fn (array $a, array $b) => strcmp($b['createdAt'], $a['createdAt']));

        return $files;
    }

    public function delete(string $fileHash): bool
    {
        try {
            $metadata = $this->readMetadata($fileHash);
        } catch (\Throwable $e) {
            logger()->notice($e);

            return false;
        }

        if (! $this->canManageMetadata($metadata)) {
            throw new AuthorizationException('You are not allowed to delete this file.');
        }

        if ($metadata->deletedAt) {
            logger()->notice('File ['.$fileHash.'] already move to trash');

            return true;
        }

        $softDelete = (bool) ($this->config['soft_delete'] ?? true);

        if ($softDelete) {
            $trashDirectory = $this->trashDirectory();
            $this->ensureDirectory($trashDirectory);

            $trashPath = trim($trashDirectory, '/').'/'.$metadata->name;

            if (! $metadata->path || ! $this->disk->exists($metadata->path)) {
                $this->deleteMetadata($fileHash);

                return false;
            }

            if (! $this->disk->move($metadata->path, $trashPath)) {
                logger()->notice('Cant move to trash ['.$fileHash.']');

                return false;
            }

            $metadata->setDeleted($trashPath);

            logger()->info('Update meta ['.$fileHash.'] : '.json_encode($metadata->toArray()));
            $this->writeMetadata($metadata);

            return true;
        }

        if ($metadata->path && $this->disk->exists($metadata->path)) {
            $this->disk->delete($metadata->path);
        }

        $this->deleteMetadata($fileHash);

        return true;
    }

    public function restore(string $fileHash): bool
    {
        try {
            $metadata = $this->readMetadata($fileHash);
        } catch (\Throwable $e) {
            logger()->notice($e);

            return false;
        }

        if (! $metadata->deletedAt) {
            logger()->notice('Cant restore metadata #'.$metadata['id']);

            return false;
        }

        if (! $this->canManageMetadata($metadata)) {
            throw new AuthorizationException('You are not allowed to restore this file.');
        }

        $trashPath = $metadata->trashPath;
        if (! $trashPath || ! $this->disk->exists($trashPath)) {
            $this->deleteMetadata($fileHash);

            return false;
        }

        $this->uploadDirectory();

        if ($this->disk->move($trashPath, $metadata->path)) {
            return false;
        }

        $metadata->deletedAt = null;
        $metadata->trashPath = null;
        $metadata->url = $this->resolvePublicUrl($metadata->path);
        $this->writeMetadata($metadata);

        return true;
    }

    public function cleanupTrash(): int
    {
        $ttl = (int) ($this->config['trash_ttl_days'] ?? 30);
        $threshold = $ttl > 0 ? Date::now()->subDays($ttl) : Date::now();

        $metadataDirectory = $this->metadataDirectory();
        if (! $this->disk->exists($metadataDirectory)) {
            return 0;
        }

        $removed = 0;
        foreach ($this->disk->files($metadataDirectory) as $path) {
            if (! Str::endsWith($path, '.json')) {
                continue;
            }

            try {
                $metadata = $this->readMetadataByPath($path);
            } catch (\Throwable $e) {
                logger()->warning($e);

                continue;
            }

            if (! $metadata->deletedAt) {
                continue;
            }

            if (Date::parse($metadata->deletedAt)->lessThanOrEqualTo($threshold)) {
                if ($metadata->trashPath !== null && $this->disk->exists($metadata->trashPath)) {
                    $this->disk->delete($metadata->trashPath);
                }

                $this->disk->delete($path);
                $removed++;
            }
        }

        return $removed;
    }

    protected function canManageMetadata(FileMetadata $metadata): bool
    {
        $user = Auth::user();
        if ($this->hasFullAccess($user)) {
            return true;
        }

        if ($this->config['allow_delete_all_files'] ?? false) {
            return true;
        }

        $currentUserId = $user ? (string) $user->getAuthIdentifier() : null;
        if ($currentUserId === null) {
            return false;
        }

        $ownerId = $metadata->userId;
        if ($ownerId === null) {
            return false;
        }

        return (string) $ownerId === $currentUserId;
    }

    protected function hasFullAccess(?Authenticatable $user): bool
    {
        if (! $user) {
            return false;
        }

        $fullAccess = $this->config['full_access'] ?? [];

        $userIds = array_map(static fn ($value) => (string) $value, $fullAccess['users'] ?? []);
        if ($userIds && in_array((string) $user->getAuthIdentifier(), $userIds, true)) {
            return true;
        }

        $roles = $fullAccess['roles'] ?? [];
        if ($roles) {
            if (method_exists($user, 'hasAnyRole')) {
                if ($user->hasAnyRole($roles)) {
                    return true;
                }
            } elseif (method_exists($user, 'hasRole')) {
                foreach ($roles as $role) {
                    if ($user->hasRole($role)) {
                        return true;
                    }
                }
            } elseif (method_exists($user, 'getRoleNames')) {
                $roleNames = $user->getRoleNames();
                if (is_iterable($roleNames)) {
                    foreach ($roleNames as $roleName) {
                        if (in_array((string) $roleName, $roles, true)) {
                            return true;
                        }
                    }
                }
            }
        }

        return false;
    }

    protected function isOwnFile(FileMetadata $metadata): bool
    {
        $currentUserId = $this->currentUserId();
        if ($currentUserId === null) {
            return false;
        }

        if ($metadata->userId === null) {
            return false;
        }

        return (string) $metadata->userId === $currentUserId;
    }

    /**
     * @return array<string, mixed>
     */
    public function formatFileForResponse(FileMetadata $metadata): array
    {
        $response = parent::formatFileForResponse($metadata);
        $response['own'] = $this->isOwnFile($metadata);

        return $response;
    }
}
