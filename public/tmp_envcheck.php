<?php
header('Content-Type: text/plain; charset=utf-8');

$keys = [
  'LINE_MSG_CHANNEL_SECRET',
  'LINE_MSG_CHANNEL_ACCESS_TOKEN',
  'LINE_CHANNEL_SECRET',
  'LINE_CHANNEL_ACCESS_TOKEN',
];

foreach ($keys as $k) {
  $v = getenv($k);
  echo $k . " = " . ($v === false ? "false" : ("len=" . strlen($v))) . "\n";
}