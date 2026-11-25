<?php
declare(strict_types=1);

namespace Erp\Modules\Accounting;

use InvalidArgumentException;

final class BankStatementCsv
{
    /** @return list<array{line_number:int,transaction_date:string,reference:string,description:?string,amount:string,currency_code:?string,partner_email:?string,external_id:?string}> */
    public static function parse(string $contents): array
    {
        if ($contents === '' || strlen($contents) > 5_000_000) throw new InvalidArgumentException('Choose a non-empty CSV file no larger than 5 MB.');
        if (str_contains($contents, "\0") || preg_match('//u', $contents) !== 1) throw new InvalidArgumentException('The statement file must be valid UTF-8 text.');
        $firstLine = strtok($contents, "\r\n");
        if (!is_string($firstLine)) throw new InvalidArgumentException('The CSV file is empty.');
        $delimiters = [',' => substr_count($firstLine, ','), ';' => substr_count($firstLine, ';'), "\t" => substr_count($firstLine, "\t")];
        arsort($delimiters);
        $delimiter = (string)array_key_first($delimiters);
        if ($delimiters[$delimiter] < 1) throw new InvalidArgumentException('CSV columns must be separated by commas, semicolons, or tabs.');

        $stream = fopen('php://temp', 'r+');
        if ($stream === false) throw new InvalidArgumentException('The CSV file could not be read.');
        try {
            fwrite($stream, $contents);
            rewind($stream);
            $headers = fgetcsv($stream, null, $delimiter, '"', '\\');
            if (!is_array($headers) || count($headers) > 32) throw new InvalidArgumentException('The CSV header row is missing or has too many columns.');
            if (isset($headers[0])) $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$headers[0]) ?? (string)$headers[0];
            $aliases = [
                'date' => 'date', 'transactiondate' => 'date', 'valuedate' => 'date',
                'reference' => 'reference', 'ref' => 'reference', 'bankreference' => 'reference',
                'description' => 'description', 'label' => 'description', 'memo' => 'description',
                'amount' => 'amount', 'signedamount' => 'amount', 'transactionamount' => 'amount',
                'currency' => 'currency', 'currencycode' => 'currency',
                'partneremail' => 'partner_email', 'email' => 'partner_email',
                'externalid' => 'external_id', 'transactionid' => 'external_id',
            ];
            $columns = [];
            foreach ($headers as $index => $header) {
                $key = strtolower((string)preg_replace('/[^a-z0-9]/i', '', trim((string)$header)));
                if ($key === '' || !isset($aliases[$key])) continue;
                $field = $aliases[$key];
                if (isset($columns[$field])) throw new InvalidArgumentException('The CSV contains duplicate columns for ' . str_replace('_', ' ', $field) . '.');
                $columns[$field] = (int)$index;
            }
            foreach (['date','amount'] as $required) if (!isset($columns[$required])) throw new InvalidArgumentException('The CSV must contain Date and Amount columns.');

            $rows = [];
            $sourceRow = 1;
            while (($cells = fgetcsv($stream, null, $delimiter, '"', '\\')) !== false) {
                $sourceRow++;
                if ($cells === [null] || !array_filter($cells, static fn(mixed $value): bool => trim((string)$value) !== '')) continue;
                if (count($rows) >= 5_000) throw new InvalidArgumentException('A statement import can contain at most 5,000 transaction rows.');
                $value = static fn(string $field): string => trim((string)($cells[$columns[$field] ?? -1] ?? ''));
                $date = $value('date');
                $parsedDate = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
                if (!$parsedDate || $parsedDate->format('Y-m-d') !== $date) throw new InvalidArgumentException('CSV row ' . $sourceRow . ': Date must use YYYY-MM-DD.');
                $amount = $value('amount');
                if ($amount === '' || mb_strlen($amount) > 32) throw new InvalidArgumentException('CSV row ' . $sourceRow . ': Amount is required and must be a plain decimal number.');
                $reference = $value('reference');
                $description = $value('description');
                $externalId = $value('external_id');
                $partnerEmail = $value('partner_email');
                if ($reference === '') $reference = $externalId !== '' ? $externalId : 'Statement line ' . (count($rows) + 1);
                if (mb_strlen($reference) > 190 || mb_strlen($description) > 500 || mb_strlen($externalId) > 120) throw new InvalidArgumentException('CSV row ' . $sourceRow . ': Reference, Description, or External ID is too long.');
                if ($partnerEmail !== '' && (mb_strlen($partnerEmail) > 190 || filter_var($partnerEmail, FILTER_VALIDATE_EMAIL) === false)) throw new InvalidArgumentException('CSV row ' . $sourceRow . ': Partner Email is invalid.');
                $currency = strtoupper($value('currency'));
                if ($currency !== '' && preg_match('/^[A-Z]{3}$/D', $currency) !== 1) throw new InvalidArgumentException('CSV row ' . $sourceRow . ': Currency must be a three-letter code.');
                $rows[] = [
                    'line_number' => count($rows) + 1,
                    'transaction_date' => $date,
                    'reference' => $reference,
                    'description' => $description !== '' ? $description : null,
                    'amount' => $amount,
                    'currency_code' => $currency !== '' ? $currency : null,
                    'partner_email' => $partnerEmail !== '' ? strtolower($partnerEmail) : null,
                    'external_id' => $externalId !== '' ? $externalId : null,
                ];
            }
            if (!$rows) throw new InvalidArgumentException('The CSV contains no transaction rows.');
            return $rows;
        } finally {
            fclose($stream);
        }
    }
}
