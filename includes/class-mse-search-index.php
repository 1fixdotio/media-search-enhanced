<?php
/**
 * Media Search Enhanced — Phase 3 spike.
 *
 * SKELETON ONLY. This file is intentionally NOT loaded by the plugin bootstrap.
 * It exists to validate the read-path injection described in
 * docs/phase-3-large-library-mode.md, nothing more.
 *
 * Do not wire this into media-search-enhanced.php. Do not call these methods
 * from production code paths. The write path (hooks), WP-CLI rebuild, feature
 * flag, and uninstall are all out of scope for the spike — see section 10 of
 * the design doc for the follow-up issue breakdown.
 *
 * @package Media_Search_Enhanced
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

class MSE_Search_Index {

	/**
	 * Schema version. Bump when DDL changes so `posts_clauses` can fall back
	 * to the Phase 1 LIKE path until `wp mse-search-index rebuild` runs.
	 */
	const SCHEMA_VERSION = 1;

	/**
	 * Index table base name (without $wpdb->prefix).
	 */
	const TABLE_BASE = 'mse_search_index';

	/**
	 * Return the fully-qualified table name for the current blog.
	 *
	 * @return string
	 */
	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . self::TABLE_BASE;
	}

	/**
	 * Create the index table.
	 *
	 * NOT CALLED ANYWHERE. The real implementation will be driven by an
	 * activation hook and/or a `wp mse-search-index install` subcommand.
	 *
	 * @return void
	 */
	public static function create_table() {
		global $wpdb;

		$table   = self::table_name();
		$charset = $wpdb->get_charset_collate();

		// See design doc section 2 (recommended hybrid schema).
		$sql = "CREATE TABLE {$table} (
			attachment_id    BIGINT(20) UNSIGNED NOT NULL,
			searchable       MEDIUMTEXT NOT NULL,
			searchable_short VARCHAR(191) NOT NULL DEFAULT '',
			language_code    VARCHAR(16) NOT NULL DEFAULT '',
			blog_id          BIGINT(20) UNSIGNED NOT NULL DEFAULT 1,
			updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (attachment_id),
			KEY ix_short (searchable_short),
			KEY ix_scope (blog_id, language_code),
			FULLTEXT KEY ft_searchable (searchable) /*!50700 WITH PARSER ngram */
		) ENGINE=InnoDB {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Compose the searchable blob for an attachment and write a row.
	 *
	 * Respects the Phase 1 `mse_search_fields` filter at WRITE TIME (section 2
	 * of design doc — this is the documented semantic shift). A disabled
	 * field is not written into the blob, so toggling the filter requires a
	 * rebuild.
	 *
	 * NOT WIRED TO HOOKS. See section 10 follow-up issue #2.
	 *
	 * @param int $attachment_id
	 * @return bool True on success.
	 */
	public static function upsert( $attachment_id ) {
		unset( $attachment_id ); // pseudocode — real implementation deferred
		return false;
	}

	/**
	 * Delete an attachment's row.
	 *
	 * @param int $attachment_id
	 * @return bool
	 */
	public static function delete( $attachment_id ) {
		unset( $attachment_id );
		return false;
	}

	/**
	 * Query the index for matching attachment IDs.
	 *
	 * Primary path: MATCH(searchable) AGAINST (...).
	 * Short-term fallback: LIKE against searchable_short for terms below
	 * innodb_ft_min_token_size.
	 *
	 * The spike test does not exercise this method — it stubs the return
	 * value to prove the posts_clauses injection mechanism works. Real
	 * implementation is deferred to follow-up issue #3.
	 *
	 * @param string[] $terms
	 * @param string   $language_code
	 * @param int      $blog_id
	 * @param int      $limit
	 * @return int[] Attachment IDs.
	 */
	public static function query( $terms, $language_code = '', $blog_id = 0, $limit = 1000 ) {
		unset( $terms, $language_code, $blog_id, $limit );
		return array();
	}

	/**
	 * Build the `AND {$posts}.ID IN (...)` SQL fragment for a given set of IDs.
	 *
	 * This is the one piece of the read path the spike test exercises: we
	 * want to prove that appending this to `$pieces['where']` leaves the
	 * existing private-post visibility str_replace and search-fields
	 * behaviour intact.
	 *
	 * @param int[] $ids
	 * @return string SQL fragment (possibly empty). Always starts with " AND ".
	 */
	public static function build_in_clause( $ids ) {
		global $wpdb;

		$ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $ids ) ) ) );

		if ( empty( $ids ) ) {
			// An empty index-path result means "nothing matched" — force
			// the outer query to return zero rows, do not pass through to
			// the Phase 1 LIKE path. Callers that want the fallback must
			// detect the empty return value before calling this.
			return ' AND 1=0';
		}

		return ' AND ' . $wpdb->posts . '.ID IN (' . implode( ',', $ids ) . ')';
	}
}
