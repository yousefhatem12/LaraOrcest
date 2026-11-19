<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\Contracts;

interface LlmDriverContract
{
    /**
     * Unique identifier for the LLM driver (e.g. 'openai', 'anthropic', 'fake').
     */
    public function getName(): string;

    /**
     * Sends a chat completion request.
     *
     * @param  array<int, array{role: string, content: string}>  $messages
     * @param  array<string, mixed>  $options
     * @return array{content: string, usage: array{prompt_tokens: int, completion_tokens: int, total_tokens: int}}
     */
    public function chat(array $messages, array $options = []): array;

    /**
     * Sends a chat request expecting structured JSON output conforming to a schema.
     *
     * @param  array<int, array{role: string, content: string}>  $messages
     * @param  array<string, mixed>  $schema
     * @param  array<string, mixed>  $options
     * @return array{data: array<string, mixed>, raw: string, usage: array{prompt_tokens: int, completion_tokens: int, total_tokens: int}}
     */
    public function chatStructured(array $messages, array $schema, array $options = []): array;
}
