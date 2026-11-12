<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('onboarding_journeys', 'duration_days')) {
            Schema::table('onboarding_journeys', fn (Blueprint $table) => $table->unsignedSmallInteger('duration_days')->nullable());
        }
    }

    public function down(): void
    {
        Schema::table('onboarding_journeys', fn (Blueprint $table) => $table->dropColumn('duration_days'));
    }
};
