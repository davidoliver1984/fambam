<?php

namespace App\Http\Controllers;

use App\Enums\FamilyExportScope;
use App\Models\FamilyExport;
use App\Models\FamilySpace;
use App\Models\User;
use App\Services\FamilyExportManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class FamilyExportController extends Controller
{
    public function __construct(private readonly FamilyExportManager $exports) {}

    public function index(FamilySpace $familySpace, Request $request): JsonResponse
    {
        Gate::authorize('requestPersonalExport', $familySpace);
        /** @var User $actor */
        $actor = $request->user();

        return response()->json(['data' => $this->exports->allFor($actor)->map(
            fn (FamilyExport $export): array => $this->payload($export),
        )]);
    }

    public function show(FamilySpace $familySpace, string $familyExport, Request $request): JsonResponse
    {
        Gate::authorize('requestPersonalExport', $familySpace);
        /** @var User $actor */
        $actor = $request->user();

        return response()->json(['data' => $this->payload($this->exports->findFor($actor, $familyExport))]);
    }

    public function storeFull(FamilySpace $familySpace, Request $request): JsonResponse
    {
        Gate::authorize('requestFullExport', $familySpace);
        /** @var User $actor */
        $actor = $request->user();

        return response()->json(['data' => $this->payload(
            $this->exports->request($familySpace, $actor, FamilyExportScope::FamilySpaceFull, $request),
        )], 202);
    }

    public function storePersonal(FamilySpace $familySpace, Request $request): JsonResponse
    {
        Gate::authorize('requestPersonalExport', $familySpace);
        /** @var User $actor */
        $actor = $request->user();

        return response()->json(['data' => $this->payload(
            $this->exports->request($familySpace, $actor, FamilyExportScope::Personal, $request),
        )], 202);
    }

    public function download(FamilySpace $familySpace, string $familyExport, Request $request): JsonResponse
    {
        Gate::authorize('requestPersonalExport', $familySpace);
        /** @var User $actor */
        $actor = $request->user();
        $export = $this->exports->findFor($actor, $familyExport);
        $authorization = $this->exports->authorizeDownload($export, $actor, $request);

        return response()->json(['data' => [
            'url' => $authorization->url,
            'expires_at' => $authorization->expiresAt->toAtomString(),
        ]]);
    }

    /** @return array<string, mixed> */
    private function payload(FamilyExport $export): array
    {
        $export->loadMissing('requester:id,name');

        return [
            'id' => $export->id,
            'scope' => $export->scope->value,
            'state' => $export->state->value,
            'requested_by' => $export->requested_by,
            'requester' => ['id' => $export->requester->id, 'name' => $export->requester->name],
            'photo_count' => $export->photo_count,
            'byte_size' => $export->byte_size,
            'archive_sha256' => $export->archive_sha256,
            'failure_reason' => $export->failure_reason,
            'expires_at' => $export->expires_at?->toAtomString(),
            'created_at' => $export->created_at?->toAtomString(),
        ];
    }
}
