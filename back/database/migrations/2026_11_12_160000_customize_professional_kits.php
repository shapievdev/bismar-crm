<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('onboarding_templates', fn (Blueprint $table) => $table->json('kit_items')->nullable());
        Schema::table('onboarding_journeys', function (Blueprint $table): void {
            $table->json('kit_items')->nullable();
            $table->json('kit_receipts')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('onboarding_templates', fn (Blueprint $table) => $table->dropColumn('kit_items'));
        Schema::table('onboarding_journeys', fn (Blueprint $table) => $table->dropColumn(['kit_items', 'kit_receipts']));
    }
};
