<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('onboarding_stage_meetings', function (Blueprint $table): void {
            $table->json('participant_ids')->nullable();
            $table->string('scheduled_time', 5)->nullable();
        });
        Schema::table('onboarding_journey_meetings', function (Blueprint $table): void {
            $table->json('participant_ids')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('onboarding_stage_meetings', fn (Blueprint $table) => $table->dropColumn(['participant_ids', 'scheduled_time']));
        Schema::table('onboarding_journey_meetings', fn (Blueprint $table) => $table->dropColumn('participant_ids'));
    }
};
