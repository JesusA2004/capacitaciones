<?php

namespace App\Http\Controllers\Api\V1\Concerns;

use Illuminate\Http\JsonResponse;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Forma estándar de listas paginadas de la API v1 del ciclo laboral:
 * { data: [...], meta: { current_page, per_page, total, last_page } }.
 */
trait RespondePaginado
{
    /**
     * @template T
     *
     * @param  LengthAwarePaginator<int, T>  $paginador
     * @param  callable(T): array<string, mixed>  $transformar
     * @param  array<string, mixed>  $extra
     */
    protected function paginado(LengthAwarePaginator $paginador, callable $transformar, array $extra = []): JsonResponse
    {
        return response()->json([
            'data' => array_map($transformar, $paginador->items()),
            'meta' => [
                'current_page' => $paginador->currentPage(),
                'per_page' => $paginador->perPage(),
                'total' => $paginador->total(),
                'last_page' => $paginador->lastPage(),
                ...$extra,
            ],
        ]);
    }
}
