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

        add_filter( 'allow_empty_comment', '__return_true' ); // ++ add post type limitations to this too
        //add_filter( 'preprocess_comment', [$this, 'filter_comment'] );
        //add_action( 'comment_post', [$this, 'form_fields_save'] ); // ++ add post type limitations to this too

        add_action( 'wp', function() { // to filter post types

            global $post;
            if ( !in_array( $post->post_type, self::$types ) ) { return; }

            if ( !self::is_replying() ) { // only the review can have the rating

                // wrap the main fields to fit the rating in
                add_action( 'comment_form_top', function() {
                    echo '<div class="wp-block-column">';
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

            // not logged in layout fixes
            // remove not used fields for not logged-in-s ++ try to move to wp
            add_filter( 'comment_form_default_fields', function($fields) {
                unset( $fields['url'] );
                unset( $fields['cookies'] ); // can modify later, instead of hidding
                return $fields;
            });
            // comments form change fields order
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
                $d['comment_notes_before'] = '';
                $d['logged_in_as'] = '';
                return $d;
            });
            
            //add_filter( 'comment_text', [$this, 'comment_rating_draw'], 30 );

            add_action( 'wp_enqueue_scripts', [$this, 'styles_scripts_add'] );
            add_action( 'wp_footer', [$this, 'styles_dynamic_print'] );

        });

        // stop storing user data ++ try to move to wp
        add_filter( 'pre_comment_user_ip', function($a) { return ''; } );
        add_filter( 'pre_comment_user_agent', function($a) { return ''; } );
        add_filter( 'pre_comment_author_url', function($a) { return ''; } );
        
        // filter the comments actions
        //add_action( 'bulk_actions-edit-comments', [$this, 'filter_comments_actions'] ); // ++ ^
        //add_action( 'comment_row_actions', [$this, 'filter_comments_actions'] ); // ++ ^

    }
    
    public function form_fields_layout($a) {
        ?>
        <div class="<?php echo self::$pr ?>form wp-block-column" style="flex-basis:33.33%;order:2">
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
        
        /* ++for faster reply, but modify to not leave the stars
        if ( is_singular() && get_option( 'thread_comments' ) ) {
            wp_enqueue_script( 'comment-reply' );
        }
        */
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
            .cr_form {
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
    
    public function filter_comments_actions($actions) {
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
            'per_nomination_sum' => [],
            'not_zero_stars_amo' => [],
            'per_nomination_avg' => [], // total / filled
            'cast_weights' => [],
            'per_nomination_wtd' => [], // avg * weight
            'not_zero_ratings_amo' => 0,
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

        $a['total_avg'] = array_sum( $a['per_nomination_avg'] ) / $a['not_zero_ratings_amo'];
        $a['total_wtd'] = array_sum( $a['per_nomination_wtd'] ) / $a['not_zero_ratings_amo'];

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
        if ( isset( $_GET['replytocom'] ) && $_GET['replytocom'] != '0' ) { // && is_singular()
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
        global $comment;
        if (
            !$comment->comment_parent && // only 2 lvls
            current_user_can( 'edit_post' )
        ) {
            return true;
        }
        return false;
    }
    public static function can_edit() {
        global $comment;
        if (
            (
                //current_user_can( 'edit_comment', $comment->comment_ID ) &&
                    // edit_comment_link() already has this filter
                $comment->user_id == get_current_user_id()
            ) ||
            current_user_can( 'administrator' )
        ) {
            return true;
        }
        return false;
    }

}

new FCP_Comment_Rate();