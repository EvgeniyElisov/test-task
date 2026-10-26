<?php

declare(strict_types=1);

final class Logger
{
    /** @var resource */
    private $handle;

    public function __construct(string $path)
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException("Не удалось создать каталог лога: {$dir}");
        }

        $handle = fopen($path, 'wb');
        if ($handle === false) {
            throw new RuntimeException("Не удалось открыть лог: {$path}");
        }
        $this->handle = $handle;
        $this->info('log_started', ['path' => $path]);
    }

    public function info(string $message, array $context = []): void
    {
        $this->write('INFO', $message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->write('WARNING', $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->write('ERROR', $message, $context);
    }

    private function write(string $level, string $message, array $context): void
    {
        $line = sprintf(
            "[%s] %s: %s%s\n",
            date('c'),
            $level,
            $message,
            $context === [] ? '' : ' ' . json_encode($context, JSON_UNESCAPED_UNICODE)
        );
        fwrite($this->handle, $line);
    }

    public function close(): void
    {
        if (is_resource($this->handle)) {
            fclose($this->handle);
        }
    }
}
