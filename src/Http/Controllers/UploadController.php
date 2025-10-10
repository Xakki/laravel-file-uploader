<?php

declare(strict_types=1);

namespace Xakki\LaravelFileUploader\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Lang;
use Illuminate\Validation\ValidationException;
use Xakki\LaravelFileUploader\DTO\FileMetadata;
use Xakki\LaravelFileUploader\Http\Requests\UploadChunkRequest;

class UploadController extends AbstractController
{
    public function store(Request $request): JsonResponse
    {
        try {
            $chunkRequest = UploadChunkRequest::createFrom($request)
                ->setContainer(app())
                ->setRedirector(app('redirect'));
            $chunkRequest->validateResolved();
            $result = $this->uploader->handleChunk($chunkRequest);
        } catch (ValidationException $e) {
            logger()->notice($e);
            $errors = $e->errors();
            $firstErrorMessage = $e->getMessage();
            foreach ($e->errors() as $messages) {
                if (! empty($messages)) {
                    $firstErrorMessage = (string) $messages[0];
                    break;
                }
            }

            return $this->sendError(
                Lang::get('file-uploader::messages.attention').$firstErrorMessage,
                $errors,
                status: 422,
            );
        } catch (\Error $e) {
            logger()->warning($e);

            return $this->sendError(Lang::get('file-uploader::messages.attention').$e->getMessage(), status: 422);
        } catch (\Throwable $e) {
            logger()->error($e);

            return $this->sendError(Lang::get('file-uploader::messages.error').$e->getMessage(), status: 500);
        }

        $completed = $result instanceof FileMetadata;
        $response = [
            'completed' => $completed,
        ];
        if ($completed) {
            $response['metadata'] = $this->uploader->formatFileForResponse($result);
            $messageKey = 'file-uploader::messages.upload_completed';
        } else {
            $messageKey = 'file-uploader::messages.chunk_received';
        }

        return $this->sendResponse($response, Lang::get($messageKey, [
            'current' => $chunkRequest->chunkIndex + 1,
            'total' => $chunkRequest->totalChunks,
            'name' => $chunkRequest->fileName,
        ]));
    }
}
