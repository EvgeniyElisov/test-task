<?php

declare(strict_types=1);

final class ReportWriter
{
    /**
     * @param list<array<string, mixed>> $results
     */
    public function writeCsv(string $path, array $results): void
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException("Не удалось создать каталог отчёта: {$dir}");
        }

        $handle = fopen($path, 'wb');
        if ($handle === false) {
            throw new RuntimeException("Не удалось записать отчёт: {$path}");
        }

        try {
            fprintf($handle, "\xEF\xBB\xBF");
            fputcsv($handle, [
                'order_id',
                'status_mp',
                'decision',
                'action',
                'issues',
                'details',
                'line',
                'sku',
                'qty',
                'price_mp',
                'price_1c',
                'stock_1c',
                'code_1c',
                'line_issues',
            ], ';', '"', '\\');

            foreach ($results as $row) {
                $lines = $row['lines'];
                if ($lines === []) {
                    fputcsv($handle, [
                        $row['order_id'],
                        $row['status_mp'],
                        $row['decision'],
                        $row['action'],
                        implode('|', $row['issues']),
                        implode(' || ', $row['issue_details']),
                        '',
                        '',
                        '',
                        '',
                        '',
                        '',
                        '',
                        '',
                    ], ';', '"', '\\');
                    continue;
                }

                foreach ($lines as $line) {
                    fputcsv($handle, [
                        $row['order_id'],
                        $row['status_mp'],
                        $row['decision'],
                        $row['action'],
                        implode('|', $row['issues']),
                        implode(' || ', $row['issue_details']),
                        $line['line'],
                        $line['sku'] !== '' ? $line['sku'] : $line['sku_raw'],
                        $line['qty'],
                        $this->num($line['price_mp']),
                        $this->num($line['price_1c']),
                        $this->num($line['stock_1c']),
                        $line['code_1c'] ?? '',
                        implode('|', $line['issues']),
                    ], ';', '"', '\\');
                }
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * Краткая сводка в консоль для менеджера.
     *
     * @param list<array<string, mixed>> $results
     */
    public function printSummary(array $results): void
    {
        $counts = ['OK' => 0, 'BLOCKED' => 0, 'SKIP' => 0, 'ERROR' => 0];
        foreach ($results as $row) {
            $d = $row['decision'];
            $counts[$d] = ($counts[$d] ?? 0) + 1;
        }

        echo "=== Сводка сверки МП ↔ 1С ===\n";
        echo "OK (можно в 1С): {$counts['OK']}\n";
        echo "BLOCKED (не выгружать): {$counts['BLOCKED']}\n";
        echo "SKIP (отменён/дубль): {$counts['SKIP']}\n";
        echo "ERROR: {$counts['ERROR']}\n\n";

        foreach ($results as $row) {
            if ($row['decision'] === 'OK') {
                continue;
            }
            echo "{$row['order_id']} [{$row['decision']}] {$row['action']}\n";
            foreach ($row['issue_details'] as $d) {
                echo "  - {$d}\n";
            }
        }
    }

    private function num(mixed $v): string
    {
        if ($v === null || $v === '') {
            return '';
        }
        return is_numeric($v) ? number_format((float) $v, 2, '.', '') : (string) $v;
    }
}
