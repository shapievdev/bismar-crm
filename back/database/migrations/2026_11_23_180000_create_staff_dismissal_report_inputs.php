<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_dismissal_report_inputs', function (Blueprint $table): void {
            $table->id();
            $table->string('scope_key', 64)->unique();
            $table->json('filters');
            $table->json('values');
            $table->foreignId('updated_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_dismissal_report_inputs');
    }
};
