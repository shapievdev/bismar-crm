<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lessons', function (Blueprint $table): void {
            // The rich document, as the editor's own node tree. Storing the
            // tree rather than HTML means the schema itself is the allow-list:
            // only node types the renderer knows about can ever appear.
            $table->jsonb('content_json')->nullable()->after('content');
        });
    }

    public function down(): void
    {
        Schema::table('lessons', function (Blueprint $table): void {
            $table->dropColumn('content_json');
        });
    }
};
