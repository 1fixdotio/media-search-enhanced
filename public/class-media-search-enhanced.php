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
	const VERSION = '0.5.1';

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
		add_filter( 'posts_where', array( $this, 'posts_where' ) );
		add_filter( 'posts_join', array( $this, 'posts_join' ) );
		add_filter( 'posts_distinct', array( $this, 'posts_distinct' ) );

		// Add a media search form shortcode
		add_shortcode( 'mse-search-form', array( $this, 'search_form' ) );

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
	 * Set WHERE clause in the SQL statement
	 *
	 * @return string WHERE statement
	 *
	 * @since    0.2.0
	 */
	public static function posts_where( $where ) {

		global $wp_query, $wpdb;

		$vars = $wp_query->query_vars;
		if ( empty( $vars ) ) {
			$vars = ( isset( $_REQUEST['query'] ) ) ? $_REQUEST['query'] : array();
		}

		// Rewrite the where clause
		if ( ! empty( $vars['s'] ) && ( ( isset( $_REQUEST['action'] ) && 'query-attachments' == $_REQUEST['action'] ) || 'attachment' == $vars['post_type'] ) ) {
			$where = " AND $wpdb->posts.post_type = 'attachment' AND ($wpdb->posts.post_status = 'inherit' OR $wpdb->posts.post_status = 'private')";

			if ( ! empty( $vars['post_parent'] ) ) {
				$where .= " AND $wpdb->posts.post_parent = " . $vars['post_parent'];
			}


			$where .= " AND ( ($wpdb->posts.post_title LIKE '%" . $vars['s'] . "%') OR ($wpdb->posts.guid LIKE '%" . $vars['s'] . "%') OR ($wpdb->posts.post_content LIKE '%" . $vars['s'] . "%') OR ($wpdb->posts.post_excerpt LIKE '%" . $vars['s'] . "%')";
			$where .= " OR ($wpdb->postmeta.meta_key = '_wp_attachment_image_alt' AND $wpdb->postmeta.meta_value LIKE '%" . $vars['s'] . "%')";
			$where .= " OR ($wpdb->postmeta.meta_key = '_wp_attached_file' AND $wpdb->postmeta.meta_value LIKE '%" . $vars['s'] . "%')";

			// Get taxes for attachements
			$taxes = get_object_taxonomies( 'attachment' );
			if ( ! empty( $taxes ) ) {
				$where .= " OR (tter.slug LIKE '%" . $vars['s'] . "%')";
				$where .= " OR (ttax.description LIKE '%" . $vars['s'] . "%')";
				$where .= " OR (tter.name LIKE '%" . $vars['s'] . "%')";
			}

			$where .= " )";
		}

		return $where;

	}

	/**
	 * Set JOIN statement in the SQL statement
	 *
	 * @return string JOIN statement
	 *
	 * @since    0.2.0
	 */
	public static function posts_join( $join ) {

		global $wp_query, $wpdb;
		$vars = $wp_query->query_vars;
		if ( empty( $vars ) ) {
			$vars = ( isset( $_REQUEST['query'] ) ) ? $_REQUEST['query'] : array();
		}

		if ( ! empty( $vars['s'] ) && ( ( isset( $_REQUEST['action'] ) && 'query-attachments' == $_REQUEST['action'] ) || 'attachment' == $vars['post_type'] ) ) {
			$join .= " LEFT JOIN $wpdb->postmeta ON $wpdb->posts.ID = $wpdb->postmeta.post_id";

			// Get taxes for attachements
			$taxes = get_object_taxonomies( 'attachment' );
			if ( ! empty( $taxes ) ) {
				$on = array();
				foreach ( $taxes as $tax ) {
					$on[] = "ttax.taxonomy = '$tax'";
				}
				$on = '( ' . implode( ' OR ', $on ) . ' )';

				$join .= " LEFT JOIN $wpdb->term_relationships AS trel ON ($wpdb->posts.ID = trel.object_id) LEFT JOIN $wpdb->term_taxonomy AS ttax ON (" . $on . " AND trel.term_taxonomy_id = ttax.term_taxonomy_id) LEFT JOIN $wpdb->terms AS tter ON (ttax.term_id = tter.term_id) ";
			}
		}

		return $join;

	}

	/**
	 * Set DISTINCT statement in the SQL statement
	 *
	 * @return string DISTINCT statement
	 *
	 * @since 0.2.0
	 *
	 */
	public static function posts_distinct() {

		global $wp_query;
		$vars = $wp_query->query_vars;
		if ( empty( $vars ) ) {
			$vars = ( isset( $_REQUEST['query'] ) ) ? $_REQUEST['query'] : array();
		}

		if ( ! empty( $vars['s'] ) )
			return 'DISTINCT';

	}

	/**
	 * Create media search form
	 *
	 * @return string Media search form
	 *
	 * @since 0.5.0
	 */
	public function search_form() {

		$domain = $this->plugin_slug;

		$form = get_search_form( false );
		$form = preg_replace( "/(form.*class=\")(.\S*)\"/", '$1$2 ' . apply_filters( 'mse_search_form_class', 'mse-search-form' ) . '"', $form );
		$form = preg_replace( "/placeholder=\"(.\S)*\"/", 'placeholder="' . apply_filters( 'mse_search_form_placeholder', __( 'Search Media...', $domain ) ) . '"', $form );
		$form = str_replace( '</form>', '<input type="hidden" name="post_type" value="attachment" /></form>', $form );

		$result = apply_filters( 'mse_search_form', $form );

		return $result;
	}

}
