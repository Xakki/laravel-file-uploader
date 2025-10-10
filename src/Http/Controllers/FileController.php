<?php

declare(strict_types=1);

namespace Xakki\LaravelFileUploader\Http\Controllers;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Lang;
use Symfony\Component\HttpFoundation\Response;
use Xakki\LaravelFileUploader\Services\FileWidget;

class FileController extends AbstractController
{
    public function __construct(protected FileWidget $widget)
    {
        parent::__construct($widget);
    }

    public function index(): JsonResponse
    {
        $files = config('file-uploader.allow_list')
            ? $this->widget->list()
            : [];

        return $this->sendResponse([
            'files' => $files,
        ], 'ok');
    }

    public function destroy(string $id): JsonResponse
    {
        try {
            $deleted = $this->widget->delete($id);
        } catch (AuthorizationException) {
            return $this->sendError(
                Lang::get('file-uploader::messages.not_allow'),
                status: Response::HTTP_FORBIDDEN
            );
        }

        if (! $deleted) {
            return $this->sendError(
                Lang::get('file-uploader::messages.not_found'),
                status: Response::HTTP_NOT_FOUND
            );
        }

        return $this->sendResponse([
            'id' => $id,
        ], Lang::get('file-uploader::messages.moved_to_trash'));
    }

    public function restore(string $id): JsonResponse
    {
        try {
            if (! $this->widget->restore($id)) {
                return $this->sendError(
                    Lang::get('file-uploader::messages.not_found'),
                    status: Response::HTTP_NOT_FOUND
                );
            }
        } catch (AuthorizationException) {
            return $this->sendError(
                Lang::get('file-uploader::messages.not_allow'),
                status: Response::HTTP_FORBIDDEN
            );
        }

        return $this->sendResponse([
            'id' => $id,
        ], Lang::get('file-uploader::messages.restored'));
    }

    public function cleanup(): JsonResponse
    {
        if (! config('file-uploader.allow_cleanup')) {
            return $this->sendError(
                Lang::get('file-uploader::messages.not_allow'),
                status: Response::HTTP_FORBIDDEN
            );
        }
        $count = $this->widget->cleanupTrash();

        return $this->sendResponse([
            'count' => $count,
        ], Lang::get('file-uploader::messages.cleanup_done', ['count' => $count]));
    }
}
