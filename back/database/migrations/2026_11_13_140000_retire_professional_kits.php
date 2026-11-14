<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Keep historical definitions and receipts for the activity log.
        DB::table('onboarding_templates')->update(['requires_graduation' => false]);
        DB::table('onboarding_journeys')->update(['requires_graduation' => false]);

        // Finish routes previously waiting only for equipment, without touching
        // paused routes, empty routes or routes with unfinished learning stages.
        DB::table('onboarding_journeys')
            ->where('status', 'active')->whereNull('completed_at')
            ->whereExists(fn ($query) => $query->selectRaw('1')->from('onboarding_journey_stages')
                ->whereColumn('journey_id', 'onboarding_journeys.id'))
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('onboarding_journey_stages')
                ->whereColumn('journey_id', 'onboarding_journeys.id')->whereNull('completed_at'))
            ->update(['status' => 'completed', 'completed_at' => now(), 'updated_at' => now()]);
    }

    public function down(): void
    {
        // Previous per-route requirements cannot be inferred after retirement.
        // Historical kit data is retained; do not reopen completed routes.
    }
};
