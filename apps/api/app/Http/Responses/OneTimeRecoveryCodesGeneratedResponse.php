<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;
use Laravel\Fortify\Contracts\RecoveryCodesGeneratedResponse;

class OneTimeRecoveryCodesGeneratedResponse implements RecoveryCodesGeneratedResponse
{
    public function toResponse($request): JsonResponse
    {
        return response()->json([
            'recovery_codes' => $request->user()->recoveryCodes(),
        ]);
    }
}
