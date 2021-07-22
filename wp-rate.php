<?php
/*
Plugin Name: Comment and Rate
Description: Adds custom rating fields to a comment
Version: 1.0.0
*/

defined( 'ABSPATH' ) || exit;

class FCP_Comment_Rate {

	public static $dev = true, // developers mode, avoid caching js & css
                  $pr = 'cr_', // prefix (db, css)
                  $types = ['clinic'], // post types to support
                  $ratings = ['Kompetenz', 'Freundlichkeit', 'Warte zeit für Termin', 'Räumlichkeit'], // nominations
                  //$weights = [8, 3.2, 2.4, 2], // any size, but proportionally correct in relation to each other
                  $stars = 5,
                  $star_proportions = 2 / 3; // star width (square) / image width
	
	private function plugin_setup() {

		$this->self_url  = plugins_url( '/', __FILE__ );
		$this->self_path = plugin_dir_path( __FILE__ );

		$this->css_ver = '1.0.0' . ( self::$dev ? '.'.time() : '' );
		$this->js_ver = '0.0.1' . ( self::$dev ? '.'.time() : '' );
	}

    public function __construct() {

        $this->plugin_setup();

        //add_filter( 'allow_empty_comment', '__return_true' ); // ++ add post type limitations to this too ++ custom
        //add_filter( 'preprocess_comment', [$this, 'filter_comment'] );
        //add_action( 'comment_post', [$this, 'form_fields_save'] ); // ++ add post type limitations to this too

        add_action( 'wp', function() { // to filter post types

            global $post;
            if ( !in_array( $post->post_type, self::$types ) ) { return; }

            // only the review can have the rating
            if ( !self::is_replying() ) {

                // wrap the main fields to fit the rating in
                add_action( 'comment_form_top', function() {
                    echo '<div class="' . self::$pr . 'init-fields wp-block-column">';
                });
                add_action( 'comment_form', function() {
                    echo '</div>';
                });
                add_filter( 'comment_form_defaults', function($d) {
                    $d['class_form'] .= ' wp-block-columns';
                    return $d;
                });

                // draw the custom fields
                add_action( 'comment_form', [$this, 'form_fields_layout'] );

            }
            
            // reply form right after the comment
            // wp_enqueue_script( 'comment-reply' ); // ++ do a custom variant, as this just moves the form with stars

            $this->form_view_fixes();

            // modify the comments printing function wp_list_comments()
            add_filter( 'wp_list_comments_args', function($a) {
                $new_args = [
                    'avatar_size' => 80,
                    'max_depth' => 2,
                    'style'       => 'div',
                    'callback' => [$this, 'comment_print'],
                    'short_ping'  => true,
                    'reply_text'  => 'Reply to this review',
                    'reverse_top_level' => true,
                    'reverse_children' => true
                ];
                $a = array_merge( $a, $new_args );
                return $a;
            });

            // hide the form on conditions
            add_filter( 'comments_open', function ( $open, $post_id ) {
                // only the page author & admin can reply the reviews
                // ++add the $_POST filter!!!
                if ( !FCP_Comment_Rate::is_replying() || FCP_Comment_Rate::can_post_a_reply() ) {
                    return true;
                }
                return false;
            }, 10, 2 );

            //add_filter( 'comment_text', [$this, 'comment_rating_draw'], 30 );

            // styling the stars
            add_action( 'wp_enqueue_scripts', [$this, 'styles_scripts_add'] );
            add_action( 'wp_footer', [$this, 'styles_dynamic_print'] );

        });

        // stop storing user data ++ try to move to wp ++ can remove it manually on save?
        add_filter( 'pre_comment_user_ip', function($a) { return ''; } );
        add_filter( 'pre_comment_user_agent', function($a) { return ''; } );
        add_filter( 'pre_comment_author_url', function($a) { return ''; } );
        
        // limit the permissions ++move the following to costom post type limitation
        // hide the links in admin
        add_action( 'bulk_actions-edit-comments', [$this, 'filter_comments_actions_view'] );
        add_action( 'comment_row_actions', [$this, 'filter_comments_actions_view'] );
        
        // check permissions on comments' actions
        add_action( 'admin_init', [$this, 'filter_comments_actions'] );
    }

    public function comment_print( $comment, $args, $depth ) {
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
                <?php _e( 'Your comment and rating are awaiting moderation.' ) ?>
            </em><br/>
        <?php } ?>

        <div class="comment-content">
            <?php comment_text() ?>
        </div>

        <div class="comment-more">
            <?php
            if ( FCP_Comment_Rate::can_reply() ) {
                comment_reply_link(
                    array_merge(
                        $args,
                        [
                            'add_below' => $add_below,
                            'depth'     => $depth,
                            'max_depth' => $args['max_depth']
                        ]
                    )
                );
            }

            if ( FCP_Comment_Rate::can_edit() ) {
                edit_comment_link( __( 'Edit' ), '  ', '' );
            }
            
            echo get_comment_date();
            //echo get_comment_time();

            ?>
        </div>
        <?php
    }

    public function form_view_fixes() {

        // not logged in layout fixes
        // remove not used fields for not logged-in-s
        add_filter( 'comment_form_default_fields', function($fields) {
            unset( $fields['url'] );
            unset( $fields['cookies'] );
            return $fields;
        });

        // the form fields order change
        add_filter( 'comment_form_fields', function($fields){

            $new_order = ['author', 'email', 'comment'];
            $new_fields = [];

            foreach ( $new_order as $k ) {
                $new_fields[ $k ] = $fields[ $k ];
                unset( $fields[ $k ] );
            }

            if ( $fields ) {
                foreach( $fields as $k => $v ) {
                    $new_fields[ $k ] = $v;
                }
            }

            return $new_fields;
        });
        
        // customize the form defaults
        add_filter( 'comment_form_defaults', function($d) {
            // modify the form printing function comment_form()
            $d['comment_notes_before'] = '';
            $d['logged_in_as'] = '';

            $d['title_reply'] = FCP_Comment_Rate::is_replying() ? __( 'Leave a reply to' ) : __( 'Leave a Review' );

            $d['fields']['author'] = '
                <p class="comment-form-author">
                    <input id="author" name="author" type="text" value="" size="30" placeholder="' . __( 'Name' ) . '*" required="required" maxlength="245" />
                </p>
            ';

            $d['fields']['email'] = '
                <p class="comment-form-email">
                    <label>
                        <input id="email" name="email" type="text" value="" size="30" aria-describedby="email-notes" placeholder="' . __( 'Email' ) . '*" required="required" maxlength="100" />
                    </label>
                </p>
            ';

            $d['comment_field'] = '
                <p class="comment-form-comment">
                    <label>
                        <textarea id="comment" name="comment" cols="45" rows="8" placeholder="' .
                        ( FCP_Comment_Rate::is_replying() ? __( 'Your Reply' ) : __( 'Your Review' ) ) .
                        '*" maxlength="65525"></textarea>
                    </label>
                </p>
            ';

            $d['submit_button'] = '
                <input name="submit" type="submit" id="submit" class="submit" value="' .
                ( FCP_Comment_Rate::is_replying() ? __( 'Reply' ) : __( 'Post Review' ) ) .
                '">
            ';

            return $d;
        });
    }

    public function form_fields_layout($a) {
        ?>
        <div class="<?php echo self::$pr ?>fields wp-block-column" style="flex-basis:33.33%">
        <h3 class="with-line"><?php _e( 'Rate' ) ?></h3>
        <?php
            foreach ( self::$ratings as $v ) {
                $slug = self::slug( $v );
        ?>
            <fieldset class="<?php echo self::$pr ?>wrap">
                <legend><?php echo $v ?></legend>
                <div class="<?php echo self::$pr ?>radio_bar">
                <?php for ( $i = self::$stars; $i > 0; $i-- ) { ?>
                    <input type="radio"
                        id="<?php echo $slug . $i ?>"
                        name="<?php echo $slug ?>"
                        value="<?php echo $i ?>"
                    />
                    <label for="<?php echo $slug . $i ?>" title="<?php echo $i ?>"></label>
                <?php } ?>
                </div>
            </fieldset>
        <?php } ?>
        </div>
        <?php
    }
    
    private function comment_stars_wrap_layout($title, $stars) {
        ob_start();
        
        ?>
        <div class="<?php echo self::$pr ?>wrap">
            <div class="<?php echo self::$pr ?>headline"><?php echo $title ?></div>
            <?php self::stars_layout( $stars ) ?>
        </div>
        <?php
        
        $content = ob_get_contents();
        ob_end_clean();
        return $content;
    }
    
    public function comment_rating_draw($content) {
        $result = '';
    
        foreach ( self::$ratings as $v ) {
            $slug = self::slug( $v );

            if ( $stars = get_comment_meta( get_comment_ID(), $slug, true ) ) {
                $result .= $this->comment_stars_wrap_layout( $v, $stars );
            }
        }
        
        if ( $result ) {
            $result = '<div class="'.self::$pr.'comment">'.$result.'</div>';
        }

        return $result . $content;
    }

    public function form_fields_save($comment_id) {

        if ( self::is_replying() ) {
            return;
        }

        foreach ( self::$ratings as $v ) {
            $slug = self::slug( $v );

            if ( ( !isset( $_POST[ $slug ] ) ) ) {
                //delete_comment_meta( $comment_id, $slug );
                continue;
            }

            add_comment_meta( $comment_id, $slug, (int) $_POST[ $slug ] );
        }
    }

    public function styles_scripts_add() {
        wp_enqueue_style( self::$pr . 'stars', $this->self_url . 'style.css', [], $this->css_ver );
    }

    public function styles_dynamic_print() {
        
        $width = round( 100 / ( self::$stars - 1 + self::$star_proportions ), 5 );
        $height = round( $width * self::$star_proportions, 5 );
        $wh_radio = round( 100 / self::$stars, 5 );
        // ++ can't really get the accurate width by height, so should count background-width for .cr_rating_bar > div
        ?>
        <style>
            .cr_rating_bar {
                --<?php echo self::$pr ?>bar-height:<?php echo $height ?>%;
            }
            .cr_fields {
                --<?php echo self::$pr ?>star-size:<?php echo $wh_radio ?>%;
            }
        </style>
        <?php
    }

    public function filter_comment($commentdata) {

        if ( !self::can_reply() ) {
            //unset( $commentdata['comment_post_ID'] );
            //return [];
        }

        return $commentdata;

    }
    
    public function filter_comments_actions_view($actions) {

        if ( !self::can_reply() ) {
            unset(
                $actions['reply']
            );
        }
        
        if ( !self::can_edit() ) {
            unset(
                $actions['edit'],
                $actions['quickedit'],
                $actions['trash'],
                $actions['delete'],
                $actions['spam'],
                $actions['approve'],
                $actions['unapprove']
            );
        }

        return $actions;
    }

    public function filter_comments_actions() { // ++ajax actions are still intact :(
        if ( !isset( $_GET['action'] ) && !isset( $_POST['action'] ) ) { return; }

        if ( !self::can_reply() ) {
        
            $forbidden_post_actions = [
                'replyto-comment'
            ];

            if ( isset( $_POST['action'] ) && in_array( $_POST['action'], $forbidden_post_actions ) ) {
                die( 'Access denied' );
            }
        }
        
        if ( !self::can_edit() ) {

            $forbidden_get_actions = [
                'approvecomment',
                'unapprovecomment',
                'editcomment',
                'spamcomment',
                'trashcomment'
            ];
            
            $forbidden_post_actions = [
                'edit-comment', // quic edit
                'editedcomment' // the edit interface
            ];

            if ( isset( $_GET['action'] ) && in_array( $_GET['action'], $forbidden_get_actions ) ) {
                die( 'Access denied' );
            }

            if ( isset( $_POST['action'] ) && in_array( $_POST['action'], $forbidden_post_actions ) ) {
                die( 'Access denied' );
            }
        }
    }

//-----__--___-__--_______STATICS to print in templates -------___--_______-

    public static function nominations_layout($ratings) {
        foreach ( self::$ratings as $v ) {
            $slug = self::slug( $v );
            if ( !$ratings[ $slug ] ) {
                continue;
            }
            ?>
            <div class="<?php echo self::$pr ?>nomination">
                <div class="<?php echo self::$pr ?>wrap">
                    <div class="<?php echo self::$pr ?>headline"><?php echo $v ?></div>
                    <?php self::stars_layout( $ratings[ $slug ] ) ?>
                </div>
            </div>
            <?php
        }
    }

    public static function stars_layout($stars = 0) {

        if ( !$stars) { return; }
    
        $stars = $stars > self::$stars ? self::$stars : $stars;
        $width = round( $stars / self::$stars * 100, 5 );
        
        ?>
        <div class="<?php echo self::$pr ?>rating_bar">
            <div style="width:<?php echo $width ?>%"></div>
        </div>
        <?php
    }

    public static function ratings_count($id = 0) {

        if ( !$id ) $id = get_the_ID();
    
        $comments = get_approved_comments( $id );

        if ( !$comments ) { return; }
        
        $a = [
            'per_nomination_sum' => [], // total per nomination
            'not_zero_stars_amo' => [], // number of rated comments by nomination with stars > 0 (aka filled)
            'per_nomination_avg' => [], // total / filled
            'cast_weights' => [],
            'per_nomination_wtd' => [], // avg * casted weight
            'not_zero_ratings_amo' => 0, // <= count( self::$ratings )
            'total_avg' => 0,
            'total_wtd' => 0,
        ];

        // fetch ratings
        foreach ( self::$ratings as $v ) {
            $slug = self::slug( $v );

            $a['per_nomination_sum'][ $slug ] = 0;
            $a['not_zero_stars_amo'][ $slug ] = 0;

            foreach ( $comments as $comment ) {       
                $stars = get_comment_meta( $comment->comment_ID, $slug, true );

                if ( !$stars ) { continue; }

                $a['per_nomination_sum'][ $slug ] += (int) $stars > self::$stars ? self::$stars : (int) $stars;
                $a['not_zero_stars_amo'][ $slug ]++;
            }
        }

        // counting avgs weighted and totals
        foreach ( self::$ratings as $k => $v ) {
            $slug = self::slug( $v );

            $a['per_nomination_avg'][ $slug ] = $a['not_zero_stars_amo'][ $slug ] ?
                $a['per_nomination_sum'][ $slug ] / $a['not_zero_stars_amo'][ $slug ] :
                0;
                
            // cast weights
            $a['per_nomination_wtd'][ $slug ] = $a['per_nomination_avg'][ $slug ];

            if ( isset( self::$weights ) && count( self::$weights ) === count( self::$ratings ) ) {

                $a['cast_weights'][ $k ] = 
                    self::$weights[ $k ] * count( self::$ratings ) / array_sum( self::$weights );
                    
                $a['per_nomination_wtd'][ $slug ] =
                    $a['per_nomination_avg'][ $slug ] * $a['cast_weights'][ $k ];
            }
            
            if ( $a['not_zero_stars_amo'][ $slug ] ) {
                $a['not_zero_ratings_amo']++;
            }

        }

        $a['total_avg'] = $a['not_zero_ratings_amo'] ?
            array_sum( $a['per_nomination_avg'] ) / $a['not_zero_ratings_amo'] :
            0;
        $a['total_wtd'] = $a['not_zero_ratings_amo'] ?
            array_sum( $a['per_nomination_wtd'] ) / $a['not_zero_ratings_amo'] :
            0;

        $result = $a['per_nomination_wtd'];
        $result['__total'] = $a['total_wtd'];

        foreach( $result as &$v ) {
            $v = round( $v, 5 );
        }

        return $result;
    }

    private static function slug($name) {
        static $keep = [];
        
        if ( $keep[ $name ] ) {
            return $keep[ $name ];
        }
        
        $keep[ $name ] = self::$pr . sanitize_title( $name );
    
        return $keep[ $name ];
    }

    public static function is_replying() {
        if ( isset( $_GET['replytocom'] ) && $_GET['replytocom'] != '0' ) { // ++ && is_singular()?
            return true;
        }
        if ( isset( $_POST['comment_parent'] ) && $_POST['comment_parent'] != '0' ) {
            return true;
        }
        return false;
    }
    public static function can_post_a_reply() { // the post author or an admin
        if (
            self::is_replying() &&
            current_user_can( 'edit_post' )
            // ++ && the comment belongs to current page?
        ) {
            return true;
        }
        return false;
    }
    public static function can_reply() {
        if ( isset( $_POST['action'] ) && isset( $_POST['comment_ID'] ) ) { // filter the admin post actions
            $comment = get_comment( $_POST['comment_ID'] );
            
        } else { // filter the front-end inside the comments loop
            global $comment;
        }
        
        if (
            !$comment->comment_parent && // only 2 lvls
            current_user_can( 'edit_post', $comment->comment_post_ID )
        ) {
            return true;
        }

        return false;
    }
    public static function can_edit() {
        if ( isset( $_GET['action'] ) && isset( $_GET['c'] ) ) { // filter the admin screens & get actions
            $comment = get_comment( $_GET['c'] );
            
        } elseif ( isset( $_POST['action'] ) && isset( $_POST['comment_ID'] ) ) { // filter the admin post actions
            $comment = get_comment( $_POST['comment_ID'] );
            
        } else { // filter the front-end inside the comments loop
            global $comment;
        }

        if (
            (
                current_user_can( 'edit_comment', $comment->comment_ID ) &&
                $comment->user_id == get_current_user_id()
            ) ||
            current_user_can( 'administrator' )
        ) {
            return true;
        }
        return false;
    }
    
    public static function print_rating_summary() {
        $ratings = FCP_Comment_Rate::ratings_count();

        ?>
        <div class="comment-rating-headline with-line">
            Reviews (<?php echo get_comments_number() ?>)
        </div>
        <div class="comment-rating-total">
        <?php FCP_Comment_Rate::stars_layout( $ratings['__total'] ) ?>
        <?php echo $ratings['__total'] ? round( $ratings['__total'], 1 ) : '' ?>
        </div>
        <?php FCP_Comment_Rate::nominations_layout( $ratings ) ?>
        <?php
    }

}

new FCP_Comment_Rate();
