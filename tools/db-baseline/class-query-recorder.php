<?php
/**
 * Records every query issued through the extend-wp DB layer.
 *
 * @package extend-wp
 */

namespace EWP\Tools\Db_Baseline;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Listens on $wpdb's `query` filter, keeps only queries whose backtrace runs
 * through AWM_DB_Creator or the awm_*_db_content* API, and writes one record
 * per query: entry point, consumer call site, argument shape, SQL, row count
 * and result hash.
 *
 * Works on whichever AWM_DB_Creator copy is loaded (plugin or bundled) since
 * it never touches the class. `query` runs before wpdb::flush(), so each
 * record is completed with the previous query's results when the next query
 * (or shutdown) arrives.
 */
class Query_Recorder
{
    /** Backtrace depth inspected per query. */
    const MAX_FRAMES = 40;

    /** @var Record_Writer */
    private $writer;

    /** @var string Identifier shared by every record of this request. */
    private $request_id;

    /** @var array|null Record waiting for its query's results. */
    private $pending = null;

    /** @var bool Guard against recording our own work. */
    private $busy = false;

    /**
     * @param Record_Writer $writer     Destination for records.
     * @param string        $request_id Identifier for this request.
     */
    public function __construct(Record_Writer $writer, $request_id)
    {
        $this->writer     = $writer;
        $this->request_id = (string) $request_id;
    }

    /**
     * Attach to WordPress.
     *
     * @return void
     */
    public function register()
    {
        add_filter('query', array($this, 'on_query'), PHP_INT_MAX);
        add_action('shutdown', array($this, 'flush'), PHP_INT_MAX);
    }

    /**
     * `query` filter: complete the previous record, start a new one.
     *
     * @param string $sql Query about to run.
     * @return string The query, unchanged.
     */
    public function on_query($sql)
    {
        if ($this->busy) {
            return $sql;
        }
        $this->flush();
        $site = Call_Site::resolve(debug_backtrace(0, self::MAX_FRAMES));
        if ($site !== null) {
            $this->pending = $this->build($sql, $site);
        }
        return $sql;
    }

    /**
     * Complete the pending record with the last query's results and write it.
     *
     * @return void
     */
    public function flush()
    {
        if ($this->pending === null) {
            return;
        }
        global $wpdb;
        $this->busy = true;
        $record     = $this->pending + self::results($wpdb, $this->pending['sql']);
        $this->pending = null;
        $this->writer->write($record);
        $this->busy = false;
    }

    /**
     * Build a record from the SQL and the resolved call site.
     *
     * @param string $sql  Query text.
     * @param array  $site Output of Call_Site::resolve().
     * @return array Record without results.
     */
    private function build($sql, array $site)
    {
        $shape = Shape::of($site['args']);
        $call  = Call_Site::relative($site['file']) . ':' . $site['line'];
        return array(
            'request'     => $this->request_id,
            'context'     => self::context(),
            'entry'       => $site['entry'],
            'inner'       => $site['inner'],
            'owner'       => Call_Site::owner($site['file']),
            'call'        => $call,
            'fingerprint' => sha1($site['entry'] . '|' . $site['inner'] . '|' . $call . '|' . wp_json_encode($shape)),
            'shape'       => $shape,
            'args'        => Shape::sample($site['args']),
            'sql'         => (string) $sql,
        );
    }

    /**
     * Results of the query that just finished.
     *
     * @param \wpdb  $wpdb Database object.
     * @param string $sql  Query text the results belong to.
     * @return array { rows, result_hash, error }.
     */
    private static function results($wpdb, $sql)
    {
        $select = (bool) preg_match('/^\s*(SELECT|SHOW|DESCRIBE)\b/i', $sql);
        return array(
            'rows'        => $select ? (int) $wpdb->num_rows : (int) $wpdb->rows_affected,
            'result_hash' => $select ? md5((string) wp_json_encode($wpdb->last_result)) : null,
            'error'       => (string) $wpdb->last_error,
        );
    }

    /**
     * Describe the current request: kind and route/command.
     *
     * @return array { kind, target }.
     */
    private static function context()
    {
        if (defined('WP_CLI') && WP_CLI) {
            $argv = isset($GLOBALS['argv']) ? (array) $GLOBALS['argv'] : array();
            return array('kind' => 'cli', 'target' => implode(' ', array_slice($argv, 1)));
        }
        $uri = isset($_SERVER['REQUEST_URI']) ? (string) wp_unslash($_SERVER['REQUEST_URI']) : '';
        return array('kind' => self::web_kind($uri), 'target' => $uri);
    }

    /**
     * Kind of a web request.
     *
     * @param string $uri Request URI.
     * @return string One of cron, ajax, rest, admin, front.
     */
    private static function web_kind($uri)
    {
        if (wp_doing_cron()) {
            return 'cron';
        }
        if (wp_doing_ajax()) {
            return 'ajax';
        }
        if (strpos($uri, '/wp-json/') !== false || isset($_GET['rest_route'])) {
            return 'rest';
        }
        return is_admin() ? 'admin' : 'front';
    }
}
