<?php

declare(strict_types=1);

namespace Cms\Webhooks;

/** Posts a delivery. The real one is CurlSender; tests bind a fake. */
interface Sender
{
    /**
     * @param  list<string>  $headers  "Name: value"
     * @return array{status: int, body: string}  the answer, whatever its status
     *
     * @throws \RuntimeException when the address is refused or nothing answered
     */
    public function send(string $url, string $body, array $headers): array;
}
