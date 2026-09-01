<?php

declare(strict_types=1);

namespace App\Support\News;

use App\Models\Contracts\PartOfCourse;
use App\Models\Course;
use App\Models\CourseModule;
use App\Models\Lesson;
use App\Models\Regulation;
use App\Models\User;
use App\Support\Lms\CourseAccess;
use App\Support\Lms\RegulationAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

/**
 * Всё, на что новость умеет ссылаться, и всё, что об этом нужно знать.
 *
 * Одно место на четыре вида: как называется, куда ведёт, кому видно и как его
 * найти поиском. Разложить это по ресурсу, контроллеру и форм-реквесту значит
 * трижды написать одно и то же — и однажды добавить пятый вид в двух местах
 * из трёх.
 */
final class LinkedMaterial
{
    /**
     * Виды и их короткие имена. Совпадают с картой в AppServiceProvider: она
     * решает, что попадёт в базу, а этот список — что можно выбрать.
     *
     * @var array<string, class-string<Model>>
     */
    public const KINDS = [
        'course' => Course::class,
        'module' => CourseModule::class,
        'lesson' => Lesson::class,
        'regulation' => Regulation::class,
    ];

    /**
     * @return list<string>
     */
    public static function kinds(): array
    {
        return array_keys(self::KINDS);
    }

    public static function kindLabel(string $kind): string
    {
        return match ($kind) {
            'course' => 'Курс',
            'module' => 'Модуль',
            'lesson' => 'Урок',
            'regulation' => 'Документ',
            default => $kind,
        };
    }

    /**
     * Куда ведёт ссылка.
     *
     * У модуля своей страницы нет — он ведёт на курс, внутри которого лежит:
     * так читатель попадает туда, где модуль виден целиком, а не в никуда.
     * Null означает «материала больше нет» — курс могли мягко удалить.
     */
    public static function url(Model $material): ?string
    {
        return match (true) {
            $material instanceof Course => '/lms/'.$material->slug,
            $material instanceof Regulation => '/lms/documents/'.$material->slug,
            $material instanceof CourseModule => self::courseUrl($material),
            $material instanceof Lesson => self::lessonUrl($material),
            default => null,
        };
    }

    /**
     * Как материал называется в списке.
     */
    public static function title(Model $material): string
    {
        /** @var object{title?: string} $material */
        return (string) ($material->title ?? '');
    }

    /**
     * Строка под названием: где этот материал лежит.
     */
    public static function subtitle(Model $material): ?string
    {
        if ($material instanceof CourseModule || $material instanceof Lesson) {
            $course = $material->owningCourse();

            return $course === null ? null : 'Курс «'.$course->title.'»';
        }

        if ($material instanceof Course || $material instanceof Regulation) {
            return $material->category?->name;
        }

        return null;
    }

    /**
     * Можно ли этому человеку привязать материал к новости.
     *
     * Проверяется только закрытость, но не право читать базу знаний: тот, кто
     * ведёт новости, не обязан быть учеником, а сослаться на курс ему нужно.
     * Читателю ссылка всё равно не покажется, если открыть он её не сможет, —
     * это решает isVisibleTo() уже на выдаче.
     *
     * Правило то же, каким отбирает search(): иначе поиск предлагал бы то, что
     * сохранение отвергает.
     */
    public static function isLinkableBy(?Model $material, User $actor): bool
    {
        $subject = self::subjectOf($material);

        return match (true) {
            $subject instanceof Course => CourseAccess::of($actor)->allows($subject),
            $subject instanceof Regulation => RegulationAccess::of($actor)->allows($subject),
            default => false,
        };
    }

    /**
     * Виден ли материал этому человеку.
     *
     * Часть курса решается доступом к самому курсу — так же, как везде в базе
     * знаний (см. PartOfCourse). Регламент отвечает за себя сам.
     */
    public static function isVisibleTo(?Model $material, User $reader): bool
    {
        $subject = self::subjectOf($material);

        if ($subject === null) {
            return false;
        }

        return Gate::forUser($reader)->allows('view', $subject);
    }

    /**
     * Кем доступ решается: сам материал или курс, частью которого он является.
     */
    private static function subjectOf(?Model $material): Course|Regulation|null
    {
        if ($material === null) {
            return null;
        }

        $subject = $material instanceof PartOfCourse ? $material->owningCourse() : $material;

        return $subject instanceof Course || $subject instanceof Regulation ? $subject : null;
    }

    /**
     * Поиск по всем видам разом: составителю всё равно, курс это или урок, —
     * ему нужно то, что называется так, как он набрал.
     *
     * @return list<array{kind: string, id: int, title: string, subtitle: ?string, url: ?string}>
     */
    public static function search(string $term, User $actor, int $perKind = 5): array
    {
        $found = [];

        foreach (self::KINDS as $kind => $model) {
            foreach (self::queryFor($kind, $term, $actor, $perKind) as $material) {
                $found[] = [
                    'kind' => $kind,
                    'id' => (int) $material->getKey(),
                    'title' => self::title($material),
                    'subtitle' => self::subtitle($material),
                    'url' => self::url($material),
                ];
            }
        }

        return $found;
    }

    /**
     * @return iterable<int, Model>
     */
    private static function queryFor(string $kind, string $term, User $actor, int $limit): iterable
    {
        $pattern = '%'.$term.'%';

        // ICU обязателен: базы собраны с C-сортировкой, где ILIKE складывает
        // только латиницу, и «касса» не нашла бы «Кассовую».
        $byTitle = fn (Builder $query) => $query->whereRaw('title COLLATE "und-x-icu" ILIKE ?', [$pattern]);

        return match ($kind) {
            'course' => Course::query()->visibleTo($actor)->where($byTitle)
                ->with('category')->limit($limit)->get(),

            'regulation' => Regulation::query()->visibleTo($actor)->where($byTitle)
                ->with('category')->limit($limit)->get(),

            // Части курса отбираются по доступу к своему курсу — тем же
            // условием, что и сам курс.
            'module' => CourseModule::query()->where($byTitle)
                ->whereHas('course', fn (Builder $course) => $course->visibleTo($actor))
                ->with('course')->limit($limit)->get(),

            'lesson' => Lesson::query()->where($byTitle)
                ->whereHas('module.course', fn (Builder $course) => $course->visibleTo($actor))
                ->with('module.course')->limit($limit)->get(),

            default => [],
        };
    }

    private static function courseUrl(CourseModule $module): ?string
    {
        $course = $module->owningCourse();

        return $course === null ? null : '/lms/'.$course->slug;
    }

    private static function lessonUrl(Lesson $lesson): ?string
    {
        $course = $lesson->owningCourse();

        return $course === null ? null : '/lms/'.$course->slug.'/lessons/'.$lesson->getKey();
    }
}
