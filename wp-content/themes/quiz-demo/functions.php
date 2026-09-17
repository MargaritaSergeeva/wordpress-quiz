<?php
/** Load the demo layout and the quiz's locally hosted typeface. */
add_action( 'wp_enqueue_scripts', static function () {
    $dependencies = wp_style_is( 'wpq-font', 'registered' ) ? [ 'wpq-font' ] : [];
    wp_enqueue_style( 'quiz-demo', get_stylesheet_uri(), $dependencies, '1.0.1' );
}, 20 );
