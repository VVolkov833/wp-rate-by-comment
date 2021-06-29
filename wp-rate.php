<?php
/*
Plugin Name: Comment and Rate
Description: Adds custom rating fields to a comment
Version: 1.0.0
*/

defined( 'ABSPATH' ) || exit;

class FCP_Comment_Rate {

	public static $dev = true,
                  $pr = 'cr_', // prefix (db, css)
                  $types = ['clinic'], // post types to support
                  $ratings = ['Aaaaa', 'Bbbb', 'Cccccccc', 'Ddddddddd'], // nominations
                  //$ratings = ['Kompetenz', 'Freundlichkeit', 'Warte zeit für Termin', 'Räumlichkeit'], // nominations
                  //$weights = [8, 3.2, 2.4, 2], // any size, but proportionally correct in relation to each other
                  $stars = 5,
                  $star_proportions = 2 / 3; // star width (square) / image width
	
	private function plugin_setup() {

		$this->self_url  = plugins_url( '/', __FILE__ );
		$this->self_path = plugin_dir_path( __FILE__ );

		$this->css_ver = '0.0.1' . ( self::$dev ? '.'.time() : '' );
		$this->js_ver = '0.0.1' . ( self::$dev ? '.'.time() : '' );
	}

    public function __construct() {

        $this->plugin_setup();

        add_filter( 'allow_empty_comment', '__return_true' ); // ++ add post type limitations to this too?

        add_action( 'wp', function() {
            global $post;
            if ( !in_array( $post->post_type, self::$types ) ) { return; }
        
            add_action( 'comment_form_logged_in_after', [$this, 'form_fields_layout'] );
            add_action( 'comment_form_after_fields', [$this, 'form_fields_layout'] );
            add_action( 'comment_post', [$this, 'form_fields_save'] );
        
            add_filter( 'comment_text', [$this, 'comment_rating_draw'], 30 );

            add_action( 'wp_enqueue_scripts', [$this, 'styles_scripts_add'] );
            add_action( 'wp_footer', [$this, 'styles_dynamic_print'] );
        });

    }
    
    public function form_fields_layout() {
        ?>
        <div class="<?php echo self::$pr ?>form wp-block-column" style="flex-basis:33.33%">
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
        foreach ( self::$ratings as $v ) {
            $slug = self::slug( $v );

            if ( ( !isset( $_POST[ $slug ] ) ) ) {
                //delete_comment_meta( $comment_id, $slug );
                return;
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

        ?>
        <style>
            .cr_rating_bar {
                --<?php echo self::$pr ?>bar-height:<?php echo $height ?>%;
            }
            .cr_form {
                --<?php echo self::$pr ?>input-size:<?php echo $wh_radio ?>%;
            }
        </style>
        <?php
    }

//-----__--___-__--_______STATICS-------___--_______-

    public static function stars_layout($stars = 0) {
        $stars = $stars > self::$stars ? self::$stars : $stars;
        $width = round( $stars / self::$stars * 100, 5 );
        
        ?>
        <div class="<?php echo self::$pr ?>rating_bar">
            <div style="width:<?php echo $width ?>%"></div>
        </div>
        <?php
    }
    
    public static function nomination_layout( $ratings ) {
        foreach ( self::$ratings as $v ) {
            $slug = self::slug( $v );
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

    public static function ratings_count($id = 0) {

        if ( !$id ) $id = get_the_ID();
    
        $comments = get_approved_comments( $id );

        if ( !$comments ) { return; }
        
        $a = [
            'per_nomination_sum' => [],
            'not_zero_stars' => [],
            'per_nomination_avg' => [], // total / filled
            'cast_weights' => [],
            'per_nomination_wtd' => [], // avg * weight
            'total_avg' => 0,
            'total_wtd' => 0,
        ];

        // fetch ratings
        foreach ( $comments as $comment ) {
            foreach ( self::$ratings as $v ) {
                $slug = self::slug( $v );
                $stars = get_comment_meta( $comment->comment_ID, $slug, true );

                if ( !$stars ) { continue; }

                $a['per_nomination_sum'][ $slug ] += (int) $stars > self::$stars ? self::$stars : (int) $stars;
                $a['not_zero_stars'][ $slug ]++;
            }
        }

        // counting avgs weighted and totals
        foreach ( self::$ratings as $k => $v ) {
            $slug = self::slug( $v );

            $a['per_nomination_avg'][ $slug ] =
                $a['per_nomination_sum'][ $slug ] / $a['not_zero_stars'][ $slug ];
                
            // cast weights
            $a['per_nomination_wtd'][ $slug ] = $a['per_nomination_avg'][ $slug ];

            if ( isset( self::$weights ) && count( self::$weights ) === count( self::$ratings ) ) {

                $a['cast_weights'][ $k ] = 
                    self::$weights[ $k ] * count( self::$ratings ) / array_sum( self::$weights );
                    
                $a['per_nomination_wtd'][ $slug ] =
                    $a['per_nomination_avg'][ $slug ] * $a['cast_weights'][ $k ];
            }

        }

        $a['total_avg'] = array_sum( $a['per_nomination_avg'] ) / count( self::$ratings );
        $a['total_wtd'] = array_sum( $a['per_nomination_wtd'] ) / count( self::$ratings );

        $result = $a['per_nomination_wtd'];
        $result['__total'] = $a['total_wtd'];

        foreach( $result as &$v ) {
            $v = round( $v, 5 );
        }

        return $result;
    }

    public static function slug($slug) {
        static $keep = [];
        
        if ( $keep[$slug] ) {
            return $keep[ $slug ];
        }
        
        $keep[ $slug ] = self::$pr . sanitize_title( $slug );
    
        return $keep[ $slug ];
    }

}

new FCP_Comment_Rate();
