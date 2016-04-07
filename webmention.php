<?php
/**
 * Plugin Name: WebMention
 * Plugin URI: https://github.com/pfefferle/wordpress-webmention
 * Description: WebMention support for WordPress posts
 * Author: pfefferle
 * Author URI: http://notizblog.org/
 * Version: 2.5.0
 * License: MIT
 * License URI: http://opensource.org/licenses/MIT
 * Text Domain: webmention
 */

/**
 * A wrapper for WebMentionPlugin::send_webmention
 *
 * @param string $source source url
 * @param string $target target url
 *
 * @return array of results including HTTP headers
 */
function send_webmention( $source, $target ) {
	return WebMentionPlugin::send_webmention( $source, $target );
}

// initialize plugin
add_action( 'init', array( 'WebMentionPlugin', 'init' ) );

if ( ! defined( 'WEBMENTION_COMMENT_APPROVE' ) ) {
	define( 'WEBMENTION_COMMENT_APPROVE', 0 );
}

if ( ! defined( 'WEBMENTION_COMMENT_TYPE' ) ) {
	define( 'WEBMENTION_COMMENT_TYPE', 'webmention' );
}

/**
 * WebMention Plugin Class
 *
 * @author Matthias Pfefferle
 */
class WebMentionPlugin {
	/**
	 * Initialize the plugin, registering WordPress hooks
	 */
	public static function init() {
		// a pseudo hook so you can run a do_action('send_webmention')
		// instead of calling WebMentionPlugin::send_webmention
		add_action( 'send_webmention', array( 'WebMentionPlugin', 'send_webmention' ), 10, 2 );

		add_filter( 'query_vars', array( 'WebMentionPlugin', 'query_var' ) );
		add_action( 'parse_query', array( 'WebMentionPlugin', 'parse_query' ) );

		// admin settings
		add_action( 'admin_init', array( 'WebMentionPlugin', 'admin_register_settings' ) );
		add_action( 'admin_comment_types_dropdown', array( 'WebMentionPlugin', 'comment_types_dropdown' ) );

		// endpoint discovery
		add_action( 'wp_head', array( 'WebMentionPlugin', 'html_header' ), 99 );
		add_action( 'send_headers', array( 'WebMentionPlugin', 'http_header' ) );
		add_filter( 'host_meta', array( 'WebMentionPlugin', 'jrd_links' ) );
		add_filter( 'webfinger_user_data', array( 'WebMentionPlugin', 'jrd_links' ) );
		add_filter( 'webfinger_post_data', array( 'WebMentionPlugin', 'jrd_links' ) );

		// run webmentions before the other pinging stuff
		add_action( 'do_pings', array( 'WebMentionPlugin', 'do_webmentions' ), 5, 1 );

		add_action( 'publish_post', array( 'WebMentionPlugin', 'publish_post_hook' ) );

		// default handlers
		add_filter( 'webmention_title', array( 'WebMentionPlugin', 'default_title_filter' ), 10, 4 );
		add_filter( 'webmention_content', array( 'WebMentionPlugin', 'default_content_filter' ), 10, 4 );
		add_filter( 'webmention_check_dupes', array( 'WebMentionPlugin', 'check_dupes' ), 10, 2 );
		add_action( 'webmention_request', array( 'WebMentionPlugin', 'synchronous_request_handler' ), 10, 3 );
	}

	/**
	 * Adds some query vars
	 *
	 * @param array $vars
	 * @return array
	 */
	public static function query_var($vars) {
		$vars[] = 'webmention';

		return $vars;
	}

	/**
	 * Parse the WebMention request and render the document
	 *
	 * @param WP $wp WordPress request context
	 *
	 * @uses do_action() Calls 'webmention_request' on the default request
	 */
	public static function parse_query( $wp ) {
		// check if it is a webmention request or not
		if ( ! array_key_exists( 'webmention', $wp->query_vars ) ) {
			return;
		}

		// plain text header
		header( 'Content-Type: text/plain; charset=' . get_option( 'blog_charset' ) );

		// check if source url is transmitted
		if ( ! isset( $_POST['source'] ) ) {
			status_header( 400 );
			echo '"source" is missing';
			exit;
		}

		// check if target url is transmitted
		if ( ! isset( $_POST['target'] ) ) {
			status_header( 400 );
			echo '"target" is missing';
			exit;
		}

		// @todo check if target-host matches the blog-host

		// remove url-scheme
		$schemeless_target = preg_replace( '/^https?:\/\//i', '', $_POST['target'] );

		// check post with http only
		$post_ID = url_to_postid( 'http://' . $schemeless_target );

		// if there is no post
		if ( ! $post_ID ) {
			// try https url
			$post_ID = url_to_postid( 'https://' . $schemeless_target );
		}

		// add some kind of a "default" id to add all
		// webmentions to a specific post/page
		$post_ID = apply_filters( 'webmention_post_id', $post_ID, $_POST['target'] );

		// check if post id exists
		if ( ! $post_ID ) {
			status_header( 404 );
			echo 'Specified target URL not found.';
			exit;
		}

		// check if pings are allowed
		if ( ! pings_open( $post_ID ) ) {
			status_header( 403 );
			echo 'Pings are disabled for this post';
			exit;
		}

		$post_ID = intval( $post_ID );
		$post = get_post( $post_ID );

		// check if post exists
		if ( ! $post ) {
			return;
		}

		// be sure to add an "exit;" to the end of your request handler
		do_action( 'webmention_request', $_POST['source'], $_POST['target'], $post );

		// if no "action" is responsible, return a 500
		status_header( 500 );
		echo 'Webmention Handler Failed.';
		exit;
	}

	/**
	 * Synchronous request handler
	 *
	 * Tries to map a target url to a specific post and generates a simple
	 * "default" comment.
	 *
	 * @param string $source the source url
	 * @param string $target the target url
	 * @param string $post the post associated with the target
	 *
	 * @uses apply_filters calls "webmention_post_id" on the post_ID
	 * @uses apply_filters calls "webmention_title" on the default comment-title
	 * @uses apply_filters calls "webmention_content" on the default comment-content
	 * @uses apply_filters calls "webmention_comment_type" on the default comment type
	 *	the default is "webmention"
	 * @uses apply_filters calls "webmention_comment_approve" to set the comment
	 *	to auto-approve (for example)
	 * @uses apply_filters calls "webmention_comment_parent" to add a parent comment-id
	 * @uses apply_filters calls "webmention_success_header" on the default response
	 *	header
	 * @uses do_action calls "webmention_post" on the comment_ID to be pingback
	 *	and trackback compatible
	 */
	public static function synchronous_request_handler( $source, $target, $post ) {

		$response = wp_remote_get( $_POST['source'], array( 'timeout' => 100 ) );

		// check if source is accessible
		if ( is_wp_error( $response ) ) {
			status_header( 400 );
			echo 'Source URL not found.';
			exit;
		}
		$remote_source = wp_remote_retrieve_body( $response );

		// check if source really links to target
		if ( ! strpos( htmlspecialchars_decode( $remote_source ), str_replace( array( 'http://www.', 'http://', 'https://www.', 'https://' ), '', untrailingslashit( preg_replace( '/#.*/', '', $_POST['target'] ) ) ) ) ) {
			status_header( 400 );
			echo 'Source Site Does Not Link to Target.';
			exit;
		}
		// if it does, get rid of all evil
		if ( ! function_exists( 'wp_kses_post' ) ) {
			include_once( ABSPATH . 'wp-includes/kses.php' );
		}
		$remote_source_original = $remote_source;
		$remote_source = wp_kses_post( $remote_source );

		// filter title or content of the comment
		$title = apply_filters( 'webmention_title', '', $remote_source, $target, $source );
		$remote_source = apply_filters( 'webmention_content', '', $remote_source, $target, $source );

		// generate comment
		$comment_post_ID = (int) $post->ID;
		$comment_author = wp_slash( $title );
		$comment_author_email = '';
		$comment_author_url = esc_url_raw( $source );
		$comment_content = wp_slash( $remote_source );

		// change this if your theme can't handle the WebMentions comment type
		$comment_type = apply_filters( 'webmention_comment_type', WEBMENTION_COMMENT_TYPE );

		// change this if you want to auto approve your WebMentions
		$comment_approved = apply_filters( 'webmention_comment_approve', WEBMENTION_COMMENT_APPROVE );

		// filter the parent id
		$comment_parent = apply_filters( 'webmention_comment_parent', null, $target );

		$commentdata = compact( 'comment_post_ID', 'comment_author', 'comment_author_url', 'comment_author_email', 'comment_content', 'comment_type', 'comment_parent', 'comment_approved', 'remote_source', 'remote_source_original' );

		// check dupes
		$comment = apply_filters( 'webmention_check_dupes', null, $commentdata );

		// disable flood control
		remove_filter( 'check_comment_flood', 'check_comment_flood_db', 10, 3 );

		// update or save webmention
		if ( $comment ) {
			$commentdata['comment_ID'] = $comment->comment_ID;
			$commentdata['comment_approved'] = $comment->comment_approved;
			// save comment
			wp_update_comment( $commentdata );
			$comment_ID = $comment->comment_ID;

	    do_action( 'webmention_update', $comment_ID, $commentdata );
		} else {
			// save comment
			$comment_ID = wp_new_comment( $commentdata );

	    do_action( 'webmention_post', $comment_ID, $commentdata );
		}

		// re-add flood control
		add_filter( 'check_comment_flood', 'check_comment_flood_db', 10, 3 );

		// set header
		status_header( apply_filters( 'webmention_success_header', 200 ) );

		// render a simple and customizable text output
		echo apply_filters( 'webmention_success_message', get_comment_link( $comment_ID ) );

		exit;
	}

	/**
	 * Try to make a nice comment
	 *
	 * @param string $context the comment-content
	 * @param string $contents the HTML of the source
	 * @param string $target the target URL
	 * @param string $source the source URL
	 *
	 * @return string the filtered content
	 */
	public static function default_content_filter( $content, $contents, $target, $source ) {
		// get post format
		$post_ID = url_to_postid( $target );
		$post_format = get_post_format( $post_ID );

		// replace "standard" with "Article"
		if ( ! $post_format || 'standard' == $post_format ) {
			$post_format = 'Article';
		} else {
			$post_formatstrings = get_post_format_strings();
			// get the "nice" name
			$post_format = $post_formatstrings[ $post_format ];
		}

		$host = parse_url( $source, PHP_URL_HOST );

		// strip leading www, if any
		$host = preg_replace( '/^www\./', '', $host );

		// generate default text
		$content = sprintf( __( 'This %s was mentioned on <a href="%s">%s</a>', 'webmention' ), $post_format, esc_url( $source ), $host );

		return $content;
	}

	/**
	 * Check if a comment already exists
	 *
	 * @param  array      $comment     the filtered comment
	 * @param  array      $commentdata the comment, created for the webmention data
	 *
	 * @return array|null              the dupe or null
	 */
	public static function check_dupes( $comment, $commentdata ) {
		global $wpdb;
		global $wp_version;
		if ( $wp_version >= 4.4 ) {
			// check if comment is already set
			$comments = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $wpdb->comments WHERE comment_post_ID = %d AND comment_author_url = %s", $commentdata['comment_post_ID'], htmlentities( $commentdata['comment_author_url'] ) ) );
		} else {
			$args = array(
						'comment_post_ID' => $commentdata['comment_post_ID'],
						'author_url' => htmlentities( $commentdata['comment_author_url'] ),
			);
			$comments = get_comments( $args );
		}
		// check result
		if ( ! empty( $comments ) ) {
			error_log( print_r( $comments, true ) . PHP_EOL, 3, dirname( __FILE__ ) . '/log.txt' );

			return $comments[0];
		}

		// check comments sent via salmon are also dupes
		// or anyone else who can't use comment_author_url as the original link,
		// but can use a _crossposting_link meta value.
		// @link https://github.com/pfefferle/wordpress-salmon/blob/master/plugin.php#L192
		if ( $wp_version >= 4.4 ) {
			$comments = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $wpdb->comments INNER JOIN $wpdb->commentmeta USING (comment_ID) WHERE comment_post_ID = %d AND meta_key = '_crossposting_link' AND meta_value = %s", $commentdata['comment_post_ID'], htmlentities( $commentdata['comment_author_url'] ) ) );
		} else {
			$args = array(
			'comment_post_ID' => $commentdata['comment_post_ID'],
			'author_url' => htmlentities( $commentdata['comment_author_url'] ),
						'meta_key' => '_crossposting_link',
						'meta_value' => $commentdata['comment_author_url'],
			);
			$comments = get_comments( $args );
		}

		// check result
		if ( ! empty( $comments ) ) {
			return $comments[0];
		}

		return null;
	}

	/**
	 * Extend the "filter by comment type" of in the comments section
	 * of the admin interface with "webmention"
	 *
	 * @param array $types the different comment types
	 *
	 * @return array the filtert comment types
	 */
	public static function comment_types_dropdown( $types ) {
		$types['webmention'] = __( 'WebMentions', 'webmention' );

		return $types;
	}

	/**
	 * Try to make a nice title (username)
	 *
	 * @param string $title the comment-title (username)
	 * @param string $contents the HTML of the source
	 * @param string $target the target URL
	 * @param string $source the source URL
	 *
	 * @return string the filtered title
	 */
	public static function default_title_filter( $title, $contents, $target, $source ) {
		$meta_tags = get_meta_tags( $source );

		// use meta-author
		if ( $meta_tags && is_array( $meta_tags ) && array_key_exists( 'author', $meta_tags ) ) {
			$title = $meta_tags['author'];
		} elseif ( preg_match( '/<title>(.+)<\/title>/i', $contents, $match ) ) { // use title
			$title = trim( $match[1] );
		} else { // or host
			$host = parse_url( $source, PHP_URL_HOST );

			// strip leading www, if any
			$title = preg_replace( '/^www\./', '', $host );
		}

		return $title;
	}

	/**
	 * Marks the post as "no webmentions sent yet"
	 *
	 * @param int $post_ID
	 */
	public static function publish_post_hook( $post_ID ) {
		// check if pingbacks are enabled
		if ( get_option( 'default_pingback_flag' ) ) {
			add_post_meta( $post_ID, '_mentionme', '1', true );
		}
	}

	/**
	 * Send WebMentions
	 *
	 * @param string $source source url
	 * @param string $target target url
	 * @param int $post_ID the post_ID (optional)
	 *
	 * @return array of results including HTTP headers
	 */
	public static function send_webmention( $source, $target, $post_ID = null ) {
		// stop selfpings on the same URL
		if ( ( get_option( 'webmention_disable_selfpings_same_url' ) === '1' ) &&
			 ( $source === $target ) ) {
			return false;
		}

		// stop selfpings on the same domain
		if ( ( get_option( 'webmention_disable_selfpings_same_domain' ) === '1' ) &&
			 ( parse_url( $source, PHP_URL_HOST ) === parse_url( $target, PHP_URL_HOST ) ) ) {
			return false;
		}

		// discover the webmention endpoint
		$webmention_server_url = self::discover_endpoint( $target );

		// if I can't find an endpoint, perhaps you can!
		$webmention_server_url = apply_filters( 'webmention_server_url', $webmention_server_url, $target );

		$args = array(
			'body' => 'source=' . urlencode( $source ) . '&target=' . urlencode( $target ),
			'timeout' => 100,
		);

		if ( $webmention_server_url ) {
			$response = wp_remote_post( $webmention_server_url, $args );

			// use the response to do something usefull
			do_action( 'webmention_post_send', $response, $source, $target, $post_ID );

			return $response;
		}

		return false;
	}

	/**
	 * Send WebMentions if new Post was saved
	 *
	 * You can still hook this function directly into the `publish_post` action:
	 *
	 * <code>
	 *	 add_action('publish_post', array('WebMentionPlugin', 'send_webmentions'));
	 * </code>
	 *
	 * @param int $post_ID the post_ID
	 */
	public static function send_webmentions($post_ID) {
		// get source url
		$source = get_permalink( $post_ID );

		// get post
		$post = get_post( $post_ID );

		// initialize links array
		$links = array();

		// Find all external links in the source
		if ( preg_match_all( '/<a[^>]+href=.(https?:\/\/[^\'\"]+)/i', $post->post_content, $matches ) ) {
			$links = $matches[1];
		}

		// filter links
		$targets = apply_filters( 'webmention_links', $links, $post_ID );
		$targets = array_unique( $targets );

		foreach ( $targets as $target ) {
			// send webmention
			$response = self::send_webmention( $source, $target, $post_ID );

			// check response
			if ( ! is_wp_error( $response ) &&
				wp_remote_retrieve_response_code( $response ) < 400 ) {
				$pung = get_pung( $post_ID );

				// if not already added to punged urls
				if ( ! in_array( $target, $pung ) ) {
					// tell the pingback function not to ping these links again
					add_ping( $post_ID, $target );
				}
			}

			// rescedule if server responds with a http error 5xx
			if ( wp_remote_retrieve_response_code( $response ) >= 500 ) {
				self::reschedule( $post_ID );
			}
		}
	}

	/**
	 * Rescedule WebMentions on HTTP code 500
	 *
	 * @param int $post_ID the post id
	 */
	public static function reschedule( $post_ID ) {
		$tries = get_post_meta( $post_ID, '_mentionme_tries', true );

		// check "tries" and set to 0 if null
		if ( ! $tries ) {
			$tries = 0;
		}

		// raise "tries" counter
		$tries = $tries + 1;

		// rescedule only three times
		if ( $tries <= 3 ) {
			// save new tries value
			update_post_meta( $post_ID, '_mentionme_tries', $tries );

			// and rescedule
			add_post_meta( $post_ID, '_mentionme', '1', true );

			wp_schedule_single_event( time() + ( $tries * 900 ), 'do_pings' );
		} else {
			delete_post_meta( $post_ID, '_mentionme_tries' );
		}
	}

	/**
	 * Do webmentions
	 */
	public static function do_webmentions() {
		global $wpdb;

		// get all posts that should be "mentioned"
		$mentions = $wpdb->get_results( "SELECT ID, meta_id FROM {$wpdb->posts}, {$wpdb->postmeta} WHERE {$wpdb->posts}.ID = {$wpdb->postmeta}.post_id AND {$wpdb->postmeta}.meta_key = '_mentionme'" );

		// iterate mentions
		foreach ( $mentions as $mention ) {
			delete_metadata_by_mid( 'post', $mention->meta_id );

			// send them webmentions
			self::send_webmentions( $mention->ID );
		}
	}

	/**
	 * Finds a WebMention server URI based on the given URL
	 *
	 * Checks the HTML for the rel="http://webmention.org/" link and http://webmention.org/ headers. It does
	 * a check for the http://webmention.org/ headers first and returns that, if available. The
	 * check for the rel="http://webmention.org/" has more overhead than just the header.
	 *
	 * @param string $url URL to ping
	 *
	 * @return bool|string False on failure, string containing URI on success
	 */
	public static function discover_endpoint( $url ) {
		/** @todo Should use Filter Extension or custom preg_match instead. */
		$parsed_url = parse_url( $url );

		if ( ! isset( $parsed_url['host'] ) ) { // Not an URL. This should never happen.
			return false;
		}

		// do not search for a WebMention server on our own uploads
		$uploads_dir = wp_upload_dir();
		if ( 0 === strpos( $url, $uploads_dir['baseurl'] ) ) {
			return false;
		}

		$response = wp_remote_head( $url, array( 'timeout' => 100, 'httpversion' => '1.0' ) );

		if ( is_wp_error( $response ) ) {
			return false;
		}

		// check link header
		if ( $links = wp_remote_retrieve_header( $response, 'link' ) ) {
			if ( is_array( $links ) ) {
				foreach ( $links as $link ) {
					if ( preg_match( '/<(.[^>]+)>;\s+rel\s?=\s?[\"\']?(http:\/\/)?webmention(.org)?\/?[\"\']?/i', $link, $result ) ) {
						return self::make_url_absolute( $url, $result[1] );
					}
				}
			} else {
				if ( preg_match( '/<(.[^>]+)>;\s+rel\s?=\s?[\"\']?(http:\/\/)?webmention(.org)?\/?[\"\']?/i', $links, $result ) ) {
					return self::make_url_absolute( $url, $result[1] );
				}
			}
		}

		// not an (x)html, sgml, or xml page, no use going further
		if ( preg_match( '#(image|audio|video|model)/#is', wp_remote_retrieve_header( $response, 'content-type' ) ) ) {
			return false;
		}

		// now do a GET since we're going to look in the html headers (and we're sure its not a binary file)
		$response = wp_remote_get( $url, array( 'timeout' => 100, 'httpversion' => '1.0' ) );

		if ( is_wp_error( $response ) ) {
			return false;
		}

		$contents = wp_remote_retrieve_body( $response );

		// boost performance and use alreade the header
		$header = substr( $contents, 0, stripos( $contents, '</head>' ) );

		// unicode to HTML entities
		$contents = mb_convert_encoding( $contents, 'HTML-ENTITIES', mb_detect_encoding( $contents ) );

		libxml_use_internal_errors( true );

		$doc = new DOMDocument();
		$doc->loadHTML( $contents );

		$xpath = new DOMXPath( $doc );

		// check <link> elements
		// checks only head-links
		foreach ( $xpath->query( '//head/link[contains(concat(" ", @rel, " "), " webmention ") or contains(@rel, "webmention.org")]/@href' ) as $result ) {
			return self::make_url_absolute( $url, $result->value );
		}

		// check <a> elements
		// checks only body>a-links
		foreach ( $xpath->query( '//body//a[contains(concat(" ", @rel, " "), " webmention ") or contains(@rel, "webmention.org")]/@href' ) as $result ) {
			return self::make_url_absolute( $url, $result->value );
		}

		return false;
	}

	/**
	 * The WebMention autodicovery meta-tags
	 */
	public static function html_header() {
		$endpoint = apply_filters( 'webmention_endpoint', site_url( '?webmention=endpoint' ) );

		// backwards compatibility with v0.1
		echo '<link rel="http://webmention.org/" href="' . $endpoint . '" />' . "\n";
		echo '<link rel="webmention" href="' . $endpoint . '" />' . "\n";
	}

	/**
	 * The WebMention autodicovery http-header
	 */
	public static function http_header() {
		$endpoint = apply_filters( 'webmention_endpoint', site_url( '?webmention=endpoint' ) );

		// backwards compatibility with v0.1
		header( 'Link: <' . $endpoint . '>; rel="http://webmention.org/"', false );
		header( 'Link: <' . $endpoint . '>; rel="webmention"', false );
	}

	/**
	 * Generates webfinger/host-meta links
	 */
	public static function jrd_links( $array ) {
		$endpoint = apply_filters( 'webmention_endpoint', site_url( '?webmention=endpoint' ) );

		$array['links'][] = array( 'rel' => 'webmention', 'href' => $endpoint );
		$array['links'][] = array( 'rel' => 'http://webmention.org/', 'href' => $endpoint );

		return $array;
	}

	/**
	 * Converts relative to absolute urls
	 *
	 * Based on the code of 99webtools.com
	 *
	 * @link http://99webtools.com/relative-path-into-absolute-url.php
	 *
	 * @param string $base the base url
	 * @param string $rel the relative url
	 *
	 * @return string the absolute url
	 */
	public static function make_url_absolute( $base, $rel ) {
		if ( 0 === strpos( $rel, '//' ) ) {
			return parse_url( $base, PHP_URL_SCHEME ) . ':' . $rel;
		}
		// return if already absolute URL
		if ( parse_url( $rel, PHP_URL_SCHEME ) != '' ) {
			return $rel;
		}
		// queries and	anchors
		if ( '#' == $rel[0]  || '?' == $rel[0] ) {
			return $base . $rel;
		}
		// parse base URL and convert to local variables:
		// $scheme, $host, $path
		extract( parse_url( $base ) );
		// remove	non-directory element from path
		$path = preg_replace( '#/[^/]*$#', '', $path );
		// destroy path if relative url points to root
		if ( '/' == $rel[0] ) {
			$path = '';
		}
		// dirty absolute URL
		$abs = "$host";
		// check port
		if ( isset( $port ) && ! empty( $port ) ) {
			$abs .= ":$port";
		}
		// add path + rel
		$abs .= "$path/$rel";
		// replace '//' or '/./' or '/foo/../' with '/'
		$re = array( '#(/\.?/)#', '#/(?!\.\.)[^/]+/\.\./#' );
		for ( $n = 1; $n > 0; $abs = preg_replace( $re, '/', $abs, -1, $n ) ) { }
		// absolute URL is ready!
		return $scheme . '://' . $abs;
	}

	/**
	 * Register WebMention admin settings.
	 */
	public static function admin_register_settings() {
		register_setting( 'discussion', 'webmention_disable_selfpings_same_url' );
		register_setting( 'discussion', 'webmention_disable_selfpings_same_domain' );

		add_settings_field( 'webmention_disucssion_settings', __( 'WebMention Settings', 'webmention' ), array( 'WebMentionPlugin', 'discussion_settings' ), 'discussion', 'default' );
	}

	/**
	 * Add WebMention options to the WordPress discussion settings page.
	 */
	public static function discussion_settings () {
?>
	<fieldset>
		<label for="webmention_disable_selfpings_same_url">
			<input type="checkbox" name="webmention_disable_selfpings_same_url" id="webmention_disable_selfpings_same_url" value="1" <?php
				echo checked( true, get_option( 'webmention_disable_selfpings_same_url' ) );  ?> />
			<?php _e( 'Disable self-pings on the same URL <small>(for example "http://example.com/?p=123")</small>', 'webmention' ) ?>
		</label>

		<br />

		<label for="webmention_disable_selfpings_same_domain">
			<input type="checkbox" name="webmention_disable_selfpings_same_domain" id="webmention_disable_selfpings_same_domain" value="1" <?php
				echo checked( true, get_option( 'webmention_disable_selfpings_same_domain' ) );  ?> />
			<?php _e( 'Disable self-pings on the same Domain <small>(for example "example.com")</small>', 'webmention' ) ?>
		</label>
	</fieldset>
<?php
	}
}

if ( ! function_exists( 'get_webmentions_number' ) ) :
	/**
	 * Return the Number of WebMentions
	 *
	 * @param int $post_id The post ID (optional)
	 *
	 * @return int the number of WebMentions for one Post
	 */
	function get_webmentions_number( $post_id = 0 ) {
		$post = get_post( $post_id );

		// change this if your theme can't handle the WebMentions comment type
		$webmention_comment_type = defined( 'WEBMENTION_COMMENT_TYPE' ) ? WEBMENTION_COMMENT_TYPE : 'webmention';
		$comment_type = apply_filters( 'webmention_comment_type', $webmention_comment_type );

		$args = array(
			'post_id' => $post->ID,
			'type'	=> $comment_type,
			'count'	 => true,
			'status'	=> 'approve',
		);

		$comments_query = new WP_Comment_Query;
		return $comments_query->query( $args );
	}
endif;
