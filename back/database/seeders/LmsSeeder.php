<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\CourseStatus;
use App\Enums\QuestionType;
use App\Models\Category;
use App\Models\Course;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * A starter course so the LMS is not empty on a fresh install.
 * Idempotent: matched by slug and reused.
 */
final class LmsSeeder extends Seeder
{
    public function run(): void
    {
        $author = User::query()->orderBy('id')->first();

        $categories = [
            ['name' => 'Онбординг', 'slug' => 'onbording', 'position' => 0],
            ['name' => 'Продажи', 'slug' => 'prodazhi', 'position' => 1],
            ['name' => 'Документы', 'slug' => 'dokumenty', 'position' => 2],
        ];

        foreach ($categories as $attributes) {
            Category::firstOrCreate(['slug' => $attributes['slug']], $attributes);
        }

        $onboarding = Category::firstWhere('slug', 'onbording');

        $course = Course::firstOrCreate(
            ['slug' => 'vvedenie-v-bismar-crm'],
            [
                'author_id' => $author?->getKey(),
                'category_id' => $onboarding?->getKey(),
                'title' => 'Введение в Bismar CRM',
                'summary' => 'Базовый курс для новых сотрудников: как устроена система и что от вас требуется.',
                'description' => 'Курс знакомит с интерфейсом CRM, правилами ведения данных и разграничением доступа. Пройдите его в первую неделю работы.',
                'status' => CourseStatus::Published,
                'published_at' => now(),
            ],
        );

        if ($course->modules()->exists()) {
            return;
        }

        $basics = $course->modules()->create([
            'title' => 'Основы',
            'description' => 'С чего начать работу в системе.',
            'position' => 0,
        ]);

        $basics->lessons()->create([
            'title' => 'Интерфейс и навигация',
            'slug' => 'interfeys-i-navigatsiya',
            'position' => 0,
            'duration_minutes' => 10,
            'content' => <<<'TEXT'
            Меню сверху показывает только те разделы, к которым у вас есть доступ. Если раздела не видно — его закрывает ваша роль, а не ошибка.

            Права проверяются на сервере при каждом запросе. Интерфейс лишь отражает решение сервера, поэтому «обойти» скрытый раздел через прямую ссылку не получится.

            Начните с раздела «Обучение»: он открыт всем сотрудникам.
            TEXT,
        ]);

        $rules = $course->modules()->create([
            'title' => 'Правила работы',
            'description' => 'Что и как заполнять.',
            'position' => 1,
        ]);

        $lesson = $rules->lessons()->create([
            'title' => 'Ведение данных',
            'slug' => 'vedenie-dannyh',
            'position' => 0,
            'duration_minutes' => 15,
            'content' => <<<'TEXT'
            Заполняйте карточки сразу после контакта с клиентом, пока детали свежи. Данные, внесённые «потом», почти всегда неполны.

            Обязательные поля отмечены в форме. Если данных нет — так и оставьте поле пустым, а не подставляйте заглушку: пустое поле честнее прочерка и не портит отчёты.

            В конце модуля вас ждёт короткий тест.
            TEXT,
        ]);

        $quiz = $lesson->quiz()->create([
            'title' => 'Проверка знаний',
            'description' => 'Два вопроса по материалу модуля.',
            'passing_score' => 70,
            'max_attempts' => null,
        ]);

        $first = $quiz->questions()->create([
            'text' => 'Когда следует заполнять карточку клиента?',
            'type' => QuestionType::Single,
            'points' => 1,
            'position' => 0,
        ]);

        $first->options()->createMany([
            ['text' => 'Сразу после контакта', 'is_correct' => true, 'position' => 0],
            ['text' => 'В конце недели', 'is_correct' => false, 'position' => 1],
            ['text' => 'Когда попросит руководитель', 'is_correct' => false, 'position' => 2],
        ]);

        $second = $quiz->questions()->create([
            'text' => 'Что делать, если данных для обязательного поля нет?',
            'type' => QuestionType::Single,
            'points' => 1,
            'position' => 1,
        ]);

        $second->options()->createMany([
            ['text' => 'Оставить поле пустым', 'is_correct' => true, 'position' => 0],
            ['text' => 'Поставить прочерк', 'is_correct' => false, 'position' => 1],
            ['text' => 'Написать «уточнить»', 'is_correct' => false, 'position' => 2],
        ]);
    }
}
