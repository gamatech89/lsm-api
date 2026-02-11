<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller as BaseController;
use Illuminate\Http\JsonResponse;

/**
 * Base API Controller
 * 
 * Provides standardized JSON response methods for all API controllers.
 * All API v1 controllers should extend this class.
 */
abstract class Controller extends BaseController
{
    /**
     * Return a successful JSON response.
     *
     * @param mixed $data The data to return
     * @param string|null $message Optional success message
     * @param int $code HTTP status code (default: 200)
     * @return JsonResponse
     */
    protected function successResponse(mixed $data = null, ?string $message = null, int $code = 200): JsonResponse
    {
        $response = [
            'success' => true,
        ];

        if ($message !== null) {
            $response['message'] = $message;
        }

        if ($data !== null) {
            $response['data'] = $data;
        }

        return response()->json($response, $code);
    }

    /**
     * Return an error JSON response.
     *
     * @param string $message Error message
     * @param int $code HTTP status code (default: 400)
     * @param array|null $errors Validation or detailed errors
     * @return JsonResponse
     */
    protected function errorResponse(string $message, int $code = 400, ?array $errors = null): JsonResponse
    {
        $response = [
            'success' => false,
            'message' => $message,
        ];

        if ($errors !== null) {
            $response['errors'] = $errors;
        }

        return response()->json($response, $code);
    }

    /**
     * Return a created response (201).
     *
     * @param mixed $data The created resource data
     * @param string|null $message Optional message
     * @return JsonResponse
     */
    protected function createdResponse(mixed $data, ?string $message = 'Resource created successfully'): JsonResponse
    {
        return $this->successResponse($data, $message, 201);
    }

    /**
     * Return a no content response (204).
     *
     * @return JsonResponse
     */
    protected function noContentResponse(): JsonResponse
    {
        return response()->json(null, 204);
    }

    /**
     * Return an unauthorized response (401).
     *
     * @param string $message Error message
     * @return JsonResponse
     */
    protected function unauthorizedResponse(string $message = 'Unauthorized'): JsonResponse
    {
        return $this->errorResponse($message, 401);
    }

    /**
     * Return a forbidden response (403).
     *
     * @param string $message Error message
     * @return JsonResponse
     */
    protected function forbiddenResponse(string $message = 'Forbidden'): JsonResponse
    {
        return $this->errorResponse($message, 403);
    }

    /**
     * Return a not found response (404).
     *
     * @param string $message Error message
     * @return JsonResponse
     */
    protected function notFoundResponse(string $message = 'Resource not found'): JsonResponse
    {
        return $this->errorResponse($message, 404);
    }

    // =====================================================
    // ALIAS METHODS (shorthand for cleaner controller code)
    // =====================================================

    /**
     * Alias for successResponse
     */
    protected function success(mixed $data = null, ?string $message = null, int $code = 200): JsonResponse
    {
        return $this->successResponse($data, $message, $code);
    }

    /**
     * Alias for errorResponse
     */
    protected function error(string $message, int $code = 400, ?array $errors = null): JsonResponse
    {
        return $this->errorResponse($message, $code, $errors);
    }

    /**
     * Alias for createdResponse
     */
    protected function created(mixed $data, ?string $message = 'Resource created successfully'): JsonResponse
    {
        return $this->createdResponse($data, $message);
    }

    /**
     * Alias for forbiddenResponse
     */
    protected function forbidden(string $message = 'Forbidden'): JsonResponse
    {
        return $this->forbiddenResponse($message);
    }

    /**
     * Alias for notFoundResponse
     */
    protected function notFound(string $message = 'Resource not found'): JsonResponse
    {
        return $this->notFoundResponse($message);
    }
}
