<?php
/**
 * Parser for pre-built SQL IN lists such as "('a','b',3)".
 *
 * @package extend-wp
 */

namespace EWP\Db;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Turns the IN-list strings older callers build by hand into plain arrays,
 * so the values can be bound as placeholders like any array.
 *
 * Accepted grammar: `(` item (`,` item)* `)` or `()`, where an item is a
 * single- or double-quoted string (`\'`, `\"`, `\\` and doubled quotes
 * allowed) or a numeric literal. Anything else — unbalanced quotes, SQL
 * keywords, sub-selects, comments — is rejected, never concatenated.
 */
final class Legacy_In_List
{
    /**
     * Parse a legacy IN list.
     *
     * @param string $list String such as "('a','b')", "(1, 2)" or "()".
     * @return array|null Values (strings, or ints/floats for numeric literals); null when the string is not a plain list.
     */
    public static function parse($list)
    {
        if (!is_string($list)) {
            return null;
        }
        $list = trim($list);
        if (strlen($list) < 2 || $list[0] !== '(' || substr($list, -1) !== ')') {
            return null;
        }
        $body = trim(substr($list, 1, -1));
        if ($body === '') {
            return array();
        }
        return self::parse_items($body);
    }

    /**
     * Parse the comma-separated items between the parentheses.
     *
     * @param string $body List body without the outer parentheses.
     * @return array|null Values, null on any syntax error.
     */
    private static function parse_items($body)
    {
        $values = array();
        $length = strlen($body);
        $pos    = 0;
        while (true) {
            $pos  = self::skip_spaces($body, $pos);
            $item = self::read_item($body, $pos);
            if ($item === null) {
                return null;
            }
            $values[] = $item['value'];
            $pos      = self::skip_spaces($body, $item['end']);
            if ($pos === $length) {
                return $values;
            }
            if ($body[$pos] !== ',') {
                return null;
            }
            $pos++;
        }
    }

    /**
     * Read one quoted string or numeric literal starting at $pos.
     *
     * @param string $body Text being parsed.
     * @param int    $pos  Offset of the item.
     * @return array|null { value, end } where end is the offset after the item; null when invalid.
     */
    private static function read_item($body, $pos)
    {
        if ($pos >= strlen($body)) {
            return null;
        }
        $char = $body[$pos];
        if ($char === "'" || $char === '"') {
            return self::read_quoted($body, $pos, $char);
        }
        if (preg_match('/\G-?\d+(?:\.\d+)?/', $body, $m, 0, $pos)) {
            $literal = $m[0];
            $value   = strpos($literal, '.') === false ? (int) $literal : (float) $literal;
            return array('value' => $value, 'end' => $pos + strlen($literal));
        }
        return null;
    }

    /**
     * Read a quoted string, undoing backslash escapes and doubled quotes.
     *
     * @param string $body  Text being parsed.
     * @param int    $pos   Offset of the opening quote.
     * @param string $quote The quote character.
     * @return array|null { value, end }; null when the string is not closed.
     */
    private static function read_quoted($body, $pos, $quote)
    {
        $length = strlen($body);
        $value  = '';
        for ($i = $pos + 1; $i < $length; $i++) {
            $char = $body[$i];
            if ($char === '\\' && $i + 1 < $length) {
                $value .= $body[++$i];
                continue;
            }
            if ($char === $quote && $i + 1 < $length && $body[$i + 1] === $quote) {
                $value .= $quote;
                $i++;
                continue;
            }
            if ($char === $quote) {
                return array('value' => $value, 'end' => $i + 1);
            }
            $value .= $char;
        }
        return null;
    }

    /**
     * Offset of the next non-space character.
     *
     * @param string $body Text being parsed.
     * @param int    $pos  Start offset.
     * @return int Offset (equal to the length at the end).
     */
    private static function skip_spaces($body, $pos)
    {
        $length = strlen($body);
        while ($pos < $length && ctype_space($body[$pos])) {
            $pos++;
        }
        return $pos;
    }
}
