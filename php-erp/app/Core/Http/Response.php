<?php
declare(strict_types=1);

namespace Erp\Core\Http;

final class Response
{
    /** @param array<string, mixed> $payload */
    private function __construct(
        private readonly array $payload,
        private readonly int $status,
        private readonly array $headers = [],
        private readonly ?string $rawBody = null,
        private readonly string $contentType = 'application/json; charset=utf-8',
        private readonly mixed $streamBody = null,
    ) {
    }

    /** @param array<string, mixed> $payload */
    public static function json(array $payload, int $status = 200, array $headers = []): self
    {
        return new self($payload, $status, $headers);
    }

    public static function raw(string $body, string $contentType, int $status = 200, array $headers = []): self
    {
        return new self([], $status, $headers, $body, $contentType);
    }

    /** @param resource $stream */
    public static function stream($stream, string $contentType, int $status = 200, array $headers = []): self
    {
        if (!is_resource($stream)) {
            throw new \InvalidArgumentException('Response stream must be an open resource.');
        }
        return new self([], $status, $headers, null, $contentType, $stream);
    }

    public function send(): void
    {
        http_response_code($this->status);
        header('Content-Type: ' . $this->contentType);
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');
        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value);
        }
        if ($this->rawBody !== null) {
            echo $this->rawBody;
            return;
        }
        if (is_resource($this->streamBody)) {
            rewind($this->streamBody);
            fpassthru($this->streamBody);
            fclose($this->streamBody);
            return;
        }
        echo json_encode($this->payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
