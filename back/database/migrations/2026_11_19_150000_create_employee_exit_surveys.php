<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_exit_surveys', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('assigned_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('dismissed_at');
            $table->json('employee');
            $table->json('definition');
            $table->json('answers')->nullable();
            $table->timestamp('submitted_at')->nullable()->index();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'submitted_at', 'cancelled_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_exit_surveys');
    }
};
