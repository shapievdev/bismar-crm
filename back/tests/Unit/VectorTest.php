<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Ai\Vector;
use PHPUnit\Framework\TestCase;

/**
 * Нормирование векторов смысла и сравнение их между собой.
 *
 * Векторы приводятся к единичной длине по дороге в базу, поэтому близость — это
 * скалярное произведение, а расстояния по косинусу и по скалярному произведению
 * упорядочивают одинаково. От этого зависит, что пороги в config/ai.php и мера,
 * по которой построен индекс, говорят об одном и том же. Ошибка здесь не падает,
 * а тихо портит порядок выдачи, поэтому проверяется арифметика, а не формат.
 */
final class VectorTest extends TestCase
{
    public function test_a_vector_is_normalised_on_its_way_to_the_database(): void
    {
        // Длина исходного вектора — 5.
        $this->assertSame('[0.6,0,0.8]', Vector::literal([3.0, 0.0, 4.0]));
    }

    /** Запись понятна расширению как есть: `[a,b,c]`, без пробелов и кавычек. */
    public function test_the_literal_is_written_the_way_pgvector_reads_it(): void
    {
        $this->assertMatchesRegularExpression(
            '/^\[-?[\d.E-]+(,-?[\d.E-]+)*\]$/',
            Vector::literal([1.0, -2.0, 3.0]),
        );
    }

    public function test_the_same_meaning_scores_one(): void
    {
        $vector = Vector::normalised([1.0, 2.0, 3.0]);

        $this->assertEqualsWithDelta(1.0, Vector::similarity($vector, $vector), 0.0001);
    }

    public function test_unrelated_directions_score_zero(): void
    {
        $a = Vector::normalised([1.0, 0.0]);
        $b = Vector::normalised([0.0, 1.0]);

        $this->assertEqualsWithDelta(0.0, Vector::similarity($a, $b), 0.0001);
    }

    public function test_a_closer_meaning_scores_higher(): void
    {
        $asked = Vector::normalised([1.0, 1.0, 0.0]);
        $near = Vector::normalised([1.0, 0.9, 0.1]);
        $far = Vector::normalised([0.0, 0.1, 1.0]);

        $this->assertGreaterThan(Vector::similarity($asked, $far), Vector::similarity($asked, $near));
    }

    /** Пустой вектор не должен ронять ни запись, ни сравнение. */
    public function test_a_missing_vector_is_survivable(): void
    {
        $this->assertSame([], Vector::normalised([]));
        $this->assertSame('[]', Vector::literal([]));
        $this->assertSame(0.0, Vector::similarity([], [1.0, 2.0]));
    }

    /** Нулевой вектор не делится на свою длину: деления на ноль здесь нет. */
    public function test_a_zero_vector_is_left_alone(): void
    {
        $this->assertSame([0.0, 0.0], Vector::normalised([0.0, 0.0]));
    }
}
