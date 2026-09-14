<?php

namespace EWP\Surfaces\Resources;

use Gnnpls\WP\Context;
use Gnnpls\WP\Operation;
use Gnnpls\WP\Resource;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The editor preview of one dynamic block (`GET {namespace}/{name}/preview`)
 * as a REST-only resource over EWP_Dynamic_Blocks::render_preview().
 *
 * One instance per gathered block, registered by EWP_Dynamic_Blocks on
 * `rest_api_init` through EWP_Surfaces::register_block_preview_routes().
 * Every request parameter is a block attribute, so no input is declared
 * and extra parameters are allowed; only the block's own registered render
 * callback ever runs.
 *
 * @package    EWP\Surfaces
 * @author     Motivar
 *
 * @since 1.5.0
 */
final class Block_Preview_Resource extends Resource
{
    /** @var array Block definition from gather_blocks(). */
    private $block;

    /** @var \EWP_Dynamic_Blocks */
    private $service;

    /**
     * @param array               $block   Block definition (`namespace`, `name`, `render_callback`, …).
     * @param \EWP_Dynamic_Blocks $service Block registry.
     */
    public function __construct(array $block, \EWP_Dynamic_Blocks $service)
    {
        $this->block   = $block;
        $this->service = $service;
    }

    /** {@inheritDoc} */
    public function name()
    {
        return 'block-preview:' . $this->block['namespace'] . '/' . $this->block['name'];
    }

    /** {@inheritDoc} */
    public function label()
    {
        return sprintf(__('Block preview: %s', 'extend-wp'), $this->block['namespace'] . '/' . $this->block['name']);
    }

    /** {@inheritDoc} */
    public function service()
    {
        return $this->service;
    }

    /** {@inheritDoc} */
    public function rest_namespace()
    {
        return $this->block['namespace'] . '/' . $this->block['name'];
    }

    /** The route is `{namespace}/{name}/preview`, directly under the block namespace. @return string */
    public function rest_base()
    {
        return '';
    }

    /**
     * The block definition this resource previews.
     *
     * @return array
     */
    public function block()
    {
        return $this->block;
    }

    /**
     * Editor-only.
     *
     * @return string[]
     */
    public function surfaces()
    {
        return [Context::REST];
    }

    /**
     * @return string
     */
    public function surfaces_reason()
    {
        return __('renders block markup for the editor; the block itself is the front-end surface', 'extend-wp');
    }

    /** Anyone who can edit posts may preview a block. @return string */
    public function capability()
    {
        return 'edit_posts';
    }

    /** {@inheritDoc} */
    public function operations()
    {
        $block = $this->block;

        return [
            'preview' => Operation::read('render_preview')
                ->label(__('Render a block preview', 'extend-wp'))
                ->description(__('Render the block with the given attributes through its registered render callback, wrapped for the editor\'s script loader.', 'extend-wp'))
                ->allow_extra()
                ->args(function (array $input) use ($block) {
                    return [$input, $block];
                })
                ->output(['type' => 'string'])
                ->rest('GET', 'preview'),
        ];
    }
}
