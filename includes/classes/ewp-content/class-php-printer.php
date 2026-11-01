<?php

namespace EWP\Content;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Turns PHP values into valid, properly escaped PHP source.
 *
 * Arrays print as short arrays with stable indentation, ints, floats, bools
 * and null stay native, strings are single-quoted with `\` and `'` escaped, and
 * the string value of a translatable key (label, title, explanation by
 * default) is wrapped in `__('…', 'extend-wp')`. Output only depends on the
 * input and the printer settings, so the same data always prints the same code.
 *
 * @package    EWP\Content
 * @author     Motivar
 *
 * @since 1.15.0
 */
final class Php_Printer
{
    /** Keys whose string values are wrapped in a translation call by default. */
    const DEFAULT_TRANSLATABLE = ['label', 'title', 'explanation'];

    /** @var string[] */
    private $translatable;

    /** @var string */
    private $text_domain;

    /** @var string */
    private $indent;

    /**
     * @param string[] $translatable Keys whose non-empty string values are wrapped in `__()`.
     * @param string   $text_domain  Text domain of the translation calls.
     * @param string   $indent       One indentation level.
     */
    public function __construct(array $translatable = self::DEFAULT_TRANSLATABLE, $text_domain = 'extend-wp', $indent = '    ')
    {
        $this->translatable = array_values(array_map('strval', $translatable));
        $this->text_domain  = (string) $text_domain;
        $this->indent       = (string) $indent;
    }

    /**
     * Print a value as a PHP expression.
     *
     * @param mixed  $value Scalar, null, array or object (printed as an array).
     * @param int    $depth Indentation level of the line the expression starts on.
     * @param string $key   Array key the value belongs to, decides translation wrapping.
     *
     * @return string PHP expression without a trailing semicolon.
     */
    public function print_value($value, $depth = 0, $key = '')
    {
        if (is_object($value)) {
            $value = (array) $value;
        }
        if (is_array($value)) {
            return $this->print_array($value, $depth);
        }
        if ($value === null) {
            return 'null';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_int($value)) {
            return (string) $value;
        }
        if (is_float($value)) {
            return $this->print_float($value);
        }
        if (!is_scalar($value)) {
            return 'null';
        }

        return $this->print_string((string) $value, (string) $key);
    }

    /**
     * Print an array as a short array literal, one entry per line.
     *
     * Sequential lists (0, 1, 2, …) print without keys.
     *
     * @param array $items Array to print.
     * @param int   $depth Indentation level of the line the literal starts on.
     *
     * @return string PHP expression.
     */
    public function print_array(array $items, $depth = 0)
    {
        if ($items === []) {
            return '[]';
        }

        return "[\n" . $this->print_items($items, $depth + 1) . "\n" . str_repeat($this->indent, $depth) . ']';
    }

    /**
     * Print the entries of an array without the surrounding brackets, one per line, comma separated.
     *
     * @param array $items Array whose entries to print.
     * @param int   $depth Indentation level of the entries.
     *
     * @return string Entries, joined with ",\n" (no trailing comma).
     */
    public function print_items(array $items, $depth = 0)
    {
        $is_list = $this->is_list($items);
        $pad     = str_repeat($this->indent, $depth);
        $lines   = [];
        foreach ($items as $key => $item) {
            $prefix  = $is_list ? '' : $this->print_key($key) . ' => ';
            $lines[] = $pad . $prefix . $this->print_value($item, $depth, is_string($key) ? $key : '');
        }

        return implode(",\n", $lines);
    }

    /**
     * Quote a string as a single-quoted PHP literal.
     *
     * @param string $text Raw text.
     *
     * @return string Literal such as 'It\'s'.
     */
    public function quote($text)
    {
        return "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], (string) $text) . "'";
    }

    /**
     * Print an array key.
     *
     * @param int|string $key Array key.
     *
     * @return string
     */
    private function print_key($key)
    {
        return is_int($key) ? (string) $key : $this->quote($key);
    }

    /**
     * Print a string, wrapped in a translation call when its key is translatable.
     *
     * @param string $text Value.
     * @param string $key  Array key the value belongs to.
     *
     * @return string
     */
    private function print_string($text, $key)
    {
        if ($text !== '' && $key !== '' && in_array($key, $this->translatable, true)) {
            return '__(' . $this->quote($text) . ', ' . $this->quote($this->text_domain) . ')';
        }

        return $this->quote($text);
    }

    /**
     * Print a float so it parses back to the same float.
     *
     * @param float $value Number.
     *
     * @return string
     */
    private function print_float($value)
    {
        if (is_nan($value) || is_infinite($value)) {
            return 'null';
        }

        return var_export($value, true);
    }

    /**
     * Whether the keys are exactly 0..n-1 in order.
     *
     * @param array $items Array to check.
     *
     * @return bool
     */
    private function is_list(array $items)
    {
        return array_keys($items) === range(0, count($items) - 1);
    }
}
