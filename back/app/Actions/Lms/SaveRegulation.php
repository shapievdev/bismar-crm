<?php

declare(strict_types=1);

namespace App\Actions\Lms;

use App\Enums\CourseStatus;
use App\Enums\CourseVisibility;
use App\Enums\MaterialKind;
use App\Models\Regulation;
use App\Models\User;
use App\Support\Lms\BlockIdentifier;
use App\Support\Lms\Keywords;
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
    public function __construct(private SlugGenerator $slugs, private BlockIdentifier $blocks) {}

    /**
     * @param  array{
     *     title: string,
     *     summary?: ?string,
     *     content_json?: ?array<string, mixed>,
     *     status: string,
     *     visibility: string,
     *     category_id?: ?int,
     *     keywords?: ?list<string>,
     *     kind?: MaterialKind
     * } $attributes
     */
    public function handle(array $attributes, User $author, ?Regulation $regulation = null): Regulation
    {
        return DB::transaction(function () use ($attributes, $author, $regulation): Regulation {
            $status = CourseStatus::from($attributes['status']);

            // Вид ставится один раз, при заведении: справочник не становится
            // документом от того, что его правят, — у них разные разделы, и
            // переезд означал бы смену адреса под уже разосланной ссылкой.
            $regulation ??= new Regulation([
                'author_id' => $author->getKey(),
                'kind' => $attributes['kind'] ?? MaterialKind::Document,
            ]);

            // Имена блокам присваивает сервер — как уроку, и по той же причине:
            // имя, пришедшее от клиента, может оказаться выдуманным или чужим, а
            // ссылаться на него будут потом годами. Без этого шага у блоков
            // документа имён не было вовсе, а расшифровка собирается по ним — и
            // статья не попадала в поиск консультанта до ручной переиндексации.
            $document = $this->blocks->assign($attributes['content_json'] ?? null);

            $regulation->fill([
                'title' => $attributes['title'],
                'summary' => $attributes['summary'] ?? null,
                'content_json' => $document,
                'status' => $status,
                'visibility' => CourseVisibility::from($attributes['visibility']),
                'category_id' => $attributes['category_id'] ?? null,
                'published_at' => $this->publishedAt($regulation, $status),
            ]);

            // null — это «поля не было в запросе», и набранное остаётся на
            // месте; пустой список — «слов больше нет».
            if (($attributes['keywords'] ?? null) !== null) {
                $regulation->keywords = Keywords::clean($attributes['keywords']);
            }

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
