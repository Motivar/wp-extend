<?php

namespace EWP\SelfTest\Cases;

use MammothWP\SelfTest\Case_Base;
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
class Object_Search_Case extends Case_Base
{
    /** {@inheritDoc} */
    public function preview()
    {
        return [
            __('Create a private post with a unique title.', 'extend-wp'),
            __('REST: GET /extend-wp/v1/objects/search?object_type=post_type:post&search=<title> — expect {success: true, data: [...]} containing the post.', 'extend-wp'),
            __('CLI: wp ewp objects search <title> --type=post_type:post — expect a table row with the post id.', 'extend-wp'),
            __('Ability: ewp-system/search-objects — expect {count, results} containing the post.', 'extend-wp'),
            __('include: the same three surfaces with include=<post id>,<page id> — expect only the post (the page is another type), as the block editor labels saved ids.', 'extend-wp'),
            __('Cleanup: delete the post.', 'extend-wp'),
        ];
    }

    /** {@inheritDoc} */
    public function run()
    {
        $title   = 'Self-test object ' . substr(md5(uniqid('', true)), 0, 8);
        $post_id = wp_insert_post(['post_title' => $title, 'post_type' => 'post', 'post_status' => 'private']);
        $page_id = wp_insert_post(['post_title' => $title . ' page', 'post_type' => 'page', 'post_status' => 'private']);
        $context = ['post_id' => (int) $post_id, 'page_id' => (int) $page_id, 'title' => $title, 'observed' => []];
        $o       = &$context['observed'];
        $include = [(int) $post_id, (int) $page_id];

        $o['rest']    = $this->rest('GET', '/extend-wp/v1/objects/search', ['object_type' => 'post_type:post', 'search' => $title]);
        $o['ability'] = $this->ability('ewp-system/search-objects', ['object_type' => 'post_type:post', 'search' => $title]);
        $o['cli']     = $this->cli(EWP_Surfaces::cli('objects', 'search'), [$title], ['type' => 'post_type:post', 'format' => 'json']);

        $o['rest_include']    = $this->rest('GET', '/extend-wp/v1/objects/search', ['object_type' => 'post_type:post', 'include' => implode(',', $include)]);
        $o['ability_include'] = $this->ability('ewp-system/search-objects', ['object_type' => 'post_type:post', 'include' => $include]);
        $o['cli_include']     = $this->cli(EWP_Surfaces::cli('objects', 'search'), [], ['type' => 'post_type:post', 'include' => implode(',', $include), 'format' => 'json']);

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
        $only = function ($rows) use ($id) {
            $ids = array_map('intval', wp_list_pluck(array_values((array) $rows), 'id'));
            return $ids === [$id];
        };
        $printed_only = function ($printed) use ($only) {
            foreach ((array) $printed as $chunk) {
                if (isset($chunk['items']) && $only($chunk['items'])) {
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
            $this->check('rest', 'GET /objects/search?include= returns only the post of the type', $this->status($o, 'rest_include') === 200 && $only($o['rest_include']['data']['data']), $this->detail($o, 'rest_include')),
            $this->ability_check($o, 'ability_include', 'ewp-system/search-objects include returns only the post', function ($data) use ($only) {
                return isset($data['results']) && $only($data['results']);
            }),
            $this->cli_check_printed($o, 'cli_include', 'wp ewp objects search --include prints only the post', $printed_only),
        ];
    }

    /** {@inheritDoc} */
    public function cleanup(array $context)
    {
        $notes = [];
        foreach (['post_id', 'page_id'] as $key) {
            if (!empty($context[$key])) {
                wp_delete_post((int) $context[$key], true);
                $notes[] = sprintf(/* translators: %d: ID */ __('Deleted post #%d.', 'extend-wp'), (int) $context[$key]);
            }
        }

        return $notes;
    }
}
