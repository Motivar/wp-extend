<?php
/**
 * In-process benchmark probe. Run with `wp eval-file`. Prints one JSON line.
 * Measures: WP+plugins bootstrap, REST route registration, abilities, and
 * in-process dispatch of routes that exist on every branch under test.
 */
$out = [
    'boot_ms'    => round(timer_stop(0, 6) * 1000, 2),
    'boot_q'     => get_num_queries(),
    'boot_files' => count(get_included_files()),
    'boot_mem_mb'=> round(memory_get_peak_usage() / 1048576, 2),
];
wp_set_current_user(1);

$t = microtime(true); $q = get_num_queries();
$routes = rest_get_server()->get_routes();
$out['rest_init_ms'] = round((microtime(true) - $t) * 1000, 2);
$out['rest_init_q']  = get_num_queries() - $q;
$out['routes_total'] = count($routes);
$out['routes_plugin'] = count(array_filter(array_keys($routes), fn($k) => preg_match('#^/(extend-wp|ewp|ewp-filter|awm-dynamic-api|mwp-self-test)/#', $k)));

$t = microtime(true);
$out['abilities'] = function_exists('wp_get_abilities') ? count(wp_get_abilities()) : null;
$out['abilities_ms'] = round((microtime(true) - $t) * 1000, 2);

$cases = [
    'logs'          => ['GET', '/extend-wp/v1/logs', ['per_page' => 20]],
    'logs_types'    => ['GET', '/extend-wp/v1/logs/types', []],
    'rh_plugins'    => ['GET', '/extend-wp/v1/rest-health/plugins', []],
    'rh_endpoints'  => ['POST', '/extend-wp/v1/rest-health/endpoints', ['plugins' => ['wp-extend']]],
    'op_pages'      => ['GET', '/extend-wp/v1/options-portability/pages', []],
    'obj_search'    => ['GET', '/extend-wp/v1/objects/search', ['object_type' => 'post_type:post', 'search' => 'a', 'per_page' => 5]],
];
foreach ($cases as $k => [$m, $r, $p]) {
    $req = new WP_REST_Request($m, $r);
    foreach ($p as $pk => $pv) $req->set_param($pk, $pv);
    $t = microtime(true); $q = get_num_queries();
    $res = rest_do_request($req);
    $out["r_{$k}_ms"] = round((microtime(true) - $t) * 1000, 2);
    $out["r_{$k}_q"]  = get_num_queries() - $q;
    $out["r_{$k}_st"] = $res->get_status();
}
$out['end_files']  = count(get_included_files());
$out['end_mem_mb'] = round(memory_get_peak_usage() / 1048576, 2);
echo json_encode($out), "\n";
