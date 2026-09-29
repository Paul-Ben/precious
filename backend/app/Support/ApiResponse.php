<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Builds the standard API envelope documented in docs/api/README.md:
 *
 *   { "success": true,  "message": "...", "data": {...}, "meta": {...} }
 *   { "success": false, "message": "...", "code": "...", "errors": {...} }
 */
final class ApiResponse
{
    public static function success(
        mixed $data = null,
        string $message = 'OK',
        array $meta = [],
        int $status = 200,
    ): JsonResponse {
        if ($data instanceof ResourceCollection && $data->resource instanceof LengthAwarePaginator) {
            $meta = array_merge(self::paginationMeta($data->resource), $meta);
        }

        if ($data instanceof JsonResource) {
            $data = $data->resolve(request());
        }

        return new JsonResponse([
            'success' => true,
            'message' => $message,
            'data' => $data ?? (object) [],
            'meta' => (object) $meta,
        ], $status);
    }

    public static function created(mixed $data = null, string $message = 'Created.'): JsonResponse
    {
        return self::success($data, $message, [], 201);
    }

    public static function error(
        string $message,
        int $status = 400,
        string $code = 'ERROR',
        ?array $errors = null,
        array $extra = [],
    ): JsonResponse {
        $body = [
            'success' => false,
            'message' => $message,
            'code' => $code,
        ];

        if ($errors !== null) {
            $body['errors'] = $errors;
        }

        return new JsonResponse(array_merge($body, $extra), $status);
    }

    /**
     * @return array<string, int>
     */
    public static function paginationMeta(LengthAwarePaginator $paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'last_page' => $paginator->lastPage(),
        ];
    }
}
