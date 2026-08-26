<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('photo_comments', function (Blueprint $table): void {
            $table->char('album_id', 26)->nullable()->after('photo_id');
            $table->foreign('album_id')->references('id')->on('albums')->cascadeOnDelete();
            $table->index(['family_space_id', 'photo_id', 'album_id', 'created_at']);
        });

        Schema::table('photo_reactions', function (Blueprint $table): void {
            $table->dropUnique(['photo_id', 'user_id']);
            $table->char('album_id', 26)->nullable()->after('photo_id');
            $table->foreign('album_id')->references('id')->on('albums')->cascadeOnDelete();
            $table->unique(['photo_id', 'album_id', 'user_id']);
            $table->index(['family_space_id', 'photo_id', 'album_id']);
        });

        Schema::create('duplicate_candidates', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('family_space_id', 26);
            $table->char('photo_id', 26);
            $table->char('candidate_photo_id', 26);
            $table->string('source', 30);
            $table->string('status', 20)->default('pending');
            $table->char('matched_sha256', 64)->nullable();
            $table->string('algorithm', 80)->nullable();
            $table->unsignedInteger('processing_version')->nullable();
            $table->decimal('score', 12, 8)->nullable();
            $table->timestamps();
            $table->foreign('family_space_id')->references('id')->on('family_spaces')->cascadeOnDelete();
            $table->foreign('photo_id')->references('id')->on('photos')->cascadeOnDelete();
            $table->foreign('candidate_photo_id')->references('id')->on('photos')->cascadeOnDelete();
            $table->unique(['family_space_id', 'photo_id', 'candidate_photo_id', 'source'], 'duplicate_candidates_pair_source_unique');
            $table->index(['family_space_id', 'status', 'source']);
        });

        Schema::create('duplicate_decisions', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('family_space_id', 26);
            $table->char('photo_low_id', 26);
            $table->char('photo_high_id', 26);
            $table->string('source', 40);
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at');
            $table->foreignId('reopened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reopened_at')->nullable();
            $table->timestamps();
            $table->foreign('family_space_id')->references('id')->on('family_spaces')->cascadeOnDelete();
            $table->foreign('photo_low_id')->references('id')->on('photos')->cascadeOnDelete();
            $table->foreign('photo_high_id')->references('id')->on('photos')->cascadeOnDelete();
            $table->unique(['family_space_id', 'photo_low_id', 'photo_high_id'], 'duplicate_decisions_pair_unique');
            $table->index(['family_space_id', 'reopened_at']);
        });

        Schema::create('media_upload_duplicate_holds', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('family_space_id', 26);
            $table->char('media_upload_id', 26)->unique();
            $table->char('target_album_id', 26);
            $table->timestamp('detected_at');
            $table->string('resolution', 30)->nullable();
            $table->char('chosen_photo_id', 26)->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->foreign('family_space_id')->references('id')->on('family_spaces')->cascadeOnDelete();
            $table->foreign('media_upload_id')->references('id')->on('media_uploads')->cascadeOnDelete();
            $table->foreign('target_album_id')->references('id')->on('albums')->cascadeOnDelete();
            $table->foreign('chosen_photo_id')->references('id')->on('photos')->nullOnDelete();
            $table->index(['family_space_id', 'resolved_at']);
        });

        $this->backfillExactCandidates();

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE duplicate_candidates ADD CONSTRAINT duplicate_candidates_order_check CHECK (photo_id < candidate_photo_id)');
            DB::statement('ALTER TABLE duplicate_decisions ADD CONSTRAINT duplicate_decisions_order_check CHECK (photo_low_id < photo_high_id)');
            DB::statement("ALTER TABLE media_upload_duplicate_holds ADD CONSTRAINT media_upload_duplicate_holds_resolution_check CHECK (resolution IS NULL OR resolution IN ('use_existing', 'create_new', 'cancel'))");
            foreach (['duplicate_candidates', 'duplicate_decisions', 'media_upload_duplicate_holds'] as $table) {
                DB::unprepared("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY; ALTER TABLE {$table} FORCE ROW LEVEL SECURITY; CREATE POLICY {$table}_tenant_isolation ON {$table} USING (family_space_id = app_current_family_space_id()) WITH CHECK (family_space_id = app_current_family_space_id());");
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('media_upload_duplicate_holds');
        Schema::dropIfExists('duplicate_decisions');
        Schema::dropIfExists('duplicate_candidates');

        Schema::table('photo_reactions', function (Blueprint $table): void {
            $table->dropUnique('photo_reactions_photo_id_album_id_user_id_unique');
            $table->dropForeign(['album_id']);
            $table->dropIndex(['family_space_id', 'photo_id', 'album_id']);
            $table->dropColumn('album_id');
            $table->unique(['photo_id', 'user_id']);
        });
        Schema::table('photo_comments', function (Blueprint $table): void {
            $table->dropForeign(['album_id']);
            $table->dropIndex(['family_space_id', 'photo_id', 'album_id', 'created_at']);
            $table->dropColumn('album_id');
        });
    }

    private function backfillExactCandidates(): void
    {
        $pairs = DB::table('photos as first_photo')
            ->join('media_uploads as first_upload', 'first_upload.id', '=', 'first_photo.media_upload_id')
            ->join('media_uploads as second_upload', function ($join): void {
                $join->on('second_upload.family_space_id', '=', 'first_upload.family_space_id')
                    ->on('second_upload.original_sha256', '=', 'first_upload.original_sha256');
            })
            ->join('photos as second_photo', 'second_photo.media_upload_id', '=', 'second_upload.id')
            ->whereColumn('first_photo.id', '<', 'second_photo.id')
            ->whereNull('first_photo.deleted_at')
            ->whereNull('second_photo.deleted_at')
            ->whereNotNull('first_upload.original_sha256')
            ->select([
                'first_photo.family_space_id',
                'first_photo.id as photo_id',
                'second_photo.id as candidate_photo_id',
                'first_upload.original_sha256',
            ])->get();

        foreach ($pairs as $pair) {
            DB::table('duplicate_candidates')->insertOrIgnore([
                'id' => (string) Str::ulid(),
                'family_space_id' => $pair->family_space_id,
                'photo_id' => $pair->photo_id,
                'candidate_photo_id' => $pair->candidate_photo_id,
                'source' => 'exact',
                'status' => 'pending',
                'matched_sha256' => $pair->original_sha256,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
};
