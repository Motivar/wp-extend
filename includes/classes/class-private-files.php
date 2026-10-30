<?php

namespace EWP;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Private files under a public directory (uploads).
 *
 * Log files and REST-health captures live under `uploads/`, which the web
 * server serves directly. `.htaccess` only protects Apache, so files written
 * through this class rely on two server-independent guards instead:
 *
 * - every file name carries a per-site random key (option `ewp_private_file_key`),
 *   so it cannot be guessed;
 * - every file is a `.php` file whose first line is `<?php exit; ?>`: a direct
 *   request runs PHP and gets an empty response, or a 403 where PHP is blocked
 *   in uploads. Readers skip that first line.
 *
 * @package EWP
 *
 * @since 1.9.0
 */
class Private_Files
{
    /** Option holding the per-site file key. */
    const KEY_OPTION = 'ewp_private_file_key';

    /** First line of every private file; stops PHP before the data. */
    const GUARD = "<?php exit; ?>\n";

    /**
     * Per-site random key appended to private file names, created on first use.
     *
     * @return string 32 lowercase hex characters.
     */
    public static function key()
    {
        $key = get_option(self::KEY_OPTION, '');
        if (is_string($key) && preg_match('/^[a-f0-9]{32}$/', $key)) {
            return $key;
        }
        $key = bin2hex(random_bytes(16));
        update_option(self::KEY_OPTION, $key, true);
        return $key;
    }

    /**
     * Create a directory with deny files (.htaccess for Apache, empty index files against listings).
     *
     * @param string $dir Required. Absolute directory path.
     * @return bool True when the directory exists afterwards.
     */
    public static function secure_dir($dir)
    {
        if (!file_exists($dir) && !wp_mkdir_p($dir)) {
            return false;
        }
        $files = array(
            '.htaccess'  => "# Deny direct access\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nOrder deny,allow\nDeny from all\n</IfModule>\n",
            'index.php'  => "<?php\n// Silence is golden.\n",
            'index.html' => '',
        );
        foreach ($files as $name => $content) {
            if (!file_exists($dir . '/' . $name)) {
                file_put_contents($dir . '/' . $name, $content);
            }
        }
        return true;
    }

    /**
     * Strip the guard line from file contents.
     *
     * @param string $contents Required. Raw file contents.
     * @return string Contents without a leading guard line.
     */
    public static function strip_guard($contents)
    {
        $contents = (string) $contents;
        return strpos($contents, self::GUARD) === 0 ? substr($contents, strlen(self::GUARD)) : $contents;
    }

    /**
     * Read a guarded JSON file.
     *
     * @param string $path Required. Absolute file path.
     * @return array Decoded data, or an empty array when missing or invalid.
     */
    public static function read_json($path)
    {
        if (!is_readable($path)) {
            return array();
        }
        $data = json_decode(self::strip_guard((string) file_get_contents($path)), true);
        return is_array($data) ? $data : array();
    }

    /**
     * Write a guarded JSON file, creating its directory.
     *
     * @param string $path Required. Absolute file path (should end in `.php`).
     * @param array  $data Required. Data to encode.
     * @return bool True on success.
     */
    public static function write_json($path, array $data)
    {
        if (!self::secure_dir(dirname($path))) {
            return false;
        }
        $json = wp_json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        return file_put_contents($path, self::GUARD . $json, LOCK_EX) !== false;
    }

    /**
     * Move a legacy unguarded JSON file to its guarded path once.
     *
     * Does nothing when the legacy file is missing or the new file already exists;
     * the legacy file is deleted either way once the new one exists.
     *
     * @param string $legacy_path Required. Old public path (e.g. `payloads.json`).
     * @param string $path        Required. New guarded path.
     * @return void
     */
    public static function migrate_json($legacy_path, $path)
    {
        if (!file_exists($legacy_path)) {
            return;
        }
        if (!file_exists($path)) {
            $data = json_decode((string) file_get_contents($legacy_path), true);
            if (!self::write_json($path, is_array($data) ? $data : array())) {
                return;
            }
        }
        wp_delete_file($legacy_path);
    }
}
