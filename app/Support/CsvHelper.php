<?php

namespace App\Support;

class CsvHelper
{
    /**
     * Format a single CSV cell safely, neutralizing spreadsheet formula injection and line breaks.
     */
    public static function cell(mixed $value): string
    {
        $str = $value === null ? '' : (string) $value;
        $str = str_replace(["\r", "\n"], ' ', $str);

        if ($str !== '' && !is_numeric($str) && preg_match('/^[=+\-@\t]/', $str)) {
            $str = "'" . $str;
        }

        return '"' . str_replace('"', '""', $str) . '"';
    }

    /**
     * Render an array of values as a single CSV row ending with a newline.
     */
    public static function row(array $cells): string
    {
        return implode(',', array_map([self::class, 'cell'], $cells)) . "\n";
    }
}
