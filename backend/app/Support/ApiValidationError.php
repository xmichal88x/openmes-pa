<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;

/**
 * A refused business rule answered as 422.
 *
 * The reason stays in `message` because every client (PA queue, the bridge, the
 * SPA) already reads it; `errors` repeats it in the field-keyed shape Laravel's
 * own validation response uses (`{field: [message]}`), so a form can point at
 * the input that was refused instead of showing a bare banner.
 */
final class ApiValidationError
{
    /**
     * @param  string  $field  the request field (or entity) the refusal is about
     */
    public static function response(string $field, string $message): JsonResponse
    {
        return response()->json([
            'message' => $message,
            'errors' => [$field => [$message]],
        ], 422);
    }
}
