<?php

declare(strict_types=1);

namespace App\Support\Onboarding;

use App\Enums\StageStatus;
use Carbon\CarbonImmutable;

/**
 * Состояние одного этапа журнала: требования, прогресс, статус.
 *
 * Собирает StageRequirements; это единственный источник и для карты стажёра,
 * и для автозакрытия, и для HR-экрана — разойтись им нельзя.
 */
final readonly class StageState
{
    public function __construct(
        public int $journeyStageId,
        public StageStatus $status,
        /**
         * Требования по одному: вид, подпись, обязательность, закрыто ли и когда.
         *
         * @var list<array{
         *     kind: string,
         *     key: string,
         *     title: string,
         *     is_required: bool,
         *     done: bool,
         *     done_at: ?CarbonImmutable,
         *     url: ?string,
         *     meta: array<string, mixed>,
         * }>
         */
        public array $items,
        public int $requiredTotal,
        public int $requiredDone,
        /** Сдан ли тест — null, когда теста нет. */
        public ?bool $quizPassed,
        /** Ждёт ли работа вердикта проверяющего. */
        public bool $quizAwaitingReview,
        /** Попытки кончились, сданной нет. */
        public bool $quizFailed,
        /** Самая поздняя дата закрытого требования — ею этап и закрывается. */
        public ?CarbonImmutable $lastDoneAt,
    ) {}

    /**
     * Готов ли этап к автозакрытию: все обязательные требования закрыты.
     *
     * Этап без единого требования — информационный день («поработай в зале
     * рядом с наставником») — закрывается сам в день открытия: требовать с
     * него нечего, а вечно открытый он запирал бы маршрут при строгом порядке.
     * Хочется подтверждения — админ добавляет этапу задание.
     */
    public function isSettled(): bool
    {
        return $this->requiredTotal === 0 || $this->requiredDone >= $this->requiredTotal;
    }

    /** Доля закрытого — для карточки «в процессе, N%». */
    public function progress(): int
    {
        if ($this->requiredTotal === 0) {
            return 0;
        }

        return (int) round($this->requiredDone / $this->requiredTotal * 100);
    }
}
