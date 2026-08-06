<?php

use App\Http\Controllers\AccountSecurityController;
use App\Http\Controllers\CurrentUserController;
use App\Http\Controllers\FamilyCircleController;
use App\Http\Controllers\FamilySpaceController;
use App\Http\Controllers\FamilySpaceMembershipController;
use App\Http\Controllers\InvitationAcceptanceController;
use App\Http\Controllers\InvitationController;
use App\Http\Controllers\PersonAccountLinkController;
use App\Http\Controllers\PersonController;
use App\Http\Controllers\RelationshipController;
use Aws\Sqs\SqsClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\SpanKind;

Route::get('/health', static fn (): array => [
    'service' => 'api',
    'status' => 'ok',
]);

Route::middleware(['throttle:invitation-acceptance', 'database-context'])->group(function (): void {
    Route::post('/invitations/exchange', [InvitationAcceptanceController::class, 'exchange']);
    Route::post('/invitations/accept', [InvitationAcceptanceController::class, 'accept']);
});

Route::middleware(['auth:sanctum', 'database-context'])->group(function (): void {
    Route::get('/user', [CurrentUserController::class, 'show']);
    Route::patch('/user/profile', [CurrentUserController::class, 'update']);
    Route::put('/user/password', [AccountSecurityController::class, 'updatePassword'])
        ->middleware('throttle:account-security');
    Route::post('/user/revoke-sessions', [AccountSecurityController::class, 'revokeSessions'])
        ->middleware('throttle:account-security');
    Route::get('/family-spaces', [FamilySpaceController::class, 'index']);
    Route::post('/family-spaces', [FamilySpaceController::class, 'store']);

    Route::prefix('/families/{familySpace}')->middleware('family-space')->group(function (): void {
        Route::get('/', [FamilySpaceController::class, 'show']);
        Route::post('/deletion', [FamilySpaceController::class, 'requestDeletion']);
        Route::delete('/deletion', [FamilySpaceController::class, 'cancelDeletion']);
        Route::get('/memberships', [FamilySpaceMembershipController::class, 'index']);
        Route::patch('/memberships/{membership}', [FamilySpaceMembershipController::class, 'update']);
        Route::delete('/memberships/{membership}', [FamilySpaceMembershipController::class, 'destroy']);
        Route::get('/invitations', [InvitationController::class, 'index']);
        Route::post('/invitations', [InvitationController::class, 'store'])
            ->middleware('throttle:invitation-issuance');
        Route::post('/invitations/{invitation}/resend', [InvitationController::class, 'resend'])
            ->middleware('throttle:invitation-issuance');
        Route::post('/invitations/{invitation}/revoke', [InvitationController::class, 'revoke']);
        Route::get('/people', [PersonController::class, 'index']);
        Route::post('/people', [PersonController::class, 'store']);
        Route::get('/people/{person}', [PersonController::class, 'show']);
        Route::patch('/people/{person}', [PersonController::class, 'update']);
        Route::get('/people/{person}/proposals', [PersonController::class, 'proposals']);
        Route::post('/people/{person}/proposals', [PersonController::class, 'propose']);
        Route::post('/people/{person}/proposals/{proposal}/approve', [PersonController::class, 'approveProposal']);
        Route::post('/people/{person}/proposals/{proposal}/reject', [PersonController::class, 'rejectProposal']);
        Route::get('/people/{person}/account-link-claims', [PersonAccountLinkController::class, 'claims']);
        Route::post('/people/{person}/account-link-claims', [PersonAccountLinkController::class, 'proposeClaim']);
        Route::post('/people/{person}/account-link-claims/{claim}/approve', [PersonAccountLinkController::class, 'approveClaim']);
        Route::post('/people/{person}/account-link-claims/{claim}/reject', [PersonAccountLinkController::class, 'rejectClaim']);
        Route::put('/people/{person}/account-link', [PersonAccountLinkController::class, 'assign']);
        Route::delete('/people/{person}/account-link', [PersonAccountLinkController::class, 'unlink']);
        Route::get('/people/{person}/relationships', [RelationshipController::class, 'index']);
        Route::post('/people/{person}/relationships', [RelationshipController::class, 'store']);
        Route::get('/people/{person}/relationship-proposals', [RelationshipController::class, 'proposals']);
        Route::post('/people/{person}/relationship-proposals', [RelationshipController::class, 'propose']);
        Route::post('/people/{person}/relationship-proposals/{proposal}/approve', [RelationshipController::class, 'approve']);
        Route::post('/people/{person}/relationship-proposals/{proposal}/reject', [RelationshipController::class, 'reject']);
        Route::patch('/relationships/{relationship}', [RelationshipController::class, 'update']);
        Route::delete('/relationships/{relationship}', [RelationshipController::class, 'destroy']);
        Route::post('/relationships/{relationship}/dispute', [RelationshipController::class, 'dispute']);
        Route::get('/circles', [FamilyCircleController::class, 'index']);
        Route::post('/circles', [FamilyCircleController::class, 'store']);
        Route::patch('/circles/{circle}', [FamilyCircleController::class, 'update']);
        Route::delete('/circles/{circle}', [FamilyCircleController::class, 'destroy']);
        Route::post('/circles/{circle}/people', [FamilyCircleController::class, 'addPerson']);
        Route::delete('/circles/{circle}/people/{person}', [FamilyCircleController::class, 'removePerson']);
    });
});

if (app()->environment(['local', 'testing'])) {
    Route::post('/observability/synthetic-upload', static function (Request $request): JsonResponse {
        abort_unless(app()->environment(['local', 'testing']), 404);

        $tracer = Globals::tracerProvider()->getTracer('fambam-api');
        $span = $tracer->spanBuilder('synthetic-upload.request')
            ->setSpanKind(SpanKind::KIND_PRODUCER)
            ->startSpan();
        $scope = $span->activate();

        try {
            $spanContext = $span->getContext();
            $carrier = [
                'traceparent' => sprintf(
                    '00-%s-%s-%02x',
                    $spanContext->getTraceId(),
                    $spanContext->getSpanId(),
                    $spanContext->getTraceFlags(),
                ),
            ];

            $client = new SqsClient([
                'version' => 'latest',
                'region' => config('queue.connections.sqs.region'),
                'endpoint' => config('queue.connections.sqs.endpoint'),
                'credentials' => [
                    'key' => config('queue.connections.sqs.key'),
                    'secret' => config('queue.connections.sqs.secret'),
                ],
            ]);
            $queueUrl = config('queue.connections.sqs.prefix').'/'.config('image-analysis.queue');
            $messageAttributes = [];

            foreach ($carrier as $name => $value) {
                $messageAttributes[$name] = ['DataType' => 'String', 'StringValue' => $value];
            }

            $client->sendMessage([
                'QueueUrl' => $queueUrl,
                'MessageBody' => json_encode([
                    'type' => 'SyntheticUploadRequested',
                    'family_space_id' => null,
                    'actor_user_id' => null,
                    'correlation_id' => $request->header('X-Correlation-ID'),
                    'traceparent' => $carrier['traceparent'],
                ], JSON_THROW_ON_ERROR),
                'MessageAttributes' => $messageAttributes,
            ]);

            return response()->json([
                'status' => 'queued',
                'trace_id' => $span->getContext()->getTraceId(),
            ]);
        } finally {
            $scope->detach();
            $span->end();
        }
    });
}
