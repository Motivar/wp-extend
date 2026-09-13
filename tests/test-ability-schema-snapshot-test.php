<?php
/**
 * Pins the input/output schemas of the typed content abilities
 * (ewp-fields, ewp-wp-content, ewp-search) so migrating them onto the
 * kit cannot silently change what AI callers see.
 *
 * The first run writes tests/fixtures/typed-ability-schemas.json; every
 * later run compares against it. Regenerate deliberately by deleting the
 * file when a schema change is intended, and say so in the changelog.
 */
class Test_Ability_Schema_Snapshot extends WP_UnitTestCase
{
    const CATEGORIES = ['ewp-fields', 'ewp-wp-content', 'ewp-search'];

    public function test_typed_ability_schemas_match_the_snapshot()
    {
        if (!function_exists('wp_get_abilities')) {
            $this->markTestSkipped('Abilities API not available.');
        }

        $current = [];
        foreach (wp_get_abilities() as $ability) {
            if (!in_array($ability->get_category(), self::CATEGORIES, true)) {
                continue;
            }
            $current[$ability->get_name()] = [
                'input'  => $ability->get_input_schema(),
                'output' => $ability->get_output_schema(),
            ];
        }
        ksort($current);
        $this->assertNotEmpty($current, 'typed abilities must be registered');

        $file = __DIR__ . '/fixtures/typed-ability-schemas.json';
        if (!file_exists($file)) {
            file_put_contents($file, wp_json_encode($current, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
            $this->markTestSkipped('Snapshot written to tests/fixtures/typed-ability-schemas.json; re-run to compare.');
        }

        $expected = $this->canonical(json_decode(file_get_contents($file), true));
        $actual   = $this->canonical(json_decode(wp_json_encode($current), true));

        $this->assertSame(array_keys($expected), array_keys($actual), 'the set of typed abilities changed');
        foreach ($expected as $name => $schemas) {
            $this->assertSame($schemas, $actual[$name], "schema drift in {$name}");
        }
    }

    /**
     * Sort object keys recursively; JSON object key order carries no meaning,
     * while list order (required, enum) is kept.
     */
    private function canonical($value)
    {
        if (!is_array($value)) {
            return $value;
        }
        $is_list = array_keys($value) === range(0, count($value) - 1);
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonical($item);
        }
        if (!$is_list) {
            ksort($value);
        }

        return $value;
    }
}
