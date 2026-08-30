<?php

declare(strict_types=1);

namespace App\Http\Requests\Lms;

use App\Models\Course;
use App\Models\Regulation;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * План сотрудника целиком: порядок присланного и есть порядок шагов.
 *
 * Шаг — курс или регламент, поэтому приходит парой «вид и номер»: номер сам по
 * себе ничего не значит, курс №3 и регламент №3 — разные вещи.
 */
final class UpdateLearningPlanRequest extends FormRequest
{
    /** Что вообще бывает шагом плана. Совпадает с картой в AppServiceProvider. */
    private const KINDS = [
        'course' => Course::class,
        'regulation' => Regulation::class,
    ];

    /**
     * Менять чужой план — дело должности, а не отмеченного права.
     *
     * Читать планы по-прежнему позволяет `enrollments.manage`: посмотреть, как
     * идут дела у сотрудников, доверяют шире, чем решать, что им проходить.
     * Само же решение принимает администратор или суперадминистратор — так
     * распорядился пользователь (2026-08-30).
     *
     * Проверка стоит здесь, а не в политике: политика ответила бы то же самое,
     * но заводить её ради одного правила о должности — лишний слой между
     * запросом и ответом.
     */
    public function authorize(): bool
    {
        /** @var User|null $actor */
        $actor = $this->user();

        return $actor?->accessLevel()->grantsEverything() ?? false;
    }

    /**
     * @throws AuthorizationException
     */
    protected function failedAuthorization(): never
    {
        throw new AuthorizationException('Менять план обучения может только администратор.');
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            // Присутствует всегда: пустой список — это «убрать весь план», и
            // отличить его от «поле не прислали» иначе нечем.
            'items' => ['present', 'array'],
            'items.*.type' => ['required', 'string', Rule::in(array_keys(self::KINDS))],
            'items.*.id' => ['required', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                // Сначала об ошибках: `validated()` на провалившемся разборе
                // бросает исключение, а не отдаёт то, что уцелело.
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $wanted = $this->items();

                if ($wanted === []) {
                    return;
                }

                /** @var User $actor */
                $actor = $this->user();

                foreach (self::KINDS as $kind => $model) {
                    $ids = array_values(array_map(
                        static fn (array $item): int => $item['id'],
                        array_filter($wanted, static fn (array $item): bool => $item['type'] === $kind),
                    ));

                    if ($ids === []) {
                        continue;
                    }

                    /** @var Builder<Course|Regulation> $query */
                    $query = $model::query();

                    $found = $query->visibleTo($actor)->whereKey($ids)->pluck('id')
                        ->map(intval(...))->all();

                    // Назначить можно только то, что видишь сам. Иначе чужой
                    // закрытый материал попадал бы в план по угаданному номеру
                    // — и сотрудник увидел бы название, которое ему закрыто.
                    if (array_diff($ids, $found) !== []) {
                        $validator->errors()->add(
                            'items',
                            'В плане есть материал, которого вы не видите.',
                        );

                        return;
                    }
                }
            },
        ];
    }

    /**
     * @return list<array{type: string, id: int}>
     */
    public function items(): array
    {
        /** @var list<array{type: string, id: int|string}> $items */
        $items = $this->validated('items', []);

        return array_values(array_map(
            static fn (array $item): array => ['type' => (string) $item['type'], 'id' => (int) $item['id']],
            $items,
        ));
    }
}
