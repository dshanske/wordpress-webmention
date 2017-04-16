<?php
$comment_id = get_query_var('comment_id');
$comment = get_comment( $comment_id );
$target = null;

// check parent comment
if ( $comment->comment_parent ) {
	// get parent comment...
	$parent = get_comment( $comment->comment_parent );
	// ...and gernerate target url
	$target = $parent->comment_author_url;
}

get_header();
?>
	<article id="comment-<?php comment_ID(); ?>" <?php comment_class( 'p-comment h-cite' ); ?>>
			<header class="entry-meta">
				<span class="p-author h-card comment-author">
					<?php echo get_avatar( $comment, 50 ); ?>
					<?php printf( '<cite class="p-name">%s</cite>', get_comment_author_link() ); ?>
					</address><!-- .comment-author .vcard -->
					<a href="<?php echo esc_url( get_comment_link( $comment->comment_ID ) ); ?>"><time datetime="<?php comment_time( 'c' ); ?>" class="dt-published dt-updated published updated">
						<?php
						/* translators: 1: date, 2: time */
						printf( __( '%1$s at %2$s', 'webmention' ), get_comment_date(), get_comment_time() ); ?>
					</time></a>
					<ul>
					<?php if ( $target ) { ?>
						<li><a href="<?php echo $target; ?>" rel="in-reply-to" class="u-in-reply-to"><?php echo $target; ?></a></li>
					<?php } ?>
					</ul>
			</header>
			<div class="e-content p-summary p-name"><?php comment_text(); ?></div>
	</article>
<?php 
get_sidebar();
get_footer();
