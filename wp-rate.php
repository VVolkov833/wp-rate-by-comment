<?php
/*
Plugin Name: Comment and Rate
Description: Adds custom rating fields to a particular post type's comments
Version: 0.1.1
Requires at least: 4.7
Requires PHP: 5.2.4
Author: Firmcatalyst, Vadim Volkov
Text Domain: fcpcr
Domain Path: /languages
*/

defined( 'ABSPATH' ) || exit;

class FCP_Comment_Rate {

    private static  $dev = true, // developers mode, avoid caching js & css
                    $pr = 'cr_', // prefix (db, css)
                    $types = ['clinic', 'doctor'], // post types
                    $schema = true, // support the schema
                    $competencies = ['Expertise', 'Kindness', 'Waiting time for an appointment', 'Facilities'],
                    //$weights = [8, 3.2, 2.4, 2], // $competencies' weights
                    $stars = 5, // max amount of stars
                    $proportions = 2 / 3; // star image h / w

    public static function ver() {
        static $ver;
        if ( $ver ) { return $ver; }
        $ver = get_file_data( __FILE__, [ 'ver' => 'Version' ] )[ 'ver' ] . ( self::$dev ? time() : '' );
        return $ver;
    }

    public function __construct() {

        // add translation languages
        add_action( 'plugins_loaded', function() {
            load_plugin_textdomain( 'fcpcr', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
        });


        // ************* printing conditions
        add_action( 'wp', function() {
        
            if ( is_admin() ) { return; }
            global $post;
            if ( !in_array( $post->post_type, self::$types ) ) { return; }


            // forms modifying
            if ( comments_open() ) { // just to not run those in common circumstances - the printing is filtered anyways
                $this->form_layout_change();
                if ( !self::is_replying() ) {
                    $this->draw_main_fields_wrapper();
                    add_action( 'comment_form', [$this, 'draw_rating_fields'] );
                }
            }

            // forms printing conditions
            add_filter( 'comments_open', function ( $open, $post_id ) { // notice - only printing is effected
                if ( !$open ) { return false; }
                //if ( !in_array( get_post( $post_id )->post_type, self::$types ) ) { return false; } //^
                if ( self::is_replying() && !self::can_post_a_reply() ) { return false; }
                return true;
            }, 10, 2 );

            // comments printing modify & default settings for wp_list_comments()
            add_filter( 'wp_list_comments_args', function($a) {
                $new_args = [
                    'avatar_size' => 80,
                    'max_depth' => 2,
                    'callback' => [$this, 'comment_print'], // the reply & edit links are printed here too
                    //'short_ping' => true, // the simplest format?
                    'reply_text' => __( 'Reply to this review', 'fcpcr' ),
                    'reverse_top_level' => true,
                    'reverse_children' => true
                ];
                return array_merge( $a, $new_args );
            });


            // add the rating values to the comment text
            add_filter( 'comment_text', [$this, 'comment_rating_draw'] );

            // load styles
            if ( comments_open() ) {
                add_action( 'wp_enqueue_scripts', [$this, 'style_form'] );
            }
            if ( get_comments_number() ) {
                add_action( 'wp_enqueue_scripts', [$this, 'style_comments'] );
            }
            add_action( 'wp_head', [$this, 'style_sizes'] );

        });


        // ************* wp-admin printing conditions

        // hide the links in admin
        add_action( 'bulk_actions-edit-comments', [$this, 'hide_comments_actions_links'] );
        add_action( 'comment_row_actions', [$this, 'hide_comments_actions_links'] );

        // filter displaying the editing screen
        add_action( 'current_screen', [$this, 'hide_comments_actions_screens'] );


        // ************* operations with comments

        // saving
        //add_filter( 'preprocess_comment', [$this, 'filter_comment'] ); // add if filter for the content is needed
        //add_filter( 'comment_save_pre', ' // same, but for the content exactly
        add_action( 'comment_post', [$this, 'form_fields_save'] ); // ++ add post type limitations to this too

        // recount the total rating of a post on the following actions
        add_action( 'trashed_comment', [$this, 'reset_total_score'] );
        add_action( 'untrashed_comment', [$this, 'reset_total_score'] );
        add_action( 'spammed_comment', [$this, 'reset_total_score'] );
        add_action( 'unspammed_comment', [$this, 'reset_total_score'] );

        add_action( 'comment_unapproved_to_approved', [$this, 'reset_total_score'] );
        add_action( 'comment_approved_to_unapproved', [$this, 'reset_total_score'] );
        add_action( 'comment_approved_to_delete', [$this, 'reset_total_score'] );
        add_action( 'comment_delete_to_approved', [$this, 'reset_total_score'] );

        add_action( 'comment_post', [$this, 'reset_total_score'] ); // added for logged-in-s

    }


    public function reset_total_score($comment_id) {

        $comment = get_comment( $comment_id ); 
        $post_id = $comment->comment_post_ID;

        update_post_meta( $post_id, 'cr_total_wtd', self::ratings_count( $post_id )['__total'] );

    }


    public function form_fields_save($comment_id) {

         // stars are only for the reviews (1st lvl comments)
        if ( self::is_replying() ) { return; }

        foreach ( self::$competencies as $v ) {
            $slug = self::slug( $v );
            if ( ( !isset( $_POST[ $slug ] ) ) ) { continue; }
            add_comment_meta( $comment_id, $slug, (int) $_POST[ $slug ] );
        }
    }

    public function hide_comments_actions_screens__() {
        if ( !isset( $_GET['action'] ) ) { return; }
        if ( self::can_edit() ) { return; }
        $forbid = [ 'approvecomment', 'unapprovecomment', 'editcomment', 'spamcomment', 'trashcomment' ];
        if ( isset( $_GET['action'] ) && in_array( $_GET['action'], $forbid ) ) {
            self::access_denied();
        }
    }

    public function hide_comments_actions_screens_() { // ++ajax actions are still intact :( ++ try using transition_comment_status hook maybe or the hooks, which are used for function reset_total_score()!!
    // ++try using 
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

    public static function nominations_layout($competencies) {
        foreach ( self::$competencies as $v ) {
            $slug = self::slug( $v );
            if ( !$competencies[ $slug ] ) {
                continue;
            }
            ?>
            <div class="<?php echo self::$pr ?>nomination">
                <div class="<?php echo self::$pr ?>wrap">
                    <div class="<?php echo self::$pr ?>headline"><?php _e( $v, 'fcpcr' ) ?></div>
                    <?php self::stars_layout( $competencies[ $slug ] ) ?>
                </div>
            </div>
            <?php
        }
    }

    public static function stars_layout($stars = 0) {

        //if ( !$stars) { return; }
    
        $stars = $stars > self::$stars ? self::$stars : $stars;
        $width = round( $stars / self::$stars * 100, 5 );
        
        ?>
        <div class="<?php echo self::$pr ?>stars_bar">
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
            'not_zero_ratings_amo' => 0, // <= count( self::$competencies )
            'total_avg' => 0,
            'total_wtd' => 0,
        ];

        // fetch ratings
        foreach ( self::$competencies as $v ) {
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
        foreach ( self::$competencies as $k => $v ) {
            $slug = self::slug( $v );

            $a['per_nomination_avg'][ $slug ] = $a['not_zero_stars_amo'][ $slug ] ?
                $a['per_nomination_sum'][ $slug ] / $a['not_zero_stars_amo'][ $slug ] :
                0;
                
            // cast weights
            $a['per_nomination_wtd'][ $slug ] = $a['per_nomination_avg'][ $slug ];

            if ( isset( self::$weights ) && count( self::$weights ) === count( self::$competencies ) ) {

                $a['cast_weights'][ $k ] = 
                    self::$weights[ $k ] * count( self::$competencies ) / array_sum( self::$weights );
                    
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
        $result['__count'] = max( array_values( $a['not_zero_stars_amo'] ) );

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

    public static function access_denied() {
        wp_die( 'Access denied', 'Access denied', [ 'response' => 403, 'back_link' => true ] );    
    }

    public static function is_replying() { // posting a not first lvl comment, get && post
        if ( isset( $_GET['replytocom'] ) && $_GET['replytocom'] != '0' ) { // ++ && is_singular('clinic')?
            return true;
        }
        if ( isset( $_POST['comment_parent'] ) && $_POST['comment_parent'] != '0' ) {
            return true;
        }
        return false;
    }
    public static function can_post_a_reply() { // get && post
        if (
            //self::is_replying() &&
            current_user_can( 'edit_post' )
            // ++ && the comment belongs to current page?? ++ test if need to add
        ) {
            return true;
        }
        return false;
    }
    public static function can_reply() {

        $comment = self::comment_filter();
        if ( $comment === false ) { return false; }
        
        if (
            !$comment->comment_parent && // only 2 lvls
            current_user_can( 'edit_post', $comment->comment_post_ID ) // ++might not be needed here
        ) {
            return true;
        }

        return false;
    }
    public static function can_edit() {

        $comment = self::comment_filter();
        if ( $comment === false ) { return false; }

        if (
            (
                current_user_can( 'edit_comment', $comment->comment_ID ) && // ++might not be needed here
                $comment->user_id == get_current_user_id()
            ) ||
            current_user_can( 'administrator' )
        ) {
            return true;
        }
        return false;
    }

    // check if the comment fits the post type
    public static function comment_filter($comment = '') {

        if ( $comment && is_numeric( $comment ) ) {
            $comment = get_comment( $comment );
            if ( !$comment || !is_object( $comment ) ) { return false; }
        }

        if ( !$comment || !is_object( $comment ) ) {
            
            if ( isset( $_GET['action'] ) && isset( $_GET['c'] ) ) { // filter the admin screens & get actions
                $comment = get_comment( $_GET['c'] );
            } elseif ( isset( $_POST['action'] ) && isset( $_POST['comment_ID'] ) ) { // filter the admin post actions
                $comment = get_comment( $_POST['comment_ID'] );
            } else { // filter the front-end inside the comments loop
                global $comment; // ++can just pass empty get_comment() to retrieve the global value - test it
            }
        }

        if ( !$comment || !is_object( $comment ) ) { return false; }
        if ( !isset( $comment->comment_post_ID ) ) { return false; }
        
        $post_type = get_post_type( $comment->comment_post_ID );
        if ( !in_array( $post_type, self::$types ) ) { return false; }
        return $comment;
    }

    public static function print_rating_summary() {
        $competencies = self::ratings_count();
        ?>
        <div class="<?php echo self::$pr ?>summary-headline">
            <?php _e( 'Reviews', 'fcpcr' ) ?> (<?php echo get_comments_number() ?>)
        </div>
        <div class="<?php echo self::$pr ?>summary-total">
        <?php self::stars_layout( $competencies['__total'] ) ?>
        <?php echo $competencies['__total'] ? number_format( round( $competencies['__total'], 1 ), 1, ',', '' ) : '' ?>
        </div>
        <?php self::nominations_layout( $competencies ) ?>
        <?php
        self::style_summary();
    }

    public static function print_rating_summary_short() {

        $competencies = self::ratings_count();
        if ( !$competencies ) { return; }

        if ( $competencies['__total'] && self::$schema ) {
            self::print_rating_summary_short_schema($competencies);
            return;
        }

        self::print_rating_summary_short_schema($competencies);
    }
    
    private static function print_rating_summary_short_noschema($competencies) {
        ?>
        <div class="<?php echo self::$pr ?>summary-total">
            <?php self::stars_layout( $competencies['__total'] ) ?>
            <?php
            echo $competencies['__total'] ?
            '<span>'.number_format( round( $competencies['__total'], 1 ), 1, ',', '' ).'</span>' :
            '<span>'.__( 'Not rated yet', 'fcpcr' ).'</span>';
            ?>
        </div>
        <?php
    }
    
    private static function print_rating_summary_short_schema($competencies) {
        ?>
        <div class="<?php echo self::$pr ?>summary-total" itemprop="aggregateRating" itemscope itemtype="https://schema.org/AggregateRating">
            <?php self::stars_layout( $competencies['__total'] ) ?>
            <?php
            echo $competencies['__total'] ?
            '<span itemprop="ratingValue">'.number_format( round( $competencies['__total'], 1 ), 1, ',', '' ).'</span>' :
            '<span>'.__( 'Not rated yet', 'fcpcr' ).'</span>';
            ?>
            <meta itemprop="ratingCount" content="<?php echo $competencies['__count'] ?>">
        </div>
        <?php
    }
    
    public static function print_stars_total() {
        $total = self::ratings_count()['__total'];
        if ( !$total ) { return; }
        self::stars_layout( $total ); // ++just add option to hide if zero
    }


    // ************* wp-admin printing limitations

    public function hide_comments_actions_links($actions) {

        if ( self::comment_filter() === false ) { return $actions; }

        $remove = []; // actions to remove
        if ( !self::can_reply() ) { $remove[] = 'reply'; }
        if ( !self::can_edit() ) {
            $remove = array_merge( $remove, ['edit', 'quickedit', 'trash', 'delete', 'spam', 'approve', 'unapprove'] );
        }

        foreach ( $remove as $v ) { unset( $actions[ $v ] ); }
        return $actions;
    }

    public function hide_comments_actions_screens() { // only the edit screen there is, actually
        if ( get_current_screen()->id !== 'comment' ) { return; }
        if ( self::can_edit() ) { return; }
        self::access_denied();
    }


    // ************* comment print modifying

    // the comments printing custom layout
    // apply the rephrasing & change the elements order and structure
    public function comment_print( $comment, $args, $depth ) {
        // the original is in wp-includes/class-walker-comment.php
        include __DIR__ . '/inc/class-walker-comment.php';
    }
    
    public function comment_rating_draw($content) {
        $result = '';

        $comment_stars_wrap_layout = function($title, $stars) {
            ob_start();
            
            ?>
            <div class="<?php echo self::$pr ?>wrap">
                <div class="<?php echo self::$pr ?>headline"><?php _e( $title, 'fcpcr' ) ?></div>
                <?php self::stars_layout( $stars ) ?>
            </div>
            <?php
            
            $content = ob_get_contents();
            ob_end_clean();
            return $content;
        };

        foreach ( self::$competencies as $v ) {
            $slug = self::slug( $v );

            if ( $stars = get_comment_meta( get_comment_ID(), $slug, true ) ) {
                $result .= $comment_stars_wrap_layout( $v, $stars );
            }
        }
        
        if ( $result ) {
            $result = '<div class="'.self::$pr.'comment">'.$result.'</div>';
        }

        return $result . $content;
    }


    // ************* forms modifying

    private function draw_main_fields_wrapper() {
        // wrap the main fields to fit the rating in
        add_action( 'comment_form_top', function() { // before
            echo '<div class="' . self::$pr . 'main-fields wp-block-column">';
        });
        add_action( 'comment_form', function() { // after
            echo '</div>';
        });
        add_filter( 'comment_form_defaults', function($d) { // add class-name to the <form
            $d['class_form'] .= ' wp-block-columns';
            return $d;
        });
    }

    private function form_layout_change() {

        // remove not used fields
        add_filter( 'comment_form_default_fields', function($fields) {
            unset( $fields['url'] );
            unset( $fields['cookies'] );
            return $fields;
        });

        // modify the text lines
        add_filter( 'comment_form_defaults', function($d) {

            // modify the form printing function comment_form()
            $d['comment_notes_before'] = '';
            $d['logged_in_as'] = '';
            
            $is_replying = self::is_replying();

            $d['title_reply'] =  __( 'Leave a Review', 'fcpcr' );
            $d['title_reply_to'] = __( 'Leave a reply to %s', 'fcpcr' );

            $comment = $is_replying ? __( 'Your Reply', 'fcpcr' ) : __( 'Your Review', 'fcpcr' );
            $d['comment_field'] = preg_replace( '/placeholder="([^"]*)"/',
                'placeholder="'.$comment.'"', $d['comment_field'] );
            
            $submit = $is_replying ? __( 'Reply', 'fcpcr' ) : __( 'Post the Review', 'fcpcr' );
            $d['submit_button'] = preg_replace( '/value="([^"]*)"/', 'value="'.$submit.'"', $d['submit_button'] );

            return $d;
        });
    }

    public function draw_rating_fields($a) {
        ?>
        <div class="<?php echo self::$pr ?>fields wp-block-column" style="flex-basis:33.33%">
        <?php
            foreach ( self::$competencies as $v ) {
                $slug = self::slug( $v );
        ?>
            <fieldset class="<?php echo self::$pr ?>wrap">
                <legend><?php _e( $v, 'fcpcr' ) ?></legend>
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


    // ************* styles functions

    public function style_form() {
        wp_enqueue_style( self::$pr . 'stars-form', plugins_url( '/', __FILE__ ) . 'assets/form.css', [], self::ver() );
    }
    public function style_comments() {
        wp_enqueue_style( self::$pr . 'stars-comments', plugins_url( '/', __FILE__ ) . 'assets/comments.css', [], self::ver() );
    }
    public static function style_summary() {
        static $styles_printed;
        if ( $styles_printed ) { return; }
        $styles_printed = true;
        echo '<style>';
        include_once __DIR__ . '/assets/summary.css';  //++not like that, include along with the shortcode or the method()
        include_once __DIR__ . '/assets/fs-stars.css';  //++not like that, include along --||--
        echo '</style>';
    }

    public function style_sizes() {
        $width = round( 100 / ( self::$stars - 1 + self::$proportions ), 3 );
        $height = round( $width * self::$proportions, 3 );
        $wh_radio = round( 100 / self::$stars, 5 );
        ?><style>.cr_stars_bar{--star_height:<?php echo $height ?>%}.cr_fields{--star_size:<?php echo $wh_radio ?>%;}</style><?php
    }

}

new FCP_Comment_Rate();

// ++first screen css for stars
/* the number of first lvl comments
    $first_lvl_comments = get_comments([
        'post_id' => get_the_ID(),
        'status' => 'approve',
        'hierarchical' => 'threaded', // count only first lvl
        'count' => true, // return only the count
    ]);
//*/
// ++change the message to ~only author and admin can reply the review
// ++add_filter( 'allow_empty_comment', '__return_true' ); // ++can't be custom typed, can make a custom f ???
// ++load the gutenberg styles if are not loaded on the page (columns layout)
// ++edit the rating on the back-end https://wp-kama.ru/id_8342/kak-dobavit-proizvolnye-polya-v-formu-kommentariev-wordpress.html
// ++use https://wp-kama.ru/hook/get_comment_author_link hook to disable the author link
    // .form-table.editcomment tr:nth-child(3) {display:none}