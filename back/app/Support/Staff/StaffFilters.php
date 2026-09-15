<?php

declare(strict_types=1);

namespace App\Support\Staff;

use App\Enums\WorkMode;
use Carbon\CarbonImmutable;

/**
 * Срез, в котором смотрят движение персонала.
 *
 * Отдельным предметом, а не горстью аргументов: срез ходит через весь отчёт —
 * в сводку, в график, в таблицу по подразделениям, в выгрузку и во внешнюю
 * ссылку, — и каждая из них обязана понимать его одинаково. Шесть параметров,
 * переданных по отдельности, однажды разъедутся порядком.
 *
 * Период обязателен и всегда закрыт с обеих сторон: «текучесть» без периода —
 * бессмыслица, а открытый справа период считал бы будущее.
 */
final readonly class StaffFilters
{
    /**
     * @param  list<int>|null  $departmentIds  null — вся компания; пустой список — ни одного
     * @param  list<int>  $tagIds  ручные теги; человек должен нести все разом
     */
    public function __construct(
        public CarbonImmutable $from,
        public CarbonImmutable $to,
        public ?array $departmentIds = null,
        public ?string $jobTitle = null,
        public ?WorkMode $workMode = null,
        public array $tagIds = [],
    ) {}

    /**
     * Срез из того, что прислал экран.
     *
     * Умолчание — текущий месяц: открывая отчёт без вопроса, человек хочет
     * увидеть, что происходит сейчас.
     *
     * @param  array<string, mixed>  $input
     * @param  list<int>|null  $departmentIds  уже суженные правами — см. StaffScope
     */
    public static function fromInput(array $input, ?array $departmentIds): self
    {
        $from = isset($input['from']) ? CarbonImmutable::parse((string) $input['from']) : CarbonImmutable::now()->startOfMonth();
        $to = isset($input['to']) ? CarbonImmutable::parse((string) $input['to']) : CarbonImmutable::now()->endOfMonth();

        // Перепутанные местами края — обычная опечатка в адресе, и разворот
        // честнее, чем пустой отчёт без объяснения.
        if ($to->lessThan($from)) {
            [$from, $to] = [$to, $from];
        }

        $mode = isset($input['work_mode']) && $input['work_mode'] !== ''
            ? WorkMode::tryFrom((string) $input['work_mode'])
            : null;

        $title = isset($input['job_title']) && trim((string) $input['job_title']) !== ''
            ? trim((string) $input['job_title'])
            : null;

        /** @var list<int> $tags */
        $tags = array_values(array_unique(array_map(intval(...), (array) ($input['tags'] ?? []))));

        return new self(
            from: $from->startOfDay(),
            to: $to->endOfDay(),
            departmentIds: $departmentIds,
            jobTitle: $title,
            workMode: $mode,
            tagIds: $tags,
        );
    }

    /**
     * Срез в том виде, в каком он уходит в журнал выгрузок и во внешнюю ссылку.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'from' => $this->from->toDateString(),
            'to' => $this->to->toDateString(),
            'departments' => $this->departmentIds,
            'job_title' => $this->jobTitle,
            'work_mode' => $this->workMode?->value,
            'tags' => $this->tagIds,
        ];
    }

    /** Сколько дней в периоде — знаменатель среднесписочной. */
    public function days(): int
    {
        return (int) $this->from->startOfDay()->diffInDays($this->to->startOfDay()) + 1;
    }
}
