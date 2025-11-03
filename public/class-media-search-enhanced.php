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
	const VERSION = '0.9.1';

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
		add_filter( 'posts_clauses', array( $this, 'posts_clauses' ), 20 );

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
	 * Set query clauses in the SQL statement
	 *
	 * @return array
	 *
	 * @since    0.6.0
	 */
	public static function posts_clauses( $pieces ) {

		global $wp_query, $wpdb;

		$vars = $wp_query->query_vars;
		if ( empty( $vars ) ) {
			$vars = ( isset( $_REQUEST['query'] ) ) ? $_REQUEST['query'] : array();
		}

		// Rewrite the where clause
		if ( ! empty( $vars['s'] ) && ( ( isset( $_REQUEST['action'] ) && 'query-attachments' == $_REQUEST['action'] ) || 'attachment' == $vars['post_type'] ) ) {
			$pieces['where'] = " AND $wpdb->posts.post_type = 'attachment' AND ($wpdb->posts.post_status = 'inherit' OR $wpdb->posts.post_status = 'private')";

			if ( class_exists('WPML_Media') ) {
				global $sitepress;
				//get current language
				$lang = $sitepress->get_current_language();
				$pieces['where'] .= $wpdb->prepare( " AND t.element_type='post_attachment' AND t.language_code = %s", $lang );
			}

			if ( ! empty( $vars['post_parent'] ) ) {
				$pieces['where'] .= " AND $wpdb->posts.post_parent = " . $vars['post_parent'];
			} elseif ( isset( $vars['post_parent'] ) && 0 === $vars['post_parent'] ) {
				// Get unattached attachments
				$pieces['where'] .= " AND $wpdb->posts.post_parent = 0";
			}

			if ( ! empty( $vars['post_mime_type'] ) ) {
				$mime_types = is_array( $vars['post_mime_type'] ) ? $vars['post_mime_type'] : [ $vars['post_mime_type'] ];
				$like_clauses = array();

				foreach ( $mime_types as $mime_type ) {
					$mime_type_like = '%' . $wpdb->esc_like( $mime_type ) . '%';
					$like_clauses[] = $wpdb->prepare( "$wpdb->posts.post_mime_type LIKE %s", $mime_type_like );
				}

				$pieces['where'] .= " AND (" . implode( ' OR ', $like_clauses ) . ")";
			}

			if ( ! empty( $vars['m'] ) ) {
				$year = substr( $vars['m'], 0, 4 );
				$monthnum = substr( $vars['m'], 4 );
				$pieces['where'] .= $wpdb->prepare( " AND YEAR($wpdb->posts.post_date) = %d AND MONTH($wpdb->posts.post_date) = %d", $year, $monthnum );
			} else {
				if ( ! empty( $vars['year'] ) && 'false' != $vars['year'] ) {
					$pieces['where'] .= $wpdb->prepare( " AND YEAR($wpdb->posts.post_date) = %d", $vars['year'] );
				}

				if ( ! empty( $vars['monthnum'] ) && 'false' != $vars['monthnum'] ) {
					$pieces['where'] .= $wpdb->prepare( " AND MONTH($wpdb->posts.post_date) = %d", $vars['monthnum'] );
				}
			}

			// search for keyword "s"
			$like = '%' . $wpdb->esc_like( $vars['s'] ) . '%';
			$pieces['where'] .= $wpdb->prepare( " AND ( ($wpdb->posts.ID LIKE %s) OR ($wpdb->posts.post_title LIKE %s) OR ($wpdb->posts.guid LIKE %s) OR ($wpdb->posts.post_content LIKE %s) OR ($wpdb->posts.post_excerpt LIKE %s)", $like, $like, $like, $like, $like );
			$pieces['where'] .= $wpdb->prepare( " OR (mse_pm.meta_key = '_wp_attachment_image_alt' AND mse_pm.meta_value LIKE %s)", $like );
			$pieces['where'] .= $wpdb->prepare( " OR (mse_pm.meta_key = '_wp_attached_file' AND mse_pm.meta_value LIKE %s)", $like );

			// Get taxes for attachments
			$taxes = get_object_taxonomies( 'attachment' );
			if ( ! empty( $taxes ) ) {
				$pieces['where'] .= $wpdb->prepare( " OR (tter.slug LIKE %s) OR (ttax.description LIKE %s) OR (tter.name LIKE %s)", $like, $like, $like );
			}

			$pieces['where'] .= " )";

			$pieces['join'] .= " LEFT JOIN $wpdb->postmeta AS mse_pm ON $wpdb->posts.ID = mse_pm.post_id";

			// Get taxes for attachments
			$taxes = get_object_taxonomies( 'attachment' );
			if ( ! empty( $taxes ) ) {
				$on = array();
				foreach ( $taxes as $tax ) {
					$on[] = "ttax.taxonomy = '$tax'";
				}
				$on = '( ' . implode( ' OR ', $on ) . ' )';

				$pieces['join'] .= " LEFT JOIN $wpdb->term_relationships AS trel ON ($wpdb->posts.ID = trel.object_id) LEFT JOIN $wpdb->term_taxonomy AS ttax ON (" . $on . " AND trel.term_taxonomy_id = ttax.term_taxonomy_id) LEFT JOIN $wpdb->terms AS tter ON (ttax.term_id = tter.term_id) ";
			}

			$pieces['distinct'] = 'DISTINCT';

			$pieces['orderby'] = "$wpdb->posts.post_date DESC";
		}

		return $pieces;
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
		$form = preg_replace( "/placeholder=\"(.\S)*\"/", 'placeholder="' . $placeholder . '"', $form );
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
