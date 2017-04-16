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
		}
		else if ( ! get_comment( $comment_id ) ) {
			return get_404_template();
		}
	
		$new_template = locate_template( array( $template ) );
		if ( '' === $new_template ) {
			return plugin_dir_path( __FILE__ ) . '../templates/' . $end;
		}
		else {
			return $new_template;
		}
	}
}
