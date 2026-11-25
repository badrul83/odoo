<?php
declare(strict_types=1);

namespace Erp\Modules\Inventory;

use InvalidArgumentException;

final class ProductCsv
{
    /** @return list<string> */
    public static function headers(bool $includeAccounts): array
    {
        $headers = [
            'Record ID', 'Product', 'Internal Reference', 'Barcode', 'Category', 'Product Class', 'Product Type',
            'Sales Unit', 'Purchase Unit', 'Carton Size', 'Units per Carton', 'Tracking', 'Invoice Policy',
            'Piece Price', 'Carton Price', 'Cost', 'Can be Sold', 'Can be Purchased', 'Active', 'Weight', 'Volume', 'Description',
        ];
        if ($includeAccounts) {
            $headers[] = 'Income Account Code';
            $headers[] = 'Purchase Expense Account Code';
        }
        return $headers;
    }

    /**
     * @return list<array{row_number:int,fields:array<string,string>}>
     */
    public static function parse(string $contents): array
    {
        if ($contents === '' || strlen($contents) > 5_000_000) {
            throw new InvalidArgumentException('Choose a non-empty CSV file no larger than 5 MB.');
        }
        if (str_contains($contents, "\0") || preg_match('//u', $contents) !== 1) {
            throw new InvalidArgumentException('Save the spreadsheet as a UTF-8 CSV file before importing.');
        }
        $firstLine = strtok($contents, "\r\n");
        if (!is_string($firstLine)) {
            throw new InvalidArgumentException('The CSV file is empty.');
        }
        $firstLine = preg_replace('/^\xEF\xBB\xBF/', '', $firstLine) ?? $firstLine;
        $delimiters = [',' => substr_count($firstLine, ','), ';' => substr_count($firstLine, ';'), "\t" => substr_count($firstLine, "\t")];
        arsort($delimiters);
        $delimiter = (string)array_key_first($delimiters);
        if ($delimiters[$delimiter] < 1) {
            throw new InvalidArgumentException('CSV columns must be separated by commas, semicolons, or tabs.');
        }

        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            throw new InvalidArgumentException('The CSV file could not be read.');
        }
        try {
            fwrite($stream, $contents);
            rewind($stream);
            $headers = fgetcsv($stream, null, $delimiter, '"', '\\');
            if (!is_array($headers) || count($headers) > 24) {
                throw new InvalidArgumentException('The CSV header row is missing or contains too many columns.');
            }
            if (isset($headers[0])) {
                $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$headers[0]) ?? (string)$headers[0];
            }
            $aliases = [
                'recordid' => 'record_id', 'id' => 'record_id',
                'product' => 'name', 'name' => 'name', 'productname' => 'name',
                'internalreference' => 'internal_reference', 'reference' => 'internal_reference', 'defaultcode' => 'internal_reference',
                'barcode' => 'barcode', 'category' => 'category', 'productclass' => 'product_class', 'cpnoncp' => 'product_class',
                'classification' => 'product_class', 'producttype' => 'product_type',
                'salesunit' => 'sales_unit', 'salesuom' => 'sales_unit', 'unitofmeasure' => 'sales_unit',
                'purchaseunit' => 'purchase_unit', 'purchaseuom' => 'purchase_unit',
                'cartonsize' => 'carton_size', 'unitspercarton' => 'units_per_carton', 'piecespercarton' => 'units_per_carton',
                'tracking' => 'tracking', 'invoicepolicy' => 'invoice_policy',
                'pieceprice' => 'piece_price', 'priceperpiece' => 'piece_price', 'salesprice' => 'piece_price', 'listprice' => 'piece_price',
                'cartonprice' => 'carton_price', 'priceinctn' => 'carton_price', 'priceincarton' => 'carton_price',
                'cost' => 'cost_price', 'costprice' => 'cost_price',
                'canbesold' => 'sale_ok', 'saleok' => 'sale_ok',
                'canbepurchased' => 'purchase_ok', 'purchaseok' => 'purchase_ok',
                'active' => 'active', 'status' => 'active', 'weight' => 'weight', 'volume' => 'volume',
                'description' => 'description', 'internalnotes' => 'description',
                'incomeaccountcode' => 'income_account_code', 'purchaseexpenseaccountcode' => 'purchase_account_code',
            ];
            $columns = [];
            foreach ($headers as $index => $header) {
                $key = strtolower((string)preg_replace('/[^a-z0-9]/i', '', trim((string)$header)));
                if ($key === '' || !isset($aliases[$key])) {
                    throw new InvalidArgumentException('Unknown product CSV column: ' . trim((string)$header) . '. Download the product template to see supported fields.');
                }
                $field = $aliases[$key];
                if (isset($columns[$field])) {
                    throw new InvalidArgumentException('The CSV contains duplicate columns for ' . str_replace('_', ' ', $field) . '.');
                }
                $columns[$field] = (int)$index;
            }
            if (!isset($columns['name']) && !isset($columns['record_id']) && !isset($columns['internal_reference'])) {
                throw new InvalidArgumentException('Include Product, Record ID, or Internal Reference so each row can be identified.');
            }

            $rows = [];
            $sourceRow = 1;
            while (($cells = fgetcsv($stream, null, $delimiter, '"', '\\')) !== false) {
                $sourceRow++;
                if ($cells === [null] || !array_filter($cells, static fn (mixed $value): bool => trim((string)$value) !== '')) {
                    continue;
                }
                if (count($rows) >= 2_000) {
                    throw new InvalidArgumentException('A product import can contain at most 2,000 rows.');
                }
                if (count($cells) > count($headers)) {
                    throw new InvalidArgumentException('CSV row ' . $sourceRow . ': The row has more values than the header. Check commas inside descriptions or save again as CSV.');
                }
                $fields = [];
                foreach ($columns as $field => $index) {
                    $fields[$field] = trim((string)($cells[$index] ?? ''));
                }
                $rows[] = ['row_number' => $sourceRow, 'fields' => $fields];
            }
            if (!$rows) {
                throw new InvalidArgumentException('The CSV contains no product rows.');
            }
            return $rows;
        } finally {
            fclose($stream);
        }
    }
}
