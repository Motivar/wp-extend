<?php
/**
 * AWM_Meta::meta_box_html() / templates/admin-view/meta-box.php: one template
 * for the post, content, term (add/edit) and user box layouts (since 1.14.0).
 */

class Test_Meta_Box_Template extends WP_UnitTestCase
{
    /** @var array Box with markup to escape in the id and title. */
    private $box = array('id' => 'box"1', 'title' => 'Title <b>ok</b><script>x</script>', 'explanation' => 'Explain');

    /**
     * Every layout ends with the two save markers, escaped.
     *
     * @return void
     */
    public function test_markers_in_every_layout()
    {
        foreach (array('post', 'content', 'term-add', 'term-edit', 'user') as $layout) {
            $html = AWM_Meta::meta_box_html($layout, $this->box, '<p>FIELDS</p>', 'term');
            $this->assertStringContainsString('<p>FIELDS</p>', $html, $layout);
            $this->assertStringContainsString('name="awm_metabox[]" value="box&quot;1"', $html, $layout);
            $this->assertStringContainsString('name="awm_metabox_case" value="term"', $html, $layout);
        }
    }

    /**
     * Titled layouts escape the title (basic HTML kept) and the id.
     *
     * @return void
     */
    public function test_titles_and_ids_escaped()
    {
        $add = AWM_Meta::meta_box_html('term-add', $this->box, '', 'term');
        $this->assertStringContainsString('id="awm-table-box&quot;1"', $add);
        $this->assertStringContainsString('<h2>Title <b>ok</b>x</h2>', $add);
        $this->assertStringNotContainsString('<script>', $add);
        $this->assertStringContainsString('<div class="awm-box-explanation">Explain</div>', $add);

        $user = AWM_Meta::meta_box_html('user', $this->box, '<tr><td>F</td></tr>', 'user');
        $this->assertStringContainsString('<table class="form-table"><tr><td>F</td></tr></table>', $user);
    }

    /**
     * The edit-term layout is a heading row followed by the field rows; the
     * content layout has no explanation.
     *
     * @return void
     */
    public function test_term_edit_and_content_layouts()
    {
        $edit = AWM_Meta::meta_box_html('term-edit', $this->box, '<tr><td>F</td></tr>', 'term');
        $this->assertMatchesRegularExpression('#^\s*<tr><td colspan="2"><h2>.*</h2><div class="awm-box-explanation">Explain</div></td></tr>\s*<tr><td>F</td></tr>#s', $edit);

        $this->assertStringNotContainsString('awm-box-explanation', AWM_Meta::meta_box_html('content', $this->box, 'F', 'post'));
        $this->assertStringContainsString('awm-box-explanation', AWM_Meta::meta_box_html('post', $this->box, 'F', 'post'));
    }
}
