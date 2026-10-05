<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateProfileRequest;
use App\Models\User;
use App\Services\MediaDeliveryManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CurrentUserController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json(['data' => $this->payload($user)]);
    }

    public function update(UpdateProfileRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $user->update($request->validated());

        return response()->json(['data' => $this->payload($user->refresh())]);
    }

    /** @return array<string, mixed> */
    private function payload(User $user): array
    {
        $user->loadMissing('avatarMediaUpload');
        $avatar = $user->avatarMediaUpload;
        $avatarPayload = null;
        if ($avatar !== null) {
            $authorization = app(MediaDeliveryManager::class)->canonical($avatar);
            $avatarPayload = [
                'media_upload_id' => $avatar->id,
                'url' => $authorization->url,
                'expires_at' => $authorization->expiresAt->toAtomString(),
            ];
        }

        return [
            'id' => $user->id,
            'name' => $user->name,
            'about' => $user->about,
            'email' => $user->email,
            'pending_email' => $user->pending_email,
            'pending_email_requested_at' => $user->pending_email_requested_at?->toAtomString(),
            'avatar' => $avatarPayload,
            'timezone' => $user->timezone,
            'email_verified_at' => $user->email_verified_at?->toAtomString(),
            'can_create_family_spaces' => $user->can_create_family_spaces,
            'two_factor_enabled' => $user->two_factor_confirmed_at !== null,
        ];
    }
}
