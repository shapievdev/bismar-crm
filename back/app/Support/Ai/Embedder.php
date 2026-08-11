<?php

declare(strict_types=1);

namespace App\Support\Ai;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Превращает текст в вектор смысла.
 *
 * Через обычный HTTP, а не через SDK: у Anthropic эндпоинта эмбеддингов нет
 * вовсе, а туннели и прокси перед ней отдают привычный OpenAI-совместимый
 * /v1/embeddings. Адрес и ключ берутся оттуда же, откуда их берёт консультант,
 * поэтому настраивать дважды не приходится.
 */
final readonly class Embedder
{
    /**
     * Сколько чисел в векторе.
     *
     * Модель умеет отдавать 1536, но принимает и меньше: качество ранжирования
     * на 512 падает незначительно, а хранилище и время сравнения — втрое.
     */
    public const DIMENSIONS = 512;

    /** Сколько текстов уходит одним запросом. */
    private const BATCH = 64;

    public function __construct(private ModelSettings $settings) {}

    public function isAvailable(): bool
    {
        return $this->settings->isConfigured() && $this->settings->embeddingModel() !== null;
    }

    /**
     * @param  list<string>  $texts
     * @return list<list<float>>
     */
    public function embed(array $texts): array
    {
        if ($texts === []) {
            return [];
        }

        $vectors = [];

        foreach (array_chunk($texts, self::BATCH) as $chunk) {
            $vectors = [...$vectors, ...$this->request($chunk)];
        }

        return $vectors;
    }

    /**
     * @param  list<string>  $texts
     * @return list<list<float>>
     */
    private function request(array $texts): array
    {
        $key = (string) $this->settings->key();
        $header = $this->settings->authScheme()->value === 'bearer'
            ? ['Authorization' => 'Bearer '.$key]
            : ['X-Api-Key' => $key];

        try {
            $response = Http::withHeaders($header)
                ->timeout(30)
                ->post($this->endpoint(), [
                    'model' => $this->settings->embeddingModel(),
                    'input' => $texts,
                    'dimensions' => self::DIMENSIONS,
                ]);
        } catch (ConnectionException $exception) {
            throw new RuntimeException('Сервис эмбеддингов недоступен: '.$exception->getMessage(), previous: $exception);
        }

        if ($response->failed()) {
            throw new RuntimeException(sprintf(
                'Сервис эмбеддингов ответил %d: %s',
                $response->status(),
                mb_substr((string) $response->body(), 0, 200),
            ));
        }

        /** @var list<array{embedding: list<float>}> $data */
        $data = $response->json('data') ?? [];

        if (count($data) !== count($texts)) {
            throw new RuntimeException('Сервис эмбеддингов вернул не столько векторов, сколько просили.');
        }

        return array_map(static fn (array $row): array => $row['embedding'], $data);
    }

    private function endpoint(): string
    {
        return ($this->settings->baseUrl() ?? 'https://api.openai.com').'/v1/embeddings';
    }
}
