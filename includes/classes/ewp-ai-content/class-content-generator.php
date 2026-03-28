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
		/**
		 * Action fired before content generation starts.
		 *
		 * @param int    $post_id Post ID.
		 * @param string $task    Task type (title, excerpt, full_content).
		 * @param array  $options Generation options.
		 *
		 * @since 1.0.0
		 */
		do_action('ewp_ai_content_before_generate', $post_id, $task, $options);

		// Log generation request start
		if (function_exists('ewp_log')) {
			ewp_log(
				'extend-wp',
				'ai_content_api_call',
				sprintf('AI content generation started for post #%d, task: %s', $post_id, $task),
				[
					'post_id' => $post_id,
					'task' => $task,
					'options' => $options,
				],
				'developer',
				'post_type',
				$post_id
			);
		}

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

		/**
		 * Filter the post context after building.
		 *
		 * Allows developers to modify context data before prompt generation.
		 *
		 * @param array  $context Post context array.
		 * @param int    $post_id Post ID.
		 * @param string $task    Task type.
		 * @param array  $options Generation options.
		 *
		 * @since 1.0.0
		 */
		$context = apply_filters('ewp_ai_content_generation_context', $context, $post_id, $task, $options);

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

		/**
		 * Filter translation mode before prompt building.
		 *
		 * @param string $translation_mode Translation mode (translate, recreate, or empty).
		 * @param int    $post_id Post ID.
		 * @param string $task Task type.
		 * @param array  $context Post context.
		 *
		 * @since 1.0.0
		 */
		$translation_mode = apply_filters('ewp_ai_content_translation_mode', $translation_mode, $post_id, $task, $context);

		$system_prompt    = $this->build_system_prompt( $context, $settings );
		$user_prompt      = $this->build_user_prompt( $task, $context, $options, $translation_mode );

		// Log prompts before filtering
		if (function_exists('ewp_log')) {
			ewp_log(
				'extend-wp',
				'ai_content_api_call',
				sprintf('Built prompts for post #%d', $post_id),
				[
					'post_id' => $post_id,
					'task' => $task,
					'provider' => $provider_id,
					'model' => $model,
					'system_prompt' => $system_prompt,
					'user_prompt' => $user_prompt,
					'translation_mode' => $translation_mode,
				],
				'developer',
				'post_type',
				$post_id
			);
		}

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

		/**
		 * Filter generation options before API call.
		 *
		 * Allows developers to modify max_tokens, temperature, or add
		 * provider-specific parameters.
		 *
		 * @param array  $gen_options Generation options.
		 * @param int    $post_id Post ID.
		 * @param string $task Task type.
		 * @param string $provider_id Provider ID.
		 * @param string $model Model ID.
		 *
		 * @since 1.0.0
		 */
		$gen_options = apply_filters('ewp_ai_content_provider_options', $gen_options, $post_id, $task, $provider_id, $model);

		// Attach screenshot if provided.
		if ( ! empty( $options['image_base64'] ) ) {
			$image_opts  = $this->screenshot_generator->prepare_for_provider(
				$options['image_base64'],
				$provider_id,
				$options['image_mime'] ?? 'image/jpeg'
			);
			$gen_options = array_merge( $gen_options, $image_opts );

			if (function_exists('ewp_log')) {
				ewp_log(
					'extend-wp',
					'ai_content_api_call',
					sprintf('Screenshot attached for post #%d', $post_id),
					[
						'post_id' => $post_id,
						'image_mime' => $options['image_mime'] ?? 'image/jpeg',
						'image_size_bytes' => strlen($options['image_base64']),
					],
					'developer',
					'post_type',
					$post_id
				);
			}
		}

		// Log final generation options before API call
		if (function_exists('ewp_log')) {
			ewp_log(
				'extend-wp',
				'ai_content_api_call',
				sprintf('Calling %s API for post #%d', $provider->get_label(), $post_id),
				[
					'post_id' => $post_id,
					'task' => $task,
					'provider' => $provider_id,
					'model' => $model,
					'max_tokens' => $gen_options['max_tokens'],
					'temperature' => $gen_options['temperature'],
					'has_image' => ! empty($options['image_base64']),
					'final_system_prompt' => $prompts['system'],
					'final_user_prompt' => $prompts['user'],
				],
				'developer',
				'post_type',
				$post_id
			);
		}

		// Call provider.
		$result = $provider->generate( $prompts['user'], $model, $gen_options );

		if ( is_wp_error( $result ) ) {
			if (function_exists('ewp_log')) {
				ewp_log(
					'extend-wp',
					'ai_content_api_call',
					sprintf('AI API call failed for post #%d: %s', $post_id, $result->get_error_message()),
					[
						'post_id' => $post_id,
						'task' => $task,
						'provider' => $provider_id,
						'model' => $model,
						'error_code' => $result->get_error_code(),
						'error_message' => $result->get_error_message(),
						'error_data' => $result->get_error_data(),
					],
					'developer',
					'post_type',
					$post_id
				);
			}
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

		/**
		 * Filter the generated content text before returning.
		 *
		 * Allows developers to post-process AI-generated content.
		 *
		 * @param string $content Generated content.
		 * @param string $task Task type.
		 * @param int    $post_id Post ID.
		 * @param array  $result Full result array.
		 *
		 * @since 1.0.0
		 */
		$result['content'] = apply_filters('ewp_ai_content_generated_text', $result['content'], $task, $post_id, $result);

		// Log successful generation with full details
		if (function_exists('ewp_log')) {
			ewp_log(
				'extend-wp',
				'ai_content_api_call',
				sprintf('AI content generated successfully for post #%d', $post_id),
				[
					'post_id' => $post_id,
					'task' => $task,
					'provider' => $provider_id,
					'model' => $result['model'],
					'usage' => $result['usage'],
					'content_length' => strlen($result['content']),
					'content_preview' => substr($result['content'], 0, 200),
				],
				'developer',
				'post_type',
				$post_id
			);
		}

		$final_result = [
			'content'  => $result['content'],
			'task'     => $task,
			'provider' => $provider_id,
			'model'    => $result['model'],
			'usage'    => $result['usage'],
		];

		/**
		 * Action fired after successful content generation.
		 *
		 * @param array  $final_result Complete result array.
		 * @param int    $post_id Post ID.
		 * @param string $task Task type.
		 * @param array  $options Generation options.
		 *
		 * @since 1.0.0
		 */
		do_action('ewp_ai_content_after_generate', $final_result, $post_id, $task, $options);

		return $final_result;
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

			// ── ROLE ─────────────────────────────────────────────────────
			'You are a senior SEO-focused conversion copywriter generating high-quality content for WordPress websites.',

			// ── CORE WRITING PRINCIPLES ──────────────────────────────────
			'Write clear, specific, and non-generic content.',
			'Avoid vague marketing phrases such as "high-quality service", "enhance your experience", or similar filler language.',
			'Prioritize clarity, usefulness, and real-world value over fluff.',
			'Write like a human expert — natural, confident, and concise.',

			// ── QUALITY CONSTRAINTS ──────────────────────────────────────
			'Avoid repetition and redundant phrasing.',
			'Do not invent information that is not provided.',
			'Focus on concrete details and meaningful differentiation.',

		];



		// ── BUSINESS CONTEXT (CRITICAL) ─────────────────────────────────
		if (! empty($settings['business_context'])) {
			$parts[] = 'Business context (use this to guide tone, positioning, and messaging):';
			$parts[] = $settings['business_context'];
			$parts[] = 'Ensure all content aligns with this business context.';
		}


		// ── CUSTOM INSTRUCTIONS ─────────────────────────────────────────
		if (! empty($settings['custom_instructions'])) {
			$parts[] = 'Additional instructions:';
			$parts[] = $settings['custom_instructions'];
		}


		// ── LANGUAGE & OUTPUT RULES ─────────────────────────────────────
		$parts[] = sprintf('Write in language: %s.', $context['language']);
		$parts[] = 'Output only the requested content — no explanations, no markdown, no extra text.';


		/**
		 * Filter system prompt parts before joining.
		 */
		$parts = apply_filters('ewp_ai_content_system_prompt_parts', $parts, $context, $settings);

		$system_prompt = implode("\n", $parts);

		/**
		 * Filter the complete system prompt.
		 */
		return apply_filters('ewp_ai_content_system_prompt', $system_prompt, $context, $settings);

		$system_prompt = implode("\n", $parts);

		/**
		 * Filter the complete system prompt.
		 *
		 * @param string $system_prompt Complete system prompt text.
		 * @param array  $context Post context.
		 * @param array  $settings AI settings.
		 *
		 * @since 1.0.0
		 */
		return apply_filters('ewp_ai_content_system_prompt', $system_prompt, $context, $settings);
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

		/**
		 * Filter the formatted context block.
		 *
		 * @param string $context_block Formatted context text.
		 * @param array  $context Raw context array.
		 * @param string $task Task type.
		 *
		 * @since 1.0.0
		 */
		$context_block = apply_filters('ewp_ai_content_context_block', $context_block, $context, $task);

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

		/**
		 * Filter user prompt parts before joining.
		 *
		 * Allows developers to add, remove, or reorder user prompt sections.
		 *
		 * @param array  $parts User prompt parts.
		 * @param string $task Task type.
		 * @param array  $context Post context.
		 * @param array  $options Generation options.
		 *
		 * @since 1.0.0
		 */
		$parts = apply_filters('ewp_ai_content_user_prompt_parts', $parts, $task, $context, $options);

		$user_prompt = implode("\n\n", $parts);

		/**
		 * Filter the complete user prompt.
		 *
		 * @param string $user_prompt Complete user prompt text.
		 * @param string $task Task type.
		 * @param array  $context Post context.
		 * @param array  $options Generation options.
		 *
		 * @since 1.0.0
		 */
		return apply_filters('ewp_ai_content_user_prompt', $user_prompt, $task, $context, $options);
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

		/**
		 * Filter context array before formatting.
		 *
		 * Allows developers to add or modify context fields before formatting.
		 *
		 * @param array $context Post context array.
		 *
		 * @since 1.0.0
		 */
		$context = apply_filters('ewp_ai_content_format_context', $context);

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

		/**
		 * Filter formatted context lines before joining.
		 *
		 * Allows developers to add, remove, or reorder context lines.
		 *
		 * @param array $lines Context lines array.
		 * @param array $context Raw context array.
		 *
		 * @since 1.0.0
		 */
		$lines = apply_filters('ewp_ai_content_context_lines', $lines, $context);

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
	private function build_task_instruction(string $task, array $context, string $translation_mode): string
	{

		$lang = $context['language'] ?? 'the same language as the post';

		$lang = apply_filters('ewp_ai_content_task_language', $lang, $context, $task);

		// ── Translation mode prefix ───────────────────────────────────
		$translation_prefix = '';
		if ('translate' === $translation_mode) {
			$translation_prefix = sprintf(
				"Translate the content to %s, preserving the original meaning exactly. Do not add or remove information.\n",
				$lang
			);
		} elseif ('recreate' === $translation_mode) {
			$translation_prefix = sprintf(
				"Rewrite the content for a %s-speaking audience, adapting tone and phrasing naturally while preserving intent.\n",
				$lang
			);
		}

		// ── Task instructions ─────────────────────────────────────────
		$instructions = [

			'title' => $translation_prefix . implode("\n", [
				'Write an SEO-optimized post title.',
				'- Max 60 characters',
				'- Include relevant keywords if possible',
				'- Be clear and specific',
				'- Avoid clickbait and generic phrases',
				'Return only the title text (no quotes, no formatting).',
			]),

			'excerpt' => $translation_prefix . implode("\n", [
				'Write a concise and engaging excerpt.',
				'- 2–3 sentences',
				'- Maximum ~160 characters if possible',
				'- Clearly communicate the value of the content',
				'- Avoid generic phrases and filler language',
				'Return only the excerpt text.',
			]),

			'full_content' => $translation_prefix . implode("\n", [
				'Write well-structured, SEO-friendly content in HTML format.',

				'Structure:',
				'- Use <h2> and <h3> headings',
				'- Use short paragraphs (2–4 lines)',
				'- Use <ul>/<ol> lists where appropriate',

				'Content guidelines:',
				'- Be informative, clear, and practical',
				'- Avoid repetition and filler phrases',
				'- Avoid generic marketing language',
				'- Use natural, human tone',

				'Formatting rules:',
				'- Use valid HTML only (no markdown)',
				'- Do NOT wrap content in <html> or <body>',
				'- Do NOT include explanations',

				'Return only the HTML content.',
			]),
		];

		$instructions = apply_filters('ewp_ai_content_task_instructions', $instructions, $translation_prefix, $context, $lang);

		$instruction = $instructions[$task] ?? ($translation_prefix . 'Write content for this post.');

		return apply_filters('ewp_ai_content_task_instruction', $instruction, $task, $context, $translation_mode);
	}
}