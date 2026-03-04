<?php
declare(strict_types=1);
header('Content-Type: text/plain; charset=utf-8');

echo "host=" . gethostname() . "\n";
echo "sapi=" . PHP_SAPI . "\n";
echo "php=" . PHP_VERSION . "\n";
echo "__FILE__=" . __FILE__ . "\n";
echo "cwd=" . getcwd() . "\n";
echo "mtime=" . date('c', filemtime(__FILE__)) . "\n";
echo "docroot=" . ($_SERVER['DOCUMENT_ROOT'] ?? '') . "\n";
echo "script=" . ($_SERVER['SCRIPT_FILENAME'] ?? '') . "\n";
