<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Full-text search over lesson text, for the knowledge-base consultant.
 *
 * An expression index rather than a stored tsvector column: there is nothing to
 * keep in sync on write, and no way for the index to drift from the text it
 * describes. Postgres recomputes the vector only for rows it is indexing.
 *
 * The `russian` configuration is what makes this worth having — it stems, so a
 * question about "возражения" finds a lesson that says "возражением".
 *
 * Title and body are weighted apart. A lesson called "Разбор возражений" is
 * about возражения; one that mentions the word once in passing is not, and
 * without weights the second outranks the first as soon as it is longer. The
 * expression has to match the one the consultant searches with, or Postgres
 * will not use this index at all — both live in KnowledgeBase.
 */
return new class extends Migration
{
    /**
     * Lowercasing goes through an ICU collation because the database was
     * created under C, where Postgres folds ASCII only — "Что" would never be
     * recognised as a stopword and never reach the stemmer. The typographic
     * quotes are dropped for the same reason: under C the parser cannot see
     * them as punctuation and «дорого» becomes a lexeme of its own.
     */
    private const EXPRESSION = <<<'SQL'
        setweight(to_tsvector('russian', lower(regexp_replace(coalesce(title, ''), '[«»„“”‘’‚‛…—–]', ' ', 'g') COLLATE "und-x-icu")), 'A')
        || setweight(to_tsvector('russian', lower(regexp_replace(coalesce(content, ''), '[«»„“”‘’‚‛…—–]', ' ', 'g') COLLATE "und-x-icu")), 'B')
    SQL;

    public function up(): void
    {
        // Doubled parentheses on purpose: `gin (a || b)` reads as a list of
        // indexed columns, and an expression has to be wrapped to be seen as
        // one value.
        DB::statement(sprintf(
            'CREATE INDEX lessons_search_idx ON lessons USING gin ((%s))',
            self::EXPRESSION,
        ));
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS lessons_search_idx');
    }
};
