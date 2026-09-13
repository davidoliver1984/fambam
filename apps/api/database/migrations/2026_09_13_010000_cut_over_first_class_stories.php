<?php

use App\Stories\LegacyStoryBackfill;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        app(LegacyStoryBackfill::class)->run();

        if (DB::getDriverName() === 'sqlite') {
            $this->rebuildSqliteConsumers();

            return;
        }

        $this->repointStoryForeignKeys('stories');

        Schema::table('notifications', function (Blueprint $table): void {
            $table->char('story_comment_id', 26)->nullable();
            $table->foreign(['story_comment_id', 'family_space_id'], 'notifications_story_comment_family_foreign')
                ->references(['id', 'family_space_id'])->on('story_comments')->cascadeOnDelete();
        });
        Schema::table('notification_deliveries', function (Blueprint $table): void {
            $table->char('story_comment_id', 26)->nullable();
            $table->foreign(['story_comment_id', 'family_space_id'], 'notification_deliveries_story_comment_family_foreign')
                ->references(['id', 'family_space_id'])->on('story_comments')->cascadeOnDelete();
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
ALTER TABLE notifications DROP CONSTRAINT notifications_subject_check;
ALTER TABLE notifications ADD CONSTRAINT notifications_subject_check CHECK (
 (category = 'comment' AND family_export_id IS NULL AND ((photo_id IS NOT NULL AND album_id IS NOT NULL AND comment_id IS NOT NULL AND story_id IS NULL AND story_comment_id IS NULL AND person_id IS NULL) OR (story_id IS NOT NULL AND story_comment_id IS NOT NULL AND photo_id IS NULL AND album_id IS NULL AND comment_id IS NULL AND person_id IS NULL)))
 OR (category = 'contribution' AND album_id IS NOT NULL AND photo_id IS NULL AND story_id IS NULL AND story_comment_id IS NULL AND person_id IS NULL AND comment_id IS NULL AND family_export_id IS NULL)
 OR (category = 'story' AND story_id IS NOT NULL AND photo_id IS NULL AND album_id IS NULL AND story_comment_id IS NULL AND person_id IS NULL AND comment_id IS NULL AND family_export_id IS NULL)
 OR (category = 'identity' AND person_id IS NOT NULL AND photo_id IS NOT NULL AND album_id IS NULL AND story_id IS NULL AND story_comment_id IS NULL AND comment_id IS NULL AND family_export_id IS NULL)
 OR (category = 'export' AND family_export_id IS NOT NULL AND photo_id IS NULL AND album_id IS NULL AND story_id IS NULL AND story_comment_id IS NULL AND person_id IS NULL AND comment_id IS NULL)
);
ALTER TABLE notification_deliveries DROP CONSTRAINT notification_deliveries_subject_check;
ALTER TABLE notification_deliveries ADD CONSTRAINT notification_deliveries_subject_check CHECK (
 (category = 'comment' AND family_export_id IS NULL AND ((photo_id IS NOT NULL AND album_id IS NOT NULL AND comment_id IS NOT NULL AND story_id IS NULL AND story_comment_id IS NULL AND person_id IS NULL) OR (story_id IS NOT NULL AND story_comment_id IS NOT NULL AND photo_id IS NULL AND album_id IS NULL AND comment_id IS NULL AND person_id IS NULL)))
 OR (category = 'contribution' AND album_id IS NOT NULL AND photo_id IS NULL AND story_id IS NULL AND story_comment_id IS NULL AND person_id IS NULL AND comment_id IS NULL AND family_export_id IS NULL)
 OR (category = 'story' AND story_id IS NOT NULL AND photo_id IS NULL AND album_id IS NULL AND story_comment_id IS NULL AND person_id IS NULL AND comment_id IS NULL AND family_export_id IS NULL)
 OR (category = 'identity' AND person_id IS NOT NULL AND photo_id IS NOT NULL AND album_id IS NULL AND story_id IS NULL AND story_comment_id IS NULL AND comment_id IS NULL AND family_export_id IS NULL)
 OR (category = 'export' AND family_export_id IS NOT NULL AND photo_id IS NULL AND album_id IS NULL AND story_id IS NULL AND story_comment_id IS NULL AND person_id IS NULL AND comment_id IS NULL)
);
SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            throw new RuntimeException('The first-class Story cutover is not reversible on the SQLite development driver.');
        }

        DB::table('notification_deliveries')->whereNotNull('story_comment_id')->delete();
        DB::table('notifications')->whereNotNull('story_comment_id')->delete();
        foreach ([['family_activities', 'subject_story_id'], ['notification_deliveries', 'story_id'], ['notifications', 'story_id']] as [$table, $column]) {
            DB::table($table)->whereNotNull($column)
                ->whereNotIn($column, DB::table('photo_stories')->select('id'))
                ->delete();
        }

        $this->repointStoryForeignKeys('photo_stories');

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE notifications DROP CONSTRAINT notifications_subject_check');
            DB::statement('ALTER TABLE notification_deliveries DROP CONSTRAINT notification_deliveries_subject_check');
        }

        Schema::table('notification_deliveries', function (Blueprint $table): void {
            $table->dropForeign('notification_deliveries_story_comment_family_foreign');
            $table->dropColumn('story_comment_id');
        });
        Schema::table('notifications', function (Blueprint $table): void {
            $table->dropForeign('notifications_story_comment_family_foreign');
            $table->dropColumn('story_comment_id');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
ALTER TABLE notifications ADD CONSTRAINT notifications_subject_check CHECK (
 (category = 'comment' AND photo_id IS NOT NULL AND album_id IS NOT NULL AND comment_id IS NOT NULL AND story_id IS NULL AND person_id IS NULL AND family_export_id IS NULL)
 OR (category = 'contribution' AND album_id IS NOT NULL AND photo_id IS NULL AND story_id IS NULL AND person_id IS NULL AND comment_id IS NULL AND family_export_id IS NULL)
 OR (category = 'story' AND story_id IS NOT NULL AND photo_id IS NULL AND album_id IS NULL AND person_id IS NULL AND comment_id IS NULL AND family_export_id IS NULL)
 OR (category = 'identity' AND person_id IS NOT NULL AND photo_id IS NOT NULL AND album_id IS NULL AND story_id IS NULL AND comment_id IS NULL AND family_export_id IS NULL)
 OR (category = 'export' AND family_export_id IS NOT NULL AND photo_id IS NULL AND album_id IS NULL AND story_id IS NULL AND person_id IS NULL AND comment_id IS NULL)
);
ALTER TABLE notification_deliveries ADD CONSTRAINT notification_deliveries_subject_check CHECK (
 (category = 'comment' AND photo_id IS NOT NULL AND album_id IS NOT NULL AND comment_id IS NOT NULL AND story_id IS NULL AND person_id IS NULL AND family_export_id IS NULL)
 OR (category = 'contribution' AND album_id IS NOT NULL AND photo_id IS NULL AND story_id IS NULL AND person_id IS NULL AND comment_id IS NULL AND family_export_id IS NULL)
 OR (category = 'story' AND story_id IS NOT NULL AND photo_id IS NULL AND album_id IS NULL AND person_id IS NULL AND comment_id IS NULL AND family_export_id IS NULL)
 OR (category = 'identity' AND person_id IS NOT NULL AND photo_id IS NOT NULL AND album_id IS NULL AND story_id IS NULL AND comment_id IS NULL AND family_export_id IS NULL)
 OR (category = 'export' AND family_export_id IS NOT NULL AND photo_id IS NULL AND album_id IS NULL AND story_id IS NULL AND person_id IS NULL AND comment_id IS NULL)
);
SQL);
        }
    }

    private function repointStoryForeignKeys(string $table): void
    {
        foreach ([
            ['family_activities', 'subject_story_id', 'family_activities_story_family_foreign'],
            ['notifications', 'story_id', 'notifications_story_family_foreign'],
            ['notification_deliveries', 'story_id', 'notification_deliveries_story_family_foreign'],
        ] as [$child, $column, $constraint]) {
            Schema::table($child, fn (Blueprint $blueprint) => $blueprint->dropForeign($constraint));
            Schema::table($child, fn (Blueprint $blueprint) => $blueprint
                ->foreign([$column, 'family_space_id'], $constraint)
                ->references(['id', 'family_space_id'])->on($table)->cascadeOnDelete());
        }
    }

    private function rebuildSqliteConsumers(): void
    {
        $activities = DB::table('family_activities')->get()->map(fn (object $row): array => (array) $row)->all();
        $notifications = DB::table('notifications')->get()->map(fn (object $row): array => (array) $row)->all();
        $deliveries = DB::table('notification_deliveries')->get()->map(fn (object $row): array => (array) $row)->all();

        Schema::disableForeignKeyConstraints();

        try {
            Schema::drop('notification_deliveries');
            Schema::drop('notifications');
            Schema::drop('family_activities');

            Schema::create('family_activities', function (Blueprint $table): void {
                $table->char('id', 26)->primary();
                $table->char('family_space_id', 26);
                $table->foreignId('actor_user_id');
                $table->char('actor_person_id', 26)->nullable();
                $table->string('action_type', 40);
                foreach (['subject_album_id', 'subject_event_id', 'subject_story_id', 'subject_person_id', 'contribution_batch_id'] as $column) {
                    $table->char($column, 26)->nullable();
                }
                $table->json('photo_ids')->nullable();
                $table->timestamp('created_at');
                $table->foreign('family_space_id')->references('id')->on('family_spaces')->cascadeOnDelete();
                $table->foreign('actor_user_id')->references('id')->on('users')->restrictOnDelete();
                $table->foreign(['actor_person_id', 'family_space_id'])->references(['id', 'family_space_id'])->on('people')->restrictOnDelete();
                $table->foreign(['subject_album_id', 'family_space_id'])->references(['id', 'family_space_id'])->on('albums')->cascadeOnDelete();
                $table->foreign(['subject_event_id', 'family_space_id'])->references(['id', 'family_space_id'])->on('events')->cascadeOnDelete();
                $table->foreign(['subject_story_id', 'family_space_id'])->references(['id', 'family_space_id'])->on('stories')->cascadeOnDelete();
                $table->foreign(['subject_person_id', 'family_space_id'])->references(['id', 'family_space_id'])->on('people')->cascadeOnDelete();
                $table->unique(['id', 'family_space_id']);
                $table->index(['family_space_id', 'created_at', 'id'], 'family_activities_recent_index');
                $table->index(
                    ['family_space_id', 'actor_user_id', 'action_type', 'subject_album_id', 'contribution_batch_id'],
                    'family_activities_grouping_index',
                );
            });

            $this->createSqliteNotificationTable('notifications', false);
            $this->createSqliteNotificationTable('notification_deliveries', true);
            if ($activities !== []) {
                DB::table('family_activities')->insert($activities);
            }
            if ($notifications !== []) {
                DB::table('notifications')->insert(array_map(fn (array $row): array => [...$row, 'story_comment_id' => null], $notifications));
            }
            if ($deliveries !== []) {
                DB::table('notification_deliveries')->insert(array_map(fn (array $row): array => [...$row, 'story_comment_id' => null], $deliveries));
            }
        } finally {
            Schema::enableForeignKeyConstraints();
        }
    }

    private function createSqliteNotificationTable(string $name, bool $delivery): void
    {
        Schema::create($name, function (Blueprint $table) use ($delivery): void {
            $table->char('id', 26)->primary();
            $table->char('family_space_id', 26);
            $table->foreignId('recipient_user_id');
            $table->string('category', 24);
            $table->char('source_action_id', 26);
            if ($delivery) {
                $table->char('notification_id', 26)->nullable();
            }
            foreach (['photo_id', 'album_id', 'story_id', 'person_id', 'comment_id', 'story_comment_id', 'family_export_id'] as $column) {
                $table->char($column, 26)->nullable();
            }
            if ($delivery) {
                $table->string('channel', 16);
                $table->string('status', 16)->default('pending');
                $table->timestamp('attempted_at')->nullable();
                $table->timestamp('sent_at')->nullable();
                $table->string('failure_reason')->nullable();
            } else {
                $table->timestamp('read_at')->nullable();
            }
            $table->timestamps();
            $table->foreign('family_space_id')->references('id')->on('family_spaces')->cascadeOnDelete();
            $table->foreign('recipient_user_id')->references('id')->on('users')->restrictOnDelete();
            if ($delivery) {
                $table->foreign('notification_id')->references('id')->on('notifications')->nullOnDelete();
            }
            foreach (['photo' => 'photos', 'album' => 'albums', 'story' => 'stories', 'person' => 'people', 'comment' => 'photo_comments', 'story_comment' => 'story_comments', 'family_export' => 'family_exports'] as $column => $parent) {
                $table->foreign(["{$column}_id", 'family_space_id'])->references(['id', 'family_space_id'])->on($parent)->cascadeOnDelete();
            }
            $natural = ['family_space_id', 'recipient_user_id', 'category', 'source_action_id'];
            if ($delivery) {
                $natural[] = 'channel';
            }
            $table->unique($natural);
        });
    }
};
