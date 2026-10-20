<?php
/**
 * Object search (1.5.7): `include` fetches given ids of the searched type,
 * the capability follows the object type (post type `edit_posts`, taxonomy
 * `assign_terms`, else `manage_options`), and posts the user cannot read are
 * left out.
 */

class Test_Object_Search extends WP_UnitTestCase
{
    /**
     * GET /extend-wp/v1/objects/search as the current user.
     *
     * @param array $params Query parameters.
     * @return WP_REST_Response
     */
    private function rest_search(array $params)
    {
        $request = new WP_REST_Request('GET', '/extend-wp/v1/objects/search');
        foreach ($params as $name => $value) {
            $request->set_param($name, $value);
        }
        return rest_do_request($request);
    }

    /**
     * Ids in a REST search response.
     *
     * @param WP_REST_Response $response Response.
     * @return int[]
     */
    private function ids(WP_REST_Response $response)
    {
        $data = $response->get_data();
        return array_map('intval', wp_list_pluck(isset($data['data']) ? $data['data'] : [], 'id'));
    }

    /**
     * Test: Include returns only ids of the searched type.
     *
     * @return void
     */
    public function test_include_returns_only_ids_of_the_searched_type()
    {
        $post = self::factory()->post->create(['post_title' => 'Included post']);
        $page = self::factory()->post->create(['post_type' => 'page', 'post_title' => 'A page']);
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));

        $response = $this->rest_search(['object_type' => 'post_type:post', 'include' => [$post, $page]]);

        $this->assertSame(200, $response->get_status());
        $this->assertSame([$post], $this->ids($response));
        $this->assertSame('Included post', $response->get_data()['data'][0]['label']);
    }

    /**
     * Test: Include accepts a comma separated list.
     *
     * @return void
     */
    public function test_include_accepts_a_comma_separated_list()
    {
        $a = self::factory()->post->create();
        $b = self::factory()->post->create();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));

        $response = $this->rest_search(['object_type' => 'post_type:post', 'include' => "$a,$b"]);

        $this->assertEqualsCanonicalizing([$a, $b], $this->ids($response));
    }

    /**
     * Test: Capability follows the object type.
     *
     * @return void
     */
    public function test_capability_follows_the_object_type()
    {
        $this->assertSame('edit_posts', \EWP\Search\Object_Search::capability('post_type:post'));
        $this->assertSame('edit_pages', \EWP\Search\Object_Search::capability('post_type:page'));
        $this->assertSame(get_taxonomy('category')->cap->assign_terms, \EWP\Search\Object_Search::capability('taxonomy:category'));
        $this->assertSame('manage_options', \EWP\Search\Object_Search::capability('custom_content:ewp_fields'));
        $this->assertSame('manage_options', \EWP\Search\Object_Search::capability('post_type:no_such_type'));
        $this->assertSame('manage_options', \EWP\Search\Object_Search::capability(''));
    }

    /**
     * Test: Capability filter receives the object type.
     *
     * @return void
     */
    public function test_capability_filter_receives_the_object_type()
    {
        $seen = null;
        $filter = function ($capability, $object_type) use (&$seen) {
            $seen = $object_type;
            return 'manage_options';
        };
        add_filter('awm_object_search_capability', $filter, 10, 2);

        $this->assertSame('manage_options', \EWP\Search\Object_Search::capability('post_type:post'));
        $this->assertSame('post_type:post', $seen);
        remove_filter('awm_object_search_capability', $filter, 10);
    }

    /**
     * Test: An author can search posts but not custom content.
     *
     * @return void
     */
    public function test_an_author_can_search_posts_but_not_custom_content()
    {
        self::factory()->post->create(['post_title' => 'Searchable needle']);
        wp_set_current_user(self::factory()->user->create(['role' => 'author']));

        $this->assertSame(200, $this->rest_search(['object_type' => 'post_type:post', 'search' => 'needle'])->get_status());
        $this->assertSame(403, $this->rest_search(['object_type' => 'custom_content:ewp_fields', 'search' => 'x'])->get_status());
    }

    /**
     * Test: A subscriber cannot search.
     *
     * @return void
     */
    public function test_a_subscriber_cannot_search()
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'subscriber']));

        $this->assertSame(403, $this->rest_search(['object_type' => 'post_type:post', 'search' => 'a'])->get_status());
    }

    /**
     * Test: Posts the user cannot read are left out.
     *
     * @return void
     */
    public function test_posts_the_user_cannot_read_are_left_out()
    {
        $admin = self::factory()->user->create(['role' => 'administrator']);
        $public = self::factory()->post->create(['post_title' => 'Needle public', 'post_author' => $admin]);
        $private = self::factory()->post->create(['post_title' => 'Needle private', 'post_author' => $admin, 'post_status' => 'private']);
        wp_set_current_user(self::factory()->user->create(['role' => 'contributor']));

        $ids = $this->ids($this->rest_search(['object_type' => 'post_type:post', 'search' => 'Needle', 'search_meta' => false]));

        $this->assertContains($public, $ids);
        $this->assertNotContains($private, $ids, 'A contributor cannot read another author\'s private post.');
    }
}
