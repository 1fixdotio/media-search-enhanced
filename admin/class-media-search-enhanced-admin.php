<?php
/**
 * Media Search Enhanced.
 *
 * @package   Media_Search_Enhanced_Admin
 * @author    1fixdotio <1fixdotio@gmail.com>
 * @license   GPL-2.0+
 * @link      http://1fix.io
 * @copyright 2014 1Fix.io
 */

/**
 * Plugin class. This class should ideally be used to work with the
 * administrative side of the WordPress site.
 *
 * If you're interested in introducing public-facing
 * functionality, then refer to `class-media-search-enhanced.php`
 *
 * @package Media_Search_Enhanced_Admin
 * @author  1fixdotio <1fixdotio@gmail.com>
 */
class Media_Search_Enhanced_Admin {

	/**
	 * Instance of this class.
	 *
	 * @since    0.0.1
	 *
	 * @var      object
	 */
	protected static $instance = null;

	/**
	 * Slug of the plugin screen.
	 *
	 * @since    0.0.1
	 *
	 * @var      string
	 */
	protected $plugin_screen_hook_suffix = null;

	/**
	 * Initialize the plugin by loading admin scripts & styles and adding a
	 * settings page and menu.
	 *
	 * @since     0.0.1
	 */
	private function __construct() {

		add_filter( 'posts_where', array( $this, 'posts_where' ) );
		add_filter( 'posts_join', array( $this, 'posts_join' ) );
		add_filter( 'posts_distinct', array( $this, 'posts_distinct' ) );

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
	 * Set WHERE clause in the SQL statement
	 *
	 * @return string WHERE statement
	 *
	 * @since    0.2.0
	 */
	public function posts_where( $where ) {

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
	public function posts_join( $join ) {

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
	public function posts_distinct() {

		global $wp_query;
		$vars = $wp_query->query_vars;
		if ( empty( $vars ) ) {
			$vars = ( isset( $_REQUEST['query'] ) ) ? $_REQUEST['query'] : array();
		}

		if ( ! empty( $vars['s'] ) )
			return 'DISTINCT';

	}

}
