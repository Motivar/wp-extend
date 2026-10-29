<?php

namespace EWP\Fields;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Renders a field library: the implementation behind awm_show_content().
 *
 * Per field: visibility (language, `not_visible_by` / `not_editable_by`
 * roles), preparation (awm_prepare_field(), stored value, masking, default,
 * id override, wrapper classes), the label, the Field_Type's markup, the
 * `awm_custom_meta[]` save marker and the view's row wrapper. All markup
 * comes from templates under templates/fields/ (wrapper/*, label,
 * explanation, html, save-marker, container).
 *
 * Re-entrant: section, tab and repeater types render nested libraries
 * through awm_show_content() while an outer render is in progress, so no
 * per-render state is kept on the instance.
 *
 * @package EWP\Fields
 * @since 1.11.0
 */
final class Field_Renderer
{
    /** @var string[] Cases that print their own title instead of the shared label. */
    const OWN_LABEL_CASES = array('checkbox_multiple', 'repeater', 'awm_tab', 'button');

    /** @var string[] Input types rendered without the shared label. */
    const UNLABELLED_INPUT_TYPES = array('submit', 'hidden', 'button');

    /** @var self|null Shared instance used by awm_show_content(). */
    private static $instance;

    /** @var string[]|null Current user's roles, resolved once per request. */
    private static $current_roles;

    /** @var Template_Loader Template resolver. */
    private $templates;

    /** @var Field_Registry|null Type registry (null: the shared one, looked up lazily). */
    private $registry;

    /** @var string[]|null Roles to evaluate visibility rules against (null: current user). */
    private $roles;

    /**
     * @param Template_Loader|null $templates Template resolver (default: plugin templates).
     * @param Field_Registry|null  $registry  Type registry (default: Field_Registry::instance()).
     * @param string[]|null        $roles     Roles for `not_visible_by` / `not_editable_by` (default: current user's).
     */
    public function __construct(?Template_Loader $templates = null, ?Field_Registry $registry = null, ?array $roles = null)
    {
        $this->templates = $templates ?: new Template_Loader();
        $this->registry  = $registry;
        $this->roles     = $roles;
    }

    /**
     * Shared instance.
     *
     * @return self
     */
    public static function instance()
    {
        if (!self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Drop the shared instance and the cached current-user roles (tests).
     *
     * @return void
     */
    public static function reset()
    {
        self::$instance      = null;
        self::$current_roles = null;
    }

    /**
     * Render a field library. Same contract as awm_show_content().
     *
     * @param array      $library     Field library (meta key => definition), optional `awm-id` entry for the container id.
     * @param int|array  $object_id   Post/term/user id, 0, or the widget instance (view `widget`).
     * @param string     $view        post|term|user|option|widget|restrict_manage_posts|none.
     * @param string     $target      edit|display (passed to `awm_show_content_filter`).
     * @param bool       $show_labels Passed to `awm_show_content_filter`.
     * @param string     $specific    Render only this key (leading `_` stripped), '' for all.
     * @param string     $sep         Separator for display values (passed to the filter).
     * @param string     $context_id  Context id, e.g. the options page id.
     * @return string|array HTML; '' when nothing rendered; an empty array for an empty library (legacy).
     */
    public function render(array $library, $object_id = 0, $view = 'post', $target = 'edit', $show_labels = true, $specific = '', $sep = ', ', $context_id = '')
    {
        global $awm_post_id;
        $awm_post_id = $object_id;
        if (!isset($GLOBALS['awm_field_configs'])) {
            $GLOBALS['awm_field_configs'] = array();
        }

        $container_id = '';
        if (isset($library['awm-id'])) {
            $container_id = $library['awm-id'];
            unset($library['awm-id']);
        }
        if (empty($library)) {
            return array();
        }

        $args = array(
            'object_id'    => $object_id,
            'view'         => $view,
            'specific'     => (string) $specific,
            'context_id'   => $context_id,
            'container_id' => $container_id,
        );

        $library   = self::sort($library);
        $fragments = array();
        $counted   = 0;
        foreach ($library as $key => $field) {
            $field = $this->apply_visibility((array) $field);
            if (null === $field) {
                if (!awm_field_hidden_in_current_language($library[$key])) {
                    unset($library[$key]);
                }
                continue;
            }
            $row = $this->render_field((string) $key, $field, $args);
            if (null === $row) {
                continue;
            }
            $fragments = $row['replace_all'] ? array($row['html']) : array_merge($fragments, array($row['html']));
            $counted  += $row['counted'] ? 1 : 0;
        }

        return $this->finish($fragments, $library, $counted, $args, $target, $show_labels, $sep);
    }

    /**
     * Sort a library by `order` (default 100), keeping keys.
     *
     * @param array $library Field library.
     * @return array
     */
    public static function sort(array $library)
    {
        uasort($library, function ($a, $b) {
            $first  = isset($a['order']) ? absint($a['order']) : 100;
            $second = isset($b['order']) ? absint($b['order']) : 100;
            return $first - $second;
        });
        return $library;
    }

    /**
     * Apply language and role rules to one field.
     *
     * Administrators are exempt from role rules. `not_visible_by` hides the
     * field; `not_editable_by` renders it disabled and read-only.
     *
     * @param array $field Field definition.
     * @return array|null The (possibly disabled) field, null when it must not render.
     */
    public function apply_visibility(array $field)
    {
        if (awm_field_hidden_in_current_language($field)) {
            return null;
        }
        $roles = null !== $this->roles ? $this->roles : self::current_user_roles();
        if (in_array('administrator', $roles, true)) {
            return $field;
        }
        if (self::matches_roles($field, 'not_visible_by', $roles)) {
            return null;
        }
        if (self::matches_roles($field, 'not_editable_by', $roles)) {
            $field['attributes']['disabled'] = 'disabled';
            $field['attributes']['readonly'] = 'readonly';
            $field['disabled']               = true;
        }
        return $field;
    }

    /**
     * Whether any of the roles is listed in a field's role rule.
     *
     * @param array    $field Field definition.
     * @param string   $rule  `not_visible_by` or `not_editable_by`.
     * @param string[] $roles Roles to check.
     * @return bool
     */
    private static function matches_roles(array $field, $rule, array $roles)
    {
        if (empty($field[$rule])) {
            return false;
        }
        return (bool) array_intersect($roles, (array) $field[$rule]);
    }

    /**
     * Current user's roles, resolved once per request.
     *
     * @return string[]
     */
    private static function current_user_roles()
    {
        if (null !== self::$current_roles) {
            return self::$current_roles;
        }
        self::$current_roles = array();
        if (is_user_logged_in()) {
            $user = wp_get_current_user();
            if (!is_wp_error($user)) {
                self::$current_roles = (array) $user->roles;
            }
        }
        return self::$current_roles;
    }

    /**
     * Render one field with its label, save marker and row wrapper.
     *
     * @param string $key   Meta key.
     * @param array  $field Field definition (visibility already applied).
     * @param array  $args  Render arguments (object_id, view, specific, context_id, container_id).
     * @return array|null `html`, `counted` (counts toward the container's `count`), `replace_all`
     *                    (an `html` field with `strip` replaces everything before it); null when skipped.
     */
    private function render_field($key, array $field, array $args)
    {
        $short = '_' === substr($key, 0, 1) ? ltrim($key, '_') : $key;
        $label = isset($field['label']) ? $field['label'] : $key;
        if ('widget' === $args['view']) {
            $field['exclude_meta'] = true;
        }
        if ('' !== $args['specific'] && $short != $args['specific']) {
            return null;
        }
        if ((isset($field['show']) ? $field['show'] : 1) != 1) {
            return null;
        }

        $explanation = isset($field['explanation']) ? $this->templates->render('fields/explanation', array('text' => __($field['explanation'], 'extend-wp'))) : '';
        $is_html     = 'html' === $field['case'];
        if (!$is_html) {
            $field = awm_prepare_field($field, $args['container_id']);
        }
        $state = $this->resolve_state($key, $field, $args);

        if ($is_html && !empty($field['value'])) {
            return $this->html_row($key, $short, $field, $explanation);
        }
        return $this->field_row($key, $label, $state, $explanation, $args);
    }

    /**
     * Shared preparation: stored value (masked when encrypted), the field
     * config registry, `attributes.value` / `default`, the `attributes.id`
     * override and the wrapper classes (`awm_label_class_filter`).
     *
     * @param string $key   Meta key.
     * @param array  $field Prepared field definition.
     * @param array  $args  Render arguments.
     * @return array `field`, `value`, `dom_id`, `classes` (wrapper), `required` (bool).
     */
    private function resolve_state($key, array $field, array $args)
    {
        $value = awm_get_field_value($args['view'], $field, $args['object_id'], $key);
        $GLOBALS['awm_field_configs'][$key] = $field;
        $value = self::masked_value($key, $field, $value, $args);

        $classes = !empty($field['label_class']) ? (array) $field['label_class'] : array();
        if (isset($field['attributes']['value'])) {
            $value = $field['attributes']['value'];
            unset($field['attributes']['value']);
        }
        if (empty($value) && '0' !== $value && 0 !== $value && isset($field['default'])) {
            $value = $field['default'];
        }
        $dom_id = $key;
        if (isset($field['attributes']['id'])) {
            $dom_id = $field['attributes']['id'];
            unset($field['attributes']['id']);
        }

        $required  = !empty($field['required']);
        $classes[] = $field['case'];
        /**
         * Filter the CSS classes of a field wrapper.
         *
         * @since 1.0.0
         *
         * @param string[]     $classes  Classes.
         * @param array        $field    Field definition.
         * @param string|false $required `required="true"` markup, or false.
         */
        $classes = (array) apply_filters('awm_label_class_filter', $classes, $field, $required ? 'required="true"' : false);
        if (in_array('awm-needed', $classes)) {
            $required = false;
        }
        if (isset($field['type'])) {
            $classes[] = $field['type'];
        }

        return array('field' => $field, 'value' => $value, 'dom_id' => $dom_id, 'classes' => $classes, 'required' => $required);
    }

    /**
     * The masked display value of an encrypted field (`show_masked`, default true).
     *
     * @param string $key   Meta key.
     * @param array  $field Field definition.
     * @param mixed  $value Value read through the normal (decrypting) path.
     * @param array  $args  Render arguments.
     * @return mixed
     */
    private static function masked_value($key, array $field, $value, array $args)
    {
        if (!awm_should_encrypt_field($field) || !(isset($field['show_masked']) ? $field['show_masked'] : true)) {
            return $value;
        }
        $raw = awm_get_raw_field_value($args['view'], $args['object_id'], $key);
        return \EWP_Encryption::is_encrypted($raw) ? \EWP_Encryption::mask($raw) : $value;
    }

    /**
     * A non-empty `html` field: its value in a plain box (no row wrapper, not counted).
     *
     * @param string $key         Meta key.
     * @param string $short       Meta key without leading `_`.
     * @param array  $field       Field definition.
     * @param string $explanation Explanation markup.
     * @return array Row (see render_field()).
     */
    private function html_row($key, $short, array $field, $explanation)
    {
        $strip = isset($field['strip']);
        $html  = $strip ? (string) $field['value'] : $this->templates->render('fields/html', array(
            'key'         => $key,
            'id'          => $short,
            'label'       => isset($field['show_label'], $field['label']) ? $field['label'] : null,
            'explanation' => $explanation,
            'value'       => $field['value'],
        ));
        return array('html' => $html, 'counted' => false, 'replace_all' => $strip);
    }

    /**
     * A regular field: label, the type's markup, the save marker and the row wrapper.
     *
     * @param string $key         Meta key.
     * @param mixed  $label       Field label (or the meta key).
     * @param array  $state       resolve_state() result.
     * @param string $explanation Explanation markup.
     * @param array  $args        Render arguments.
     * @return array|null Row (see render_field()), null when the type suppressed the field.
     */
    private function field_row($key, $label, array $state, $explanation, array $args)
    {
        $field         = $state['field'];
        $wrapper_attrs = array();
        $html          = $this->shared_label($label, $field, $state['dom_id'], $explanation, $args['view']);

        if (!empty($field['show-when']) && is_array($field['show-when'])) {
            $wrapper_attrs[] = 'show-when="' . str_replace('"', '\'', wp_json_encode($field['show-when'])) . '"';
        }
        if (!empty($field['disable-elements']) && is_array($field['disable-elements'])) {
            $field['attributes']['disable-elements'] = str_replace('"', '\'', wp_json_encode($field['disable-elements']));
        }
        $attributes = (!empty($field['attributes']) && is_array($field['attributes'])) ? Field_Attributes::prepare($field['attributes']) : array();
        $value      = (isset($attributes['min']) && $state['value'] == 0) ? $attributes['min'] : $state['value'];

        $output = $this->render_type($key, $field, $value, $attributes, $state, $explanation, $args);
        if ($output->is_suppressed()) {
            return null;
        }
        if (!$output->has_meta_marker()) {
            $field['exclude_meta'] = true;
        }
        $html .= $output->html();
        if ($label && self::posts_meta_marker($field, $args['view'])) {
            $html .= $this->templates->render('fields/save-marker', array('key' => $key));
        }
        if (!$output->has_wrapper()) {
            return array('html' => $html, 'counted' => false, 'replace_all' => false);
        }

        $classes   = array_merge($state['classes'], $output->wrapper_classes(), array('awm-meta-field'));
        $wrapped   = $this->wrap($html, $field, $state['dom_id'], $classes, array_merge($wrapper_attrs, $output->wrapper_attributes()), $explanation, $args);
        return array('html' => $wrapped, 'counted' => !in_array('awm_no_show', $classes), 'replace_all' => false);
    }

    /**
     * The shared label, printed before the markup of labelled inputs, selects, textareas and radios.
     *
     * @param mixed  $label       Field label (or the meta key).
     * @param array  $field       Field definition.
     * @param string $dom_id      Element id.
     * @param string $explanation Explanation markup.
     * @param string $view        Render view.
     * @return string Label markup, '' when the field gets none.
     */
    private function shared_label($label, array $field, $dom_id, $explanation, $view)
    {
        if (!empty($field['hide-label']) || !$label || in_array($field['case'], self::OWN_LABEL_CASES, true)) {
            return '';
        }
        $labelled_input = 'input' === $field['case'] && isset($field['type']) && 'none' !== $view && !in_array($field['type'], self::UNLABELLED_INPUT_TYPES, true);
        if (!$labelled_input && !in_array($field['case'], array('select', 'textarea', 'radio'), true)) {
            return '';
        }
        return $this->templates->render('fields/label', array('for' => $dom_id, 'label' => $label, 'explanation' => $explanation));
    }

    /**
     * Render the field's own markup through its registered type.
     *
     * @param string $key         Meta key.
     * @param array  $field       Field definition.
     * @param mixed  $value       Resolved value.
     * @param array  $attributes  Prepared attributes.
     * @param array  $state       resolve_state() result.
     * @param string $explanation Explanation markup.
     * @param array  $args        Render arguments.
     * @return Field_Output Empty output for an unknown case.
     */
    private function render_type($key, array $field, $value, array $attributes, array $state, $explanation, array $args)
    {
        $registry = $this->registry ?: Field_Registry::instance();
        $type     = $registry->get($field['case']);
        if (!$type) {
            return new Field_Output('');
        }
        $classes = isset($field['class']) ? implode(' ', (array) $field['class']) : '';
        $context = new Field_Context($key, $state['dom_id'], $value, $field, $attributes, $classes, $state['required'], $args['view'], $args['object_id'], $explanation, $args['context_id']);
        return $type->render($context);
    }

    /**
     * Whether the field posts the `awm_custom_meta[]` save marker.
     *
     * @param array  $field Field definition.
     * @param string $view  Render view.
     * @return bool
     */
    private static function posts_meta_marker(array $field, $view)
    {
        return 'none' !== $view
            && !isset($field['attributes']['exclude_meta'])
            && !isset($field['attributes']['disabled'])
            && !isset($field['exclude_meta']);
    }

    /**
     * Wrap a field's markup in the view's row (template `fields/wrapper/{view}`).
     *
     * @param string   $html          Field markup (label, input, save marker).
     * @param array    $field         Field definition.
     * @param string   $dom_id        Element id.
     * @param string[] $classes       Row classes.
     * @param string[] $wrapper_attrs Escaped attribute markup for the row.
     * @param string   $explanation   Explanation markup.
     * @param array    $args          Render arguments.
     * @return string
     */
    private function wrap($html, array $field, $dom_id, array $classes, array $wrapper_attrs, $explanation, array $args)
    {
        if ('none' === $args['view']) {
            return $html;
        }
        $template = 'fields/wrapper/default';
        if ('term' === $args['view']) {
            $template = empty($args['object_id']) ? 'fields/wrapper/term-new' : 'fields/wrapper/term';
        } elseif ('user' === $args['view']) {
            $template = 'fields/wrapper/user';
        }
        return $this->templates->render($template, array(
            'html'        => $html,
            'dom_id'      => $dom_id,
            'case'        => $field['case'],
            'classes'     => implode(' ', $classes),
            'attributes'  => implode(' ', $wrapper_attrs),
            'label'       => isset($field['label']) ? $field['label'] : '',
            'explanation' => $explanation,
            'information' => is_admin() && !empty($field['information']) ? $field['information'] : '',
        ));
    }

    /**
     * Filter the fragments and wrap them in the container.
     *
     * @param string[] $fragments   Rendered rows.
     * @param array    $library     Library without hidden-by-role fields.
     * @param int      $counted     Rows counted toward `count`.
     * @param array    $args        Render arguments.
     * @param string   $target      edit|display.
     * @param bool     $show_labels Labels flag passed by the caller.
     * @param string   $sep         Display separator.
     * @return string
     */
    private function finish(array $fragments, array $library, $counted, array $args, $target, $show_labels, $sep)
    {
        if (empty($fragments) || empty($library)) {
            return '';
        }
        /**
         * Filter the rendered fragments of a field library (one per row).
         *
         * @since 1.0.0 Receives the caller's `$label` flag since 1.11.0 (before, the last field's label leaked in).
         *
         * @param string[] $fragments Rendered rows.
         * @param int      $id        Object ID.
         * @param array    $library   Field library.
         * @param string   $view      post|term|user|option|…
         * @param string   $target    edit|display.
         * @param bool     $label     Whether labels are shown.
         * @param string   $specific  Single field key to render, or empty.
         * @param string   $sep       Separator for display values.
         */
        $fragments = apply_filters('awm_show_content_filter', $fragments, $args['object_id'], $library, $args['view'], $target, $show_labels, $args['specific'], $sep);
        if (empty($fragments)) {
            return '';
        }
        return $this->templates->render('fields/container', array(
            'id'    => $args['container_id'],
            'count' => $counted,
            'html'  => implode('', (array) $fragments),
        ));
    }
}
