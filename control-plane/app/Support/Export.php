<?php

namespace App\Support;

use Symfony\Component\HttpFoundation\StreamedResponse;

/** Eksport listy płaskich rekordów do CSV, JSON albo YAML (do pobrania). */
final class Export
{
    public const FORMATS = ['csv', 'json', 'yaml'];

    /**
     * @param  iterable<array<string, scalar|null>>  $rows
     * @param  list<string>  $columns
     */
    public static function download(iterable $rows, array $columns, string $format, string $basename): StreamedResponse
    {
        $types = ['csv' => 'text/csv; charset=UTF-8', 'json' => 'application/json', 'yaml' => 'application/yaml'];

        return response()->streamDownload(function () use ($rows, $columns, $format) {
            $out = fopen('php://output', 'w');
            if ($format === 'csv') {
                fwrite($out, "\xEF\xBB\xBF"); // BOM — Excel rozpozna UTF-8
                fputcsv($out, $columns, escape: '');
                foreach ($rows as $row) {
                    fputcsv($out, array_map(fn ($c) => self::csvSafe($row[$c] ?? null), $columns), escape: '');
                }
            } elseif ($format === 'json') {
                fwrite($out, '[');
                $first = true;
                foreach ($rows as $row) {
                    fwrite($out, ($first ? "\n  " : ",\n  ").json_encode(array_intersect_key($row, array_flip($columns)), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                    $first = false;
                }
                fwrite($out, $first ? "]\n" : "\n]\n");
            } else {
                $any = false;
                foreach ($rows as $row) {
                    $any = true;
                    foreach ($columns as $i => $column) {
                        fwrite($out, ($i === 0 ? '- ' : '  ').$column.': '.self::yamlScalar($row[$column] ?? null)."\n");
                    }
                }
                if (! $any) {
                    fwrite($out, "[]\n");
                }
            }
            fclose($out);
        }, $basename.'.'.$format, ['Content-Type' => $types[$format]]);
    }

    /** Komórka zaczynająca się od =, +, -, @ byłaby formułą w arkuszu (CSV injection). */
    private static function csvSafe(mixed $value): mixed
    {
        return is_string($value) && $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$value : $value;
    }

    private static function yamlScalar(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            is_bool($value) => $value ? 'true' : 'false',
            is_int($value), is_float($value) => (string) $value,
            default => json_encode((string) $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        };
    }
}
