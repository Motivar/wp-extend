<?php
/**
 * Block sidebar selects: EWP_Dynamic_Blocks::prepare_attributes() passes each
 * option's `filter_values` to the editor script as strings, leaves options
 * without it unchanged, keeps `filter_options_by`, and types a
 * `multiple` select as an array.
 */

class Test_Block_Select_Options extends WP_UnitTestCase
{
    /**
     * Run the private prepare_attributes() on one attribute library.
     *
     * @param array $attributes Block attributes, keyed by attribute name.
     * @return array Prepared attributes.
     */
    private function prepare(array $attributes)
    {
        $reflection = new ReflectionMethod(EWP_Dynamic_Blocks::instance(), 'prepare_attributes');
        $reflection->setAccessible(true);
        return $reflection->invoke(EWP_Dynamic_Blocks::instance(), $attributes, 'ewp-test/select-block');
    }

    /**
     * The prepared options of one attribute, keyed by option value.
     *
     * @param array  $prepared Result of prepare().
     * @param string $key      Attribute name.
     * @return array<string,array>
     */
    private function options(array $prepared, $key)
    {
        $options = [];
        foreach ($prepared[$key]['options'] as $option) {
            $options[(string) $option['option']] = $option;
        }
        return $options;
    }

    public function test_filter_values_reach_the_editor_as_strings()
    {
        $prepared = $this->prepare([
            'taxonomy' => [
                'label' => 'Taxonomy',
                'case' => 'select',
                'filter_options_by' => 'post_type',
                'options' => [
                    '' => ['label' => 'Select taxonomy'],
                    'category' => ['label' => 'Categories', 'filter_values' => ['post', 'page']],
                    'year' => ['label' => 'Years', 'filter_values' => [2024, 2025]],
                ],
            ],
        ]);
        $options = $this->options($prepared, 'taxonomy');

        $this->assertSame(['post', 'page'], $options['category']['filter_values']);
        $this->assertSame(['2024', '2025'], $options['year']['filter_values'], 'Values are cast to strings, so they compare with attribute values in JS.');
        $this->assertArrayNotHasKey('filter_values', $options[''], 'An option without filter_values is always offered.');
        $this->assertSame('Categories', $options['category']['label']);
    }

    public function test_filter_options_by_is_kept()
    {
        $prepared = $this->prepare([
            'taxonomy' => [
                'case' => 'select',
                'filter_options_by' => '@post_type',
                'options' => ['category' => ['label' => 'Categories', 'filter_values' => ['post']]],
            ],
        ]);

        $this->assertSame('@post_type', $prepared['taxonomy']['filter_options_by']);
    }

    public function test_keyed_filter_values_become_a_list()
    {
        $prepared = $this->prepare([
            'term' => [
                'case' => 'select',
                'filter_options_by' => 'taxonomy',
                'options' => ['5' => ['label' => 'News', 'filter_values' => ['a' => 'category']]],
            ],
        ]);
        $options = $this->options($prepared, 'term');

        $this->assertSame(['category'], $options['5']['filter_values'], 'A keyed array reaches JS as a plain list.');
    }

    public function test_non_array_filter_values_are_ignored()
    {
        $prepared = $this->prepare([
            'term' => [
                'case' => 'select',
                'options' => ['5' => ['label' => 'News', 'filter_values' => 'category']],
            ],
        ]);
        $options = $this->options($prepared, 'term');

        $this->assertArrayNotHasKey('filter_values', $options['5']);
    }

    public function test_select_without_filters_is_unchanged()
    {
        $prepared = $this->prepare([
            'layout' => [
                'case' => 'select',
                'options' => [
                    'grid' => ['label' => 'Grid'],
                    'list' => ['label' => 'List'],
                ],
            ],
        ]);

        $this->assertSame(
            [['option' => 'grid', 'label' => 'Grid'], ['option' => 'list', 'label' => 'List']],
            $prepared['layout']['options']
        );
        $this->assertArrayNotHasKey('multiple', $prepared['layout']);
    }

    public function test_multiple_select_is_flagged_for_the_editor()
    {
        $prepared = $this->prepare([
            'post_ids' => [
                'case' => 'select',
                'attributes' => ['multiple' => true],
                'options' => ['1' => ['label' => 'Hello world']],
            ],
        ]);

        $this->assertTrue($prepared['post_ids']['multiple']);
        $this->assertSame('array', $prepared['post_ids']['type']);
        $this->assertSame([], $prepared['post_ids']['default'], 'An unset multiple select defaults to an empty array.');
    }
}
