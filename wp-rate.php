<?php
/*
Plugin Name: Comment and Rate
Description: Adds custom rating fields to a particular post type's comments
Version: 2.0.0
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
                    $posts = [], // post ids
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
            if ( !self::comments_are_reviews() ) { return; }


            // forms printing conditions
            add_filter( 'comments_open', function ( $open, $post_id ) { // notice - only printing is effected
                if ( !$open ) { return false; }
                $comment = self::is_replying();
                if ( $comment && !self::can_reply( $comment ) ) { return false; }
                return true;
            }, 10, 2 );

            // forms modifying
            if ( comments_open() ) { // just to not run those in common circumstances - the printing is filtered anyways
                $this->form_layout_change();
                if ( !self::is_replying() ) {
                    $this->draw_main_fields_wrapper();
                    add_action( 'comment_form', [$this, 'rating_fields_layout'] );
                }
            }


            // comments printing modify & override settings for wp_list_comments()
            add_filter( 'wp_list_comments_args', function($a) {
                $new_args = [
                    'avatar_size' => 80,
                    'max_depth' => 2,
                    'callback' => [$this, 'comment_print'], // the reply & edit links are printed here
                    //'short_ping' => true, // the simplest format?
                    'reply_text' => __( 'Reply to this review', 'fcpcr' ),
                    'reverse_top_level' => true,
                    'reverse_children' => true
                ];
                return array_merge( $a, $new_args );
            });


            // add the rating values to the comment text
            add_filter( 'comment_text', [$this, 'comment_rating_draw'], 30 ); // after paragraphs are spread

            // load styles
            if ( comments_open() ) {
                add_action( 'wp_enqueue_scripts', [$this, 'style_form'] );
            }
            if ( get_comments_number() || !empty( $_GET['unapproved'] ) ) {
                add_action( 'wp_enqueue_scripts', [$this, 'style_comments'] );
            }
            //add_action( 'wp_head', [$this, 'style_inline_sizes'] ); //++--stars can be printed somewhere outside

        });


        // ************* wp-admin printing conditions

        // hide the links in admin
        add_action( 'bulk_actions-edit-comments', [$this, 'hide_comments_actions_links'] );
        add_action( 'comment_row_actions', [$this, 'hide_comments_actions_links'] );

        // filter displaying the editing screen
        add_action( 'current_screen', [$this, 'hide_comments_actions_screens'] );
        add_action( 'current_screen', [$this, 'highlight_unapproved_comments'] );
        add_action( 'current_screen', [$this, 'hide_changing_status_options'] );


        // ************* operations with comments to limit

        // limit operations
        add_action( 'transition_comment_status', [$this, 'limit_status_change'], 10, 3 );
        add_filter( 'preprocess_comment', [$this, 'filter_reply'] );
        add_filter( 'wp_update_comment_data', [$this, 'filter_edit'], 10, 3 );


        // ************* operations with comments to save

        // saving
        add_action( 'comment_post', [$this, 'save_ratings'] );
        // recount the total rating of a post on the following actions
        add_action( 'comment_post', [$this, 'reset_score_on_new'], 10, 3 );
        add_action( 'transition_comment_status', [$this, 'reset_score_on_transit'], 10, 3 );

    }


    // ************* operations with comments to limit

    public function limit_status_change($new_status, $old_status, $comment) {

        if ( !self::comments_are_reviews( $comment ) ) { return; }

        // the transition hook runs after save, so gotta restore the initial status instead of just forbidding
        $restore_status = function() use ( $comment, $old_status ) {
            global $wpdb;
            $statuses = [
                'approved' => '1',
                'unapproved' => '0',
                'spam' => 'spam',
                'trash' => 'trash',
            ];

            $wpdb->update( // because wp_update_comment() works earlier
                $wpdb->comments,
                [ 'comment_approved' => $statuses[ $old_status ] ],
                [ 'comment_ID' => $comment->comment_ID ]
            );

            self::access_denied(); // ++--bulk along with comments might go wrong. maybe hide the checkbox for reviews?
        };

        if ( !self::can_moderate() && in_array( $new_status, ['approved', 'unapproved', 'spam'] ) ) {
            $restore_status();
        }
        if ( !self::can_edit( $comment ) && in_array( $new_status, ['trash'] ) ) {
            $restore_status();
        }
    }
    
    public function filter_reply($commentdata) {

        if ( !self::comments_are_reviews( null, $commentdata['comment_post_ID'] ) ) { return $commentdata; }

        if ( !$commentdata['comment_parent'] ) { return $commentdata; } // not a reply

        $comment = self::get_comment( $commentdata['comment_parent'] );
        if ( !$comment ) { self::access_denied(); } // unknown parent
    
        if ( !self::can_reply( $comment ) ) { self::access_denied(); }
        
        return $commentdata;
    }
    
    public function filter_edit($data, $comment, $commentarr) {

        if ( !self::comments_are_reviews( $comment['comment_ID'] ) ) { return $data; }

        $comment = self::get_comment( $comment['comment_ID'] );
        if ( !$comment ) { self::access_denied(); }

        if ( !self::can_edit( $comment ) ) {
            self::access_denied();
        }
        return $data;
    }


    // ************* operations with comments to save

    public function save_ratings($comment_id) {

         // stars are only for the reviews (1st lvl comments)
        if ( self::is_replying() ) { return; }
        if ( !self::comments_are_reviews( $comment_id ) ) { return; }

        foreach ( self::$competencies as $v ) {
            $slug = self::slug( $v );
            if ( !isset( $_POST[ $slug ] ) || !is_numeric( $_POST[ $slug ] ) ) { continue; }
            add_comment_meta( $comment_id, $slug, (int) $_POST[ $slug ] );
        }
    }

    public function reset_score_on_new($comment_id, $comment_approved, $commentdata) {
        if ( $comment_approved !== 1 ) { return; }
        $this->reset_total_score( $comment_id );
    }
    public function reset_score_on_transit($new_status, $old_status, $comment) {
        if ( $old_status !== 'approved' && $new_status !== 'approved' ) { return; }
        $this->reset_total_score( $comment );
    }
    private function reset_total_score($comment) {
        if ( !self::comments_are_reviews( $comment ) ) { return; }

        $comment = self::get_comment( $comment );
        if ( !$comment ) { return false; }

        update_post_meta( $comment->comment_post_ID, 'cr_total_wtd',
            self::count_all( $comment->comment_post_ID )['__rating']
        );
    }


    // ************* wp-admin printing limitations

    public function hide_comments_actions_links($actions) {

        if ( !self::comments_are_reviews() || !self::get_comment() ) { return $actions; }

        $remove = []; // actions to remove
        if ( !self::can_reply() ) {
            $remove = array_merge( $remove, ['reply'] );
        }
        if ( !self::can_moderate() ) {
            $remove = array_merge( $remove, ['spam', 'approve', 'unapprove'] );
        }
        if ( !self::can_edit() ) {
            $remove = array_merge( $remove, ['edit', 'quickedit', 'trash', 'delete', 'spam', 'approve', 'unapprove'] );
        }

        foreach ( $remove as $v ) { unset( $actions[ $v ] ); }
        return $actions;
    }

    public function hide_comments_actions_screens() {
        if ( get_current_screen()->id !== 'comment' ) { return; }
        if ( !self::comments_are_reviews( $_GET['c'] ) ) { return; }
        if ( self::can_edit() ) { return; }
        self::access_denied();
    }
    
    public function highlight_unapproved_comments() { // ++--it applies to common comments too. exclude edit-comments?
        if ( !in_array( get_current_screen()->id, array_merge( self::$types, ['edit-comments'] ) ) ) { return; }
        add_action( 'admin_head', function() { ?>
        <style>
            <?php if ( !self::can_moderate() ) { ?>
            #the-comment-list .unapproved { opacity:0.6 }
            <?php } ?>
            #the-comment-list .unapproved .column-comment::before {
                content:'[<?php _e( 'Awaiting moderation', 'fcpcr' ) ?>]\a';
                white-space:pre;
                text-transform:uppercase;
                color:#b32d2e;
            }
        </style>
        <?php });
    }
    
    public function hide_changing_status_options() {
        if ( get_current_screen()->id !== 'comment' ) { return; }
        if ( !self::comments_are_reviews( $_GET['c'] ) ) { return; }
        if ( self::can_moderate() ) { return; }
        add_action( 'admin_head', function() { ?>
        <style>
            #comment-status { display:none }
        </style>
        <?php });
        // there is a protection on change attempt, so only hidding
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
            self::comment_rating_layout( $title, $stars );
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
            //unset( $fields['cookies'] );
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
            // ++case for lable, not placeholder
            $d['comment_field'] = preg_replace( '/placeholder="([^"]*)"/',
                'placeholder="'.$comment.'"', $d['comment_field'] );
            
            $submit = $is_replying ? __( 'Reply', 'fcpcr' ) : __( 'Post the Review', 'fcpcr' );
            $d['submit_button'] = preg_replace( '/value="([^"]*)"/', 'value="'.$submit.'"', $d['submit_button'] );

            return $d;
        });
    }


    // ************* styles functions

    public function style_form() {
        wp_enqueue_style( self::$pr . 'stars-form', plugins_url( '/', __FILE__ ) . 'assets/form.css', [], self::ver() );
    }
    public function style_comments() {
        wp_enqueue_style( self::$pr . 'stars-comments', plugins_url( '/', __FILE__ ) . 'assets/comments.css', [], self::ver() );
    }

    private static function style_inline($slug) {
        static $printed_once = []; if ( isset( $printed_once[ $slug ] ) ) { return; } $printed_once[ $slug ] = true;
        echo '<style>';
        echo "\n" . '/* wp-rate ' . $slug . ' */' . "\n";
        include_once __DIR__ . '/assets/fs-' . $slug . '.css';
        echo '</style>';
        if ( $slug !== 'stars' ) { return; }
        self::style_inline_sizes();
    }
    private static function style_inline_sizes() {
        $width = round( 100 / ( self::$stars - 1 + self::$proportions ), 3 );
        $height = round( $width * self::$proportions, 3 );
        $wh_radio = round( 100 / self::$stars, 5 );
        ?><style>.cr_stars_bar{--star_height:<?php echo $height ?>%}.cr_fields{--star_size:<?php echo $wh_radio ?>%;}</style><?php
    }


    // ************* functional
    private static function can_reply($comment = '') { // doesn't extend, only limit the role capabilities
        $comment = self::get_comment( $comment );
        if ( !$comment ) { return false; }

        if ( !$comment->comment_approved && !current_user_can('administrator') ) { return false; }
        if ( (int) $comment->comment_parent !== 0 ) { return false; } // only the first lvl comment can be replied
        if ( !current_user_can( 'edit_post', $comment->comment_post_ID ) ) { return false; }

        return true;
    }
    
    private static function can_edit($comment = '') { // doesn't extend, only limit the role capabilities
        $comment = self::get_comment( $comment );
        if ( !$comment ) { return false; }

        if ( current_user_can( 'administrator' ) ) { return true; }
        if ( current_user_can( 'edit_comment', $comment->comment_ID ) && (int) $comment->user_id === get_current_user_id() ) { return true; } // only own comments
        
        return false;
    }

    private static function can_moderate($comment = '') {
        if ( current_user_can( 'administrator' ) ) { return true; }
        return false;
    }

    private static function is_replying() {
        if ( isset( $_GET['replytocom'] ) && $_GET['replytocom'] != '0' ) { return $_GET['replytocom']; }
        if ( isset( $_POST['comment_parent'] ) && $_POST['comment_parent'] != '0' ) { return $_POST['comment_parent']; }
        return false;
    }

    private static function comments_are_reviews($comment = '', $post_id = '') { // use the plugin's settings or global
    
        $can_use = function($post_id = 0) {
            if ( !$post_id ) { return false; }
            if ( in_array( $post_id, self::$posts ) ) { return true; }
            return in_array( get_post_type( $post_id ), self::$types );
        };
        
        if ( $post_id ) { return $can_use( $post_id ); }

        if ( !$comment ) { return $can_use( get_the_ID() ); }

        $comment = self::get_comment( $comment );
        return $comment && $can_use( $comment->comment_post_ID );
    }

    private static function get_comment($comment = '') {

        $is_comment = function($c) { return $c && $c instanceof WP_Comment; };
    
        if ( $comment && is_numeric( $comment ) ) { return get_comment( $comment ); }

        if ( !$is_comment( $comment ) ) {
            if ( is_admin() && isset( $_GET['action'] ) && isset( $_GET['c'] ) ) {
                $comment = get_comment( $_GET['c'] );
            } elseif ( isset( $_POST['action'] ) && isset( $_POST['comment_ID'] ) ) { // filter the admin post actions
                $comment = get_comment( $_POST['comment_ID'] );
            } else { // filter the front-end inside the comments loop
                $comment = get_comment();
            }
        }

        return $is_comment( $comment ) ? $comment : null;
    }

    private static function slug($name) {
        static $store = []; if ( $store[ $name ] ) { return $store[ $name ]; }

        $store[ $name ] = self::$pr . sanitize_title( $name );
        return $store[ $name ];
    }

    private static function access_denied($m = '', $t = '') {
        $m = is_array( $m ) ? '<pre>'.print_r( $m, true ).'</pre>' : $m;
        wp_die( $m ? $m : 'Access denied', $t ? $t : 'Access denied', [ 'response' => 403, 'back_link' => true ] );    
    }


    // ************* counting

    private static function count_all($id = 0) {
        static $counted = []; if ( isset( $counted[ $id ] ) ) { return $counted[ $id ]; }

        if ( !$id ) $id = get_the_ID();
    
        $comments = get_approved_comments( $id );

        if ( !$comments ) { $counted[ $id ] = false; return $counted[ $id ]; }
        
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
        $result['__rating'] = $a['total_wtd'];
        $result['__rated_reviews'] = max( array_values( $a['not_zero_stars_amo'] ) );

        foreach( $result as &$v ) {
            $v = round( $v, 5 );
        }

        $counted[ $id ] = $result;
        return $counted[ $id ];
    }


    // ************* printing functions

    public static function stars_n_rating_print($skipempty = false) {
        self::style_inline( 'rating-short' );
        $stats = self::count_all();
        if ( !$stats || !$stats['__rating'] ) {
            if ( !$skipempty ) { self::rating_short_layout_empty(); }
            return;
        }
        if ( self::$schema ) {
            self::rating_short_layout_schema( $stats['__rating'], $stats['__rated_reviews'] );
            self::$schema = false; // print rating schema only once
            return;
        }
        self::rating_short_layout( $stats['__rating'] );
    }

    public static function stars_total_print() {
        $stats = self::count_all();
        if ( !$stats || !$stats['__rating'] ) { return; }
        self::stars_print( $stats['__rating'] );
    }

    public static function summary_print() {
        self::style_inline( 'summary' );
        $stats = self::count_all();
        $count = get_comments([ // count the first lvl comments with and without score
            'post_id' => get_the_ID(),
            'status' => 'approve',
            'hierarchical' => 'threaded', // count only first lvl
            'count' => true, // return only the count
        ]);
        //$count = $stats['__rated_reviews']; // if only reviews with score gotta be shown
        self::summary_layout( $stats['__rating'], $count );
    }

    public static function stars_print($stars = 0) {
        self::style_inline( 'stars' );
        $stars = $stars && $stars > self::$stars ? self::$stars : $stars;
        $width = round( $stars / self::$stars * 100, 5 );
        self::stars_layout( $width );
    }

    private static function competences_print() {
        $stats = self::count_all();
        if ( !$stats ) { return; }
        foreach ( self::$competencies as $v ) {
            $slug = self::slug( $v );
            if ( !$stats[ $slug ] ) {
                continue;
            }
            self::competence_layout( $v, $stats[ $slug ] );
        }
    }


    // ************* layouts
    
    private static function stars_layout($width = 0) {
        ?>
        <div class="<?php echo self::$pr ?>stars_bar">
            <div style="width:<?php echo $width ?>%"></div>
        </div>
        <?php
    }

    private static function competence_layout($title, $stars) {
        ?>
        <div class="<?php echo self::$pr ?>competence">
            <div class="<?php echo self::$pr ?>wrap">
                <div class="<?php echo self::$pr ?>headline"><?php _e( $title, 'fcpcr' ) ?></div>
                <?php self::stars_print( $stars ) ?>
            </div>
        </div>
        <?php
    }

    private static function rating_short_layout($rating) {
        ?>
        <div class="<?php echo self::$pr ?>rating-short">
            <?php self::stars_print( $rating ) ?>
            <?php echo '<span>' . number_format( round( $rating, 1 ), 1, ',', '' ) . '</span>' ?>
        </div>
        <?php
    }

    private static function rating_short_layout_schema($rating, $rated_reviews) {
        ?>
        <div class="<?php echo self::$pr ?>rating-short" itemprop="aggregateRating" itemscope itemtype="https://schema.org/AggregateRating">
            <?php self::stars_print( $rating ) ?>
            <?php echo '<span itemprop="ratingValue">' . number_format( round( $rating, 1 ), 1, ',', '' ) . '</span>' ?>
            <meta itemprop="ratingCount" content="<?php echo $rated_reviews ?>">
        </div>
        <?php
    }

    private static function rating_short_layout_empty() {
        ?>
        <div class="<?php echo self::$pr ?>rating-short">
            <?php //self::stars_print() ?>
            <?php echo '<span>'.__( 'Not rated yet', 'fcpcr' ).'</span>' ?>
        </div>
        <?php
    }

    private static function summary_layout($rating, $reviews) {
        ?>
        <div class="<?php echo self::$pr ?>summary-headline">
            <?php _e( 'Reviews', 'fcpcr' ) ?> (<?php echo $reviews ?>)
        </div>
        <?php self::stars_n_rating_print( true ) ?>
        <?php self::competences_print() ?>
        <?php
    }
    
    private function comment_rating_layout($title, $stars) {
        ?>
        <div class="<?php echo self::$pr ?>wrap">
            <div class="<?php echo self::$pr ?>headline"><?php _e( $title, 'fcpcr' ) ?></div>
            <?php self::stars_print( $stars ) ?>
        </div>
        <?php
    }
    
    
    // the form fields layout gotta be public and not static
    public function rating_fields_layout() {
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

}

new FCP_Comment_Rate();

// add_filter( 'allow_empty_comment', '__return_true' ); // comment or all stars - one must be
// load the gutenberg styles if are not loaded on the page (columns layout)? or make simple custom??
// forbid changing the name on reply?
// forbid the author to review own entity
// edit the rating on the back-end https://wp-kama.ru/id_8342/kak-dobavit-proizvolnye-polya-v-formu-kommentariev-wordpress.html
// settings page https://wp-kama.ru/function/get_current_screen example 4
// stars to a panding review
// style the "will be published after moderation" phrase
// maybe not hide the url field and restore it in the template, but hide later via functions.php
//      maybe even use the original template better?
// mess with current_user_can( 'administrator' ) - add editor or find a proper capability
// add (edited) if a comment was modified
