<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('onboarding_templates', function (Blueprint $table): void {
            $table->foreignId('parent_id')->nullable()->constrained('onboarding_templates')->nullOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->string('job_title')->nullable();
            $table->string('store')->nullable();
            $table->boolean('requires_graduation')->default(false);
            $table->json('settings')->nullable();
            $table->foreignId('graduation_achievement_id')->nullable()->constrained('achievements')->nullOnDelete();
        });
        Schema::table('onboarding_journeys', function (Blueprint $table): void {
            $table->boolean('requires_graduation')->default(false);
            $table->json('settings')->nullable();
            $table->string('store')->nullable();
            $table->foreignId('graduation_achievement_id')->nullable()->constrained('achievements')->nullOnDelete();
        });
        foreach (['onboarding_stages', 'onboarding_journey_stages'] as $name) {
            Schema::table($name, function (Blueprint $table) use ($name): void {
                $table->boolean('is_final_exam')->default(false);
                $table->json('responsible_ids')->nullable();
                if ($name === 'onboarding_journey_stages') {
                    $table->timestamp('due_at')->nullable();
                    $table->timestamp('opening_override_at')->nullable();
                    $table->timestamp('notified_at')->nullable();
                    // Снимок происхождения не теряется при удалении трафарета.
                    $table->unsignedBigInteger('template_id')->nullable()->index();
                    $table->unsignedInteger('template_version')->nullable();
                    $table->string('template_title')->nullable();
                }
            });
        }
        DB::statement('UPDATE onboarding_journey_stages AS js SET template_id = s.template_id, template_version = t.version, template_title = t.title FROM onboarding_stages AS s JOIN onboarding_templates AS t ON t.id = s.template_id WHERE js.source_stage_id = s.id');
        Schema::create('onboarding_activities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('journey_id')->nullable()->constrained('onboarding_journeys')->cascadeOnDelete();
            $table->foreignId('template_id')->nullable()->constrained('onboarding_templates')->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event');
            $table->string('subject');
            $table->json('changes')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('onboarding_activities');
        Schema::table('onboarding_templates', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('parent_id');
            $table->dropConstrainedForeignId('graduation_achievement_id');
            $table->dropColumn(['version', 'job_title', 'store', 'requires_graduation', 'settings']);
        });
        Schema::table('onboarding_journeys', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('graduation_achievement_id');
            $table->dropColumn(['store', 'requires_graduation', 'settings']);
        });
        Schema::table('onboarding_stages', fn (Blueprint $table) => $table->dropColumn(['is_final_exam', 'responsible_ids']));
        Schema::table('onboarding_journey_stages', fn (Blueprint $table) => $table->dropColumn([
            'is_final_exam', 'responsible_ids', 'due_at', 'opening_override_at', 'notified_at',
            'template_id', 'template_version', 'template_title',
        ]));
    }
};
