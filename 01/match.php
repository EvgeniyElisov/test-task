<?php

declare(strict_types=1);

/**
 * CLI: сверка заказов маркетплейса с выгрузкой номенклатуры 1С.
 *
 *   php match.php
 *   php match.php --orders=data/orders.json --nomenclature=data/nomenclature_1c.csv --out=output
 */

$root = __DIR__;
require $root . '/src/Number1c.php';
require $root . '/src/OrdersSource.php';
require $root . '/src/NomenclatureRepository.php';
require $root . '/src/Logger.php';
require $root . '/src/OrderMatcher.php';
require $root . '/src/ReportWriter.php';

$opts = getopt('', ['orders::', 'nomenclature::', 'out::', 'help']);
if (isset($opts['help'])) {
    echo "Usage: php match.php [--orders=path] [--nomenclature=path] [--out=dir]\n";
    exit(0);
}

$ordersPath = $opts['orders'] ?? $root . '/data/orders.json';
$nomPath    = $opts['nomenclature'] ?? $root . '/data/nomenclature_1c.csv';
$outDir     = $opts['out'] ?? $root . '/output';

$logPath    = $outDir . '/sync.log';
$reportPath = $outDir . '/report.csv';

$logger = new Logger($logPath);

try {
    $source = new JsonFileOrdersSource($ordersPath);
    $orders = $source->fetchOrders();
    $logger->info('orders_loaded', ['count' => count($orders), 'path' => $ordersPath]);

    $nomenclature = new NomenclatureRepository($nomPath);
    $logger->info('nomenclature_loaded', ['path' => $nomPath]);

    $matcher = new OrderMatcher($nomenclature, $logger);
    $results = $matcher->match($orders);

    $writer = new ReportWriter();
    $writer->writeCsv($reportPath, $results);
    $writer->printSummary($results);

    $logger->info('done', ['report' => $reportPath, 'orders' => count($results)]);
    echo "\nОтчёт: {$reportPath}\nЛог: {$logPath}\n";
    exit(0);
} catch (Throwable $e) {
    $logger->error('fatal', ['error' => $e->getMessage()]);
    fwrite(STDERR, 'FATAL: ' . $e->getMessage() . "\n");
    exit(1);
} finally {
    $logger->close();
}
