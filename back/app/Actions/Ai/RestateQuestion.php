<?php

declare(strict_types=1);

namespace App\Actions\Ai;

use Anthropic\Client;
use App\Support\Ai\Conversation;
use App\Support\Ai\ModelSettings;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Приводит вопрос к самостоятельному виду, чтобы по нему можно было искать.
 *
 * «А сколько это сохнет?» — вопрос, понятный человеку, который читал разговор,
 * и пустой для поиска: слов о предмете в нём нет вовсе, и найти по нему нельзя
 * ничего. Пока памяти не было, такие вопросы были обречены: сотрудник получал
 * «в материалах об этом ничего нет» через сообщение после верного ответа.
 *
 * Переписывается только то, по чему ищут. Сотруднику и в журнал идут его
 * собственные слова: подменить вопрос человека догадкой модели значит спрятать
 * от того, кто разбирает журнал, что искали на самом деле.
 */
final readonly class RestateQuestion
{
    /**
     * Сколько кругов разговора хватает, чтобы понять, о чём спрашивают.
     *
     * Не все двадцать: местоимение указывает на сказанное только что, а лишние
     * круги стоят денег на каждом вопросе и уводят к теме, оставленной полчаса
     * назад.
     */
    private const CONTEXT_TURNS = 4;

    /** Длиннее этого модель уже не переписывает вопрос, а сочиняет свой. */
    private const MAX_CHARS = 400;

    private const RULES = <<<'TEXT'
    Ты готовишь вопрос сотрудника к поиску по базе знаний.
    Тебе дают предыдущий разговор и последний вопрос.

    Верни один вопрос, понятный без разговора: вместо «это», «там», «а ещё», «он»
    подставь то, о чём шла речь. Держись слов сотрудника и слов разговора, своих
    сведений не добавляй.

    Вопрос и так понятен сам по себе — верни его слово в слово.

    Ничего не объясняй и не отвечай на вопрос. В ответе — только сам вопрос, одной строкой.

    Пример.
    Разговор:
    Сотрудник: чем разбавляют фасадную краску?
    Ты: Водой, не более 10 % объёма.
    Последний вопрос: а сколько она сохнет?
    Твой ответ: Сколько сохнет фасадная краска?
    TEXT;

    public function __construct(
        private Client $client,
        private ModelSettings $settings,
    ) {}

    /**
     * Самостоятельный вопрос, или null — если переписывать нечего.
     *
     * Null и когда вопрос уже понятен сам по себе, и когда модель не ответила:
     * поиск по словам сотрудника хуже поиска по дополненным словам, но лучше,
     * чем ответ «консультант недоступен» из-за отказа вспомогательного шага.
     */
    public function handle(string $question, Conversation $conversation): ?string
    {
        if ($conversation->isEmpty()) {
            return null;
        }

        $transcript = $conversation->latest(self::CONTEXT_TURNS)->transcript();

        try {
            $message = $this->client->messages->create(
                model: $this->settings->model(),
                maxTokens: (int) config('ai.restate_max_tokens'),
                system: self::RULES,
                messages: [[
                    'role' => 'user',
                    'content' => "Разговор:\n{$transcript}\n\nПоследний вопрос: {$question}",
                ]],
            );
        } catch (Throwable $exception) {
            Log::warning('Вопрос не удалось дополнить разговором, ищем по нему как есть.', [
                'exception' => $exception,
            ]);

            return null;
        }

        $restated = '';

        foreach ($message->content as $block) {
            if ($block->type === 'text') {
                $restated .= $block->text;
            }
        }

        // Первая строка: модель, которую попросили ответить одной строкой, время
        // от времени дописывает вторую с пояснением, зачем она так переписала.
        $restated = trim(strtok(trim($restated), "\n") ?: '');

        if ($restated === '' || mb_strlen($restated) > self::MAX_CHARS) {
            return null;
        }

        // Слово в слово — значит, дополнять было нечего, и записывать в журнал
        // «искали вот так» не о чем.
        return mb_strtolower($restated) === mb_strtolower(trim($question)) ? null : $restated;
    }
}
