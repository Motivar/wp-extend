<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Context Builder for EWP AI Content Generator.
 *
 * Extracts comprehensive post data — title, content, excerpt, taxonomies,
 * custom meta, featured image, and language — and returns it as a structured
 * array ready to be injected into an AI prompt.
 *
 * @package EWP\AIContent
 * @since   1.0.0
 */
class EWP_AI_Context_Builder {

	/**
	 * Maximum characters of post content included in context.
	 * Keeps the prompt within reasonable token limits.
	 *
	 * @var int
	 */
	private const MAX_CONTENT_CHARS = 4000;

	/**
	 * Meta key prefixes to always skip (internal WP / plugin keys).
	 *
	 * @var string[]
	 */
	private const SKIP_META_PREFIXES = [ '_', 'ewp_', 'awm_' ];

	/**
	 * Build a context array for the given post.
	 *
	 * @param int $post_id WordPress post ID.
	 * @return array|\WP_Error Context array on success, WP_Error if post
	 *                         is not found or not readable.
	 *
	 * @since 1.0.0
	 */
	public function build( int $post_id ): array|\WP_Error {
		/**
		 * Action fired before building post context.
		 *
		 * @param int $post_id Post ID.
		 *
		 * @since 1.0.0
		 */
		do_action('ewp_ai_content_before_build_context', $post_id);

		$post = get_post( $post_id );

		if ( ! $post instanceof \WP_Post ) {
			return new \WP_Error(
				'context_post_not_found',
				sprintf( __( 'Post #%d not found.', 'extend-wp' ), $post_id )
			);
		}

		/**
		 * Filter the post object before extracting context.
		 *
		 * @param \WP_Post $post Post object.
		 * @param int      $post_id Post ID.
		 *
		 * @since 1.0.0
		 */
		$post = apply_filters('ewp_ai_content_context_post', $post, $post_id);

		$context = [
			'post_id'             => $post->ID,
			'title'               => $post->post_title,
			'excerpt'             => $this->get_excerpt( $post ),
			'content'             => $this->get_content( $post ),
			'post_type'           => $post->post_type,
			'status'              => $post->post_status,
			'author'              => $this->get_author_name( $post ),
			'featured_image_url'  => $this->get_featured_image_url( $post->ID ),
			'taxonomies'          => $this->get_taxonomies( $post ),
			'meta'                => $this->get_meta( $post->ID ),
			'language'            => $this->get_language(),
			'permalink'           => get_permalink( $post->ID ) ?: '',
		];

		/**
		 * Filter individual context fields.
		 *
		 * Allows developers to modify specific context fields before final filtering.
		 *
		 * @param mixed    $value Field value.
		 * @param string   $field Field name.
		 * @param \WP_Post $post Post object.
		 *
		 * @since 1.0.0
		 */
		foreach ($context as $field => $value) {
			$context[$field] = apply_filters('ewp_ai_content_context_field', $value, $field, $post);
			$context[$field] = apply_filters("ewp_ai_content_context_field_{$field}", $context[$field], $post);
		}

		/**
		 * Filter the post context before it is used to build a prompt.
		 *
		 * @param array    $context Full context array.
		 * @param \WP_Post $post    WordPress post object.
		 *
		 * @since 1.0.0
		 */
		return apply_filters( 'ewp_ai_content_context', $context, $post );
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Return a clean excerpt for the post.
	 *
	 * Uses the manual excerpt if set, otherwise falls back to a trimmed
	 * version of the post content (no HTML, max 300 chars).
	 *
	 * @param \WP_Post $post Post object.
	 * @return string
	 *
	 * @since 1.0.0
	 */
	private function get_excerpt( \WP_Post $post ): string {
		if ( ! empty( $post->post_excerpt ) ) {
			$excerpt = wp_strip_all_tags($post->post_excerpt);
		} else {
			$plain = wp_strip_all_tags($post->post_content);
			$excerpt = mb_substr($plain, 0, 300);
		}

		/**
		 * Filter the excerpt before adding to context.
		 *
		 * @param string   $excerpt Extracted excerpt.
		 * @param \WP_Post $post Post object.
		 *
		 * @since 1.0.0
		 */
		return apply_filters('ewp_ai_content_context_excerpt', $excerpt, $post);
	}

	/**
	 * Return stripped post content, truncated to MAX_CONTENT_CHARS.
	 *
	 * @param \WP_Post $post Post object.
	 * @return string
	 *
	 * @since 1.0.0
	 */
	private function get_content( \WP_Post $post ): string {
		$plain = wp_strip_all_tags( $post->post_content );
		$content = mb_substr($plain, 0, self::MAX_CONTENT_CHARS);

		/**
		 * Filter the content before adding to context.
		 *
		 * @param string   $content Extracted content (truncated).
		 * @param \WP_Post $post Post object.
		 * @param int      $max_chars Maximum characters allowed.
		 *
		 * @since 1.0.0
		 */
		return apply_filters('ewp_ai_content_context_content', $content, $post, self::MAX_CONTENT_CHARS);
	}

	/**
	 * Return the post author display name.
	 *
	 * @param \WP_Post $post Post object.
	 * @return string
	 *
	 * @since 1.0.0
	 */
	private function get_author_name( \WP_Post $post ): string {
		$user = get_userdata( (int) $post->post_author );
		$author_name = $user ? $user->display_name : '';

		/**
		 * Filter the author name before adding to context.
		 *
		 * @param string   $author_name Author display name.
		 * @param \WP_Post $post Post object.
		 * @param \WP_User|false $user User object or false.
		 *
		 * @since 1.0.0
		 */
		return apply_filters('ewp_ai_content_context_author', $author_name, $post, $user);
	}

	/**
	 * Return the full URL of the featured image, or empty string.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 *
	 * @since 1.0.0
	 */
	private function get_featured_image_url( int $post_id ): string {
		$thumbnail_id = get_post_thumbnail_id( $post_id );

		if ( ! $thumbnail_id ) {
			return '';
		}

		$src = wp_get_attachment_image_src( $thumbnail_id, 'large' );
		$image_url = $src ? $src[0] : '';

		/**
		 * Filter the featured image URL before adding to context.
		 *
		 * @param string $image_url Featured image URL.
		 * @param int    $post_id Post ID.
		 * @param int    $thumbnail_id Attachment ID.
		 *
		 * @since 1.0.0
		 */
		return apply_filters('ewp_ai_content_context_featured_image', $image_url, $post_id, $thumbnail_id);
	}

	/**
	 * Return all taxonomy terms attached to the post.
	 *
	 * Returns an associative array keyed by taxonomy name, each value
	 * being a comma-separated list of term names.
	 *
	 * @param \WP_Post $post Post object.
	 * @return array<string, string>
	 *
	 * @since 1.0.0
	 */
	private function get_taxonomies( \WP_Post $post ): array {
		$taxonomies = get_object_taxonomies( $post->post_type, 'objects' );

		/**
		 * Filter taxonomies to include in context.
		 *
		 * @param array    $taxonomies Taxonomy objects.
		 * @param \WP_Post $post Post object.
		 *
		 * @since 1.0.0
		 */
		$taxonomies = apply_filters('ewp_ai_content_context_taxonomies_list', $taxonomies, $post);

		$result     = [];

		foreach ( $taxonomies as $taxonomy ) {
			$terms = get_the_terms( $post->ID, $taxonomy->name );

			if ( empty( $terms ) || is_wp_error( $terms ) ) {
				continue;
			}

			$names = array_map( fn( $t ) => $t->name, $terms );

			// Use the taxonomy label (e.g. "Categories") as the key.
			$result[ $taxonomy->label ] = implode( ', ', $names );
		}

		/**
		 * Filter the taxonomies array before adding to context.
		 *
		 * @param array    $result Taxonomies array (label => terms).
		 * @param \WP_Post $post Post object.
		 *
		 * @since 1.0.0
		 */
		return apply_filters('ewp_ai_content_context_taxonomies', $result, $post);
	}

	/**
	 * Return filtered custom post meta suitable for AI context.
	 *
	 * Skips internal meta keys (those starting with SKIP_META_PREFIXES)
	 * and serialised values that would add noise to the prompt.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string, mixed>
	 *
	 * @since 1.0.0
	 */
	private function get_meta( int $post_id ): array {
		$all_meta = get_post_meta( $post_id );
		$result   = [];

		if ( empty( $all_meta ) ) {
			return $result;
		}

		/**
		 * Filter which meta keys to exclude from AI context.
		 *
		 * @param string[] $excluded Additional keys to exclude.
		 * @param int      $post_id Post ID.
		 *
		 * @since 1.0.0
		 */
		$extra_excluded = apply_filters('ewp_ai_exclude_meta_keys', [], $post_id);

		foreach ( $all_meta as $key => $values ) {
			if ( $this->should_skip_meta_key( $key, $extra_excluded ) ) {
				continue;
			}

			$value = maybe_unserialize( $values[0] );

			// Skip arrays/objects (serialised complex data) — too noisy.
			if ( is_array( $value ) || is_object( $value ) ) {
				continue;
			}

			/**
			 * Filter individual meta value before adding to context.
			 *
			 * @param mixed  $value Meta value.
			 * @param string $key Meta key.
			 * @param int    $post_id Post ID.
			 *
			 * @since 1.0.0
			 */
			$result[$key] = apply_filters('ewp_ai_content_context_meta_value', (string) $value, $key, $post_id);
		}

		/**
		 * Filter the complete meta array before adding to context.
		 *
		 * @param array $result Meta array (key => value).
		 * @param int   $post_id Post ID.
		 *
		 * @since 1.0.0
		 */
		return apply_filters('ewp_ai_content_context_meta', $result, $post_id);
	}

	/**
	 * Check whether a meta key should be excluded from context.
	 *
	 * @param string   $key           Meta key.
	 * @param string[] $extra_excluded Additional keys to skip.
	 * @return bool
	 *
	 * @since 1.0.0
	 */
	private function should_skip_meta_key( string $key, array $extra_excluded ): bool {
		if ( in_array( $key, $extra_excluded, true ) ) {
			return true;
		}

		foreach ( self::SKIP_META_PREFIXES as $prefix ) {
			if ( 0 === strpos( $key, $prefix ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Return the current admin language code.
	 *
	 * Uses WPML if active, otherwise falls back to get_locale().
	 *
	 * @return string Language code, e.g. 'en', 'el', 'en_US'.
	 *
	 * @since 1.0.0
	 */
	private function get_language(): string {
		$language = (string) apply_filters('wpml_current_language', get_locale());

		/**
		 * Filter the language code before adding to context.
		 *
		 * @param string $language Language code.
		 *
		 * @since 1.0.0
		 */
		return apply_filters('ewp_ai_content_context_language', $language);
	}
}