<?php

declare(strict_types=1);

use App\Enums\MaterialKind;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Справочники — второй вид страниц, устроенных как документ.
 *
 * Колонкой, а не второй таблицей: у них одно устройство до последней мелочи —
 * статья, файлы, ответственные, проверка, отметка «ознакомлен», соседи «рядом
 * по теме», расшифровки для консультанта. Вторая таблица означала бы вторую
 * копию всего перечисленного, и однажды поправили бы одну из двух.
 *
 * Дерево категорий у каждого вида своё, поэтому вид помечается и у категории:
 * в документах ищут, по какому правилу работать, в справочниках — что делать
 * прямо сейчас, и общее дерево заставляло бы отсеивать половину каждый раз.
 *
 * Всё, что уже есть, — документы: справочников до этой миграции не было.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['regulations', 'regulation_categories'] as $name) {
            Schema::table($name, function (Blueprint $table) use ($name): void {
                $table->string('kind')->default(MaterialKind::Document->value)->index();

                // Каталог всегда просматривают внутри одного вида, и порядок в
                // нём — по дате выпуска. Индекс под ровно этот запрос.
                if ($name === 'regulations') {
                    $table->index(['kind', 'status', 'published_at']);
                }
            });
        }
    }

    public function down(): void
    {
        Schema::table('regulations', function (Blueprint $table): void {
            $table->dropIndex(['kind', 'status', 'published_at']);
            $table->dropColumn('kind');
        });

        Schema::table('regulation_categories', function (Blueprint $table): void {
            $table->dropColumn('kind');
        });
    }
};
