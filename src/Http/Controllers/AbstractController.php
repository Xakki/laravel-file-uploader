<?php

declare(strict_types=1);

namespace Xakki\LaravelFileUploader\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Xakki\LaravelFileUploader\Services\FileUpload;

abstract class AbstractController extends Controller
{
    public function __construct(protected FileUpload $uploader) {}

    public function sendResponse(mixed $result, string $message, int $status = 200): JsonResponse
    {
        $response = [
            'success' => true,
            'data' => $result,
            'message' => $message,
        ];

        return response()->json(data: $response, status: $status, options: JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /**
     * @param  array<string,string[]>|\JsonSerializable  $errorMessages
     */
    public function sendError(string $error, $errorMessages = [], int $status = 404): JsonResponse
    {
        $response = [
            'success' => false,
            'message' => $error,
        ];

        if (! empty($errorMessages)) {
            $response['errors'] = $errorMessages;
        }

        return response()->json($response, $status);
    }
}
