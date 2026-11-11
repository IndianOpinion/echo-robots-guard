<?php

declare(strict_types=1);

define('ABSPATH', __DIR__ . '/stubs/');

require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/stubs/WpStub.php';
require __DIR__ . '/stubs/class-wp-error.php';
require __DIR__ . '/stubs/FakeWpdb.php';
require __DIR__ . '/stubs/wp-cli.php';
require __DIR__ . '/stubs/wordpress.php';

WpStub::reset();
