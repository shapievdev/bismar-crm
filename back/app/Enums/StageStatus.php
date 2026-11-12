<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Пять состояний этапа на карте — ровно как их видит стажёр.
 *
 * Статус нигде не хранится: он выводится из фактов (дата открытия, прогресс,
 * попытки теста) в одном месте — App\Support\Onboarding\StageRequirements.
 * Хранение означало бы второй источник истины, который однажды разойдётся с
 * фактами — например, у этапа, который досрочно открыли переносами.
 *
 * У каждого статуса есть слова и значок: цвет на карте — украшение, а не
 * единственный носитель смысла.
 */
enum StageStatus: string
{
    /** Ещё не открылся: замок и дата, когда откроется. */
    case Locked = 'locked';

    /** Открыт, но ничего ещё не сделано. */
    case Available = 'available';

    /** Открыт, часть требований закрыта. */
    case InProgress = 'in_progress';

    /** Все требования закрыты. */
    case Completed = 'completed';

    /** Попытки теста кончились, сданной нет — нужна помощь или допуск к пересдаче. */
    case QuizFailed = 'quiz_failed';

    public function label(): string
    {
        return match ($this) {
            self::Locked => 'Закрыт',
            self::Available => 'Доступен',
            self::InProgress => 'В процессе',
            self::Completed => 'Пройден',
            self::QuizFailed => 'Тест не сдан',
        };
    }

    /** Имя значка на карте — рисует фронт. */
    public function icon(): string
    {
        return match ($this) {
            self::Locked => 'lock',
            self::Available => 'dot',
            self::InProgress => 'progress',
            self::Completed => 'check',
            self::QuizFailed => 'retry',
        };
    }
}
