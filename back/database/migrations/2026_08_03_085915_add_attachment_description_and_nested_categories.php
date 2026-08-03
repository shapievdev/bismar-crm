<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lesson_attachments', function (Blueprint $table): void {
            // What the file actually contains, written by whoever uploaded it.
            // The filename alone rarely says ("scan_2024_final.pdf").
            $table->string('description', 500)->nullable()->after('name');
        });

        Schema::table('categories', function (Blueprint $table): void {
            // Self-referencing parent: deleting a category lifts its children up
            // a level rather than deleting them along with their material.
            $table->foreignId('parent_id')
                ->nullable()
                ->after('id')
                ->constrained('categories')
                ->nullOnDelete();

            $table->index(['parent_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('parent_id');
        });

        Schema::table('lesson_attachments', function (Blueprint $table): void {
            $table->dropColumn('description');
        });
    }
};
