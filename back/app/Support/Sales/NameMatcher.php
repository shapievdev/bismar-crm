<?php

declare(strict_types=1);

namespace App\Support\Sales;

use App\Models\Department;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Кого и какой отдел предложить за именем из витрины.
 *
 * Предлагает, а не решает. Сопоставление — ответственная связь: по ней выручка
 * расходится по отделам и по ней же потом спросят за план. Угаданное молча
 * однажды окажется неверным, и никто не узнает, в какой момент. Поэтому
 * подсказка приезжает отдельным полем, а строка заводится только тем, кто
 * нажал.
 *
 * Угадывать есть по чему. В 1С продавца пишут с приставкой отдела и полным
 * отчеством — «ЛКМ Агабекова Света Шагабудиновна», — а в базе знаний тот же
 * человек заведён как «Агабекова Света». Отсюда два независимых правила:
 * человек ищется по фамилии и имени, отдел — по приставке.
 */
final readonly class NameMatcher
{
    /**
     * @param  Collection<int, User>  $people
     * @param  Collection<int, Department>  $departments
     */
    public function __construct(
        private Collection $people,
        private Collection $departments,
    ) {}

    /**
     * Что предложить за этим именем.
     *
     * @return array{user_id: int|null, department_id: int|null}
     */
    public function suggest(string $externalName): array
    {
        $tokens = self::tokens($externalName);

        $user = $this->findPerson($tokens);

        return [
            'user_id' => $user?->id,
            // Отдел человека из структуры вернее приставки: приставку в 1С
            // ставят по товарному направлению, а человек может вести два.
            // Приставка остаётся для тех, за кем человека нет, — для касс.
            'department_id' => $this->departmentOf($user) ?? $this->findDepartment($tokens),
        ];
    }

    /**
     * Человек, чьи фамилия и имя целиком стоят в имени из витрины.
     *
     * Оба сразу, а не фамилия отдельно: Гаджиевых в компании четверо, и
     * подсказка по одной фамилии предлагала бы не того с уверенным видом.
     *
     * @param  list<string>  $tokens
     */
    private function findPerson(array $tokens): ?User
    {
        foreach ($this->people as $person) {
            $last = self::normalise((string) $person->last_name);
            $first = self::normalise((string) $person->first_name);

            if ($last === '' || $first === '') {
                continue;
            }

            if (in_array($last, $tokens, true) && in_array($first, $tokens, true)) {
                return $person;
            }
        }

        return null;
    }

    /**
     * Отдел, с чьим именем сходится приставка.
     *
     * «ЛКМ Агабекова» → «ЛКМ», «ХОЗ Баширова» → «Хоз+сантех», «Вода Магомедов»
     * → «Водоснабжение», «КОРП Касса» → «Корпоративный отдел».
     *
     * Сравнивается не вхождение, а общее начало: «Вода» не является началом
     * «Водоснабжения» — на четвёртой букве они расходятся, — но первыми тремя
     * совпадает, и это то самое сокращение. Отсюда два условия сразу: общих
     * букв хотя бы три и они составляют большую часть приставки. Второе и
     * отсекает ложное: у «Кассы» с «Категорийным менеджментом» общих две буквы
     * из пяти.
     *
     * Сокращения вроде «ЭИ» не угадываются ничем и остаются человеку — и это
     * правильно: «ЭИ» с равным успехом «Эл/инс» и «Электроинструмент».
     *
     * @param  list<string>  $tokens
     */
    private function findDepartment(array $tokens): ?int
    {
        $prefix = $tokens[0] ?? '';
        $length = mb_strlen($prefix);

        if ($length < 3) {
            return null;
        }

        foreach ($this->departments as $department) {
            $shared = self::sharedPrefix($prefix, self::normalise((string) $department->name));

            if ($shared >= 3 && $shared / $length >= 0.6) {
                return $department->id;
            }
        }

        return null;
    }

    /** Сколько букв в начале у двух строк общие. */
    private static function sharedPrefix(string $left, string $right): int
    {
        $limit = min(mb_strlen($left), mb_strlen($right));
        $shared = 0;

        while ($shared < $limit && mb_substr($left, $shared, 1) === mb_substr($right, $shared, 1)) {
            $shared++;
        }

        return $shared;
    }

    /**
     * Отдел человека из структуры — самый нижний из тех, где он числится.
     *
     * Отделов у человека бывает несколько: руководитель направления стоит и в
     * шапке департамента, и во главе своего отдела. Продаёт он во втором, и
     * взятый наугад первый отнёс бы его выручку на весь департамент.
     */
    private function departmentOf(?User $user): ?int
    {
        if ($user === null) {
            return null;
        }

        $parents = $this->departments->pluck('parent_id')->filter()->unique()->all();

        /** @var Department|null $leaf */
        $leaf = $user->departments->first(
            static fn (Department $department): bool => ! in_array($department->id, $parents, true),
        );

        return ($leaf ?? $user->departments->first())?->id;
    }

    /**
     * Имя, разобранное на сравнимые куски.
     *
     * @return list<string>
     */
    private static function tokens(string $name): array
    {
        return array_values(array_filter(
            array_map(self::normalise(...), preg_split('/\s+/u', $name) ?: []),
            static fn (string $token): bool => $token !== '',
        ));
    }

    /**
     * Строка в сравнимом виде: регистр и «ё» в именах пишут как придётся.
     */
    private static function normalise(string $value): string
    {
        return str_replace('ё', 'е', mb_strtolower(trim($value)));
    }
}
