<?php

namespace App\Http\Controllers;

use App\Models\EventExport;
use App\Models\FamilySpace;
use App\Models\User;
use App\Queries\FamilyEventQuery;
use App\Services\EventExportManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class EventExportController extends Controller
{
    public function __construct(
        private readonly FamilyEventQuery $events,
        private readonly EventExportManager $exports,
    ) {}

    public function index(FamilySpace $familySpace, string $event): JsonResponse
    {
        $target = $this->events->find($event);
        Gate::authorize('manageExports', $target);

        return response()->json(['data' => $this->exports->all($target)->map(
            fn (EventExport $export): array => $this->payload($export),
        )]);
    }

    public function store(FamilySpace $familySpace, string $event, Request $request): JsonResponse
    {
        $target = $this->events->find($event);
        Gate::authorize('manageExports', $target);
        /** @var User $actor */
        $actor = $request->user();

        return response()->json(['data' => $this->payload(
            $this->exports->request($target, $actor, $request),
        )], 202);
    }

    public function download(
        FamilySpace $familySpace,
        string $event,
        string $eventExport,
        Request $request,
    ): JsonResponse {
        $target = $this->events->find($event);
        Gate::authorize('manageExports', $target);
        $export = $this->exports->find($target, $eventExport);
        /** @var User $actor */
        $actor = $request->user();
        $authorization = $this->exports->authorizeDownload($export, $actor, $request);

        return response()->json(['data' => [
            'url' => $authorization->url,
            'expires_at' => $authorization->expiresAt->toAtomString(),
        ]]);
    }

    /** @return array<string, mixed> */
    private function payload(EventExport $export): array
    {
        $export->loadMissing('requester:id,name');

        return [
            'id' => $export->id,
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
