<?php

namespace EWP\SelfTest\Cases;

use EWP\SelfTest\EWP_Self_Test_Case;
use EWP\SelfTest\WP_CLI_Shim;
use EWP\Surfaces\EWP_Surfaces;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Finds a temporary post by title on every object-search surface.
 *
 * @package EWP\SelfTest
 * @since   1.5.0
 */
class Object_Search_Case extends EWP_Self_Test_Case
{
    /** {@inheritDoc} */
    public function preview()
    {
        return [
            __('Create a private post with a unique title.', 'extend-wp'),
            __('REST: GET /extend-wp/v1/objects/search?object_type=post_type:post&search=<title> — expect {success: true, data: [...]} containing the post.', 'extend-wp'),
            __('CLI: wp ewp objects search <title> --type=post_type:post — expect a table row with the post id.', 'extend-wp'),
            __('Ability: ewp-system/search-objects — expect {count, results} containing the post.', 'extend-wp'),
            __('Cleanup: delete the post.', 'extend-wp'),
        ];
    }

    /** {@inheritDoc} */
    public function run()
    {
        $title   = 'Self-test object ' . substr(md5(uniqid('', true)), 0, 8);
        $post_id = wp_insert_post(['post_title' => $title, 'post_type' => 'post', 'post_status' => 'private']);
        $context = ['post_id' => (int) $post_id, 'title' => $title, 'observed' => []];
        $o       = &$context['observed'];

        if ($this->cli_available()) {
            WP_CLI_Shim::load_plugin_commands();
        }

        $o['rest']    = $this->rest('GET', '/extend-wp/v1/objects/search', ['object_type' => 'post_type:post', 'search' => $title]);
        $o['ability'] = $this->ability('ewp-system/search-objects', ['object_type' => 'post_type:post', 'search' => $title]);
        $o['cli']     = $this->cli(EWP_Surfaces::cli('objects', 'search'), [$title], ['type' => 'post_type:post', 'format' => 'json']);

        return $context;
    }

    /** {@inheritDoc} */
    public function validate(array $context)
    {
        $o   = $context['observed'];
        $id  = (int) $context['post_id'];
        $has = function ($rows) use ($id) {
            foreach ((array) $rows as $row) {
                if (isset($row['id']) && (int) $row['id'] === $id) {
                    return true;
                }
            }
            return false;
        };

        return [
            $this->check('rest', 'GET /objects/search finds the post', $this->status($o, 'rest') === 200 && !empty($o['rest']['data']['success']) && $has($o['rest']['data']['data']), $this->detail($o, 'rest')),
            $this->ability_check($o, 'ability', 'ewp-system/search-objects finds the post', function ($data) use ($has) {
                return isset($data['results']) && $has($data['results']);
            }),
            $this->cli_check_printed($o, 'cli', 'wp ewp objects search prints the post', function ($printed) use ($has) {
                foreach ((array) $printed as $chunk) {
                    if (isset($chunk['items']) && $has($chunk['items'])) {
                        return true;
                    }
                }
                return false;
            }),
        ];
    }

    /** {@inheritDoc} */
    public function cleanup(array $context)
    {
        if (!empty($context['post_id'])) {
            wp_delete_post((int) $context['post_id'], true);
            return [sprintf(__('Deleted post #%d.', 'extend-wp'), (int) $context['post_id'])];
        }

        return [];
    }
}
