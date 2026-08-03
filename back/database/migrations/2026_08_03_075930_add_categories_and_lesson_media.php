<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->index('position');
        });

        Schema::table('courses', function (Blueprint $table): void {
            // A category may be retired without taking its material with it.
            $table->foreignId('category_id')
                ->nullable()
                ->after('author_id')
                ->constrained('categories')
                ->nullOnDelete();
        });

        Schema::table('lessons', function (Blueprint $table): void {
            // An uploaded video, as opposed to video_url which links out to
            // YouTube or Vimeo. A lesson may have either.
            $table->string('video_path')->nullable()->after('video_url');
            $table->string('video_disk')->nullable()->after('video_path');
            $table->string('video_name')->nullable()->after('video_disk');
            $table->unsignedBigInteger('video_size')->nullable()->after('video_name');
        });
    }

    public function down(): void
    {
        Schema::table('lessons', function (Blueprint $table): void {
            $table->dropColumn(['video_path', 'video_disk', 'video_name', 'video_size']);
        });

        Schema::table('courses', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('category_id');
        });

        Schema::dropIfExists('categories');
    }
};
