<?php

declare(strict_types=1);

/**
 * Источник заказов. Сейчас — JSON-файл (имитация ответа API).
 * Для HTTP достаточно заменить реализацию / передать другой класс с тем же контрактом.
 */
interface OrdersSource
{
    /**
     * @return list<array<string, mixed>>
     */
    public function fetchOrders(): array;
}

final class JsonFileOrdersSource implements OrdersSource
{
    public function __construct(private readonly string $path)
    {
    }

    public function fetchOrders(): array
    {
        $raw = file_get_contents($this->path);
        if ($raw === false) {
            throw new RuntimeException("Не удалось прочитать заказы: {$this->path}");
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('orders.json: невалидный JSON');
        }

        $orders = $decoded['data']['orders'] ?? null;
        if (!is_array($orders)) {
            throw new RuntimeException('orders.json: нет data.orders');
        }

        return array_values($orders);
    }
}
