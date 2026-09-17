<?php

namespace App\Http\Controllers;

use App\Models\FamilySpace;
use App\Queries\PhotoQuery;
use App\Services\MediaDeliveryManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

final class PhotoDownloadController extends Controller
{
    public function __construct(
        private readonly PhotoQuery $photos,
        private readonly MediaDeliveryManager $delivery,
    ) {}

    public function show(FamilySpace $familySpace, string $photo, Request $request): JsonResponse
    {
        $visible = $this->photos->findVisibleTo($request->user(), $photo);
        Gate::authorize('view', $visible);
        $authorization = $this->delivery->photoPresentation($visible);

        return response()->json(['data' => [
            'url' => $authorization->url,
            'expires_at' => $authorization->expiresAt->toAtomString(),
            'photo_version_id' => $visible->active_photo_version_id,
        ]]);
    }
}
