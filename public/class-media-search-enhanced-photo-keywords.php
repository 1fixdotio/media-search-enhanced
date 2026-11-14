<?php
/**
 * Media Search Enhanced: photo keyword index.
 *
 * @package   Media_Search_Enhanced
 * @author    1fixdotio <1fixdotio@gmail.com>
 * @license   GPL-2.0+
 * @link      http://1fix.io
 */

/**
 * Keeps a searchable plain-text copy of the keywords embedded in photos.
 *
 * WordPress reads the IPTC keywords of an uploaded photo into the `keywords`
 * array of `image_meta`, inside the serialized `_wp_attachment_metadata` value.
 * SQL cannot search that value without also matching file names, sizes and
 * camera data, so the keywords are copied into their own meta key whenever the
 * attachment metadata is written. The search then matches that key with an
 * EXISTS subquery, the same way it matches alt text.
 *
 * Attachments uploaded before this index existed are indexed by a WP-Cron
 * backfill.
 *
 * @package Media_Search_Enhanced
 */
class Media_Search_Enhanced_Photo_Keywords {

	/**
	 * Meta key holding the keywords, one per line.
	 *
	 * Search terms never contain line breaks (core strips them), so a term
	 * cannot match across two keywords.
	 *
	 * @var string
	 */
	const META_KEY = '_mse_photo_keywords';

	/**
	 * Meta key WordPress stores attachment metadata under.
	 *
	 * @var string
	 */
	const SOURCE_META_KEY = '_wp_attachment_metadata';

	/**
	 * WP-Cron hook that brings the index in line with the attachment metadata:
	 * it indexes attachments uploaded before the index existed and removes
	 * entries whose keywords are gone.
	 *
	 * @var string
	 */
	const BACKFILL_HOOK = 'mse_backfill_photo_keywords';

	/**
	 * Option holding the backfill progress: the last attachment ID examined
	 * (with or without keywords), or BACKFILL_DONE once every attachment has
	 * been examined.
	 *
	 * @var string
	 */
	const BACKFILL_OPTION = 'mse_photo_keywords_backfill';

	/**
	 * Option holding the highest post ID when the backfill started.
	 *
	 * Attachments created after that are indexed by the meta hooks and never
	 * touched by the backfill, so it cannot remove the entry of an upload whose
	 * metadata it read before the upload finished saving it.
	 *
	 * @var string
	 */
	const BACKFILL_UNTIL_OPTION = 'mse_photo_keywords_backfill_until';

	/**
	 * Option counting backfill restarts.
	 *
	 * A cron run that is still working when the backfill is restarted (e.g. by
	 * a bulk metadata deletion) sees the count change and stops, instead of
	 * writing its own progress over the restart.
	 *
	 * @var string
	 */
	const BACKFILL_RUN_OPTION = 'mse_photo_keywords_backfill_run';

	/**
	 * Value of BACKFILL_OPTION once the backfill has finished.
	 *
	 * @var string
	 */
	const BACKFILL_DONE = 'done';

	/**
	 * Attachments read per backfill query.
	 *
	 * @var int
	 */
	const BACKFILL_BATCH_SIZE = 200;

	/**
	 * Seconds a single cron run keeps indexing before it reschedules itself.
	 *
	 * wp-cron.php only runs the events that were due when it started, and
	 * spawns at most once per WP_CRON_LOCK_TIMEOUT, so one batch per run would
	 * index a large library very slowly.
	 *
	 * @var int
	 */
	const BACKFILL_TIME_LIMIT = 10;

	/**
	 * Register the hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'added_post_meta', array( __CLASS__, 'on_meta_saved' ), 10, 4 );
		add_action( 'updated_post_meta', array( __CLASS__, 'on_meta_saved' ), 10, 4 );
		add_action( 'deleted_post_meta', array( __CLASS__, 'on_meta_deleted' ), 10, 3 );

		add_action( 'init', array( __CLASS__, 'maybe_schedule_backfill' ) );
		add_action( self::BACKFILL_HOOK, array( __CLASS__, 'run_backfill' ) );
	}

	/**
	 * Restart the backfill when the plugin is activated.
	 *
	 * While the plugin was inactive, nothing kept the index in line with
	 * uploads and metadata changes.
	 *
	 * @param bool $network_wide Whether the plugin is being network-activated.
	 * @return void
	 */
	public static function activate( $network_wide = false ) {
		if ( is_multisite() && $network_wide ) {
			foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $site_id ) {
				switch_to_blog( $site_id );
				self::restart_backfill();
				restore_current_blog();
			}
			return;
		}

		self::restart_backfill();
	}

	/**
	 * Run the backfill again from the first attachment.
	 *
	 * @return void
	 */
	public static function restart_backfill() {
		update_option( self::BACKFILL_RUN_OPTION, self::current_backfill_run() + 1, false );
		delete_option( self::BACKFILL_OPTION );
		self::maybe_schedule_backfill();
	}

	/**
	 * Start the backfill, or restart it if its cron event was lost.
	 *
	 * A one-off event whose callback is missing (e.g. the plugin was
	 * deactivated mid-backfill) is dropped when it fires, so the event is
	 * rescheduled whenever the backfill is unfinished and nothing is queued.
	 *
	 * @return void
	 */
	public static function maybe_schedule_backfill() {
		$state = get_option( self::BACKFILL_OPTION );

		if ( self::BACKFILL_DONE === $state ) {
			return;
		}

		if ( false === $state ) {
			self::save_backfill_bound();
			add_option( self::BACKFILL_OPTION, 0, '', 'yes' );
		}

		if ( ! wp_next_scheduled( self::BACKFILL_HOOK ) ) {
			wp_schedule_single_event( time(), self::BACKFILL_HOOK );
		}
	}

	/**
	 * Index existing attachments in batches until done or out of time.
	 *
	 * @return void
	 */
	public static function run_backfill() {
		// Read the restart count before the progress, and both from the database:
		// a restart in another request after this process loaded its options would
		// otherwise pair the new count with the old progress, and the run would
		// skip the attachments below it.
		$run   = self::current_backfill_run();
		$state = self::get_option_from_database( self::BACKFILL_OPTION );

		if ( self::BACKFILL_DONE === $state ) {
			return;
		}

		$until = self::get_option_from_database( self::BACKFILL_UNTIL_OPTION );
		if ( false === $state || false === $until ) {
			$until = self::save_backfill_bound();
		}

		// Restarted while reading the progress: the restarted run takes over.
		if ( self::current_backfill_run() !== $run ) {
			return;
		}

		$last_id  = (int) $state;
		$deadline = microtime( true ) + self::BACKFILL_TIME_LIMIT;

		do {
			$last_id = self::backfill_batch( $last_id, self::BACKFILL_BATCH_SIZE, (int) $until );

			if ( ! self::save_backfill_progress( $run, null === $last_id ? self::BACKFILL_DONE : $last_id ) ) {
				// Restarted while this run was working; the restart owns the progress now.
				return;
			}

			if ( null === $last_id ) {
				return;
			}
		} while ( microtime( true ) < $deadline );

		wp_schedule_single_event( time(), self::BACKFILL_HOOK );
	}

	/**
	 * Save the backfill progress, unless the backfill was restarted since this run began.
	 *
	 * @param int        $run      Restart count when this run began.
	 * @param int|string $progress Last attachment ID examined, or BACKFILL_DONE.
	 * @return bool False if the backfill was restarted and this run should stop.
	 */
	private static function save_backfill_progress( $run, $progress ) {
		if ( self::current_backfill_run() !== $run ) {
			return false;
		}

		update_option( self::BACKFILL_OPTION, $progress );

		// A restart between the check and the write was overwritten: put its reset back.
		if ( self::current_backfill_run() !== $run ) {
			update_option( self::BACKFILL_OPTION, 0 );
			return false;
		}

		return true;
	}

	/**
	 * Get the backfill restart count.
	 *
	 * Read from the database rather than the option cache: a cron run lasts
	 * several seconds and a restart happens in another request.
	 *
	 * @return int
	 */
	private static function current_backfill_run() {
		return (int) self::get_option_from_database( self::BACKFILL_RUN_OPTION );
	}

	/**
	 * Read an option from the database, bypassing the option cache.
	 *
	 * @param string $option Option name.
	 * @return mixed The option value, or false if it does not exist.
	 */
	private static function get_option_from_database( $option ) {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT option_value FROM $wpdb->options WHERE option_name = %s", $option )
		);

		return null === $row ? false : maybe_unserialize( $row->option_value );
	}

	/**
	 * Bring the next batch of attachments in line with their metadata.
	 *
	 * Walks attachment IDs on the posts table's primary key, then reads the
	 * metadata of just those attachments. Ordering postmeta rows by post_id
	 * instead makes MySQL sort every remaining metadata row on each batch.
	 * Metadata without keywords is skipped in SQL, so it is never unserialized;
	 * attachments in the batch that have no keywords lose any index entry.
	 *
	 * @param int      $after_id Look at attachments with a higher post ID than this.
	 * @param int      $limit    Maximum number of attachments to look at.
	 * @param int|null $until_id Highest post ID to look at, or null for no limit.
	 * @return int|null The last attachment ID looked at, or null when no attachments are left.
	 */
	public static function backfill_batch( $after_id, $limit, $until_id = null ) {
		global $wpdb;

		$ids = array_map(
			'intval',
			$wpdb->get_col(
				$wpdb->prepare(
					"SELECT ID FROM $wpdb->posts WHERE post_type = 'attachment' AND ID > %d AND ID <= %d ORDER BY ID ASC LIMIT %d",
					$after_id,
					null === $until_id ? PHP_INT_MAX : $until_id,
					$limit
				)
			)
		);

		if ( empty( $ids ) ) {
			return null;
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT post_id, meta_value FROM $wpdb->postmeta"
				. ' WHERE post_id IN (' . implode( ',', $ids ) . ')'
				. ' AND meta_key = %s AND meta_value LIKE %s AND meta_value NOT LIKE %s',
				self::SOURCE_META_KEY,
				'%s:8:"keywords";a:%',
				'%s:8:"keywords";a:0:{%'
			)
		);

		$with_keywords = array();
		foreach ( $rows as $row ) {
			$with_keywords[] = (int) $row->post_id;
			self::index( (int) $row->post_id, $row->meta_value );
			self::evict_from_cache( (int) $row->post_id );
		}

		// Remove index entries whose keywords or metadata were removed while
		// nothing was watching (plugin inactive, bulk deletion). The metadata is
		// checked again inside the DELETE, not taken from the batch's snapshot:
		// an attachment that gained keywords since the snapshot keeps the entry
		// its save hook just wrote.
		$without_keywords = array_diff( $ids, $with_keywords );
		if ( ! empty( $without_keywords ) ) {
			$deleted = $wpdb->query(
				$wpdb->prepare(
					"DELETE idx FROM $wpdb->postmeta AS idx"
					. " LEFT JOIN $wpdb->postmeta AS src ON ( src.post_id = idx.post_id AND src.meta_key = %s AND src.meta_value LIKE %s AND src.meta_value NOT LIKE %s )"
					. ' WHERE idx.meta_key = %s AND idx.post_id IN (' . implode( ',', $without_keywords ) . ') AND src.meta_id IS NULL',
					self::SOURCE_META_KEY,
					'%s:8:"keywords";a:%',
					'%s:8:"keywords";a:0:{%',
					self::META_KEY
				)
			);

			if ( $deleted ) {
				// What delete_post_meta() would have invalidated: the posts' meta
				// and cached query results, which include searches.
				foreach ( $without_keywords as $post_id ) {
					self::evict_from_cache( $post_id );
				}
				wp_cache_set_posts_last_changed();
			}
		}

		if ( count( $ids ) < $limit ) {
			return null;
		}

		return end( $ids );
	}

	/**
	 * Re-index an attachment when its metadata is added or updated.
	 *
	 * Fires once per generated image size during an upload, so it only writes.
	 *
	 * @param int    $meta_id    Meta ID.
	 * @param int    $object_id  Post ID.
	 * @param string $meta_key   Meta key.
	 * @param mixed  $meta_value Unslashed meta value.
	 * @return void
	 */
	public static function on_meta_saved( $meta_id, $object_id, $meta_key, $meta_value ) {
		if ( self::SOURCE_META_KEY !== $meta_key ) {
			return;
		}

		self::index( $object_id, $meta_value );
	}

	/**
	 * Drop the keywords when the attachment metadata is deleted.
	 *
	 * A bulk deletion (delete_post_meta_by_key()) does not say which posts it
	 * affected, so the backfill is restarted to remove their entries.
	 *
	 * @param string[] $meta_ids  Deleted meta IDs.
	 * @param int      $object_id Post ID. Empty when deleting the key from all posts.
	 * @param string   $meta_key  Meta key.
	 * @return void
	 */
	public static function on_meta_deleted( $meta_ids, $object_id, $meta_key ) {
		if ( self::SOURCE_META_KEY !== $meta_key ) {
			return;
		}

		if ( ! $object_id ) {
			self::restart_backfill();
			return;
		}

		delete_post_meta( $object_id, self::META_KEY );
	}

	/**
	 * Save the highest current post ID as the end of the backfill.
	 *
	 * @return int The saved post ID.
	 */
	private static function save_backfill_bound() {
		global $wpdb;

		$until = (int) $wpdb->get_var( "SELECT MAX(ID) FROM $wpdb->posts" );
		update_option( self::BACKFILL_UNTIL_OPTION, $until, false );

		return $until;
	}

	/**
	 * Drop a post and its meta from the object cache.
	 *
	 * update_post_meta() and delete_post_meta() cache the post (via
	 * wp_is_post_revision()) and, when nothing changes, all of its meta, so a
	 * long backfill would otherwise hold every attachment it looked at.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	private static function evict_from_cache( $post_id ) {
		wp_cache_delete( $post_id, 'posts' );
		wp_cache_delete( $post_id, 'post_meta' );
	}

	/**
	 * Write the keyword index for one attachment.
	 *
	 * @param int   $post_id  Attachment ID.
	 * @param mixed $metadata Attachment metadata, serialized or not.
	 * @return void
	 */
	public static function index( $post_id, $metadata ) {
		$keywords = self::extract_keywords( $metadata );

		if ( empty( $keywords ) ) {
			delete_post_meta( $post_id, self::META_KEY );
			return;
		}

		// update_post_meta() unslashes its input.
		update_post_meta( $post_id, self::META_KEY, wp_slash( implode( "\n", $keywords ) ) );
	}

	/**
	 * Get the embedded keywords from attachment metadata as plain text.
	 *
	 * wp_read_image_metadata() runs the metadata through wp_kses_post_deep(),
	 * which stores `B&W` as `B&amp;W`. The entities are decoded so the keyword
	 * matches what the user typed.
	 *
	 * @param mixed $metadata Attachment metadata, serialized or not.
	 * @return string[] Unique, non-empty keywords.
	 */
	public static function extract_keywords( $metadata ) {
		$metadata = maybe_unserialize( $metadata );

		if ( ! is_array( $metadata ) || empty( $metadata['image_meta']['keywords'] ) ) {
			return array();
		}

		$keywords = array();
		foreach ( (array) $metadata['image_meta']['keywords'] as $keyword ) {
			if ( ! is_scalar( $keyword ) ) {
				continue;
			}

			$keyword = html_entity_decode( (string) $keyword, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			$keyword = trim( str_replace( array( "\r", "\n" ), ' ', $keyword ) );

			if ( '' !== $keyword ) {
				$keywords[] = $keyword;
			}
		}

		return array_values( array_unique( $keywords ) );
	}
}
