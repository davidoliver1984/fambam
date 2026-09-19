<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;
use Laravel\Fortify\Contracts\LoginResponse;

class SpaLoginResponse implements LoginResponse
{
    public function toResponse($request): JsonResponse
    {
        return response()->json(['two_factor' => false]);
    }
}
