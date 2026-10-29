<?php

namespace EWP\Fields\Encryption;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Parser for the `ewp_encrypt[]` field paths posted by options pages.
 *
 * A path is either a plain field name (`api_key`) or bracket notation
 * (`provider_config[openai_api_key]`) addressing a key inside an array option.
 *
 * @package EWP\Fields\Encryption
 * @since 1.11.0
 */
final class Option_Field_Path
{
    /**
     * Split a path into option name and field key.
     *
     * @param string $field_path Path from an `ewp_encrypt[]` marker.
     * @return array{option: string|null, field: string}|null `option` is null for a plain name; null when the bracket notation is malformed.
     */
    public static function parse($field_path)
    {
        if (strpos($field_path, '[') === false) {
            return array(
                'option' => null,
                'field'  => $field_path,
            );
        }

        preg_match('/^([^\[]+)\[([^\]]+)\]$/', $field_path, $matches);
        if (count($matches) !== 3) {
            return null;
        }

        return array(
            'option' => $matches[1],
            'field'  => $matches[2],
        );
    }
}
