<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('rehire_recommended')->nullable();
            $table->timestamp('rehire_recommended_at')->nullable();
            $table->foreignId('rehire_recommended_by_id')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('rehire_recommended_by_id');
            $table->dropColumn(['rehire_recommended', 'rehire_recommended_at']);
        });
    }
};
