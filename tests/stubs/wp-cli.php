<?php

declare(strict_types=1);

namespace {
    final class WpCliExit extends RuntimeException
    {
    }

    final class WP_CLI
    {
        /** @var array<string, object> */
        public static array $commands = [];

        /** @var list<string> */
        public static array $out = [];

        /** @var list<array{format: string, rows: list<array<string, mixed>>, fields: list<string>}> */
        public static array $tables = [];

        public static ?int $exit = null;

        public static function reset(): void
        {
            self::$commands = [];
            self::$out = [];
            self::$tables = [];
            self::$exit = null;
        }

        public static function add_command(string $name, object $command): void
        {
            self::$commands[$name] = $command;
        }

        public static function success(string $message): void
        {
            self::$out[] = 'Success: ' . $message;
        }

        public static function line(string $message = ''): void
        {
            self::$out[] = $message;
        }

        public static function error(string $message): never
        {
            self::$out[] = 'Error: ' . $message;
            self::$exit = 1;
            throw new WpCliExit($message, 1);
        }

        public static function halt(int $code): never
        {
            self::$exit = $code;
            throw new WpCliExit('halt', $code);
        }
    }
}

namespace WP_CLI\Utils {
    /**
     * @param list<array<string, mixed>> $items
     * @param list<string>|string        $fields
     */
    function format_items(string $format, array $items, array|string $fields): void
    {
        $fields = is_string($fields) ? explode(',', $fields) : $fields;
        \WP_CLI::$tables[] = ['format' => $format, 'rows' => $items, 'fields' => $fields];
        if ($format === 'json') {
            \WP_CLI::$out[] = (string) json_encode($items);
        }
    }
}
