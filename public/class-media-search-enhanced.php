<?php
/**
 * Media Search Enhanced.
 *
 * @package   Media_Search_Enhanced
 * @author    1fixdotio <1fixdotio@gmail.com>
 * @license   GPL-2.0+
 * @link      http://1fix.io
 * @copyright 2014 1Fix.io
 */

/**
 * Plugin class. This class should ideally be used to work with the
 * public-facing side of the WordPress site.
 *
 * If you're interested in introducing administrative or dashboard
 * functionality, then refer to `class-media-search-enhanced-admin.php`
 *
 * @package Media_Search_Enhanced
 * @author  1fixdotio <1fixdotio@gmail.com>
 */
class Media_Search_Enhanced {

	/**
	 * Plugin version, used for cache-busting of style and script file references.
	 *
	 * @since   0.0.1
	 *
	 * @var     string
	 */
	const VERSION = '0.9.2';

	/**
	 *
	 * Unique identifier for your plugin.
	 *
	 *
	 * The variable name is used as the text domain when internationalizing strings
	 * of text. Its value should match the Text Domain file header in the main
	 * plugin file.
	 *
	 * @since    0.0.1
	 *
	 * @var      string
	 */
	protected $plugin_slug = 'media-search-enhanced';

	/**
	 * Instance of this class.
	 *
	 * @since    0.0.1
	 *
	 * @var      object
	 */
	protected static $instance = null;

	/**
	 * Initialize the plugin by setting localization and loading public scripts
	 * and styles.
	 *
	 * @since     0.0.1
	 */
	private function __construct() {

		// Load plugin text domain
		add_action( 'init', array( $this, 'load_plugin_textdomain' ) );

		// Media Search filters
		add_filter( 'posts_search', array( $this, 'suppress_core_search' ), 20, 2 );
		add_filter( 'posts_clauses', array( $this, 'posts_clauses' ), 20, 2 );

		// Add a media search form shortcode
		add_shortcode( 'mse-search-form', array( $this, 'search_form' ) );

		// Hook the image into the_excerpt
		add_filter( 'the_excerpt', array( $this, 'get_the_image' ) );

		// Change the permalinks at media search results page
		add_filter( 'attachment_link', array( $this, 'get_the_url' ), 10, 2 );

		// Filter the search form on search page to add post_type hidden field
		add_filter( 'get_search_form', array( $this, 'search_form_on_search' ) );

	}

	/**
	 * Return the plugin slug.
	 *
	 * @since    0.0.1
	 *
	 * @return    Plugin slug variable.
	 */
	public function get_plugin_slug() {

		return $this->plugin_slug;
	}

	/**
	 * Return an instance of this class.
	 *
	 * @since     0.0.1
	 *
	 * @return    object    A single instance of this class.
	 */
	public static function get_instance() {

		// If the single instance hasn't been set, set it now.
		if ( null == self::$instance ) {
			self::$instance = new self;
		}

		return self::$instance;
	}

	/**
	 * Load the plugin text domain for translation.
	 *
	 * @since    0.0.1
	 */
	public function load_plugin_textdomain() {

		$domain = $this->plugin_slug;
		$locale = apply_filters( 'plugin_locale', get_locale(), $domain );

		load_textdomain( $domain, trailingslashit( WP_LANG_DIR ) . $domain . '/' . $domain . '-' . $locale . '.mo' );
		load_plugin_textdomain( $domain, FALSE, basename( plugin_dir_path( dirname( __FILE__ ) ) ) . '/languages/' );

	}

	/**
	 * Check whether the given query is an attachment search handled by this plugin.
	 *
	 * @param WP_Query $query The WP_Query instance to check.
	 * @return bool
	 */
	private static function is_mse_search( $query ) {
		$vars = $query->query_vars;
		if ( empty( $vars ) ) {
			$vars = ( isset( $_REQUEST['query'] ) ) ? $_REQUEST['query'] : array();
		}

		return ! empty( $vars['s'] )
			&& ( ( isset( $_REQUEST['action'] ) && 'query-attachments' == $_REQUEST['action'] )
				|| ( isset( $vars['post_type'] ) && 'attachment' == $vars['post_type'] ) );
	}

	/**
	 * Suppress WordPress core's search WHERE clause so this plugin can
	 * replace it with expanded search conditions (meta, taxonomy, etc.)
	 * without overwriting the entire WHERE.
	 *
	 * @param string   $search Search SQL fragment.
	 * @param WP_Query $query  The WP_Query instance.
	 * @return string
	 */
	public static function suppress_core_search( $search, $query ) {
		if ( self::is_mse_search( $query ) ) {
			return '';
		}
		return $search;
	}

	/**
	 * Append expanded search conditions to the SQL WHERE clause.
	 *
	 * Instead of overwriting $pieces['where'], this preserves conditions
	 * from WordPress core and other plugins (type, status, date, parent,
	 * mime, author restrictions, etc.) and only appends the search logic.
	 *
	 * Core's default search clause is suppressed by suppress_core_search()
	 * to prevent conflicts with our expanded conditions.
	 *
	 * @return array
	 *
	 * @since    0.6.0
	 */
	public static function posts_clauses( $pieces, $query ) {

		global $wpdb;

		if ( ! self::is_mse_search( $query ) ) {
			return $pieces;
		}

		$vars = $query->query_vars;
		if ( empty( $vars ) ) {
			$vars = ( isset( $_REQUEST['query'] ) ) ? $_REQUEST['query'] : array();
		}

		// Expand post_status to include 'private' for authorized users.
		// Core's WHERE already has the status condition from query_vars;
		// we widen it rather than overwriting.
		if ( current_user_can( 'read_private_posts' )
			&& strpos( $pieces['where'], "post_status = 'private'" ) === false ) {
			$pieces['where'] = str_replace(
				"$wpdb->posts.post_status = 'inherit'",
				"($wpdb->posts.post_status = 'inherit' OR $wpdb->posts.post_status = 'private')",
				$pieces['where']
			);
		} elseif ( is_user_logged_in()
			&& strpos( $pieces['where'], "post_status = 'private'" ) === false ) {
			$pieces['where'] = str_replace(
				"$wpdb->posts.post_status = 'inherit'",
				$wpdb->prepare(
					"($wpdb->posts.post_status = 'inherit' OR ($wpdb->posts.post_status = 'private' AND $wpdb->posts.post_author = %d))",
					get_current_user_id()
				),
				$pieces['where']
			);
		}

		// WPML compatibility.
		if ( class_exists( 'WPML_Media' ) ) {
			global $sitepress;
			$lang = $sitepress->get_current_language();
			$pieces['where'] .= $wpdb->prepare( " AND t.element_type='post_attachment' AND t.language_code = %s", $lang );
		}

		// Multi-term (comma-separated) search is restricted to the admin media
		// modal to prevent query amplification on public-facing search pages.
		$is_media_modal = is_admin() && defined( 'DOING_AJAX' ) && DOING_AJAX
			&& isset( $_REQUEST['action'] ) && 'query-attachments' === $_REQUEST['action'];
		$is_media_modal = apply_filters( 'mse_is_media_modal_request', $is_media_modal );
		$max_terms      = (int) apply_filters( 'mse_max_search_terms', 10 );
		if ( $is_media_modal && strpos( $vars['s'], ',' ) !== false ) {
			$terms = array_values( array_filter( array_map( 'trim', explode( ',', $vars['s'] ) ), 'strlen' ) );
		} else {
			$terms = array( $vars['s'] );
		}
		if ( empty( $terms ) ) {
			$pieces['where'] .= " AND 1=0";
			return $pieces;
		}
		$terms = array_slice( $terms, 0, max( 1, $max_terms ) );

		// Build taxonomy filter once (shared across all terms).
		$taxes      = get_object_taxonomies( 'attachment' );
		$tax_filter = '';
		if ( ! empty( $taxes ) ) {
			$tax_where = array();
			foreach ( $taxes as $tax ) {
				$tax = sanitize_key( $tax );
				$tax_where[] = $wpdb->prepare( "tt.taxonomy = %s", $tax );
			}
			$tax_filter = '( ' . implode( ' OR ', $tax_where ) . ' )';
		}

		// Generate search conditions for each term, then OR them together.
		$term_groups = array();
		foreach ( $terms as $term ) {
			$term_groups[] = self::build_search_conditions( $term, $taxes, $tax_filter );
		}

		$pieces['where'] .= " AND ( " . implode( ' OR ', $term_groups ) . " )";

		$pieces['orderby'] = "$wpdb->posts.post_date DESC";

		return $pieces;
	}

	/**
	 * Build the SQL search conditions for a single search term.
	 *
	 * @param string $term       The search term.
	 * @param array  $taxes      Attachment taxonomies.
	 * @param string $tax_filter Pre-built taxonomy filter clause.
	 * @return string SQL fragment: ( id_cond OR title LIKE ... OR EXISTS ... )
	 */
	private static function build_search_conditions( $term, $taxes, $tax_filter ) {
		global $wpdb;

		$like = '%' . $wpdb->esc_like( $term ) . '%';

		// Use exact integer match for ID when search term is numeric.
		$search_trimmed = trim( $term );
		$search_int     = absint( $search_trimmed );
		if ( $search_int > 0 && ctype_digit( $search_trimmed ) ) {
			$id_condition = sprintf( "($wpdb->posts.ID = %d)", $search_int );
		} else {
			$id_condition = '(1=0)';
		}

		$conditions = $wpdb->prepare(
			"( $id_condition OR ($wpdb->posts.post_title LIKE %s) OR ($wpdb->posts.guid LIKE %s) OR ($wpdb->posts.post_content LIKE %s) OR ($wpdb->posts.post_excerpt LIKE %s)",
			$like, $like, $like, $like
		);

		// Alt text
		$conditions .= $wpdb->prepare(
			" OR EXISTS (SELECT 1 FROM $wpdb->postmeta WHERE $wpdb->postmeta.post_id = $wpdb->posts.ID AND $wpdb->postmeta.meta_key = '_wp_attachment_image_alt' AND $wpdb->postmeta.meta_value LIKE %s)",
			$like
		);

		// Filename
		$conditions .= $wpdb->prepare(
			" OR EXISTS (SELECT 1 FROM $wpdb->postmeta WHERE $wpdb->postmeta.post_id = $wpdb->posts.ID AND $wpdb->postmeta.meta_key = '_wp_attached_file' AND $wpdb->postmeta.meta_value LIKE %s)",
			$like
		);

		// Taxonomy
		if ( ! empty( $taxes ) && ! empty( $tax_filter ) ) {
			$conditions .= " OR EXISTS (SELECT 1 FROM $wpdb->term_relationships AS tr"
				. " INNER JOIN $wpdb->term_taxonomy AS tt ON (tr.term_taxonomy_id = tt.term_taxonomy_id AND $tax_filter)"
				. " INNER JOIN $wpdb->terms AS t ON (tt.term_id = t.term_id)"
				. " WHERE tr.object_id = $wpdb->posts.ID AND ("
				. $wpdb->prepare( "t.slug LIKE %s OR tt.description LIKE %s OR t.name LIKE %s", $like, $like, $like )
				. "))";
		}

		$conditions .= " )";

		return $conditions;
	}

	/**
	 * Create media search form
	 *
	 * @return string Media search form
	 *
	 * @since 0.5.0
	 */
	public function search_form( $form = '' ) {

		$domain = $this->plugin_slug;
		$s = get_query_var( 's' );

		$placeholder = ( empty ( $s ) ) ? apply_filters( 'mse_search_form_placeholder', __( 'Search Media...', $domain ) ) : $s;

		if ( empty( $form ) )
			$form = get_search_form( false );

		$form = preg_replace( "/(form.*class=\")(.\S*)\"/", '$1$2 ' . apply_filters( 'mse_search_form_class', 'mse-search-form' ) . '"', $form );
		$form = preg_replace( "/placeholder=\"(.\S)*\"/", 'placeholder="' . esc_attr( $placeholder ) . '"', $form );
		$form = str_replace( '</form>', '<input type="hidden" name="post_type" value="attachment" /></form>', $form );

		$result = apply_filters( 'mse_search_form', $form );

		return $result;

	}

	/**
	 * Get the attachment image and hook into the_excerpt
	 *
	 * @param  string $excerpt The excerpt HTML
	 * @return string          The hooked excerpt HTML
	 *
	 * @since  0.5.2
	 */
	public function get_the_image( $excerpt ) {

		global $post;

		if ( ! is_admin() && is_search() && 'attachment' == $post->post_type ) {
			$params = array(
				'attachment_id' => $post->ID,
				'size' => 'thumbnail',
				'icon' => false,
				'attr' => array()
				);
			$params = apply_filters( 'mse_get_attachment_image_params', $params );
			extract( $params );

			$html = '';
			$clickable = apply_filters( 'mse_is_image_clickable', true );
			if ( $clickable ) {
				$html .= '<a href="' . get_attachment_link( $attachment_id ) . '"';
				$attr = apply_filters( 'wp_get_attachment_image_attributes', $attr, $post, $size );
				$attr = array_map( 'esc_attr', $attr );
				foreach ( $attr as $name => $value ) {
					$html .= " $name=" . '"' . $value . '"';
				}
				$html .= '>';
			}

			$html .= wp_get_attachment_image( $attachment_id, $size, $icon, $attr );

			if ( $clickable )
				$html .= '</a>';

			$excerpt .= $html;
		}

		return $excerpt;

	}

	/**
	 * Add filter to hook into the attachment URL
	 *
	 * @param  string $link    The attachment's permalink.
	 * @param  int $post_id Attachment ID.
	 * @return string          The attachment's permalink.
	 *
	 * @since 0.5.4
	 */
	public function get_the_url( $link, $post_id ) {

		if ( ! is_admin() && is_search() ) {
			$link = apply_filters( 'mse_get_attachment_url', $link, $post_id );
		}

		return $link;
	}

	/**
	 * Filter the search form on search page to add post_type hidden field
	 *
	 * @param  string $form The search form.
	 * @return string The filtered search form
	 *
	 * @since 0.7.2
	 */
	public function search_form_on_search( $form ) {

		if ( is_search() && is_main_query() && isset( $_GET['post_type'] ) && 'attachment' == $_GET['post_type'] ) {
			$form = $this->search_form( $form );
		}

		return $form;
	}

}
