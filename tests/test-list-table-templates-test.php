<?php
/**
 * Legacy custom content list (AWM_List_Table) chrome rendered from
 * templates/admin-view/list-table/ (since 1.14.0): status views, search box,
 * filter bar and table navigation.
 */

class Test_List_Table_Templates extends WP_UnitTestCase
{
    /**
     * Render a list-table template.
     *
     * @param string $name      Template file name.
     * @param array  $variables Template variables.
     * @return string
     */
    private function render($name, array $variables)
    {
        return awm_parse_template(awm_path . 'templates/admin-view/list-table/' . $name, $variables);
    }

    /**
     * All first, then only statuses with rows; urls carry ewp_status; current marked.
     *
     * @return void
     */
    public function test_status_views()
    {
        $statuses = array('enabled' => array('label' => 'Enabled'), 'disabled' => array('label' => 'Disabled'), 'empty' => array('label' => 'None'));
        $views    = AWM_List_Table::status_views($statuses, array('items' => 5, 'enabled' => 3, 'disabled' => 2), 'https://example.org/wp-admin/admin.php?page=x', 'disabled');

        $this->assertSame(array('all', 'enabled', 'disabled'), wp_list_pluck($views, 'key'));
        $this->assertSame(array(false, false, true), wp_list_pluck($views, 'current'));
        $this->assertSame('https://example.org/wp-admin/admin.php?page=x&ewp_status=enabled', $views[1]['url']);
        $this->assertSame(5, $views[0]['count']);
    }

    /**
     * Views markup: valid <li class>, escaped label, counts, separators, aria-current.
     *
     * @return void
     */
    public function test_views_template()
    {
        $html = $this->render('views.php', array('views' => array(
            array('key' => 'all', 'url' => 'https://example.org/?a=1&b=2', 'label' => 'All', 'count' => 5, 'current' => true),
            array('key' => 'x"y', 'url' => 'javascript:alert(1)', 'label' => 'Lbl<script>z</script>', 'count' => 2, 'current' => false),
        )));
        $this->assertStringContainsString('<li class="all">', $html);
        $this->assertStringNotContainsString('<li "all">', $html);
        $this->assertStringContainsString('class="current" aria-current="page"', $html);
        $this->assertStringContainsString('<li class="x&quot;y"> | ', $html);
        $this->assertStringNotContainsString('javascript:', $html);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('<span class="count">(2)</span>', $html);
    }

    /**
     * Search box escapes label and value; filter bar has a class, no inline style.
     *
     * @return void
     */
    public function test_search_and_filters_templates()
    {
        $search = $this->render('search-box.php', array('text' => 'Search <b>', 'input_id' => 'x-search', 'value' => '"><script>', 'submit_html' => '<input type="submit">'));
        $this->assertStringContainsString('Search &lt;b&gt;', $search);
        $this->assertStringContainsString('value="&quot;&gt;&lt;script&gt;"', $search);

        $filters = $this->render('filters.php', array('filters_html' => '<select></select>', 'page_id' => 'p"1', 'submit_html' => ''));
        $this->assertStringContainsString('<div class="ewp-list-filters"><select></select></div>', $filters);
        $this->assertStringContainsString('value="p&quot;1"', $filters);
        $this->assertStringNotContainsString('style=', $filters);
    }

    /**
     * The filter-bar style is registered for lazy loading.
     *
     * @return void
     */
    public function test_filter_style_registered()
    {
        $found = wp_list_filter(apply_filters('ewp_register_dynamic_assets', array()), array('handle' => 'ewp-list-table'));
        $this->assertCount(1, $found);
        $asset = reset($found);
        $this->assertSame('.ewp-list-filters', $asset['selector']);
        $this->assertSame('style', $asset['type']);
        $this->assertFileExists(awm_path . 'assets/css/admin/ewp-list-table.min.css');
    }
}
