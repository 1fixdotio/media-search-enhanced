<?php
/**
 * Abilities API integration for Media Search Enhanced.
 *
 * Registers a single ability — media-search-enhanced/search-media — that
 * exposes the plugin's expanded attachment search to PHP, REST, and AI agents
 * via the MCP adapter. The ability mirrors what
 * /wp/v2/media?search=...&post_type=attachment returns today, with the
 * multi-term comma syntax unlocked behind the ability's permission check.
 *
 * Loaded only on WordPress 6.9+ via a function_exists( 'wp_register_ability' )
 * guard at the call site.
 *
 * @package Media_Search_Enhanced
 * @since   0.10.0
 */

class MSE_Abilities {

	const ABILITY_NAME  = 'media-search-enhanced/search-media';
	const CATEGORY_NAME = 'media-search-enhanced';
	const MAX_PER_PAGE  = 100;
	const MAX_QUERY_LEN = 600;

	/**
	 * Hook category and ability registration into the Abilities API init actions.
	 */
	public static function init() {
		add_action( 'wp_abilities_api_categories_init', array( __CLASS__, 'register_category' ) );
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_ability' ) );
	}

	/**
	 * Register the ability category. Must fire before register_ability().
	 */
	public static function register_category() {
		wp_register_ability_category(
			self::CATEGORY_NAME,
			array(
				'label'       => __( 'Media Search Enhanced', 'media-search-enhanced' ),
				'description' => __( 'Search the WordPress Media Library across all attachment fields including alt text, filename, taxonomy terms, and embedded metadata.', 'media-search-enhanced' ),
			)
		);
	}

	/**
	 * Register the search-media ability.
	 */
	public static function register_ability() {
		wp_register_ability(
			self::ABILITY_NAME,
			array(
				'label'               => __( 'Search media library', 'media-search-enhanced' ),
				'description'         => __( 'Search the Media Library across title, alt text, filename, GUID, description, caption, and attachment taxonomy terms. Returns matching attachments with their core metadata. Comma-separated values in the query trigger multi-term OR search (up to 10 terms).', 'media-search-enhanced' ),
				'category'            => self::CATEGORY_NAME,
				'execute_callback'    => array( __CLASS__, 'execute' ),
				'permission_callback' => array( __CLASS__, 'check_permission' ),
				'input_schema'        => self::input_schema(),
				'output_schema'       => self::output_schema(),
				'meta'                => array(
					'show_in_rest' => true,
					'annotations'  => array(
						// Read-only ability — REST controller allows GET in
						// addition to POST when this annotation is set.
						'readonly' => true,
					),
				),
			)
		);
	}

	/**
	 * Permission callback. Requires the upload_files capability — the same
	 * gate WordPress uses for Media Library access.
	 *
	 * The Abilities API contract is: return a bool. Returning WP_Error here
	 * triggers _doing_it_wrong() in WP_Ability::execute() and the framework
	 * substitutes its own generic ability_invalid_permissions error.
	 */
	public static function check_permission( $input = array() ) {
		return current_user_can( 'upload_files' );
	}

	/**
	 * Input JSON Schema. Bounds are enforced by the Abilities API validator.
	 */
	public static function input_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'query'       => array(
					'type'        => 'string',
					'description' => 'Search term. Comma-separated values are treated as multiple terms and OR-ed together (up to 10 terms). Must contain at least one non-whitespace character.',
					'minLength'   => 1,
					'maxLength'   => self::MAX_QUERY_LEN,
					'pattern'     => '\\S',
				),
				'mime_type'   => array(
					'description' => 'Filter by attachment MIME type, e.g. "image/jpeg" or "application/pdf". Accepts a single value or an array of values. Each array element must be a single MIME type — do not embed commas.',
					'oneOf'       => array(
						array(
							'type'    => 'string',
							'pattern' => '^[^,]+$',
						),
						array(
							'type'  => 'array',
							'items' => array(
								'type'    => 'string',
								'pattern' => '^[^,]+$',
							),
						),
					),
				),
				'after'       => array(
					'type'        => 'string',
					'format'      => 'date-time',
					'description' => 'Return attachments uploaded on or after this RFC 3339 datetime (e.g. "2025-01-15T00:00:00Z"). Interpreted as UTC and compared against post_date_gmt. Inclusive.',
				),
				'before'      => array(
					'type'        => 'string',
					'format'      => 'date-time',
					'description' => 'Return attachments uploaded on or before this RFC 3339 datetime (e.g. "2025-01-15T23:59:59Z"). Interpreted as UTC and compared against post_date_gmt. Inclusive.',
				),
				'author'      => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => 'Restrict to attachments uploaded by this user ID.',
				),
				'post_parent' => array(
					'type'        => 'integer',
					'minimum'     => 0,
					'description' => 'Restrict to attachments with this parent post ID. Use 0 to find unattached media.',
				),
				'per_page'    => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'maximum'     => self::MAX_PER_PAGE,
					'default'     => 10,
					'description' => 'Number of results per page (1-100).',
				),
				'page'        => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'default'     => 1,
					'description' => 'Page number to return.',
				),
			),
			'required'   => array( 'query' ),
		);
	}

	/**
	 * Output JSON Schema. Nullable shapes are required so validation passes
	 * for non-image and unattached media.
	 */
	public static function output_schema() {
		return array(
			'type'  => 'array',
			'items' => array(
				'type'       => 'object',
				'properties' => array(
					'id'         => array(
						'type'        => 'integer',
						'description' => 'Attachment post ID.',
					),
					'title'      => array(
						'type'        => 'string',
						'description' => 'Attachment title.',
					),
					'alt'        => array(
						'type'        => 'string',
						'description' => 'Alt text. Empty string when not set.',
					),
					'filename'   => array(
						'type'        => 'string',
						'description' => 'Base filename of the attached file.',
					),
					'url'        => array(
						'type'        => array( 'string', 'null' ),
						'format'      => 'uri',
						'description' => 'Public URL of the attachment, or null if no URL is available (missing file, or filtered out).',
					),
					'mime_type'  => array(
						'type'        => 'string',
						'description' => 'MIME type, e.g. "image/jpeg".',
					),
					'dimensions' => array(
						'type'        => array( 'object', 'null' ),
						'description' => 'Image dimensions in pixels. Null for non-image media or for images whose stored metadata does not include width and height.',
						'properties'  => array(
							'width'  => array( 'type' => 'integer' ),
							'height' => array( 'type' => 'integer' ),
						),
					),
					'parent'     => array(
						'type'        => array( 'integer', 'null' ),
						'description' => 'Parent post ID, or null if the attachment is unattached.',
					),
					'taxonomies' => array(
						'type'                 => 'object',
						'description'          => 'Map of taxonomy slug to array of assigned term names. Empty object if no terms are assigned.',
						'additionalProperties' => array(
							'type'  => 'array',
							'items' => array( 'type' => 'string' ),
						),
					),
				),
				'required'   => array( 'id', 'title', 'alt', 'filename', 'url', 'mime_type', 'dimensions', 'parent', 'taxonomies' ),
			),
		);
	}

	/**
	 * Execute the search. Maps input to WP_Query args, runs the query through
	 * the existing posts_clauses pipeline, and formats the results.
	 *
	 * Multi-term comma syntax is enabled locally via add_filter / remove_filter
	 * around the query so the global mse_allow_multi_term_search default
	 * (is_admin()) is unchanged outside this call.
	 */
	public static function execute( $input ) {
		$args = array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			's'              => $input['query'],
			'posts_per_page' => isset( $input['per_page'] ) ? (int) $input['per_page'] : 10,
			'paged'          => isset( $input['page'] ) ? (int) $input['page'] : 1,
			// Output schema has no total/total_pages envelope, so suppress
			// SQL_CALC_FOUND_ROWS — every call would otherwise pay for a
			// count it never returns.
			'no_found_rows'  => true,
		);

		if ( ! empty( $input['mime_type'] ) ) {
			$args['post_mime_type'] = $input['mime_type'];
		}

		if ( ! empty( $input['after'] ) || ! empty( $input['before'] ) ) {
			// post_date_gmt matches the semantics of /wp/v2/media?after=/before=
			// and the RFC 3339 (UTC) values the schema documents.
			$date_query = array(
				'inclusive' => true,
				'column'    => 'post_date_gmt',
			);
			if ( ! empty( $input['after'] ) ) {
				$date_query['after'] = $input['after'];
			}
			if ( ! empty( $input['before'] ) ) {
				$date_query['before'] = $input['before'];
			}
			$args['date_query'] = array( $date_query );
		}

		if ( ! empty( $input['author'] ) ) {
			$args['author'] = (int) $input['author'];
		}

		if ( isset( $input['post_parent'] ) ) {
			$args['post_parent'] = (int) $input['post_parent'];
		}

		$enable_multi_term = static function () {
			return true;
		};
		// Use PHP_INT_MAX so a site's global mse_allow_multi_term_search
		// override cannot suppress the ability's intended multi-term unlock.
		add_filter( 'mse_allow_multi_term_search', $enable_multi_term, PHP_INT_MAX );

		try {
			$query = new WP_Query( $args );
			return array_map( array( __CLASS__, 'format_attachment' ), $query->posts );
		} finally {
			remove_filter( 'mse_allow_multi_term_search', $enable_multi_term, PHP_INT_MAX );
		}
	}

	/**
	 * Format a single attachment post into the output schema shape.
	 */
	private static function format_attachment( $post ) {
		$id = $post->ID;

		$dimensions = null;
		if ( wp_attachment_is_image( $id ) ) {
			$meta = wp_get_attachment_metadata( $id );
			if ( ! empty( $meta['width'] ) && ! empty( $meta['height'] ) ) {
				$dimensions = array(
					'width'  => (int) $meta['width'],
					'height' => (int) $meta['height'],
				);
			}
		}

		$file     = get_attached_file( $id );
		$filename = $file ? wp_basename( $file ) : '';

		$url = wp_get_attachment_url( $id );

		$taxonomies = array();
		foreach ( get_object_taxonomies( 'attachment' ) as $tax ) {
			$terms = wp_get_post_terms( $id, $tax, array( 'fields' => 'names' ) );
			if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
				$taxonomies[ $tax ] = array_values( $terms );
			}
		}

		return array(
			'id'         => (int) $id,
			// Use the raw post_title — get_the_title() runs `the_title` filters
			// (wptexturize, entity encoding) producing &#8220;quoted&#8221;
			// titles that downstream consumers would have to decode.
			'title'      => (string) $post->post_title,
			'alt'        => (string) get_post_meta( $id, '_wp_attachment_image_alt', true ),
			'filename'   => $filename,
			'url'        => $url ? (string) $url : null,
			'mime_type'  => (string) get_post_mime_type( $id ),
			'dimensions' => $dimensions,
			'parent'     => $post->post_parent ? (int) $post->post_parent : null,
			'taxonomies' => (object) $taxonomies,
		);
	}
}
