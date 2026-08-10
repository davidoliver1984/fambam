<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_uploads', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('family_space_id', 26);
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('state', 20)->default('initiated');
            $table->string('staging_object_key', 512)->unique();
            $table->string('original_object_key', 512)->nullable()->unique();
            $table->char('original_sha256', 64)->nullable();
            $table->unsignedBigInteger('byte_size')->nullable();
            $table->string('client_filename', 255);
            $table->string('client_mime_type', 255)->nullable();
            $table->string('detected_mime_type', 255)->nullable();
            $table->string('canonical_object_key', 512)->nullable()->unique();
            $table->char('upload_batch_id', 26)->nullable();
            $table->string('upload_method', 20)->default('single');
            $table->string('rejection_reason', 255)->nullable();
            $table->string('idempotency_key', 100);
            $table->char('request_fingerprint', 64);
            $table->uuid('correlation_id');
            $table->string('traceparent', 55);
            $table->timestamp('uploaded_at')->nullable();
            $table->timestamps();

            $table->foreign('family_space_id')->references('id')->on('family_spaces')->cascadeOnDelete();
            $table->unique(['family_space_id', 'user_id', 'idempotency_key']);
            $table->index(['family_space_id', 'state']);
            $table->index(['family_space_id', 'upload_batch_id']);
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
ALTER TABLE media_uploads ENABLE ROW LEVEL SECURITY;
ALTER TABLE media_uploads FORCE ROW LEVEL SECURITY;
CREATE POLICY media_uploads_tenant_isolation ON media_uploads
USING (family_space_id = app_current_family_space_id())
WITH CHECK (family_space_id = app_current_family_space_id());
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('media_uploads');
    }
};
