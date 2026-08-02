<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Password;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse;

class EnumerationSafePasswordResetLinkResponse implements FailedPasswordResetLinkRequestResponse
{
    public function toResponse($request)
    {
        return $request->wantsJson()
            ? new JsonResponse(['message' => trans(Password::RESET_LINK_SENT)])
            : back()->with('status', trans(Password::RESET_LINK_SENT));
    }
}
