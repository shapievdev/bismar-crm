<?php

declare(strict_types=1);

namespace Tests\Support;

use Http\Discovery\Psr17FactoryDiscovery;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

/**
 * Stands in for the network under the Anthropic SDK.
 *
 * The SDK's Client and MessagesService are both final, so there is no seam at
 * the object level. Substituting the transport is the seam the SDK does offer,
 * and it is the better one anyway: the request is serialised for real, so a
 * test can assert on what would actually go over the wire — snake_case keys,
 * cache breakpoints and all — rather than on the arguments we happened to pass.
 */
final class FakeAnthropicTransport implements ClientInterface
{
    /** @var list<RequestInterface> */
    private array $requests = [];

    /**
     * @param  list<string>  $replies
     */
    private function __construct(
        private readonly array $replies,
        private readonly bool $unreachable,
    ) {}

    public static function replying(string $reply): self
    {
        return new self([$reply], unreachable: false);
    }

    /**
     * A transport that answers each call with the next reply in turn.
     *
     * Нужен там, где на один вопрос сотрудника приходится не один запрос к
     * модели: сперва она достраивает вопрос разговором, и лишь потом отвечает.
     * Реплики кончились — последняя повторяется, как и у `replying`.
     */
    public static function replyingInTurn(string ...$replies): self
    {
        return new self(array_values($replies), unreachable: false);
    }

    /**
     * A transport that cannot reach the API at all — the SDK turns this into an
     * APIConnectionException.
     */
    public static function unreachable(): self
    {
        return new self([''], unreachable: true);
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $reply = $this->replies[count($this->requests)]
            ?? $this->replies[array_key_last($this->replies)]
            ?? '';

        $this->requests[] = $request;

        if ($this->unreachable) {
            throw new class extends RuntimeException implements ClientExceptionInterface {};
        }

        $body = json_encode([
            'id' => 'msg_test',
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-haiku-4-5',
            'content' => [['type' => 'text', 'text' => $reply]],
            'stop_reason' => 'end_turn',
            'stop_sequence' => null,
            'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
        ], JSON_THROW_ON_ERROR);

        return Psr17FactoryDiscovery::findResponseFactory()
            ->createResponse(200)
            ->withHeader('Content-Type', 'application/json')
            ->withBody(Psr17FactoryDiscovery::findStreamFactory()->createStream($body));
    }

    /**
     * The request body as it was sent, decoded.
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $last = $this->requests === [] ? null : $this->requests[array_key_last($this->requests)];

        if ($last === null) {
            throw new RuntimeException('Модель не вызывали.');
        }

        return json_decode((string) $last->getBody(), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * Every request that was sent, in order.
     *
     * @return list<array<string, mixed>>
     */
    public function payloads(): array
    {
        return array_map(
            static fn (RequestInterface $request): array => json_decode(
                (string) $request->getBody(),
                true,
                flags: JSON_THROW_ON_ERROR,
            ),
            $this->requests,
        );
    }

    public function calls(): int
    {
        return count($this->requests);
    }

    public function wasCalled(): bool
    {
        return $this->requests !== [];
    }
}
