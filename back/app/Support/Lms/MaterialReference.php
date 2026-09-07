<?php

declare(strict_types=1);

namespace App\Support\Lms;

use App\Models\Course;
use App\Models\Lesson;
use App\Models\Regulation;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Материал, названный в адресе: «пишу с этой страницы».
 *
 * С карточки ответственного уходят в мессенджер, и там от материала не
 * остаётся ничего — адресат читает вопрос, не понимая, о чём он. Ссылка несёт
 * с собой вид материала и номер, а всё остальное — название, адрес, подпись —
 * собирается здесь, на сервере: пришедшему из адреса верить нельзя, иначе
 * карточкой над репликой можно было бы объявить что угодно.
 *
 * Сама карточка — та же, что и у замечаний к материалу (см. MaterialAppeal):
 * в разговоре это одно и то же — «письмо пришло вот отсюда», — и рисовать его
 * двумя способами значит однажды поправить один.
 */
final readonly class MaterialReference
{
    /** Виды, о которых пишут: те же, что знает карточка над репликой. */
    public const KINDS = ['course', 'lesson', 'document', 'handbook'];

    private function __construct(
        private Course|Lesson|Regulation $material,
        private MaterialAppeal $appeal,
    ) {}

    /**
     * Материал по виду и номеру — или ничего, если такого нет.
     *
     * Вид сверяется с найденным, а не принимается на слово: документ, названный
     * справочником, привёл бы к карточке с чужой подписью и адресом в чужой
     * раздел.
     */
    public static function find(string $kind, int $id): ?self
    {
        if ($kind === 'course') {
            $course = Course::query()->with('author', 'experts')->find($id);

            return $course === null ? null : new self($course, MaterialAppeal::forCourse($course));
        }

        if ($kind === 'lesson') {
            $lesson = Lesson::query()->find($id);

            return $lesson === null ? null : new self($lesson, MaterialAppeal::forLesson($lesson));
        }

        $material = Regulation::query()->with('author', 'experts')->find($id);

        if ($material === null || $material->kind->value !== $kind) {
            return null;
        }

        return new self($material, MaterialAppeal::forMaterial($material));
    }

    /**
     * Видно ли материал тому, кто на него ссылается.
     *
     * Урок проверяется по своему курсу — как и везде: доступ дают к курсу
     * целиком, отдельного права на урок в системе нет.
     */
    public function visibleTo(User $reader): bool
    {
        $subject = $this->material instanceof Lesson
            ? $this->material->owningCourse()
            : $this->material;

        return $subject !== null && Gate::forUser($reader)->allows('view', $subject);
    }

    /**
     * Карточка над репликой. Без причины: с карточки ответственного не
     * жалуются, а спрашивают, и «ответа не хватило» здесь было бы неправдой.
     *
     * @return array<string, string|null>
     */
    public function card(): array
    {
        return $this->appeal->snapshot();
    }
}
