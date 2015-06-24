=== Media Search Enhanced ===

Contributors: 1fixdotio
Donate link: http://1fix.io/
Tags: media library, media, attachment
Requires at least: 3.5
Tested up to: 4.2.2
Stable tag: 0.6.1
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Search through all fields in Media Library.

== Description ==

This plugin is made for:

* Search through all fields in Media Library, including: ID, title, caption, alternative text and description.
* Search Taxonomies for Media, include the name, slug and description fields.
* Search media file name.
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