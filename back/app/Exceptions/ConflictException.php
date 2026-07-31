<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The request was understood and the caller was allowed to make it, but the
 * current state of the resource forbids it. Renders as 409.
 */
class ConflictException extends Exception
{
    public function render(Request $request): JsonResponse
    {
        return response()->json(
            ['message' => $this->getMessage()],
            Response::HTTP_CONFLICT,
        );
    }
}
