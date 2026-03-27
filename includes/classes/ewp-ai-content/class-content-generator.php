<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Content Generator for EWP AI Content Generator.
 *
 * Orchestrates the full generation flow:
 *  1. Builds post context via EWP_AI_Context_Builder.
 *  2. Selects and validates the requested provider.
 *  3. Builds system + user prompts for the requested task.
 *  4. Calls the provider's generate() method.
 *  5. Returns the result or a WP_Error.
 *
 * Supported tasks: 'title', 'excerpt', 'full_content'.
 * Translation modes: 'translate' (literal), 'recreate' (rewrite).
 *
 * @package EWP\AIContent
 * @since   1.0.0
 */
class EWP_AI_Content_Generator {

	/**
	 * Registered providers, keyed by provider ID.
	 *
	 * @var EWP_AI_Provider_Interface[]
	 */
	private array $providers = [];

	/**
	 * Context builder instance.
	 *
	 * @var EWP_AI_Context_Builder
	 */
	private EWP_AI_Context_Builder $context_builder;

	/**
	 * Screenshot generator instance.
	 *
	 * @var EWP_AI_Screenshot_Generator
	 */
	private EWP_AI_Screenshot_Generator $screenshot_generator;

	/**
	 * Constructor — registers all built-in providers.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->context_builder      = new EWP_AI_Context_Builder();
		$this->screenshot_generator = new EWP_AI_Screenshot_Generator();

		$this->register_provider( new EWP_AI_OpenAI_Provider() );
		$this->register_provider( new EWP_AI_Claude_Provider() );
		$this->register_provider( new EWP_AI_Gemini_Provider() );
	}

	// -------------------------------------------------------------------------
	// Public API
	// -------------------------------------------------------------------------

	/**
	 * Register an AI provider.
	 *
	 * @param EWP_AI_Provider_Interface $provider Provider instance.
	 * @return void
	 *
	 * @since 1.0.0
	 */
	public function register_provider( EWP_AI_Provider_Interface $provider ): void {
		$this->providers[ $provider->get_id() ] = $provider;
	}

	/**
	 * Return all registered providers.
	 *
	 * @return EWP_AI_Provider_Interface[]
	 *
	 * @since 1.0.0
	 */
	public function get_providers(): array {
		return $this->providers;
	}

	/**
	 * Return only providers that have an API key configured.
	 *
	 * @return EWP_AI_Provider_Interface[]
	 *
	 * @since 1.0.0
	 */
	public function get_configured_providers(): array {
		return array_filter( $this->providers, fn( $p ) => $p->is_configured() );
	}

	/**
	 * Return a single provider by ID, or null if not found.
	 *
	 * @param string $id Provider ID.
	 * @return EWP_AI_Provider_Interface|null
	 *
	 * @since 1.0.0
	 */
	public function get_provider( string $id ): ?EWP_AI_Provider_Interface {
		return $this->providers[ $id ] ?? null;
	}

	/**
	 * Build prompts for a post without calling the AI — used by the preview endpoint.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $task    'title', 'excerpt', or 'full_content'.
	 * @param array  $options Same options array as generate_content().
	 * @return array|\WP_Error { system: string, user: string } or WP_Error.
	 *
	 * @since 1.0.0
	 */
	public function get_prompts( int $post_id, string $task, array $options = [] ): array|\WP_Error {
		$context = $this->context_builder->build( $post_id );
		if ( is_wp_error( $context ) ) {
			return $context;
		}
		$settings         = EWP_AI_Content::get_settings();
		$translation_mode = $options['translation_mode'] ?? '';
		return [
			'system' => $this->build_system_prompt( $context, $settings ),
			'user'   => $this->build_user_prompt( $task, $context, $options, $translation_mode ),
		];
	}

	/**
	 * Generate content for a post.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $task    One of 'title', 'excerpt', 'full_content'.
	 * @param array  $options {
	 *     Optional parameters.
	 *
	 *     @type string $provider         Provider ID. Defaults to settings default.
	 *     @type string $model            Model ID. Defaults to settings model for provider.
	 *     @type string $instructions     Per-post custom instructions appended to the prompt.
	 *     @type string $translation_mode 'translate' or 'recreate'. Only relevant with WPML.
	 *     @type string $image_base64     Base64 screenshot from the browser (already sanitised).
	 *     @type string $image_mime       MIME type of the screenshot (default 'image/jpeg').
	 * }
	 * @return array|\WP_Error {
	 *     On success:
	 *
	 *     @type string $content   Generated text.
	 *     @type string $task      Task that was requested.
	 *     @type string $provider  Provider ID used.
	 *     @type string $model     Model used.
	 *     @type array  $usage     Token usage.
	 * }
	 *
	 * @since 1.0.0
	 */
	public function generate_content( int $post_id, string $task, array $options = [] ): array|\WP_Error {
		// Validate task.
		$valid_tasks = [ 'title', 'excerpt', 'full_content' ];
		if ( ! in_array( $task, $valid_tasks, true ) ) {
			return new \WP_Error(
				'invalid_task',
				sprintf( __( 'Invalid task "%s". Allowed: title, excerpt, full_content.', 'extend-wp' ), $task )
			);
		}

		// Build context.
		$context = $this->context_builder->build( $post_id );
		if ( is_wp_error( $context ) ) {
			return $context;
		}

		// Resolve provider + model.
		$settings         = EWP_AI_Content::get_settings();
		$provider_id      = $options['provider'] ?? $settings['default_provider'];
		$provider         = $this->get_provider( $provider_id );

		if ( ! $provider ) {
			return new \WP_Error(
				'provider_not_found',
				sprintf( __( 'Provider "%s" is not registered.', 'extend-wp' ), $provider_id )
			);
		}

		if ( ! $provider->is_configured() ) {
			return new \WP_Error(
				'provider_not_configured',
				sprintf( __( 'Provider "%s" is not configured. Please add an API key in settings.', 'extend-wp' ), $provider->get_label() )
			);
		}

		$model = $options['model'] ?? $settings[ $provider_id . '_model' ] ?? array_key_first( $provider->get_models() );

		// Build prompts.
		$translation_mode = $options['translation_mode'] ?? '';
		$system_prompt    = $this->build_system_prompt( $context, $settings );
		$user_prompt      = $this->build_user_prompt( $task, $context, $options, $translation_mode );

		/**
		 * Filter the full prompt before it is sent to the provider.
		 *
		 * @param array  $prompts  [ 'system' => string, 'user' => string ].
		 * @param string $task     Requested task.
		 * @param array  $context  Post context array.
		 * @param array  $options  Generation options.
		 *
		 * @since 1.0.0
		 */
		$prompts = apply_filters(
			'ewp_ai_content_prompt',
			[ 'system' => $system_prompt, 'user' => $user_prompt ],
			$task,
			$context,
			$options
		);

		// Build generation options.
		$gen_options = [
			'system'      => $prompts['system'],
			'max_tokens'  => (int) ( $settings['max_tokens'] ?? 2048 ),
			'temperature' => (float) ( $settings['temperature'] ?? 0.7 ),
		];

		// Attach screenshot if provided.
		if ( ! empty( $options['image_base64'] ) ) {
			$image_opts  = $this->screenshot_generator->prepare_for_provider(
				$options['image_base64'],
				$provider_id,
				$options['image_mime'] ?? 'image/jpeg'
			);
			$gen_options = array_merge( $gen_options, $image_opts );
		}

		// Call provider.
		$result = $provider->generate( $prompts['user'], $model, $gen_options );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		/**
		 * Filter the AI result before returning it to the caller.
		 *
		 * @param array  $result  [ 'content', 'model', 'usage' ].
		 * @param string $task    Requested task.
		 * @param array  $context Post context array.
		 *
		 * @since 1.0.0
		 */
		$result = apply_filters( 'ewp_ai_content_result', $result, $task, $context );

		return [
			'content'  => $result['content'],
			'task'     => $task,
			'provider' => $provider_id,
			'model'    => $result['model'],
			'usage'    => $result['usage'],
		];
	}

	// -------------------------------------------------------------------------
	// Prompt builders
	// -------------------------------------------------------------------------

	/**
	 * Build the system prompt from settings + context.
	 *
	 * The system prompt establishes the AI's role, brand voice, target
	 * audience, and any global custom instructions set in the settings page.
	 *
	 * @param array $context  Post context array from EWP_AI_Context_Builder.
	 * @param array $settings EWP AI Content settings array.
	 * @return string
	 *
	 * @since 1.0.0
	 */
	private function build_system_prompt( array $context, array $settings ): string {
		$parts = [
			'You are an expert content writer creating high-quality WordPress content.',
		];

		if ( ! empty( $settings['brand_voice'] ) ) {
			$parts[] = sprintf( 'Write in a %s tone.', $settings['brand_voice'] );
		}

		if ( ! empty( $settings['target_audience'] ) ) {
			$parts[] = sprintf( 'Target audience: %s.', $settings['target_audience'] );
		}

		// Business context (AI-generated summary from onboarding).
		if ( ! empty( $settings['business_context'] ) ) {
			$parts[] = sprintf( 'Business context: %s.', $settings['business_context'] );
		}

		// Structured business data fields.
		$business_parts = [];

		if ( ! empty( $settings['business_name'] ) ) {
			$business_parts[] = sprintf( 'Name: %s', $settings['business_name'] );
		}
		if ( ! empty( $settings['business_location'] ) ) {
			$business_parts[] = sprintf( 'Location/service area: %s', $settings['business_location'] );
		}
		if ( ! empty( $settings['business_description'] ) ) {
			$business_parts[] = sprintf( 'Description: %s', $settings['business_description'] );
		}
		if ( ! empty( $settings['key_services'] ) ) {
			$business_parts[] = sprintf( 'Products/services: %s', $settings['key_services'] );
		}
		if ( ! empty( $settings['unique_selling_points'] ) ) {
			$business_parts[] = sprintf( 'Differentiators: %s', $settings['unique_selling_points'] );
		}
		if ( ! empty( $settings['customer_sentiment'] ) ) {
			$business_parts[] = sprintf( 'Customer sentiment: %s', $settings['customer_sentiment'] );
		}

		// Links (URL-only; already validated on save).
		$review_links = is_array( $settings['review_links'] ?? null ) ? $settings['review_links'] : [];
		$review_urls  = array_filter( array_map(
			fn( $l ) => is_array( $l ) ? ( $l['url'] ?? '' ) : ( is_string( $l ) ? $l : '' ),
			$review_links
		) );
		if ( ! empty( $review_urls ) ) {
			$business_parts[] = 'Review platforms: ' . implode( ', ', $review_urls );
		}

		$social_links = is_array( $settings['social_links'] ?? null ) ? $settings['social_links'] : [];
		$social_urls  = array_filter( array_map(
			fn( $l ) => is_array( $l ) ? ( $l['url'] ?? '' ) : ( is_string( $l ) ? $l : '' ),
			$social_links
		) );
		if ( ! empty( $social_urls ) ) {
			$business_parts[] = 'Social media: ' . implode( ', ', $social_urls );
		}

		$competitors       = is_array( $settings['competitors'] ?? null ) ? $settings['competitors'] : [];
		$competitor_labels = [];
		foreach ( $competitors as $comp ) {
			$name = $comp['name'] ?? '';
			$url  = $comp['url'] ?? '';
			if ( $name ) {
				$competitor_labels[] = $url ? "{$name} ({$url})" : $name;
			}
		}
		if ( ! empty( $competitor_labels ) ) {
			$business_parts[] = 'Competitors: ' . implode( ', ', $competitor_labels );
		}

		if ( ! empty( $business_parts ) ) {
			$parts[] = 'Business information: ' . implode( '. ', $business_parts ) . '.';
		}

		if ( ! empty( $settings['custom_instructions'] ) ) {
			$parts[] = $settings['custom_instructions'];
		}

		$parts[] = sprintf( 'Write in language: %s.', $context['language'] );
		$parts[] = 'Provide only the requested content — no explanations, no meta-commentary, no markdown fences.';

		return implode( "\n", $parts );
	}

	/**
	 * Build the user prompt for a specific task.
	 *
	 * @param string $task             'title', 'excerpt', or 'full_content'.
	 * @param array  $context          Post context array.
	 * @param array  $options          Generation options (may include 'instructions').
	 * @param string $translation_mode 'translate', 'recreate', or '' (not a translation).
	 * @return string
	 *
	 * @since 1.0.0
	 */
	private function build_user_prompt( string $task, array $context, array $options, string $translation_mode ): string {
		$context_block = $this->format_context_block( $context );
		$task_prompt   = $this->build_task_instruction( $task, $context, $translation_mode );

		$parts = [
			'## Post Context',
			$context_block,
		];

		if ( ! empty( $options['instructions'] ) ) {
			$parts[] = '## Additional Instructions';
			$parts[] = sanitize_textarea_field( $options['instructions'] );
		}

		$parts[] = '## Task';
		$parts[] = $task_prompt;

		return implode( "\n\n", $parts );
	}

	/**
	 * Format the post context as a readable text block for the prompt.
	 *
	 * @param array $context Post context array.
	 * @return string
	 *
	 * @since 1.0.0
	 */
	private function format_context_block( array $context ): string {
		$lines = [];

		if ( ! empty( $context['title'] ) ) {
			$lines[] = 'Title: ' . $context['title'];
		}

		if ( ! empty( $context['post_type'] ) ) {
			$lines[] = 'Post type: ' . $context['post_type'];
		}

		if ( ! empty( $context['taxonomies'] ) ) {
			foreach ( $context['taxonomies'] as $label => $terms ) {
				$lines[] = $label . ': ' . $terms;
			}
		}

		if ( ! empty( $context['excerpt'] ) ) {
			$lines[] = 'Excerpt: ' . $context['excerpt'];
		}

		if ( ! empty( $context['content'] ) ) {
			$lines[] = 'Current content:' . "\n" . $context['content'];
		}

		if ( ! empty( $context['meta'] ) ) {
			$lines[] = 'Custom fields:';
			foreach ( $context['meta'] as $key => $value ) {
				$lines[] = '  ' . $key . ': ' . $value;
			}
		}

		if ( ! empty( $context['featured_image_url'] ) ) {
			$lines[] = 'Featured image URL: ' . $context['featured_image_url'];
		}

		return implode( "\n", $lines );
	}

	/**
	 * Build the task-specific instruction appended at the end of the prompt.
	 *
	 * @param string $task             'title', 'excerpt', or 'full_content'.
	 * @param array  $context          Post context array (used for language).
	 * @param string $translation_mode 'translate', 'recreate', or ''.
	 * @return string
	 *
	 * @since 1.0.0
	 */
	private function build_task_instruction( string $task, array $context, string $translation_mode ): string {
		$lang = $context['language'] ?? 'the same language as the post';

		// Translation mode prefix.
		$translation_prefix = '';
		if ( 'translate' === $translation_mode ) {
			$translation_prefix = sprintf(
				"Translate the content to %s, preserving the original meaning as closely as possible.\n",
				$lang
			);
		} elseif ( 'recreate' === $translation_mode ) {
			$translation_prefix = sprintf(
				"Rewrite the content for a %s-speaking audience, adapting tone and phrasing naturally for that language and culture.\n",
				$lang
			);
		}

		switch ( $task ) {
			case 'title':
				return $translation_prefix . 'Write a compelling, SEO-friendly post title. Return only the title text, no quotes.';

			case 'excerpt':
				return $translation_prefix . 'Write an engaging excerpt of 2–3 sentences that summarises the post and entices the reader. Return only the excerpt text.';

			case 'full_content':
				return $translation_prefix . 'Write complete, well-structured post content in HTML format using paragraphs (<p>), headings (<h2>, <h3>), and lists (<ul>/<ol>) where appropriate. Return only the HTML content.';

			default:
				return $translation_prefix . 'Write content for this post.';
		}
	}
}
