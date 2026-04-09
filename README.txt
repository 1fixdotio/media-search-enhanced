=== Media Search Enhanced ===

Contributors: 1fixdotio, yoren
Donate link: https://1fix.io/
Tags: media library, media, attachment
Requires at least: 3.5
Tested up to: 6.8.3
Stable tag: 1.0.0
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Search through all fields in Media Library.

== Description ==

This plugin is made for:

* Search through all fields in Media Library, including: ID, title, caption, alternative text and description.
* Search Taxonomies for Media, include the name, slug and description fields.
* Search media file name.
* **Multi-term search** — In the admin Media Library modal, use commas to search for multiple items at once (e.g. `image-a.jpg, photo-2.jpg`). Matches attachments containing **any** of the terms. Limited to 10 terms per search. This feature is only available in the admin media modal; frontend searches treat commas as literal characters.
* Use shortcode `[mse-search-form]` to insert a media search form in posts and template files. It will search for media by all fields mentioned above.

== Installation ==

= Using The WordPress Dashboard =

1. Navigate to the 'Add New' in the plugins dashboard
2. Search for 'media-search-enhanced'
3. Click 'Install Now'
4. Activate the plugin on the Plugin dashboard

= Uploading in WordPress Dashboard =

1. Navigate to the 'Add New' in the plugins dashboard
2. Navigate to the 'Upload' area
3. Select `media-search-enhanced.zip` from your computer
4. Click 'Install Now'
5. Activate the plugin in the Plugin dashboard

= Using FTP =

1. Download `media-search-enhanced.zip`
2. Extract the `media-search-enhanced` directory to your computer
3. Upload the `media-search-enhanced` directory to the `/wp-content/plugins/` directory
4. Activate the plugin in the Plugin dashboard

== Frequently Asked Questions ==

= How to link media to the file itself rather than the attachment page in media search results page? =

Please add the following code to the `functions.php` in your theme:

	function my_get_attachment_url( $url, $post_id ) {

		$url = wp_get_attachment_url( $post_id );

		return $url;
	}
	add_filter( 'mse_get_attachment_url', 'my_get_attachment_url', 10, 2 );

== Screenshots ==

1. Demo search on the Media Library screen.
2. Demo search on the Insert Media - Media Library screen.

== Changelog ==

= 1.0.0 =
* New: Multi-term search — use commas to search for multiple items at once in the admin media modal (e.g. `sunset.jpg, logo.png`). Limited to 10 terms. Only available in the admin media modal; frontend searches treat commas as literal characters.
* Performance: Replaced LEFT JOINs + DISTINCT with EXISTS subqueries, eliminating temporary tables and improving search speed up to 10x on large media libraries.
* Performance: Numeric searches (e.g. searching by attachment ID) now use exact integer matching instead of string comparison, enabling primary key index usage.
* Compatibility: The plugin no longer overwrites the entire WHERE clause. Conditions from WordPress core and other plugins are now preserved.
* Security: Fixed reflected XSS in the search form placeholder.
* Security: Private attachments are now only visible to users with appropriate permissions (editors/admins see all; authors see only their own).
* Developer: Added `mse_max_search_terms` filter to customize the multi-term cap (default 10). Added `mse_is_media_modal_request` filter to customize where multi-term search is allowed.

= 0.9.2 =
* Security enhancements.

= 0.9.1 =
* Fix: Prevent "Not unique table/alias: wp_postmeta" SQL error by aliasing the postmeta JOIN. Props [@mikemeinz](https://wordpress.org/support/users/mikemeinz/). See https://wordpress.org/support/topic/sql-syntax-error-26/

= 0.9.0 =
* Added the languages pt_BR and es_ES. Thanks to [@larodiel](https://github.com/1fixdotio/media-search-enhanced/pull/4).
* Fixed an issue when searching for images in the Image block, the plugin caused the HTTP 500 error. Also thanks to [@larodiel](https://github.com/1fixdotio/media-search-enhanced/pull/4).

= 0.8.1 =
* Fix PHP notices and updated the "Tested up to" field.

= 0.8.0 =
* Supporting MIME type and date filters when searching in the Media Library. Thanks to [@jedifunk](https://wordpress.org/support/topic/results-filters) for spotting this bug.

= 0.7.3 =
* Fix PHP warnings. Thanks to [@DavidOn3](https://wordpress.org/support/topic/warning-message-in-search-result-page).

= 0.7.2 =
* Bug fix: Make the search work with WPML Media - All languages.
* Filter the search form if it's on the media search results page.
* Make the images clickable in the search results. Can be disabled by setting the filter `mse_is_image_clickable` to `false`.

= 0.7.1 =
* Bug fix: Remove duplicate search results when WPML plugin is activated, THE RIGHT WAY.

= 0.7.0 =
* Remove duplicate search results when WPML plugin is activated. Props [@joseluiscruz](https://wordpress.org/support/topic/minor-conflict-with-wpml-media-plugin).

= 0.6.1 =
* Security update: use `$wpdb->prepare` to process SQL statements. Thanks to [@daxelrod](https://profiles.wordpress.org/daxelrod/) for this.

= 0.6.0 =
* Add ID to search fields.
* Modify the clauses with `posts_clauses` filter.

= 0.5.4 =
* Add filter `mse_get_attachment_url` to modify the attachment URLs in the media search results.

= 0.5.3 =
* Bug fix: Filtered excerpt should be returned, not echoed.

= 0.5.2 =
* Display thumbnails in the media search results.

= 0.5 =
* Use shortcode `[mse-search-form]` to insert a media search form in posts, which only searches for media files (through all fields).

= 0.4 =
* Search media file name.

= 0.3 =
* If there are Taxonomies for Media, search the name, slug and description fields.

= 0.2.1 =
* Add DISTINCT statement to SQL when query media in the "Insert Media" screen

= 0.2.0 =
* The first version
