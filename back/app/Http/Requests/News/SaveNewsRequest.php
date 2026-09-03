<?php

declare(strict_types=1);

namespace App\Http\Requests\News;

use App\Enums\NewsAudience;
use App\Enums\NewsStatus;
use App\Models\Department;
use App\Models\Group;
use App\Models\User;
use App\Support\News\LinkedMaterial;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Новость целиком — и когда её заводят, и когда правят.
 *
 * Один класс на оба случая: адрес новости с клиента не приходит вовсе (его
 * выдаёт SaveNews и больше не меняет), а всё остальное проверяется одинаково.
 */
final class SaveNewsRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],

            // Строка для карточки в ленте. Не обязательна: у короткой новости
            // и заголовка довольно.
            'excerpt' => ['nullable', 'string', 'max:500'],

            // Документ редактора блоков — тот же формат, что у урока.
            'content_json' => ['nullable', 'array'],

            'status' => ['required', Rule::enum(NewsStatus::class)],
            'is_pinned' => ['sometimes', 'boolean'],
            'audience' => ['required', Rule::enum(NewsAudience::class)],
            'requires_acknowledgement' => ['sometimes', 'boolean'],

            // Адресаты адресной новости — три списка, и они складываются:
            // отделу продаж, группе наставников и ещё двоим поимённо. Каждый
            // сам по себе необязателен, но пустыми все три быть не могут —
            // такую новость не увидит никто; проверяет это after().
            'recipients' => ['sometimes', 'array'],
            'recipients.*' => ['integer', Rule::exists('users', 'id')],

            'department_ids' => ['sometimes', 'array'],
            'department_ids.*' => ['integer', Rule::exists(Department::class, 'id')],

            'group_ids' => ['sometimes', 'array'],
            'group_ids.*' => ['integer', Rule::exists(Group::class, 'id')],

            // Куда сходить после новости: курс, модуль, урок или регламент.
            // Приходит парой «вид и номер» — номер сам по себе ничего не
            // значит, курс №3 и урок №3 разные вещи.
            'links' => ['sometimes', 'array'],
            'links.*.type' => ['required', 'string', Rule::in(LinkedMaterial::kinds())],
            'links.*.id' => ['required', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            /**
             * Адресная новость выходит к кому-то: людям, отделам или группам.
             *
             * Требуется это не раньше публикации — новость заводят с решения
             * «это не всем», а кому именно, выясняют, пока она черновик. Ошибка
             * ложится на `recipients`: в этом поле её и ждёт экран, а порознь
             * три пустых списка ни о чём не говорят.
             */
            function (Validator $validator): void {
                if ($this->needsAddressees() && $this->chosenAddressees() === 0) {
                    $validator->errors()->add(
                        'recipients',
                        'Прежде чем публиковать, выберите, кому адресована новость: сотрудников, отделы или группы.',
                    );
                }
            },
            function (Validator $validator): void {
                // Сначала об ошибках: `validated()` на провалившемся разборе
                // бросает исключение, а не отдаёт то, что уцелело.
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                /** @var User $actor */
                $actor = $this->user();

                foreach ($this->links() as $index => $link) {
                    $model = LinkedMaterial::KINDS[$link['type']] ?? null;
                    $material = $model === null ? null : $model::query()->find($link['id']);

                    // Привязать можно только то, что не закрыто от тебя: иначе
                    // чужой закрытый курс попал бы в новость по угаданному
                    // номеру, и читатель увидел бы название, которое ему
                    // закрыто. Право читать базу знаний здесь не спрашивают —
                    // тот, кто ведёт новости, не обязан быть учеником.
                    if (! LinkedMaterial::isLinkableBy($material, $actor)) {
                        $validator->errors()->add(
                            "links.{$index}.id",
                            'Такого курса или документа нет, или он вам не виден.',
                        );
                    }
                }
            },
        ];
    }

    /**
     * Привязанный материал, в том порядке, в каком его перечислили.
     *
     * @return list<array{type: string, id: int}>
     */
    public function links(): array
    {
        /** @var list<array{type: string, id: int|string}> $links */
        $links = $this->validated('links', []);

        return array_values(array_map(
            static fn (array $link): array => ['type' => (string) $link['type'], 'id' => (int) $link['id']],
            $links,
        ));
    }

    private function needsAddressees(): bool
    {
        return $this->input('audience') === NewsAudience::Selected->value
            && $this->input('status') === NewsStatus::Published->value;
    }

    /**
     * Сколько адресатов выбрано всего — людей, отделов и групп вместе.
     *
     * Читается из присланного, а не из `validated()`: тот на провалившемся
     * разборе бросает исключение, а сказать «выберите адресатов» нужно и рядом
     * с другими ошибками.
     */
    private function chosenAddressees(): int
    {
        return array_sum(array_map(
            fn (string $field): int => count((array) $this->input($field, [])),
            ['recipients', 'department_ids', 'group_ids'],
        ));
    }

    /**
     * @return array{
     *     title: string,
     *     excerpt: ?string,
     *     content_json: ?array<string, mixed>,
     *     status: string,
     *     is_pinned: bool,
     *     audience: string,
     *     requires_acknowledgement: bool,
     *     recipients: list<int>,
     *     department_ids: list<int>,
     *     group_ids: list<int>,
     *     links: list<array{type: string, id: int}>
     * }
     */
    public function toAttributes(): array
    {
        /** @var array<string, mixed> $validated */
        $validated = $this->validated();

        return [
            'title' => (string) $validated['title'],
            'excerpt' => $validated['excerpt'] ?? null,
            'content_json' => $validated['content_json'] ?? null,
            'status' => (string) $validated['status'],
            'is_pinned' => (bool) ($validated['is_pinned'] ?? false),
            'audience' => (string) $validated['audience'],
            'requires_acknowledgement' => (bool) ($validated['requires_acknowledgement'] ?? false),
            'recipients' => array_values(array_map(intval(...), $validated['recipients'] ?? [])),
            'department_ids' => array_values(array_map(intval(...), $validated['department_ids'] ?? [])),
            'group_ids' => array_values(array_map(intval(...), $validated['group_ids'] ?? [])),
            'links' => $this->links(),
        ];
    }
}
