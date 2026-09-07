<?php

declare(strict_types=1);

namespace App\Support\Lms;

use App\Enums\AppealReason;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\Regulation;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Обращение к тем, кто отвечает за материал: «не хватило» или «неверно».
 *
 * Отвечает на два вопроса, одинаковых для курса, урока, документа и
 * справочника: кому об этом писать и что показать карточкой над репликой.
 * Экраны у этих четырёх разные, а разговор одинаковый — и собирать его
 * четырьмя способами значит однажды поправить три.
 *
 * Адресаты — автор и назначенные ответственные. Больше никто: замечание должно
 * попасть тому, кто вправе поправить материал, а не в общий чат, где его
 * прочитают все и не поправит никто. Уволенных в списке нет — писать им
 * некуда, в систему они больше не входят.
 *
 * Карточка сохраняется снимком (см. миграцию `messages.about`): материал
 * переименуют или выбросят, а разговор должен остаться читаемым.
 */
final readonly class MaterialAppeal
{
    private function __construct(
        private string $kind,
        private string $title,
        /** Курс, внутри которого лежит урок. У остальных пусто. */
        private ?string $context,
        private string $url,
        /** У кого спрашивать: автор материала и его ответственные. */
        private Collection $recipients,
    ) {}

    public static function forCourse(Course $course): self
    {
        return new self(
            kind: 'course',
            title: (string) $course->title,
            context: null,
            url: '/lms/'.$course->slug,
            recipients: self::people($course->author, $course->experts),
        );
    }

    /**
     * Урок ведёт туда же, куда и курс, — за уроком стоит тот же человек.
     *
     * Свой автор у урока не хранится вовсе: уроки пишет тот, кто ведёт курс, и
     * заводить второй список ответственных на каждый урок значит просить
     * заполнять его сорок раз.
     */
    public static function forLesson(Lesson $lesson): self
    {
        $course = $lesson->owningCourse();

        return new self(
            kind: 'lesson',
            title: (string) $lesson->title,
            context: $course?->title,
            url: $course === null ? '/lms' : '/lms/'.$course->slug.'/lessons/'.$lesson->getKey(),
            recipients: self::people($course?->author, $course?->experts),
        );
    }

    public static function forMaterial(Regulation $material): self
    {
        return new self(
            kind: $material->kind->value,
            title: (string) $material->title,
            context: null,
            url: $material->path(),
            recipients: self::people($material->author, $material->experts),
        );
    }

    /**
     * Кому можно написать с этой страницы.
     *
     * @return Collection<int, User>
     */
    public function recipients(): Collection
    {
        return $this->recipients;
    }

    public function allows(User $recipient): bool
    {
        return $this->recipients->contains(
            fn (User $person): bool => (int) $person->getKey() === (int) $recipient->getKey(),
        );
    }

    /**
     * Карточка над репликой — тем, что видно в разговоре.
     *
     * @return array<string, string|null>
     */
    public function snapshot(?AppealReason $reason = null): array
    {
        return [
            'kind' => $this->kind,
            'title' => $this->title,
            'context' => $this->context,
            'url' => $this->url,

            // Причины может и не быть: с карточки ответственного пишут вопрос,
            // а не замечание, и карточка тогда просто говорит, откуда письмо.
            'reason' => $reason?->value,
        ];
    }

    /**
     * Автор и ответственные — одним списком, без повторов и без уволенных.
     *
     * Автор часто и есть ответственный: показывать его дважды значит просить
     * выбрать между человеком и им же.
     *
     * @param  Collection<int, User>|null  $experts
     * @return Collection<int, User>
     */
    private static function people(?User $author, ?Collection $experts): Collection
    {
        return collect([$author])
            ->concat($experts ?? collect())
            ->filter(fn (?User $person): bool => $person !== null && $person->dismissed_at === null)
            ->unique(fn (User $person): int => (int) $person->getKey())
            ->values();
    }
}
