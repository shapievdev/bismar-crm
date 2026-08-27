<?php

declare(strict_types=1);

namespace App\Actions\Lms;

use App\Enums\CourseStatus;
use App\Enums\CourseVisibility;
use App\Models\Regulation;
use App\Models\User;
use App\Support\SlugGenerator;
use Illuminate\Support\Facades\DB;

/**
 * Заводит регламент и правит его.
 *
 * Одно действие на оба случая: разница между «создать» и «сохранить» здесь
 * только в том, есть ли уже строка, а правила о дате публикации и о закрытости
 * одни и те же.
 */
final readonly class SaveRegulation
{
    public function __construct(private SlugGenerator $slugs) {}

    /**
     * @param  array{
     *     title: string,
     *     summary?: ?string,
     *     content_json?: ?array<string, mixed>,
     *     status: string,
     *     visibility: string,
     *     category_id?: ?int
     * } $attributes
     */
    public function handle(array $attributes, User $author, ?Regulation $regulation = null): Regulation
    {
        return DB::transaction(function () use ($attributes, $author, $regulation): Regulation {
            $status = CourseStatus::from($attributes['status']);

            $regulation ??= new Regulation(['author_id' => $author->getKey()]);

            $regulation->fill([
                'title' => $attributes['title'],
                'summary' => $attributes['summary'] ?? null,
                'content_json' => $attributes['content_json'] ?? null,
                'status' => $status,
                'visibility' => CourseVisibility::from($attributes['visibility']),
                'category_id' => $attributes['category_id'] ?? null,
                'published_at' => $this->publishedAt($regulation, $status),
            ]);

            // Адрес регламента не меняется вслед за названием: ссылку на него
            // уже могли отправить в мессенджере, и правка заголовка не повод её
            // сломать. Заводится он один раз, при создании.
            $regulation->slug ??= $this->slugs->generate($attributes['title'], Regulation::class);

            $regulation->save();

            return $regulation->load('author', 'category');
        });
    }

    /**
     * Дата публикации ставится в тот момент, когда регламент впервые вышел, и
     * дальше не двигается: правка опечатки не должна выглядеть новым правилом.
     */
    private function publishedAt(Regulation $regulation, CourseStatus $status): ?string
    {
        if (! $status->isOpenToLearners()) {
            return $regulation->published_at?->toDateTimeString();
        }

        return ($regulation->published_at ?? now())->toDateTimeString();
    }
}
