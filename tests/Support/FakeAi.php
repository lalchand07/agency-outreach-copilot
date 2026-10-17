<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Ai\AiFactory;
use PhpAiGuard\AiGuard;
use PhpAiGuard\Http\HttpRequest;
use PhpAiGuard\Http\HttpResponse;
use PhpAiGuard\Http\MockHttpClient;

/**
 * A php-ai-guard instance whose HTTP layer is php-ai-guard's own MockHttpClient,
 * answering like the Anthropic Messages API. No keys, no network.
 */
final class FakeAi
{
    public MockHttpClient $http;
    public AiGuard $ai;

    public function __construct()
    {
        $this->http = new MockHttpClient();
        $this->ai = AiFactory::forTesting($this->http);
    }

    /** Queues one reply whose text is $data JSON-encoded (or $data itself when a string). */
    public function reply(mixed $data): self
    {
        $text = is_string($data) ? $data : (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->http->queue(static function (HttpRequest $request) use ($text): HttpResponse {
            $body = json_decode($request->body, true);

            return new HttpResponse(200, (string) json_encode([
                'id' => 'msg_test',
                'type' => 'message',
                'role' => 'assistant',
                'model' => is_array($body) ? ($body['model'] ?? 'test') : 'test',
                'content' => [['type' => 'text', 'text' => $text]],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 100, 'output_tokens' => 50],
            ]));
        });

        return $this;
    }

    /** The prompt text (user message) of request $i. */
    public function prompt(int $i): string
    {
        $body = json_decode($this->http->requests[$i]->body, true);

        return is_array($body) ? (string) ($body['messages'][0]['content'] ?? '') : '';
    }

    /** @return array<mixed> */
    public function requestBody(int $i): array
    {
        $body = json_decode($this->http->requests[$i]->body, true);

        return is_array($body) ? $body : [];
    }
}
