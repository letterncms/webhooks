<?php

declare(strict_types=1);

namespace Cms\Webhooks\Tests;

use Cms\Webhooks\Sender;
use RuntimeException;

/** Stands in for the network: remembers what was sent, answers what the test says. */
final class FakeSender implements Sender
{
    /** @var list<array{url: string, body: string, headers: array<string, string>}> */
    public array $sent = [];

    /** @var list<int|string> statuses to answer in turn (a string throws it); the last one repeats */
    public array $answers = [200];

    public function send(string $url, string $body, array $headers): array
    {
        $named = [];

        foreach ($headers as $header) {
            [$name, $value] = explode(': ', $header, 2);
            $named[$name] = $value;
        }

        $this->sent[] = ['url' => $url, 'body' => $body, 'headers' => $named];
        $answer = count($this->answers) > 1 ? array_shift($this->answers) : $this->answers[0];

        if (is_string($answer)) {
            throw new RuntimeException($answer);
        }

        return ['status' => $answer, 'body' => 'answer '.$answer];
    }
}
