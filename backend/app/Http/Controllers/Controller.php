<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Pagination\LengthAwarePaginator;

abstract class Controller
{
    /**
     * Return a successful JSON response using the standard envelope.
     */
    protected function success(
        mixed $data,
        string $message = 'Success.',
        int $status = 200,
        ?array $meta = null,
    ): JsonResponse {
        return response()->json([
            'success' => true,
            'data'    => $data,
            'message' => $message,
            'errors'  => null,
            'meta'    => $meta,
        ], $status);
    }

    /**
     * Return a created (201) JSON response.
     */
    protected function created(mixed $data, string $message = 'Resource created successfully.'): JsonResponse
    {
        return $this->success($data, $message, 201);
    }

    /**
     * Return a no-content (204) response.
     */
    protected function noContent(): JsonResponse
    {
        return response()->json(null, 204);
    }

    /**
     * Return an error JSON response using the standard envelope.
     */
    protected function error(
        string $message,
        int $status = 400,
        ?array $errors = null,
    ): JsonResponse {
        return response()->json([
            'success' => false,
            'data'    => null,
            'message' => $message,
            'errors'  => $errors,
            'meta'    => null,
        ], $status);
    }

    /**
     * Build pagination meta from a LengthAwarePaginator instance.
     */
    protected function paginationMeta(LengthAwarePaginator $paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'per_page'     => $paginator->perPage(),
            'total'        => $paginator->total(),
            'last_page'    => $paginator->lastPage(),
            'links'        => [
                'first' => $paginator->url(1),
                'last'  => $paginator->url($paginator->lastPage()),
                'prev'  => $paginator->previousPageUrl(),
                'next'  => $paginator->nextPageUrl(),
            ],
        ];
    }

    /**
     * Return a paginated JSON response.
     */
    protected function paginated(
        LengthAwarePaginator $paginator,
        mixed $data,
        string $message = 'Success.',
    ): JsonResponse {
        return $this->success($data, $message, 200, $this->paginationMeta($paginator));
    }
}
