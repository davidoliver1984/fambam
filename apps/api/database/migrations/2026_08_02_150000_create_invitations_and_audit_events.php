<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('can_invite')->default(false)->after('timezone');
        });

        Schema::create('invitations', function (Blueprint $table): void {
            $table->id();
            $table->string('email');
            $table->string('token_hash', 64)->nullable()->unique();
            $table->foreignId('invited_by')->constrained('users')->restrictOnDelete();
            $table->string('status', 20)->index();
            $table->timestamp('expires_at')->index();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['email', 'status']);
        });

        Schema::create('invitation_claims', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('invitation_id')->constrained()->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at')->index();
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
        });

        Schema::create('audit_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 100)->index();
            $table->string('subject_type', 100);
            $table->string('subject_id', 100);
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_events');
        Schema::dropIfExists('invitation_claims');
        Schema::dropIfExists('invitations');

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('can_invite');
        });
    }
};
