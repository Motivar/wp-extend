<?php
/** Compare two JSONL result files: median per metric, delta. Usage: bench-report.php a.jsonl b.jsonl */
[$_, $a, $b] = $argv;
$load = fn($f) => array_map(fn($l) => json_decode($l, true), array_filter(file($f, FILE_IGNORE_NEW_LINES)));
$med = function (array $rows, string $k) { $v = array_column($rows, $k); sort($v); $n = count($v); return $n ? ($n % 2 ? $v[intdiv($n,2)] : ($v[$n/2-1] + $v[$n/2]) / 2) : null; };
$A = $load($a); $B = $load($b);
$keys = array_keys($A[0]);
printf("%-24s %14s %14s %10s\n", 'metric (median)', basename($a, '.jsonl'), basename($b, '.jsonl'), 'delta');
foreach ($keys as $k) {
    if (!is_numeric($A[0][$k] ?? null)) continue;
    $x = $med($A, $k); $y = $med($B, $k);
    $d = ($x > 0) ? sprintf('%+.1f%%', ($y - $x) / $x * 100) : '';
    printf("%-24s %14s %14s %10s\n", $k, $x, $y, $d);
}
