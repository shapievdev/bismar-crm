<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Сколько человек с нами — одним словом.
 *
 * Считается на лету из даты приёма, а не хранится и не пересчитывается по
 * ночам. Хранимый тег живёт до следующего пересчёта и ровно столько же врёт:
 * человек, у которого сегодня третий месяц, вчера вечером стал «в команде», а
 * в базе до утра значился новичком. Вычисленное не устаревает никогда и стоит
 * миллисекунду.
 *
 * Границы — по спецификации кадровой службы (2026-09-15). Они смыкаются без
 * зазоров и без нахлёста: месяц считается тридцатью днями, чтобы «1–3 месяца»
 * и «0–30 дней» не спорили за тридцать первый.
 */
enum TenureTag: string
{
    case Trainee = 'trainee';

    case Newcomer = 'newcomer';

    case Settled = 'settled';

    case Seasoned = 'seasoned';

    case Veteran = 'veteran';

    public function label(): string
    {
        return match ($this) {
            self::Trainee => 'Стажёр',
            self::Newcomer => 'Новичок',
            self::Settled => 'В команде',
            self::Seasoned => 'Опытный',
            self::Veteran => 'Старожил',
        };
    }

    /** Чем подписана группа в распределении по стажу. */
    public function range(): string
    {
        return match ($this) {
            self::Trainee => 'до 30 дней или пока не сдана аттестация',
            self::Newcomer => '1–3 месяца',
            self::Settled => '3–12 месяцев',
            self::Seasoned => '1–3 года',
            self::Veteran => 'больше 3 лет',
        };
    }

    /**
     * Тег по числу отработанных дней.
     *
     * Стажёрство здесь только по сроку: вторая его половина — непройденная
     * аттестация — живёт в App\Support\Staff\StaffTags, потому что зависит не
     * от человека, а от того, что ему назначено.
     */
    public static function byDays(int $days): self
    {
        return match (true) {
            $days <= 30 => self::Trainee,
            $days <= 90 => self::Newcomer,
            $days <= 365 => self::Settled,
            $days <= 365 * 3 => self::Seasoned,
            default => self::Veteran,
        };
    }

    /**
     * @return list<self>
     */
    public static function ordered(): array
    {
        return [self::Trainee, self::Newcomer, self::Settled, self::Seasoned, self::Veteran];
    }
}
