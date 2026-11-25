<?php
declare(strict_types=1);

namespace Erp\Core\Security;

use InvalidArgumentException;

/** A small, bounded CBOR decoder for the WebAuthn attestation structures we accept. */
final class WebAuthnCbor
{
    private int $offset = 0;
    private int $items = 0;

    private function __construct(private readonly string $data) {}

    public static function decode(string $data): mixed
    {
        if ($data === '' || strlen($data) > 65536) throw new InvalidArgumentException('Passkey response is invalid.');
        $decoder = new self($data);
        $value = $decoder->item(0);
        if ($decoder->offset !== strlen($data)) throw new InvalidArgumentException('Passkey response has trailing CBOR data.');
        return $value;
    }

    public static function decodeAt(string $data, int &$offset): mixed
    {
        if ($data === '' || strlen($data) > 65536 || $offset < 0 || $offset >= strlen($data)) throw new InvalidArgumentException('Passkey response is invalid.');
        $decoder = new self($data);
        $decoder->offset = $offset;
        $value = $decoder->item(0);
        $offset = $decoder->offset;
        return $value;
    }

    private function item(int $depth): mixed
    {
        if ($depth > 16 || ++$this->items > 4096 || $this->offset >= strlen($this->data)) throw new InvalidArgumentException('Passkey response CBOR is malformed.');
        $initial = ord($this->data[$this->offset++]);
        $major = $initial >> 5;
        $additional = $initial & 31;
        if ($major === 7) {
            return match ($additional) {
                20 => false,
                21 => true,
                22 => null,
                default => throw new InvalidArgumentException('Passkey response contains an unsupported CBOR value.'),
            };
        }
        $length = $this->length($additional);
        return match ($major) {
            0 => $length,
            1 => -1 - $length,
            2, 3 => $this->bytes($length),
            4 => $this->array($length, $depth + 1),
            5 => $this->map($length, $depth + 1),
            6 => $this->item($depth + 1),
            default => throw new InvalidArgumentException('Passkey response CBOR is malformed.'),
        };
    }

    private function length(int $additional): int
    {
        if ($additional < 24) return $additional;
        $size = match ($additional) { 24 => 1, 25 => 2, 26 => 4, 27 => 8, default => throw new InvalidArgumentException('Indefinite CBOR values are not accepted.') };
        if ($this->offset + $size > strlen($this->data)) throw new InvalidArgumentException('Passkey response CBOR is truncated.');
        if ($size === 1) $value = ord($this->data[$this->offset]);
        elseif ($size === 2) $value = unpack('nvalue', substr($this->data, $this->offset, 2))['value'];
        elseif ($size === 4) $value = unpack('Nvalue', substr($this->data, $this->offset, 4))['value'];
        else {
            $parts = unpack('Nhigh/Nlow', substr($this->data, $this->offset, 8));
            if ($parts['high'] > 2147483647) throw new InvalidArgumentException('Passkey response CBOR value is too large.');
            $value = (int)($parts['high'] * 4294967296 + $parts['low']);
        }
        $this->offset += $size;
        if ($value > 65536) throw new InvalidArgumentException('Passkey response CBOR value is too large.');
        return (int)$value;
    }

    private function bytes(int $length): string
    {
        if ($length < 0 || $this->offset + $length > strlen($this->data)) throw new InvalidArgumentException('Passkey response CBOR is truncated.');
        $value = substr($this->data, $this->offset, $length);
        $this->offset += $length;
        return $value;
    }

    private function array(int $length, int $depth): array
    {
        $values = [];
        for ($i = 0; $i < $length; $i++) $values[] = $this->item($depth);
        return $values;
    }

    private function map(int $length, int $depth): array
    {
        $values = [];
        for ($i = 0; $i < $length; $i++) {
            $key = $this->item($depth);
            if (!is_int($key) && !is_string($key)) throw new InvalidArgumentException('Passkey response contains an invalid CBOR map key.');
            if (array_key_exists($key, $values)) throw new InvalidArgumentException('Passkey response contains a duplicate CBOR map key.');
            $values[$key] = $this->item($depth);
        }
        return $values;
    }
}
