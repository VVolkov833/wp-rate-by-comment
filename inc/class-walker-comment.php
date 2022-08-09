<?php
// the original is in wp-includes/class-walker-comment.php
if ( 'div' === $args['style'] ) {
    $tag       = 'div';
    $add_below = 'comment';
} else {
    $tag       = 'li';
    $add_below = 'div-comment';
}

$classes = ' ' . comment_class( empty( $args['has_children'] ) ? '' : 'parent', null, null, false );
?>

<<?php echo $tag, $classes; ?> id="comment-<?php comment_ID() ?>">

<div class="comment-author">
    <?php

    if ( $args['avatar_size'] != 0 ) {
        echo get_avatar( $comment, $args['avatar_size'] );
    }

    echo get_comment_author();

    ?>
</div>

<?php if ( $comment->comment_approved == '0' ) { ?>
    <em class="comment-awaiting-moderation">
        <?php _e( 'Your review is awaiting moderation.', 'fcpcr' ) ?>
    </em><br/>
<?php } ?>

<div class="comment-content">
    <?php comment_text() ?>
</div>

<div class="comment-more">
    <?php
    if ( self::can_reply() ) {
        comment_reply_link( array_merge( $args, [
            'add_below' => $add_below,
            'depth'     => $depth,
            'max_depth' => $args['max_depth']
        ]));
        echo ' ';
    }

    if ( self::can_edit() ) {
        edit_comment_link( __( 'Edit' ), '', ' ' );
    }
    
    echo get_comment_date();

    ?>
</div>
<?php