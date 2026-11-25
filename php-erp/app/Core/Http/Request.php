<?php
declare(strict_types=1);

namespace Erp\Core\Http;

final class Request
{
    private function __construct(
        private readonly string $method,
        private readonly string $path,
        private readonly array $query,
        private readonly array $input,
        private readonly array $files,
        private readonly array $headers,
        private readonly string $remoteAddress,
        private readonly bool $invalidJson,
    ) {
    }

    public static function fromGlobals(): self
    {
        $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $uriPath = parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        $path = is_string($uriPath) && $uriPath !== '' ? '/' . trim($uriPath, '/') : '/';
        $scriptName = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '/'));
        if ($scriptName !== '/' && str_starts_with($path, $scriptName . '/')) {
            $path = substr($path, strlen($scriptName));
        }
        $scriptDirectory = str_replace('\\', '/', dirname($scriptName));
        $scriptDirectory = rtrim($scriptDirectory, '/');
        if ($scriptDirectory !== '' && $scriptDirectory !== '/' && $path === $scriptDirectory) {
            $path = '/';
        } elseif ($scriptDirectory !== '' && $scriptDirectory !== '/' && str_starts_with($path, $scriptDirectory . '/')) {
            $path = substr($path, strlen($scriptDirectory));
        }

        $input = $_POST;
        $invalidJson = false;
        $contentType = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));
        if (str_contains($contentType, 'application/json')) {
            $raw = file_get_contents('php://input');
            if (is_string($raw) && trim($raw) !== '') {
                try {
                    $decodedObject = json_decode($raw, false, 512, JSON_THROW_ON_ERROR);
                    if ($decodedObject instanceof \stdClass) {
                        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
                        $input = is_array($decoded) ? $decoded : [];
                    } else {
                        $invalidJson = true;
                    }
                } catch (\JsonException) {
                    $invalidJson = true;
                }
            }
        }

        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $name = strtolower(str_replace('_', '-', substr($key, 5)));
                $headers[$name] = (string)$value;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = (string)$_SERVER['CONTENT_TYPE'];
        }

        return new self(
            $method,
            $path === '' ? '/' : $path,
            $_GET,
            $input,
            $_FILES,
            $headers,
            (string)($_SERVER['REMOTE_ADDR'] ?? ''),
            $invalidJson,
        );
    }

    public function method(): string { return $this->method; }
    public function path(): string { return $this->path; }
    /** @return array<string, mixed> */
    public function query(): array { return $this->query; }
    /** @return array<string, mixed> */
    public function input(): array { return $this->input; }
    /** @return array<string, mixed>|null */
    public function file(string $name): ?array
    {
        $file = $this->files[$name] ?? null;
        return is_array($file) ? $file : null;
    }
    public function header(string $name): ?string { return $this->headers[strtolower($name)] ?? null; }
    public function remoteAddress(): string { return $this->remoteAddress; }
    public function hasInvalidJson(): bool { return $this->invalidJson; }
}
