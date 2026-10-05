<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media_uploads', function (Blueprint $table): void {
            $table->string('purpose', 32)->default('archive')->after('user_id');
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->text('about')->nullable()->after('name');
            $table->string('pending_email')->nullable()->unique()->after('email');
            $table->timestamp('pending_email_requested_at')->nullable()->after('pending_email');
            $table->char('avatar_media_upload_id', 26)->nullable()->unique()->after('pending_email_requested_at');
            $table->timestamp('last_login_at')->nullable();
            $table->string('last_login_ip', 45)->nullable();
            $table->text('last_login_user_agent')->nullable();
            $table->foreign('avatar_media_upload_id')->references('id')->on('media_uploads')->nullOnDelete();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
CREATE POLICY media_uploads_account_avatar_self_select ON media_uploads
FOR SELECT
USING (purpose = 'account_avatar' AND user_id = app_current_user_id());
SQL);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP POLICY media_uploads_account_avatar_self_select ON media_uploads');
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->dropForeign(['avatar_media_upload_id']);
            $table->dropColumn([
                'about',
                'pending_email',
                'pending_email_requested_at',
                'avatar_media_upload_id',
                'last_login_at',
                'last_login_ip',
                'last_login_user_agent',
            ]);
        });
        Schema::table('media_uploads', fn (Blueprint $table) => $table->dropColumn('purpose'));
    }
};
