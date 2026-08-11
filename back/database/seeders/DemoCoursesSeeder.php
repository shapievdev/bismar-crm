<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\CourseStatus;
use App\Models\Category;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\User;
use Database\Seeders\Demo\KnowledgeBaseContent;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Демонстрационная база знаний с настоящим текстом уроков.
 *
 * Прежняя версия наполняла каталог заглушками — по одной фразе на урок. Этого
 * хватало, чтобы посмотреть вёрстку карточек, и не хватало ни на что больше:
 * консультанту в такой базе нечего процитировать, и на любой содержательный
 * вопрос он честно отвечает, что материалов нет.
 *
 * Намеренно не вызывается из DatabaseSeeder: боевой `db:seed` не должен
 * незаметно залить базу знаний учебным текстом. Запускается по имени:
 *
 *     php artisan db:seed --class=DemoCoursesSeeder
 *
 * Идемпотентен: курсы ищутся по slug, повторный запуск обновляет текст уроков
 * и ничего не дублирует.
 */
final class DemoCoursesSeeder extends Seeder
{
    /**
     * Отпечаток уроков-заглушек прежней версии сидера.
     *
     * По нему они и удаляются: строка встречалась только в сгенерированном
     * тексте, так что настоящий материал под неё не попадёт.
     */
    private const STUB_MARKER = '%сидером демо-данных%';

    public function run(): void
    {
        $removed = $this->removeStubCourses();

        $author = User::query()->orderBy('id')->first();
        $categoryIds = Category::query()->pluck('id', 'slug');

        $lessons = 0;

        foreach (KnowledgeBaseContent::courses() as $index => $data) {
            $course = Course::updateOrCreate(
                ['slug' => Str::slug($data['title'])],
                [
                    'author_id' => $author?->getKey(),
                    'category_id' => $data['category'] === null ? null : $categoryIds->get($data['category']),
                    'title' => $data['title'],
                    'summary' => $data['summary'],
                    'description' => $data['summary'].' Учебный материал демонстрационной базы: цифры и сроки в нём условны.',
                    'status' => $data['status'],
                    'published_at' => $data['status'] === CourseStatus::Published
                        ? now()->subDays(count(KnowledgeBaseContent::courses()) - $index)
                        : null,
                ],
            );

            $lessons += $this->fillOutline($course, $data);
        }

        $this->command?->info(sprintf(
            'Заглушек удалено: %d. Курсов с материалом: %d, уроков: %d.',
            $removed,
            count(KnowledgeBaseContent::courses()),
            $lessons,
        ));
    }

    /**
     * Убирает курсы, состоящие целиком из уроков-заглушек.
     *
     * Курс, куда автор успел дописать настоящий урок, не трогаем: пусть в нём
     * останется и заглушка, это дешевле, чем удалить чужую работу.
     */
    private function removeStubCourses(): int
    {
        $stubCourseIds = DB::table('courses')
            ->join('course_modules', 'course_modules.course_id', '=', 'courses.id')
            ->join('lessons', 'lessons.module_id', '=', 'course_modules.id')
            ->groupBy('courses.id')
            ->havingRaw('count(*) = count(*) filter (where lessons.content like ?)', [self::STUB_MARKER])
            ->pluck('courses.id');

        if ($stubCourseIds->isEmpty()) {
            return 0;
        }

        // forceDelete, а не delete: курсы мягко удаляемые, а оставленная в
        // корзине заглушка так и осталась бы в базе — и в выборке поиска,
        // который отфильтровывает только deleted_at IS NULL.
        return Course::whereIn('id', $stubCourseIds)->forceDelete();
    }

    /**
     * Записывает модуль и уроки курса, перезаписывая прежний текст.
     *
     * @param  array<string, mixed>  $data
     */
    private function fillOutline(Course $course, array $data): int
    {
        $module = $course->modules()->updateOrCreate(
            ['position' => 0],
            ['title' => $data['module'], 'description' => null],
        );

        /** @var list<array{title: string, minutes: int, content: string}> $lessons */
        $lessons = $data['lessons'];

        foreach ($lessons as $position => $lesson) {
            $record = Lesson::firstOrNew(['module_id' => $module->getKey(), 'position' => $position]);

            // Slug выдаётся один раз: он попадает в ссылки, и менять его при
            // каждом прогоне сидера значило бы ломать их на ровном месте.
            $record->slug ??= Str::slug($lesson['title']).'-'.Str::lower(Str::random(4));

            $record->fill([
                'title' => $lesson['title'],
                'duration_minutes' => $lesson['minutes'],
                // Поиск и консультант читают именно это поле.
                'content' => $lesson['content'],
            ])->save();
        }

        return count($lessons);
    }
}
