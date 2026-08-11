<?php

declare(strict_types=1);

namespace App\Support\Ai;

use App\Enums\ConsultantOutcome;
use App\Models\ConsultantQuestion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Разговор сотрудника с консультантом — то, что тот помнит из прошлых вопросов.
 *
 * Без памяти каждый вопрос читался как первый: «а сколько это сохнет?» после
 * разговора о краске не значило ничего ни для поиска, ни для модели. Разговор
 * снимает это дважды — им дополняется вопрос перед поиском (см. RestateQuestion)
 * и он же уходит модели, чтобы ответ продолжал начатое.
 *
 * Читается из журнала, а не из отдельной таблицы: он и так хранит каждый
 * заданный вопрос с ответом, и вторая запись того же самого означала бы две
 * правды об одном разговоре. Оттуда же берутся и правила видимости — очищенное
 * сотрудником не помнится, закрытое с тех пор не всплывает.
 */
final readonly class Conversation
{
    /**
     * @param  list<Exchange>  $exchanges  от старого к новому
     */
    private function __construct(public array $exchanges = []) {}

    public static function empty(): self
    {
        return new self;
    }

    /**
     * Последние круги разговора этого сотрудника — и только его.
     *
     * Своя переписка у каждого: вопросы одного не должны достраивать вопросы
     * другого, даже если они сидят в одном отделе и спрашивают об одном.
     */
    public static function of(User $reader, int $turns): self
    {
        if ($turns < 1) {
            return self::empty();
        }

        try {
            // Внутри транзакции ради отката, а не ради целостности: у Postgres
            // сорвавшийся запрос обрывает всю транзакцию, и дальше в ней
            // отказывает любой следующий — вплоть до тех, которыми собирается
            // ответ. Точка отката оставляет неудачное чтение при себе.
            return new self(DB::transaction(fn (): array => self::read($reader, $turns)));
        } catch (Throwable $exception) {
            // Память — вспомогательная вещь, ровно как и сам журнал, из
            // которого она читается: разговор без памяти хуже, но он есть, а
            // отказ на весь ответ из-за неё стоил бы сотруднику ответа.
            Log::warning('Разговор не прочитан, отвечаем без памяти.', ['exception' => $exception]);

            return self::empty();
        }
    }

    /**
     * @return list<Exchange>
     */
    private static function read(User $reader, int $turns): array
    {
        $rows = ConsultantQuestion::query()
            ->where('user_id', $reader->getKey())
            // Сбои модели пропускаются: на месте ответа там лежит сообщение
            // поставщика, и подавать его модели как её собственные слова
            // значит учить её отвечать сообщениями об ошибках.
            ->where('outcome', '!=', ConsultantOutcome::Failed->value)
            // Очистив переписку, сотрудник ждёт разговора с чистого листа, а не
            // продолжения того, что он только что убрал с глаз.
            ->whereNull('hidden_at')
            // Материал могли закрыть после того, как ответ был дан: пересказ
            // вчерашнего ответа выдал бы его так же верно, как сам курс.
            ->drawnFromWhatIsOpenTo($reader)
            // По ключу вслед за временем: два вопроса, заданных в одну секунду,
            // время не различает, и порядок их выбора остаётся на усмотрение
            // базы — разговор при этом собирается задом наперёд.
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($turns)
            ->get(['question', 'answer']);

        return $rows
            // Свежие выбираем, старые показываем: модель читает разговор
            // сверху вниз, как и человек.
            ->reverse()
            ->map(static fn (ConsultantQuestion $row): Exchange => Exchange::of(
                (string) $row->question,
                (string) $row->answer,
            ))
            ->filter(static fn (Exchange $exchange): bool => $exchange->isComplete())
            ->values()
            ->all();
    }

    public function isEmpty(): bool
    {
        return $this->exchanges === [];
    }

    /** Последние круги разговора — столько, сколько нужно вызывающему. */
    public function latest(int $count): self
    {
        return new self(array_slice($this->exchanges, -max($count, 0)));
    }

    /**
     * Разговор в том виде, в каком его принимает модель.
     *
     * Настоящими репликами, а не пересказом в одном сообщении: роли и заведены
     * ради того, чтобы модель отличала свои прошлые слова от чужих.
     *
     * @return list<array<string, string>>
     */
    public function messages(): array
    {
        $messages = [];

        foreach ($this->exchanges as $exchange) {
            $messages[] = ['role' => 'user', 'content' => $exchange->question];
            $messages[] = ['role' => 'assistant', 'content' => $exchange->answer];
        }

        return $messages;
    }

    /**
     * Разговор одним куском текста — для служебных запросов к модели.
     *
     * Там, где от модели нужна одна строка, а не ответ сотруднику, ролями
     * платить незачем: перепиской в теле одного сообщения обходится дешевле.
     */
    public function transcript(): string
    {
        return implode("\n", array_map(
            static fn (Exchange $exchange): string => "Сотрудник: {$exchange->question}\nТы: {$exchange->answer}",
            $this->exchanges,
        ));
    }
}
