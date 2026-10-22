<?php

declare(strict_types=1);

namespace Tests\Unit\Sales;

use App\Models\Department;
use App\Models\User;
use App\Support\Sales\NameMatcher;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;

/**
 * Кого подсказать за именем из витрины.
 *
 * Проверяется подсказка, а не сопоставление: связь заводит человек, и всё, что
 * здесь решается, — чьё имя ему предложат первым. Но предлагать надо верно:
 * подсказку принимают пачкой на две сотни строк, и ошибившаяся однажды
 * разойдётся по всему отчёту.
 *
 * Без базы: матчер — чистая функция от двух списков, и поднимать ради неё
 * Postgres незачем.
 */
final class NameMatcherTest extends TestCase
{
    /**
     * В 1С пишут с приставкой отдела и полным отчеством, у нас — коротко.
     */
    public function test_a_person_is_found_by_surname_and_name_together(): void
    {
        $matcher = $this->matcher(
            people: [$this->person(7, 'Агабекова', 'Света')],
            departments: [],
        );

        $this->assertSame(7, $matcher->suggest('ЛКМ Агабекова Света Шагабудиновна')['user_id']);
    }

    /**
     * Одной фамилии мало.
     *
     * Гаджиевых в компании четверо, и подсказка по фамилии предлагала бы не
     * того с уверенным видом — а принимают её не глядя.
     */
    public function test_a_surname_alone_is_not_enough(): void
    {
        $matcher = $this->matcher(
            people: [$this->person(7, 'Гаджиев', 'Расул')],
            departments: [],
        );

        $this->assertNull($matcher->suggest('КОРП Гаджиев Саид Микаилович')['user_id']);
    }

    /** «ё» и регистр в именах пишут как придётся. */
    public function test_case_and_yo_do_not_break_the_match(): void
    {
        $matcher = $this->matcher(
            people: [$this->person(3, 'Алёшин', 'Пётр')],
            departments: [],
        );

        $this->assertSame(3, $matcher->suggest('КРЕП АЛЕШИН ПЕТР ИВАНОВИЧ')['user_id']);
    }

    /**
     * Приставка сходится с отделом по общему началу, а не по вхождению.
     *
     * «Вода» не является началом «Водоснабжения» — они расходятся на четвёртой
     * букве, — но это та самая приставка, и подсказать её надо.
     */
    public function test_a_prefix_finds_the_department_by_a_shared_beginning(): void
    {
        $matcher = $this->matcher(
            people: [],
            departments: [
                $this->department(11, 'Водоснабжение'),
                $this->department(12, 'Электрика'),
                $this->department(13, 'Корпоративный отдел'),
            ],
        );

        $this->assertSame(11, $matcher->suggest('Вода Магомедов Хабиб')['department_id']);
        $this->assertSame(12, $matcher->suggest('ЭЛЕК Шейхова Асият')['department_id']);
        $this->assertSame(13, $matcher->suggest('КОРП Касса')['department_id']);
    }

    /**
     * Две общие буквы из пяти — не приставка, а совпадение.
     */
    public function test_a_chance_overlap_is_not_a_prefix(): void
    {
        $matcher = $this->matcher(
            people: [],
            departments: [$this->department(21, 'Категорийный менеджмент')],
        );

        $this->assertNull($matcher->suggest('Касса 2')['department_id']);
    }

    /** Сокращение из двух букв не угадывается ничем и остаётся человеку. */
    public function test_a_two_letter_prefix_is_left_to_a_human(): void
    {
        $matcher = $this->matcher(
            people: [],
            departments: [$this->department(31, 'Эл/инс + Сад')],
        );

        $this->assertNull($matcher->suggest('ЭИ Джигараев Магомед')['department_id']);
    }

    /**
     * Отдел человека из структуры сильнее приставки.
     *
     * Приставку в 1С ставят по товарному направлению, а человек может вести
     * два; структура же говорит, где он числится на самом деле.
     */
    public function test_the_structure_wins_over_the_prefix(): void
    {
        $departments = [$this->department(11, 'Водоснабжение'), $this->department(12, 'Крепеж')];

        $person = $this->person(5, 'Магомедов', 'Хабиб');
        $person->setRelation('departments', new Collection([$departments[1]]));

        $matcher = $this->matcher(people: [$person], departments: $departments);

        $this->assertSame(12, $matcher->suggest('Вода Магомедов Хабиб')['department_id']);
    }

    /**
     * Из нескольких отделов человека берётся нижний.
     *
     * Руководитель направления стоит и в шапке департамента, и во главе своего
     * отдела; продаёт он во втором, и выручка, отнесённая на департамент,
     * растворилась бы среди соседей.
     */
    public function test_the_lowest_department_of_a_person_is_taken(): void
    {
        $head = $this->department(1, 'Гипермаркет');
        $leaf = $this->department(2, 'ЛКМ', parentId: 1);

        $person = $this->person(9, 'Агабекова', 'Света');
        $person->setRelation('departments', new Collection([$head, $leaf]));

        $matcher = $this->matcher(people: [$person], departments: [$head, $leaf]);

        $this->assertSame(2, $matcher->suggest('ЛКМ Агабекова Света')['department_id']);
    }

    /**
     * @param  list<User>  $people
     * @param  list<Department>  $departments
     */
    private function matcher(array $people, array $departments): NameMatcher
    {
        return new NameMatcher(new Collection($people), new Collection($departments));
    }

    private function person(int $id, string $last, string $first): User
    {
        $user = new User(['last_name' => $last, 'first_name' => $first]);
        $user->id = $id;
        $user->setRelation('departments', new Collection());

        return $user;
    }

    private function department(int $id, string $name, ?int $parentId = null): Department
    {
        $department = new Department(['name' => $name, 'parent_id' => $parentId]);
        $department->id = $id;
        $department->parent_id = $parentId;

        return $department;
    }
}
