<?php

declare(strict_types=1);

/** Парсинг чисел выгрузки 1С: «1 250,00» → 1250.0 */
final class Number1c
{
    public static function parse(string $raw): float
    {
        $normalized = str_replace(["\xc2\xa0", ' '], '', trim($raw));
        $normalized = str_replace(',', '.', $normalized);

        if ($normalized === '' || !is_numeric($normalized)) {
            throw new InvalidArgumentException("Некорректное число 1С: «{$raw}»");
        }

        return (float) $normalized;
    }
}
