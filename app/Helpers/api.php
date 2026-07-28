<?php

use Illuminate\Http\JsonResponse;

if (! function_exists('apiResponse')) {
    function apiResponse(
        mixed $data = null,
        string $message = '',
        int $status = 200,
        array $meta = []
    ): JsonResponse {
        $response = [
            'message' => $message,
        ];

        if ($data !== null) {
            $response['data'] = $data;
        }

        if ($meta !== []) {
            $response['meta'] = $meta;
        }

        return response()->json($response, $status);
    }
}
