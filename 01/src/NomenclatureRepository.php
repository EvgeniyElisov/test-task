<?php

declare(strict_types=1);

final class NomenclatureRepository
{
    /** @var array<string, list<array{code: string, sku: string, name: string, price: float, stock: float}>> */
    private array $bySku = [];

    public function __construct(private readonly string $csvPath)
    {
        $this->load();
    }

    private function load(): void
    {
        $handle = fopen($this->csvPath, 'rb');
        if ($handle === false) {
            throw new RuntimeException("Не удалось открыть номенклатуру: {$this->csvPath}");
        }

        try {
            $header = fgetcsv($handle, 0, ';', '"', '\\');
            if ($header === false) {
                throw new RuntimeException('Пустая выгрузка номенклатуры');
            }
            $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]) ?? $header[0];

            $line = 1;
            while (($row = fgetcsv($handle, 0, ';', '"', '\\')) !== false) {
                $line++;
                if (count($row) === 1 && ($row[0] === null || trim((string) $row[0]) === '')) {
                    continue;
                }
                if (count($row) < 6) {
                    throw new RuntimeException("Номенклатура, строка {$line}: мало колонок");
                }

                [$code, $skuRaw, $name, , $priceRaw, $stockRaw] = $row;
                $sku = self::normalizeSku((string) $skuRaw);
                if ($sku === '') {
                    throw new RuntimeException("Номенклатура, строка {$line}: пустой артикул");
                }

                $this->bySku[$sku][] = [
                    'code'  => trim((string) $code),
                    'sku'   => $sku,
                    'name'  => trim((string) $name),
                    'price' => Number1c::parse((string) $priceRaw),
                    'stock' => Number1c::parse((string) $stockRaw),
                ];
            }
        } finally {
            fclose($handle);
        }
    }

    public static function normalizeSku(string $sku): string
    {
        return strtoupper(trim($sku));
    }

    /**
     * @return list<array{code: string, sku: string, name: string, price: float, stock: float}>
     */
    public function cardsForSku(string $sku): array
    {
        return $this->bySku[self::normalizeSku($sku)] ?? [];
    }
}
