<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Векторы становятся типом базы, а не строкой, которую разбирает приложение.
 *
 * До сих пор вектор лежал колонкой text — base64 от последовательности float32,
 * — и сравнивать его умело только приложение. Из этого следовало всё остальное:
 * поиск по таблицам уроков вычитывал каждую опубликованную строку с обоими
 * векторами на каждый вопрос, а поиск по расшифровкам вообще не искал смыслом —
 * он лишь пересортировывал сто двадцать кусков, отобранных словами. Материал,
 * названный не теми словами, что вопрос, в эти сто двадцать не попадал, и найти
 * его было нечем.
 *
 * Обе беды — одна: близость считалась не там, где лежат данные. Расширение
 * vector считает её в базе и берёт для этого индекс, поэтому «ближайшие к
 * вопросу» перестаёт означать «прочитать всё».
 *
 * Индексы — HNSW по косинусу. Векторы нормируются при записи, поэтому косинус,
 * скалярное произведение и евклидово расстояние здесь упорядочивают одинаково;
 * косинус выбран потому, что в этой же мере выражены все пороги в config/ai.php,
 * и читающему запрос не приходится держать в голове перевод шкал.
 *
 * Размерность записана числом, а не константой Embedder::DIMENSIONS. Миграция
 * описывает базу такой, какой она стала в этот день; смена размерности — это
 * другая миграция и полный пересчёт векторов, а не тихое расхождение колонки с
 * тем, что в неё пишут.
 *
 * Требует расширения на сервере: `CREATE EXTENSION` ставит его в базу, но файлы
 * должны там уже лежать (Debian/Ubuntu — пакет postgresql-16-pgvector). Без них
 * миграция падает здесь и сразу, не оставив базу наполовину переделанной.
 */
return new class extends Migration
{
    private const DIMENSIONS = 512;

    /** @var array<string, list<string>> */
    private const COLUMNS = [
        'transcript_segments' => ['embedding'],
        'lesson_answers' => ['question_embedding', 'answer_embedding'],
    ];

    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS vector');

        foreach (self::COLUMNS as $table => $columns) {
            $this->toVectors($table, $columns);
        }

        foreach (self::COLUMNS as $table => $columns) {
            foreach ($columns as $column) {
                DB::statement(sprintf(
                    'CREATE INDEX %s_%s_idx ON %s USING hnsw (%s vector_cosine_ops)',
                    $table, $column, $table, $column,
                ));
            }
        }
    }

    public function down(): void
    {
        foreach (self::COLUMNS as $table => $columns) {
            foreach ($columns as $column) {
                DB::statement(sprintf('DROP INDEX IF EXISTS %s_%s_idx', $table, $column));
            }

            $this->toBase64($table, $columns);
        }

        // Расширение остаётся: его могли завести не только под эти колонки, и
        // снимать чужое основание миграция права не имеет.
    }

    /**
     * @param  list<string>  $columns
     */
    private function toVectors(string $table, array $columns): void
    {
        foreach ($columns as $column) {
            DB::statement(sprintf(
                'ALTER TABLE %s ADD COLUMN %s_as_vector vector(%d)',
                $table, $column, self::DIMENSIONS,
            ));
        }

        $this->carryOver($table, $columns, '_as_vector', 'vector', self::asVector(...));

        foreach ($columns as $column) {
            DB::statement(sprintf('ALTER TABLE %s DROP COLUMN %s', $table, $column));
            DB::statement(sprintf('ALTER TABLE %s RENAME COLUMN %s_as_vector TO %s', $table, $column, $column));
        }
    }

    /**
     * @param  list<string>  $columns
     */
    private function toBase64(string $table, array $columns): void
    {
        foreach ($columns as $column) {
            DB::statement(sprintf('ALTER TABLE %s ADD COLUMN %s_as_text text', $table, $column));
        }

        $this->carryOver($table, $columns, '_as_text', 'text', self::asBase64(...));

        foreach ($columns as $column) {
            DB::statement(sprintf('ALTER TABLE %s DROP COLUMN %s', $table, $column));
            DB::statement(sprintf('ALTER TABLE %s RENAME COLUMN %s_as_text TO %s', $table, $column, $column));
        }
    }

    /**
     * Переливает значения из старых колонок в новые, строку за строкой.
     *
     * Частями и по идентификатору: строк здесь тысячи, но вектор — это две
     * тысячи байт, и вычитывать их все разом незачем.
     *
     * @param  string  $suffix  чем новая колонка отличается по имени от старой
     * @param  string  $cast  тип, к которому приводится переданная строка
     * @param  list<string>  $columns
     * @param  callable(?string): ?string  $convert
     */
    private function carryOver(string $table, array $columns, string $suffix, string $cast, callable $convert): void
    {
        DB::table($table)
            ->select(['id', ...$columns])
            ->orderBy('id')
            ->chunk(500, function (Collection $rows) use ($table, $columns, $suffix, $cast, $convert): void {
                foreach ($rows as $row) {
                    $set = [];
                    $bindings = [];

                    foreach ($columns as $column) {
                        $value = $convert($row->{$column});

                        if ($value === null) {
                            continue;
                        }

                        // Приведение записано в самом запросе: в новую колонку
                        // едет строка, а тип у неё другой, и вывести его из
                        // подстановки Postgres не берётся.
                        $set[] = sprintf('%s%s = ?::%s', $column, $suffix, $cast);
                        $bindings[] = $value;
                    }

                    if ($set === []) {
                        continue;
                    }

                    $bindings[] = $row->id;

                    DB::update(
                        sprintf('UPDATE %s SET %s WHERE id = ?', $table, implode(', ', $set)),
                        $bindings,
                    );
                }
            });
    }

    /** base64 от float32 — в текстовую запись вектора, понятную расширению. */
    private static function asVector(?string $packed): ?string
    {
        if ($packed === null || $packed === '') {
            return null;
        }

        $binary = base64_decode($packed, strict: true);

        if ($binary === false) {
            return null;
        }

        $values = array_values(unpack('g*', $binary) ?: []);

        return $values === [] ? null : '['.implode(',', $values).']';
    }

    /** Обратно: текстовая запись вектора — в base64 от float32. */
    private static function asBase64(?string $literal): ?string
    {
        if ($literal === null || $literal === '') {
            return null;
        }

        /** @var list<float>|null $values */
        $values = json_decode($literal, associative: true);

        return is_array($values) && $values !== []
            ? base64_encode(pack('g*', ...$values))
            : null;
    }
};
