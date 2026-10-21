<?php
/**
 * Appends baseline records to a JSON-lines file.
 *
 * @package extend-wp
 */

namespace EWP\Tools\Db_Baseline;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Writes one JSON object per line into a directory that is not web-readable.
 */
class Record_Writer
{
    /** @var string Absolute directory the records go to. */
    private $dir;

    /**
     * @param string $dir Absolute directory; created on first write.
     */
    public function __construct($dir)
    {
        $this->dir = rtrim($dir, '/');
    }

    /**
     * Directory records are written to.
     *
     * @return string Absolute path.
     */
    public function dir()
    {
        return $this->dir;
    }

    /**
     * Append one record.
     *
     * @param array $record Record to encode.
     * @return bool True when the line was written.
     */
    public function write(array $record)
    {
        if (!$this->ensure_dir()) {
            return false;
        }
        $line = wp_json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        if (!is_string($line)) {
            return false;
        }
        $file = $this->dir . '/records-' . gmdate('Ymd') . '.jsonl';
        return file_put_contents($file, $line . "\n", FILE_APPEND | LOCK_EX) !== false;
    }

    /**
     * Create the directory with deny files so records are never served.
     *
     * @return bool True when the directory exists and is writable.
     */
    private function ensure_dir()
    {
        if (is_dir($this->dir)) {
            return is_writable($this->dir);
        }
        if (!wp_mkdir_p($this->dir)) {
            return false;
        }
        file_put_contents($this->dir . '/index.php', "<?php\n// Silence is golden.\n");
        file_put_contents($this->dir . '/.htaccess', "Require all denied\nDeny from all\n");
        return true;
    }
}
