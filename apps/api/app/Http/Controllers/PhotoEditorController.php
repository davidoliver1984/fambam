<?php

namespace App\Http\Controllers;

use App\Media\MediaDeliveryAuthorization;
use App\Models\FamilySpace;
use App\Models\Photo;
use App\Models\PhotoEditPreview;
use App\Models\PhotoVersion;
use App\Queries\PhotoQuery;
use App\Services\MediaDeliveryManager;
use App\Services\PhotoEditorManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

final class PhotoEditorController extends Controller
{
    public function __construct(
        private readonly PhotoQuery $photos,
        private readonly PhotoEditorManager $editor,
        private readonly MediaDeliveryManager $delivery,
    ) {}

    public function index(FamilySpace $familySpace, string $photo, Request $request): JsonResponse
    {
        $target = $this->visiblePhoto($photo, $request);

        return response()->json(['data' => [
            'active_photo_version_id' => $target->active_photo_version_id,
            'can_edit' => Gate::allows('update', $target),
            'versions' => PhotoVersion::query()->where('photo_id', $target->id)
                ->orderByDesc('created_at')->orderByDesc('id')->get()->map($this->versionPayload(...)),
        ]]);
    }

    public function preview(FamilySpace $familySpace, string $photo, Request $request): JsonResponse
    {
        $target = $this->editablePhoto($photo, $request);
        $data = $request->validate(['edit_recipe' => ['required', 'array']]);
        $result = $this->editor->preview($target, $request->user(), $data['edit_recipe'], $request);

        return response()->json(['data' => $this->previewPayload($result['preview'])], 201);
    }

    public function restorePreview(FamilySpace $familySpace, string $photo, Request $request): JsonResponse
    {
        $target = $this->editablePhoto($photo, $request);
        $result = $this->editor->preview($target, $request->user(), null, $request);
        if ($result['outcome'] === 'no_improvement_found') {
            return response()->json(['data' => ['outcome' => 'no_improvement_found']]);
        }

        return response()->json(['data' => $this->previewPayload($result['preview'])], 201);
    }

    public function previewDelivery(FamilySpace $familySpace, string $photo, string $preview, Request $request): JsonResponse
    {
        $target = $this->visiblePhoto($photo, $request);
        $item = PhotoEditPreview::query()->where('photo_id', $target->id)->findOrFail($preview);
        abort_unless($item->requested_by === $request->user()->id && $item->expires_at->isFuture(), 404);

        return response()->json(['data' => $this->deliveryPayload(
            $this->delivery->photoEditPreview($target, $item), 'photo_edit_preview')]);
    }

    public function apply(FamilySpace $familySpace, string $photo, string $preview, Request $request): JsonResponse
    {
        $target = $this->editablePhoto($photo, $request);
        $version = $this->editor->apply($target, $preview, $request->user(), $request);

        return response()->json(['data' => $this->versionPayload($version)]);
    }

    public function discard(FamilySpace $familySpace, string $photo, string $preview, Request $request): JsonResponse
    {
        $this->editor->discard($this->editablePhoto($photo, $request), $preview, $request->user());

        return response()->json(null, 204);
    }

    public function activate(FamilySpace $familySpace, string $photo, Request $request): JsonResponse
    {
        $target = $this->editablePhoto($photo, $request);
        $data = $request->validate(['photo_version_id' => ['present', 'nullable', 'string', 'size:26']]);
        $version = $this->editor->activate($target, $data['photo_version_id'], $request->user(), $request);

        return response()->json(['data' => ['active_photo_version_id' => $version?->id]]);
    }

    public function versionDelivery(FamilySpace $familySpace, string $photo, string $version, Request $request): JsonResponse
    {
        $target = $this->visiblePhoto($photo, $request);
        $item = PhotoVersion::query()->where('photo_id', $target->id)->findOrFail($version);

        return response()->json(['data' => $this->deliveryPayload(
            $this->delivery->photoVersion($target, $item), 'photo_version')]);
    }

    private function visiblePhoto(string $photo, Request $request): Photo
    {
        $target = $this->photos->findVisibleTo($request->user(), $photo);
        Gate::authorize('view', $target);

        return $target;
    }

    private function editablePhoto(string $photo, Request $request): Photo
    {
        $target = $this->visiblePhoto($photo, $request);
        Gate::authorize('update', $target);

        return $target;
    }

    /** @return array<string, mixed> */
    private function previewPayload(PhotoEditPreview $preview): array
    {
        return ['outcome' => 'preview_ready', 'id' => $preview->id,
            'edit_recipe' => $preview->edit_recipe, 'restore' => $preview->restore,
            'expires_at' => $preview->expires_at->toAtomString()];
    }

    /** @return array<string, mixed> */
    private function versionPayload(PhotoVersion $version): array
    {
        return ['id' => $version->id, 'edit_recipe' => $version->edit_recipe,
            'restore' => $version->restore, 'created_at' => $version->created_at?->toAtomString()];
    }

    /** @return array<string, mixed> */
    private function deliveryPayload(MediaDeliveryAuthorization $authorization, string $asset): array
    {
        return ['asset' => $asset, 'url' => $authorization->url, 'method' => 'GET',
            'expires_at' => $authorization->expiresAt->toAtomString()];
    }
}
