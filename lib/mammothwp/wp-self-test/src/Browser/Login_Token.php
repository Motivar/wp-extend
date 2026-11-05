<?php
/**
 * Short-lived, single-use login tokens for the browser layer.
 *
 * Security model: the runner issues a random token for one user and hands it
 * to the Node runner through an environment variable. The Node side exchanges
 * it once at `POST mmwp/v1/self-test/browser-login`, which sets the auth
 * cookies and burns the token. Only a SHA-256 hash of the token is stored,
 * it expires after `TTL` seconds, and after the run `revoke_all()` destroys
 * every session the exchange created. Nothing is issued or redeemed when the
 * environment is `production`, whatever the gating filters return.
 *
 * @package MammothWP\SelfTest
 * @since   2.1.0
 */

namespace MammothWP\SelfTest\Browser;

use MammothWP\SelfTest\Config;

if (!defined('ABSPATH')) {
    exit;
}

final class Login_Token
{
    /** Option holding the hashed tokens and the sessions they created. */
    const OPTION = 'mmwp_self_test_login_tokens';

    /** Seconds an unused token stays valid. */
    const TTL = 120;

    /**
     * Whether login tokens may exist on this site.
     *
     * @return bool
     *
     * @since 2.1.0
     */
    public static function available()
    {
        return Config::environment() !== 'production' && Config::ui_enabled();
    }

    /**
     * Issue a token for a user.
     *
     * @param int      $user_id User to log in as.
     * @param int|null $now     Current timestamp (testing seam).
     *
     * @return string|\WP_Error Plain token; only its hash is stored.
     *
     * @since 2.1.0
     */
    public static function issue($user_id, $now = null)
    {
        if (!self::available()) {
            return new \WP_Error('mmwp_self_test_login_disabled', __('Browser login tokens are not available on production.', Config::TEXT_DOMAIN));
        }

        $now   = $now === null ? time() : (int) $now;
        $token = bin2hex(random_bytes(32));
        $store = self::purge(self::store(), $now);

        $store[self::hash($token)] = ['user' => (int) $user_id, 'expires' => $now + self::TTL, 'sessions' => []];
        self::save($store);

        return $token;
    }

    /**
     * Whether a token is known, unexpired and not yet redeemed.
     *
     * @param string   $token Plain token.
     * @param int|null $now   Current timestamp (testing seam).
     *
     * @return bool
     *
     * @since 2.1.0
     */
    public static function is_valid($token, $now = null)
    {
        if (!self::available() || !is_string($token) || $token === '') {
            return false;
        }

        $store = self::store();
        $key   = self::hash($token);
        $now   = $now === null ? time() : (int) $now;

        return isset($store[$key]) && empty($store[$key]['used']) && $store[$key]['expires'] >= $now;
    }

    /**
     * Redeem a token: valid exactly once. The record stays (marked used) so
     * sessions created by the exchange can be revoked later.
     *
     * @param string   $token Plain token.
     * @param int|null $now   Current timestamp (testing seam).
     *
     * @return int User id, or 0 when the token is invalid, used or expired.
     *
     * @since 2.1.0
     */
    public static function consume($token, $now = null)
    {
        if (!self::is_valid($token, $now)) {
            return 0;
        }

        $store = self::store();
        $key   = self::hash($token);

        $store[$key]['used'] = true;
        self::save($store);

        return (int) $store[$key]['user'];
    }

    /**
     * Remember a WordPress session token created for a redeemed login token.
     *
     * @param string $token      Plain login token.
     * @param string $session_id Value of the new `WP_Session_Tokens` token.
     *
     * @return void
     *
     * @since 2.1.0
     */
    public static function attach_session($token, $session_id)
    {
        $store = self::store();
        $key   = self::hash($token);

        if (!isset($store[$key])) {
            return;
        }

        $store[$key]['sessions'][] = (string) $session_id;
        self::save($store);
    }

    /**
     * Destroy every session created through a login token and drop all
     * records, used or not.
     *
     * @return int Number of sessions destroyed.
     *
     * @since 2.1.0
     */
    public static function revoke_all()
    {
        $destroyed = 0;

        foreach (self::store() as $record) {
            foreach ($record['sessions'] as $session_id) {
                \WP_Session_Tokens::get_instance((int) $record['user'])->destroy($session_id);
                $destroyed++;
            }
        }

        delete_option(self::OPTION);

        return $destroyed;
    }

    /**
     * Hash a plain token for storage.
     *
     * @param string $token Plain token.
     *
     * @return string
     *
     * @since 2.1.0
     */
    private static function hash($token)
    {
        return hash('sha256', (string) $token);
    }

    /**
     * Drop expired, never-used records.
     *
     * @param array $store Stored records.
     * @param int   $now   Current timestamp.
     *
     * @return array
     *
     * @since 2.1.0
     */
    private static function purge(array $store, $now)
    {
        return array_filter($store, function ($record) use ($now) {
            return !empty($record['used']) || $record['expires'] >= $now;
        });
    }

    /**
     * Stored records.
     *
     * @return array
     *
     * @since 2.1.0
     */
    private static function store()
    {
        $store = get_option(self::OPTION, []);

        return is_array($store) ? $store : [];
    }

    /**
     * Persist records without autoloading them.
     *
     * @param array $store Records.
     *
     * @return void
     *
     * @since 2.1.0
     */
    private static function save(array $store)
    {
        update_option(self::OPTION, $store, false);
    }
}
