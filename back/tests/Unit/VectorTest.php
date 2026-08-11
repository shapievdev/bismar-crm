<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Ai\Vector;
use PHPUnit\Framework\TestCase;

/**
 * Упаковка и сравнение векторов смысла.
 *
 * Векторы нормируются при записи, поэтому близость — это скалярное
 * произведение. Ошибка здесь не падает, а тихо портит порядок выдачи, поэтому
 * проверяется арифметика, а не только формат.
 */
final class VectorTest extends TestCase
{
    public function test_a_vector_survives_the_round_trip(): void
    {
        $unpacked = Vector::unpack(Vector::pack([3.0, 0.0, 4.0]));

        // Нормировано: длина исходного вектора 5.
        $this->assertEqualsWithDelta([0.6, 0.0, 0.8], $unpacked, 0.0001);
    }

    public function test_the_same_meaning_scores_one(): void
    {
        $vector = Vector::unpack(Vector::pack([1.0, 2.0, 3.0]));

        $this->assertEqualsWithDelta(1.0, Vector::similarity($vector, $vector), 0.0001);
    }

    public function test_unrelated_directions_score_zero(): void
    {
        $a = Vector::unpack(Vector::pack([1.0, 0.0]));
        $b = Vector::unpack(Vector::pack([0.0, 1.0]));

        $this->assertEqualsWithDelta(0.0, Vector::similarity($a, $b), 0.0001);
    }

    public function test_a_closer_meaning_scores_higher(): void
    {
        $asked = Vector::unpack(Vector::pack([1.0, 1.0, 0.0]));
        $near = Vector::unpack(Vector::pack([1.0, 0.9, 0.1]));
        $far = Vector::unpack(Vector::pack([0.0, 0.1, 1.0]));

        $this->assertGreaterThan(Vector::similarity($asked, $far), Vector::similarity($asked, $near));
    }

    /** Пустой или испорченный вектор не должен ронять поиск. */
    public function test_a_missing_vector_is_survivable(): void
    {
        $this->assertSame([], Vector::unpack(null));
        $this->assertSame([], Vector::unpack(''));
        $this->assertSame(0.0, Vector::similarity([], [1.0, 2.0]));
    }
}
