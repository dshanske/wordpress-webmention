<?php
/**
 * Webmention Receiver Class
 *
 */
class Comment_Permalinks {

	/**
	 * Initialize the plugin, registering WordPress hooks
	 */
	public static function init() {
		add_filter( 'query_vars', array( 'Comment_Permalinks', 'query_var' ) );
		add_filter( 'template_include', array( 'Comment_Permalinks', 'template_include' ), 99 );
		add_rewrite_endpoint( 'comment', EP_ROOT, 'comment_id' );
		add_filter( 'get_comment_link', array( 'Comment_Permalinks', 'get_comment_link' ), 99, 2 );
	}

	/**
	 * adds some query vars
	 *
	 * @param array $vars
	 * @return array
	 */
	public static function query_var( $vars ) {
		$vars[] = 'comment_id';
		return $vars;
	}

	/**
	 * replace the template for all URLs with a "comment_id" query-param
	 *
	 * @param string $template the template url
	 * @return string
	 */
	public static function template_include( $template ) {
		// replace template
		$end = 'comment-single.php';
		$comment_id = get_query_var( 'comment_id', 'none' );
		if ( 'none' === $comment_id ) {
			return $template;
		}
		if ( '' == $comment_id ) {
			$end = 'comment-archive.php';
		} else if ( ! get_comment( $comment_id ) ) {
			return get_404_template();
		}

		$new_template = locate_template( array( $template ) );
		if ( '' === $new_template ) {
			return plugin_dir_path( __FILE__ ) . '../templates/' . $end;
		} else {
			return $new_template;
		}
	}

	public static function get_comment_link( $link, $comment ) {
		global $wp_rewrite;
		// Check to see if we are using rewrite rules
		$rewrite = $wp_rewrite->wp_rewrite_rules();
		if ( empty( $rewrite ) ) {
			return add_query_arg( 'comment_id', $comment->comment_ID, home_url() );
		}
		return home_url() . '/comment/' . $comment->comment_ID;
	}



	public static function url_to_commentid( $url ) {
		global $wp_rewrite;

		// First, check to see if there is a 'comment_id=N' to match against
		if ( preg_match( '#[?&](comment_id)=(\d+)#', $url, $values ) ) {
			$id = absint( $values[2] );
			if ( $id ) {
				return $id;
			}
		}

		// Get rid of the #anchor
		$url_split = explode( '#', $url );
		$url = $url_split[0];

		// Get rid of URL ?query=string
		$url_split = explode( '?', $url );
		$url = $url_split[0];

		// Set the correct URL scheme.
		$scheme = wp_parse_url( home_url(), PHP_URL_SCHEME );
		$url = set_url_scheme( $url, $scheme );

		// Add 'www.' if it is absent and should be there
		if ( false !== strpos( home_url(), '://www.' ) && false === strpos( $url, '://www.' ) ) {
			$url = str_replace( '://', '://www.', $url );
		}

		// Strip 'www.' if it is present and shouldn't be
		if ( false === strpos( home_url(), '://www.' ) ) {
			$url = str_replace( '://www.', '://', $url );
		}

		// Check to see if we are using rewrite rules
		$rewrite = $wp_rewrite->wp_rewrite_rules();

		// Not using rewrite rules, and 'comment_id=N' methods failed, so we're out of options
		if ( empty( $rewrite ) ) {
			return 0;
		}

		$path = wp_parse_url( $url, PHP_URL_PATH );
		$path_parts = explode( '/', $path );
		if ( 'comment' == $path_parts[1] ) {
			return $path_parts[2];
		}

		return 0;
	}

}
