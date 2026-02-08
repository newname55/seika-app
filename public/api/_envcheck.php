<?php
header('Content-Type: text/plain; charset=UTF-8');
$keys = [
  'LINE_MSG_CHANNEL_SECRET',
  'LINE_MSG_CHANNEL_ACCESS_TOKEN',
  'LINE_CHANNEL_SECRET',
  'LINE_CHANNEL_ACCESS_TOKEN',
];
foreach ($keys as $k) {
  $v = getenv($k);
  echo $k . ' = ' . (is_string($v) ? ('len=' . strlen($v)) : 'false') . "\n";
}