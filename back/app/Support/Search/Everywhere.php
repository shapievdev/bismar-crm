<?php

declare(strict_types=1);

namespace App\Support\Search;

use App\Enums\MaterialKind;
use App\Enums\Permission;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\News;
use App\Models\Regulation;
use App\Models\User;
use App\Support\Lms\CatalogSearch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Поиск по всей платформе — один на шапку приложения.
 *
 * Сотрудник не помнит, в каком разделе живёт то, что он ищет: «возврат» бывает
 * курсом, уроком, правилом и человеком, который этим занимается. Каталоги ищут
 * каждый у себя, и чтобы найти, надо сперва угадать раздел, — здесь спрашивают
 * один раз и получают ответ отовсюду, разложенный по разделам.
 *
 * Раздел — не украшение выдачи, а её единица: у курсов, документов и
 * справочников свои права (2026-09-11), и закрытый раздел не даёт ни находок,
 * ни самого заголовка. Пустой раздел тоже не показывается: «Документы — ничего»
 * сообщает лишь то, что раздел существует.
 *
 * Ищется по названиям, а не по содержимому: текст статей и расшифровок — корпус
 * консультанта, у него на это свой поиск со смыслом и точными ссылками (см.
 * KnowledgeBase), и повторять его здесь значило бы отвечать на другой вопрос.
 * По той же причине здесь нет переписки: у мессенджера свой поиск, и находка
 * «сказано в чате» рядом с курсом сбивала бы с толку.
 *
 * Курсы и материалы ищет Meilisearch (CatalogSearch) — тот же, что и каталоги:
 * шапка обязана находить то же, что найдёт каталог, и прощать те же опечатки.
 * Остальным хватает вхождения подстроки. Забытую раскладку прощают и тот и
 * другой — см. KeyboardLayout.
 */
final readonly class Everywhere
{
    /**
     * Сколько находок показывается в разделе.
     *
     * Подсказка, а не выдача: в ней ищут то, что узнают с первого взгляда, а
     * шестое место по подсказке всё равно не читают. Кому мало — «показать все»
     * ведёт в каталог раздела с тем же словом, а он умеет и листаться, и
     * отбирать по категории.
     */
    private const PER_SECTION = 5;

    /**
     * Короче двух букв не ищем: одна буква находит всё и потому ничего.
     *
     * То же правило, что у поиска по переписке (MessageSearch::MIN_LENGTH), и
     * по той же причине — но своё: подсказка в шапке и поиск в переписке живут
     * отдельно, и общая константа связала бы их без нужды.
     */
    private const MIN_LENGTH = 2;

    public function __construct(private CatalogSearch $catalog) {}

    /**
     * Найденное, разложенное по разделам, — в постоянном порядке.
     *
     * Порядок разделов не зависит от того, где нашлось больше: человек ищет
     * глазами и привыкает, что курсы сверху, а люди снизу. Переставлять их по
     * числу находок значило бы каждый раз заставлять его читать заголовки.
     *
     * @return list<array{kind: string, label: string, more_url: string|null, items: list<array{title: string, subtitle: string|null, url: string}>}>
     */
    public function find(?string $term, User $reader): array
    {
        $term = trim((string) $term);

        if (mb_strlen($term) < self::MIN_LENGTH) {
            return [];
        }

        $sections = [
            $this->courses($term, $reader),
            $this->lessons($term, $reader),
            ...array_map(
                fn (MaterialKind $kind): ?array => $this->materials($kind, $term, $reader),
                MaterialKind::viewableBy($reader),
            ),
            $this->news($term, $reader),
            $this->people($term, $reader),
        ];

        return array_values(array_filter($sections));
    }

    /**
     * Курсы — те же, что в каталоге: закрытые только своим, черновики только
     * тем, кто их правит.
     *
     * @return array{kind: string, label: string, more_url: string|null, items: list<array{title: string, subtitle: string|null, url: string}>}|null
     */
    private function courses(string $term, User $reader): ?array
    {
        if ($reader->cannot(Permission::ViewCourses->value)) {
            return null;
        }

        $courses = Course::query()
            ->with('category')
            ->visibleTo($reader)
            ->when(
                $reader->cannot(Permission::UpdateCourses->value),
                fn (Builder $query) => $query->openToLearners(),
            )
            ->tap(fn (Builder $query) => $this->catalog->apply($query, $term))
            ->limit(self::PER_SECTION)
            ->get();

        return $this->section('course', 'Курсы', $this->more('/lms', $term), $courses->map(
            fn (Course $course): array => [
                'title' => $course->title,
                'subtitle' => $course->category?->name,
                'url' => '/lms/'.$course->slug,
            ],
        ));
    }

    /**
     * Уроки — по названию, с курсом в подписи.
     *
     * Без курса урок не найти глазами: «Возражения» есть и в курсе продавца, и
     * в курсе руководителя, и это разные уроки.
     *
     * Доступ решает курс, как и везде в базе знаний (см. PartOfCourse): у урока
     * своей закрытости нет.
     *
     * @return array{kind: string, label: string, more_url: string|null, items: list<array{title: string, subtitle: string|null, url: string}>}|null
     */
    private function lessons(string $term, User $reader): ?array
    {
        if ($reader->cannot(Permission::ViewCourses->value)) {
            return null;
        }

        $lessons = Lesson::query()
            ->with('module.course')
            ->whereHas('module.course', fn (Builder $course) => $course
                ->visibleTo($reader)
                ->when(
                    $reader->cannot(Permission::UpdateCourses->value),
                    fn (Builder $query) => $query->openToLearners(),
                ))
            ->tap(fn (Builder $query) => Substring::apply($query, $term, ['lessons.title']))
            ->orderByRaw('lessons.title COLLATE "und-x-icu"')
            ->limit(self::PER_SECTION)
            ->get();

        // Урок без курса в выдаче не нужен: вести его некуда, а сказать о нём
        // нечего, кроме названия.
        $items = $lessons
            ->filter(fn (Lesson $lesson): bool => $lesson->owningCourse() !== null)
            ->map(function (Lesson $lesson): array {
                /** @var Course $course */
                $course = $lesson->owningCourse();

                return [
                    'title' => $lesson->title,
                    'subtitle' => 'Курс «'.$course->title.'»',
                    'url' => '/lms/'.$course->slug.'/lessons/'.$lesson->getKey(),
                ];
            });

        // Своего каталога у уроков нет — искать их дальше можно только внутри
        // курса, и обещать «показать все» здесь нечем.
        return $this->section('lesson', 'Уроки', null, $items);
    }

    /**
     * Документы и справочники — своим разделом каждый.
     *
     * Вперемешку их не показывают нигде в приложении, и здесь тем более:
     * правило, по которому работают, и справка на случай — разные ответы на
     * один и тот же вопрос, и человек различает их по заголовку раздела.
     *
     * Права проверены до вызова: раздел попадает сюда, только если открыт (см.
     * MaterialKind::viewableBy).
     *
     * @return array{kind: string, label: string, more_url: string|null, items: list<array{title: string, subtitle: string|null, url: string}>}|null
     */
    private function materials(MaterialKind $kind, string $term, User $reader): ?array
    {
        $materials = Regulation::query()
            ->with('category')
            ->ofKind($kind)
            ->visibleTo($reader)
            ->when(
                $reader->cannot($kind->updatePermission()->value),
                fn (Builder $query) => $query->published(),
            )
            ->tap(fn (Builder $query) => $this->catalog->apply($query, $term))
            ->limit(self::PER_SECTION)
            ->get();

        return $this->section($kind->value, $kind->plural(), $this->more('/lms/'.$kind->section(), $term), $materials->map(
            fn (Regulation $material): array => [
                'title' => $material->title,
                'subtitle' => $material->category?->name,
                'url' => $material->path(),
            ],
        ));
    }

    /**
     * Новости — только опубликованные и только адресованные этому человеку.
     *
     * Тем же правилом, каким их отбирает лента (News::scopeReadableBy):
     * найденная в шапке новость, которую нельзя открыть, хуже ненайденной.
     *
     * @return array{kind: string, label: string, more_url: string|null, items: list<array{title: string, subtitle: string|null, url: string}>}|null
     */
    private function news(string $term, User $reader): ?array
    {
        $news = News::query()
            ->readableBy($reader)
            ->tap(fn (Builder $query) => Substring::apply($query, $term, ['news.title', 'news.excerpt']))
            ->orderByRaw('published_at DESC NULLS LAST')
            ->limit(self::PER_SECTION)
            ->get();

        // Лента новостей поиском не отбирается — обещать «показать все» нечем.
        return $this->section('news', 'Новости', null, $news->map(
            fn (News $one): array => [
                'title' => $one->title,
                'subtitle' => $one->published_at?->format('d.m.Y'),
                'url' => '/news/'.$one->slug,
            ],
        ));
    }

    /**
     * Сотрудники — по имени и почте, работающие первыми.
     *
     * Уволенные из выдачи не выброшены: их ищут ровно затем, зачем и держат в
     * списке людей, — вернуть в строй или разобрать, что за ними осталось. Но
     * идут они после работающих и сказано об этом прямо: «Иванов» в подсказке
     * без пометки читался бы как коллега, которому можно написать.
     *
     * @return array{kind: string, label: string, more_url: string|null, items: list<array{title: string, subtitle: string|null, url: string}>}|null
     */
    private function people(string $term, User $reader): ?array
    {
        if ($reader->cannot(Permission::ViewUsers->value)) {
            return null;
        }

        $people = User::query()
            ->matching($term)
            ->orderByRaw('dismissed_at IS NOT NULL')
            ->orderByRaw('COALESCE(last_name, first_name) COLLATE "und-x-icu"')
            ->limit(self::PER_SECTION)
            ->get();

        return $this->section('person', 'Сотрудники', $this->more('/staff', $term), $people->map(
            fn (User $person): array => [
                'title' => $person->name,
                'subtitle' => $this->personSubtitle($person),
                'url' => '/staff/'.$person->getKey(),
            ],
        ));
    }

    private function personSubtitle(User $person): ?string
    {
        $job = $person->job_title === null ? null : trim((string) $person->job_title);

        if ($person->isDismissed()) {
            return $job === null || $job === '' ? 'Уволен' : 'Уволен · '.$job;
        }

        return $job === '' ? null : $job;
    }

    /**
     * Раздел выдачи — или ничего, когда в нём ничего не нашлось.
     *
     * @param  Collection<int, array{title: string, subtitle: string|null, url: string}>  $items
     * @return array{kind: string, label: string, more_url: string|null, items: list<array{title: string, subtitle: string|null, url: string}>}|null
     */
    private function section(string $kind, string $label, ?string $moreUrl, Collection $items): ?array
    {
        if ($items->isEmpty()) {
            return null;
        }

        return [
            'kind' => $kind,
            'label' => $label,
            'more_url' => $moreUrl,
            'items' => $items->values()->all(),
        ];
    }

    /**
     * Адрес каталога с тем же словом.
     *
     * Каталоги читают `search` из адреса — и курсов, и документов, и людей, —
     * поэтому «показать все» это одна ссылка, а не отдельный экран выдачи.
     */
    private function more(string $path, string $term): string
    {
        return $path.'?search='.rawurlencode($term);
    }
}
