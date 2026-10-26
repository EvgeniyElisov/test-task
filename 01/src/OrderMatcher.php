<?php

declare(strict_types=1);

/**
 * Сверка заказов МП с номенклатурой 1С.
 *
 * Остаток считаем суммарно по активным заказам (new/confirmed) в порядке файла:
 * резервируем FIFO — как будто выгружаем в 1С сверху вниз одной пачкой.
 */
final class OrderMatcher
{
    private const PRICE_EPS = 0.009; // допуск из‑за float / копеек

    public function __construct(
        private readonly NomenclatureRepository $nomenclature,
        private readonly Logger $log,
    ) {
    }

    /**
     * @param list<array<string, mixed>> $orders
     * @return list<array<string, mixed>>
     */
    public function match(array $orders): array
    {
        $seenOrderIds = [];
        /** @var array<string, float> $reserved */
        $reserved = [];
        $results = [];

        foreach ($orders as $index => $order) {
            try {
                $results[] = $this->matchOne($order, $index, $seenOrderIds, $reserved);
            } catch (Throwable $e) {
                $orderId = is_array($order) ? (string) ($order['id'] ?? '—') : '—';
                $this->log->error('order_processing_failed', [
                    'order_id' => $orderId,
                    'index'    => $index,
                    'error'    => $e->getMessage(),
                ]);
                $results[] = [
                    'order_id'       => $orderId,
                    'status_mp'      => is_array($order) ? (string) ($order['status'] ?? '') : '',
                    'decision'       => 'ERROR',
                    'action'         => 'Разобрать вручную: ошибка обработки записи',
                    'issues'         => ['PROCESSING_ERROR'],
                    'issue_details'  => [$e->getMessage()],
                    'lines'          => [],
                ];
            }
        }

        return $results;
    }

    /**
     * @param array<string, int> $seenOrderIds
     * @param array<string, float> $reserved
     * @return array<string, mixed>
     */
    private function matchOne(array $order, int $index, array &$seenOrderIds, array &$reserved): array
    {
        $orderId = trim((string) ($order['id'] ?? ''));
        if ($orderId === '') {
            $this->log->error('order_without_id', ['index' => $index]);
            return [
                'order_id'      => '—',
                'status_mp'     => (string) ($order['status'] ?? ''),
                'decision'      => 'ERROR',
                'action'        => 'В заказе нет id — не выгружать',
                'issues'        => ['MISSING_ORDER_ID'],
                'issue_details' => ['Пустой id заказа'],
                'lines'         => [],
            ];
        }

        $statusMp = (string) ($order['status'] ?? '');
        $issues = [];
        $details = [];
        $lines = [];

        if (isset($seenOrderIds[$orderId])) {
            $issues[] = 'DUPLICATE_ORDER_ID';
            $details[] = "Повтор id в выгрузке API (первый раз на позиции {$seenOrderIds[$orderId]})";
            $this->log->warning('duplicate_order_id', [
                'order_id' => $orderId,
                'first_index' => $seenOrderIds[$orderId],
                'index' => $index,
            ]);

            return [
                'order_id'      => $orderId,
                'status_mp'     => $statusMp,
                'decision'      => 'SKIP',
                'action'        => 'Дубль заказа в ответе API — не выгружать повторно',
                'issues'        => $issues,
                'issue_details' => $details,
                'lines'         => [],
            ];
        }
        $seenOrderIds[$orderId] = $index;

        if ($statusMp === 'cancelled') {
            $this->log->info('order_cancelled_skipped', ['order_id' => $orderId]);
            return [
                'order_id'      => $orderId,
                'status_mp'     => $statusMp,
                'decision'      => 'SKIP',
                'action'        => 'Отменён на МП — в 1С не передаём, остаток не резервируем',
                'issues'        => ['CANCELLED'],
                'issue_details' => ['status=cancelled'],
                'lines'         => [],
            ];
        }

        $items = $order['items'] ?? null;
        if (!is_array($items) || $items === []) {
            $this->log->error('order_empty_items', ['order_id' => $orderId]);
            return [
                'order_id'      => $orderId,
                'status_mp'     => $statusMp,
                'decision'      => 'BLOCKED',
                'action'        => 'Пустой состав — уточнить у МП / не выгружать',
                'issues'        => ['EMPTY_ITEMS'],
                'issue_details' => ['Нет строк items'],
                'lines'         => [],
            ];
        }

        foreach ($items as $lineNo => $item) {
            if (!is_array($item)) {
                $issues[] = 'INVALID_ITEM';
                $details[] = "Строка {$lineNo}: не объект";
                $this->log->error('invalid_item', ['order_id' => $orderId, 'line' => $lineNo]);
                continue;
            }

            $lineResult = $this->matchLine($orderId, $lineNo, $item, $reserved);
            $lines[] = $lineResult;
            foreach ($lineResult['issues'] as $code) {
                $issues[] = $code;
            }
            foreach ($lineResult['details'] as $d) {
                $details[] = $d;
            }
        }

        $issues = array_values(array_unique($issues));

        if ($issues === []) {
            $decision = 'OK';
            $action = 'Можно передавать в 1С';
        } else {
            $decision = 'BLOCKED';
            $action = $this->actionForIssues($issues);
        }

        return [
            'order_id'      => $orderId,
            'status_mp'     => $statusMp,
            'decision'      => $decision,
            'action'        => $action,
            'issues'        => $issues,
            'issue_details' => $details,
            'lines'         => $lines,
        ];
    }

    /**
     * @param array<string, mixed> $item
     * @param array<string, float> $reserved
     * @return array<string, mixed>
     */
    private function matchLine(string $orderId, int $lineNo, array $item, array &$reserved): array
    {
        $issues = [];
        $details = [];
        $skuRaw = (string) ($item['sku'] ?? '');
        $sku = NomenclatureRepository::normalizeSku($skuRaw);
        $qty = $item['quantity'] ?? null;
        $price = $item['price'] ?? null;

        $base = [
            'line'       => $lineNo + 1,
            'sku_raw'    => $skuRaw,
            'sku'        => $sku,
            'qty'        => $qty,
            'price_mp'   => $price,
            'price_1c'   => null,
            'stock_1c'   => null,
            'code_1c'    => null,
            'name_1c'    => null,
            'issues'     => [],
            'details'    => [],
        ];

        if ($sku === '') {
            $issues[] = 'INVALID_ITEM';
            $details[] = "Строка " . ($lineNo + 1) . ": пустой sku";
            $this->log->error('item_missing_sku', compact('orderId', 'lineNo'));
            $base['issues'] = $issues;
            $base['details'] = $details;
            return $base;
        }

        if (!is_numeric($qty) || (int) $qty <= 0) {
            $issues[] = 'INVALID_ITEM';
            $details[] = "{$sku}: некорректное quantity";
            $this->log->error('item_bad_qty', ['order_id' => $orderId, 'sku' => $sku, 'qty' => $qty]);
            $base['issues'] = $issues;
            $base['details'] = $details;
            return $base;
        }
        $qty = (int) $qty;
        $base['qty'] = $qty;

        if (!is_numeric($price)) {
            $issues[] = 'INVALID_ITEM';
            $details[] = "{$sku}: нет price";
            $this->log->error('item_missing_price', ['order_id' => $orderId, 'sku' => $sku]);
            $base['issues'] = $issues;
            $base['details'] = $details;
            return $base;
        }
        $price = (float) $price;
        $base['price_mp'] = $price;

        $cards = $this->nomenclature->cardsForSku($sku);
        if ($cards === []) {
            $issues[] = 'SKU_NOT_IN_1C';
            $details[] = "{$sku}: нет в выгрузке 1С — завести номенклатуру или поправить артикул на МП";
            $this->log->warning('sku_not_in_1c', ['order_id' => $orderId, 'sku' => $sku]);
            $base['issues'] = $issues;
            $base['details'] = $details;
            return $base;
        }

        if (count($cards) > 1) {
            $codes = array_map(static fn(array $c): string => $c['code'], $cards);
            $issues[] = 'AMBIGUOUS_SKU';
            $details[] = "{$sku}: в 1С несколько карточек (" . implode(', ', $codes) . ") — выбрать одну вручную";
            $this->log->warning('ambiguous_sku', ['order_id' => $orderId, 'sku' => $sku, 'codes' => $codes]);
            $base['issues'] = $issues;
            $base['details'] = $details;
            return $base;
        }

        $card = $cards[0];
        $base['price_1c'] = $card['price'];
        $base['stock_1c'] = $card['stock'];
        $base['code_1c'] = $card['code'];
        $base['name_1c'] = $card['name'];

        if (abs($price - $card['price']) > self::PRICE_EPS) {
            $issues[] = 'PRICE_MISMATCH';
            $details[] = sprintf(
                '%s: цена МП %.2f ≠ 1С %.2f',
                $sku,
                $price,
                $card['price']
            );
            $this->log->warning('price_mismatch', [
                'order_id' => $orderId,
                'sku' => $sku,
                'price_mp' => $price,
                'price_1c' => $card['price'],
            ]);
        }

        $already = $reserved[$sku] ?? 0.0;
        $available = $card['stock'] - $already;
        if ($qty > $available + self::PRICE_EPS) {
            $issues[] = 'INSUFFICIENT_STOCK';
            $details[] = sprintf(
                '%s: нужно %d, свободно %.0f (остаток 1С %.0f, уже зарезервировано ранее по файлу %.0f)',
                $sku,
                $qty,
                max(0, $available),
                $card['stock'],
                $already
            );
            $this->log->warning('insufficient_stock', [
                'order_id' => $orderId,
                'sku' => $sku,
                'qty' => $qty,
                'stock' => $card['stock'],
                'reserved_before' => $already,
            ]);
        }

        // Резервируем даже при нехватке — чтобы следующие заказы видели реальное «съеденное»
        $reserved[$sku] = $already + $qty;

        $base['issues'] = $issues;
        $base['details'] = $details;
        return $base;
    }

    /**
     * @param list<string> $issues
     */
    private function actionForIssues(array $issues): string
    {
        $hints = [];
        if (in_array('SKU_NOT_IN_1C', $issues, true)) {
            $hints[] = 'завести артикул в 1С / исправить на МП';
        }
        if (in_array('AMBIGUOUS_SKU', $issues, true)) {
            $hints[] = 'убрать дубль карточки в 1С';
        }
        if (in_array('PRICE_MISMATCH', $issues, true)) {
            $hints[] = 'согласовать цену с МП или обновить в 1С';
        }
        if (in_array('INSUFFICIENT_STOCK', $issues, true)) {
            $hints[] = 'докатить остаток / разбить отгрузку / отменить лишнее';
        }
        if (in_array('EMPTY_ITEMS', $issues, true) || in_array('INVALID_ITEM', $issues, true)) {
            $hints[] = 'починить данные заказа на стороне МП';
        }
        if (in_array('PROCESSING_ERROR', $issues, true)) {
            $hints[] = 'смотреть sync.log';
        }

        return $hints === []
            ? 'Есть замечания — см. детали'
            : 'Не выгружать, пока не исправят: ' . implode('; ', $hints);
    }
}
