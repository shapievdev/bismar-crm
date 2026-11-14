<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['onboarding_stage_tasks', 'onboarding_journey_tasks'] as $table) {
            Schema::table($table, fn (Blueprint $table) => $table->jsonb('responsible_ids')->default('[]'));
        }
    }

    public function down(): void
    {
        foreach (['onboarding_stage_tasks', 'onboarding_journey_tasks'] as $table) {
            Schema::table($table, fn (Blueprint $table) => $table->dropColumn('responsible_ids'));
        }
    }
};
