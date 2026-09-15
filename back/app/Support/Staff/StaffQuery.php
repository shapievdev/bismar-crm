<?php

declare(strict_types=1);

namespace App\Support\Staff;

use App\Models\User;
use Illuminate\Http\Request;

/**
 * Разбор среза, пришедшего от экрана, — в одном месте на всех.
 *
 * Отчёт, список людей, выгрузка и внешняя ссылка спрашивают одно и то же и
 * обязаны понимать вопрос одинаково: разъедутся правила — и выгрузка покажет
 * не то, что человек видел на экране, а внешняя ссылка отдаст чужое
 * подразделение.
 *
 * Здесь же срез процеживается правами. Это не украшение: фильтр приходит от
 * экрана, а экран — от человека, и без сужения директор посмотрел бы соседнее
 * направление, просто подставив его номер в адрес.
 */
final readonly class StaffQuery
{
    public function __construct(private StaffScope $scope) {}

    public function from(Request $request, User $viewer): StaffFilters
    {
        return $this->fromInput($request->validate(self::rules()), $viewer);
    }

    /**
     * Срез из готового массива — для ссылки, у которой он лежит в базе.
     *
     * @param  array<string, mixed>  $input
     */
    public function fromInput(array $input, User $viewer): StaffFilters
    {
        /** @var list<int> $wanted */
        $wanted = array_map(intval(...), (array) ($input['departments'] ?? []));

        return StaffFilters::fromInput($input, $this->scope->narrow($viewer, $wanted));
    }

    /**
     * Срез внешней ссылки: уже суженный тем, кто её выдал, и потому берётся
     * как есть.
     *
     * Второй раз процеживать нечем — открывающий ссылку в системе не значится
     * вовсе, и «его» подразделений не существует.
     *
     * @param  array<string, mixed>  $stored
     */
    public function fromStored(array $stored): StaffFilters
    {
        /** @var list<int>|null $departments */
        $departments = $stored['departments'] ?? null;

        return StaffFilters::fromInput($stored, $departments);
    }

    /**
     * @return array<string, array<int, string>>
     */
    public static function rules(): array
    {
        return [
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'departments' => ['array'],
            'departments.*' => ['integer'],
            'job_title' => ['nullable', 'string', 'max:255'],
            'work_mode' => ['nullable', 'string'],
            'tags' => ['array'],
            'tags.*' => ['integer'],
        ];
    }
}
