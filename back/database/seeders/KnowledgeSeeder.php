<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\ArticleStatus;
use App\Models\KnowledgeArticle;
use App\Models\KnowledgeCategory;
use App\Models\User;
use App\Support\SlugGenerator;
use Illuminate\Database\Seeder;

/**
 * Starter content so the knowledge base is not empty on a fresh install.
 * Idempotent: rows are matched by slug and reused.
 */
final class KnowledgeSeeder extends Seeder
{
    public function run(): void
    {
        $author = User::query()->orderBy('id')->first();

        $categories = [
            ['name' => 'Продажи', 'slug' => 'prodazhi', 'position' => 1],
            ['name' => 'Регламенты', 'slug' => 'reglamenty', 'position' => 2],
            ['name' => 'Работа с системой', 'slug' => 'rabota-s-sistemoy', 'position' => 3],
        ];

        foreach ($categories as $attributes) {
            KnowledgeCategory::firstOrCreate(['slug' => $attributes['slug']], $attributes);
        }

        $sales = KnowledgeCategory::firstWhere('slug', 'prodazhi');
        $system = KnowledgeCategory::firstWhere('slug', 'rabota-s-sistemoy');

        $articles = [
            [
                'title' => 'Как вести карточку сделки',
                'category_id' => $sales?->id,
                'excerpt' => 'Что заполнять на каждом этапе воронки, чтобы прогноз оставался достоверным.',
                'content' => <<<'TEXT'
                Карточка сделки — единственный источник правды по клиенту. Заполняйте её сразу после разговора, пока детали свежи.

                На этапе квалификации обязательны: контактное лицо, бюджет и срок принятия решения. Без них сделка не попадает в прогноз.

                При переводе на следующий этап коротко опишите, что изменилось. Одна строка в комментарии экономит коллеге полчаса на восстановление контекста.
                TEXT,
            ],
            [
                'title' => 'Роли и права доступа',
                'category_id' => $system?->id,
                'excerpt' => 'Кто что видит в системе и как запросить расширение доступа.',
                'content' => <<<'TEXT'
                Доступ определяется ролью. Наблюдатель только читает, менеджер по продажам ведёт свои сделки, руководитель видит всю команду, администратор управляет системой.

                Права проверяются на сервере при каждом запросе. Если раздел не виден в меню, значит роль его не открывает — интерфейс лишь отражает решение сервера.

                Чтобы расширить доступ, обратитесь к администратору: он меняет роли в разделе «Пользователи».
                TEXT,
            ],
        ];

        $slugGenerator = app(SlugGenerator::class);

        foreach ($articles as $attributes) {
            $slug = $slugGenerator->generate($attributes['title'], KnowledgeArticle::class);

            KnowledgeArticle::firstOrCreate(
                ['title' => $attributes['title']],
                [
                    ...$attributes,
                    'slug' => $slug,
                    'author_id' => $author?->getKey(),
                    'status' => ArticleStatus::Published,
                    'published_at' => now(),
                ],
            );
        }
    }
}
