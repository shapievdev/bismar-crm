<?php

declare(strict_types=1);

namespace App\Actions\Lms;

use App\Enums\AnswerSource;
use App\Models\Lesson;
use App\Models\Regulation;
use App\Support\Lms\BlockIdentifier;
use App\Support\Lms\RichTextExtractor;
use App\Support\Lms\TranscriptCue;
use App\Support\Lms\TranscriptParser;
use Illuminate\Support\Facades\DB;

/**
 * Держит расшифровку статьи в согласии с её текстом.
 *
 * Если её не загрузили, ею служит сам текст урока: набранное и так изложено
 * словами, и требовать от автора пересказывать собственную статью было бы
 * издевательством. Такая расшифровка помечена как выведенная и пересобирается
 * при каждом сохранении урока.
 *
 * Одна на урок, а не на абзац. Абзац помнит кусок — там же, где он помнит
 * секунду записи и страницу документа.
 *
 * Загруженная расшифровка перекрывает выведенную и правку текста переживает:
 * её пишут там, где слов в статье мало, а смысла много, — под схемой,
 * картинкой или вставленным HTML.
 *
 * Заменила собой SyncLessonPassages: нарезка урока была ровно этим, только для
 * одного вида содержания и без возможности что-либо к нему добавить.
 */
final readonly class SyncLessonTranscripts
{
    public function __construct(
        private RichTextExtractor $richText,
        private TranscriptParser $parser,
        private SaveTranscriptSegments $segments,
    ) {}

    /**
     * Держит выведенную расшифровку в согласии с текстом — у урока и у
     * документа одинаково: и то и другое написано словами, и корпус поиска у
     * них общий.
     *
     * @return int сколько блоков получили выведенную расшифровку
     */
    public function handle(Lesson|Regulation $material): int
    {
        $blocks = $this->blocks($material);

        return DB::transaction(function () use ($material, $blocks): int {
            // Выведенная пересобирается целиком: вычислять, какие абзацы
            // уцелели, дороже, чем переписать её заново. Загруженная при этом
            // не трогается — она не производное от текста.
            $material->transcripts()
                ->where('is_derived', true)
                ->where('source_kind', AnswerSource::Text)
                ->delete();

            // Автор перекрыл текст своей расшифровкой — выводить нечего.
            $overridden = $material->transcripts()
                ->where('is_derived', false)
                ->where('source_kind', AnswerSource::Text)
                ->exists();

            if ($overridden || $blocks === []) {
                return 0;
            }

            $transcript = $material->transcripts()->create([
                'source_kind' => AnswerSource::Text,
                // Одна на весь текст урока, а не на каждый абзац: у статьи на
                // семьдесят абзацев автор получал семьдесят расшифровок, между
                // которыми нечего выбирать — они все «текст урока». Место
                // внутри текста помнит кусок, а не расшифровка.
                'source_block_id' => null,
                // Текст статьи и есть её содержимое: открыв такую на правку,
                // автор видит, что именно ушло в поиск, а не пустое поле.
                'content' => implode("\n\n", $blocks),
                'is_derived' => true,
                'format' => TranscriptParser::FORMAT_PLAIN,
            ]);

            // Заголовок куска собирается из названия урока или документа, а
            // он у нас на руках: без этого расшифровка шла бы за ним отдельным
            // запросом — за тем самым, который её и создал.
            $transcript->setRelation($material instanceof Lesson ? 'lesson' : 'regulation', $material);

            $this->segments->handle($transcript, $this->cues($blocks));

            return 1;
        });
    }

    /**
     * Текст статьи, нарезанный на куски и помеченный абзацами.
     *
     * Каждый блок режется отдельно, чтобы кусок не оказался склеен из двух
     * абзацев сразу: ссылка на такой вела бы к первому, а сказанное могло быть
     * во втором.
     *
     * @param  array<string, string>  $blocks
     * @return list<TranscriptCue>
     */
    private function cues(array $blocks): array
    {
        $cues = [];

        foreach ($blocks as $blockId => $text) {
            foreach ($this->parser->parse($text) as $cue) {
                $cues[] = $cue->inBlock($blockId === '' ? null : $blockId);
            }
        }

        return $cues;
    }

    /**
     * Текст каждого блока статьи, под его именем.
     *
     * Блоки без слов — картинка, разделитель — пропускаются: искать в них
     * нечего, а расшифровку к ним автор загрузит сам, если она нужна.
     *
     * Урок, написанный простым текстом до появления редактора, идёт одним
     * блоком без имени: место в нём не указать, но найтись он обязан.
     *
     * @return array<string|null, string>
     */
    private function blocks(Lesson|Regulation $material): array
    {
        $document = $material->content_json;

        if (! is_array($document['content'] ?? null)) {
            // Простым текстом писали только уроки — и только до появления
            // редактора. У документа такого поля нет вовсе.
            $plain = $material instanceof Lesson ? trim((string) $material->content) : '';

            return $plain === '' ? [] : ['' => $plain];
        }

        $blocks = [];

        foreach ($document['content'] as $block) {
            if (! is_array($block)) {
                continue;
            }

            $id = $block['attrs'][BlockIdentifier::ATTRIBUTE] ?? null;
            $text = trim($this->richText->toPlainText($block));

            if (! is_string($id) || $id === '' || $text === '') {
                continue;
            }

            $blocks[$id] = $text;
        }

        return $blocks;
    }
}
