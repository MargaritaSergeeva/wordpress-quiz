<?php
/**
 * Plugin Name: WordPress Quiz
 * Description: Редактор квизов, сбор заявок и передача ответов в CRM.
 * Version: 0.2.1
 * Requires at least: 6.8
 * Requires PHP: 8.3
 * Text Domain: wordpress-quiz
 * License: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace WordPressQuiz;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

const VERSION = '0.2.1';
const CONSENT_VERSION = 'demo-2026-09-17';

require __DIR__ . '/includes/data.php';
require __DIR__ . '/includes/admin.php';
require __DIR__ . '/includes/frontend.php';

register_activation_hook( __FILE__, __NAMESPACE__ . '\\install' );
add_action( 'plugins_loaded', static function () {
    if ( VERSION !== get_option( 'wpq_schema_version' ) ) {
        install();
    }
} );

add_action( 'init', static function () {
    register_post_type( 'wpq_quiz', [
        'labels' => [
            'name' => 'Квизы', 'singular_name' => 'Квиз', 'add_new' => 'Добавить квиз',
            'add_new_item' => 'Новый квиз', 'edit_item' => 'Редактировать квиз',
            'all_items' => 'Все квизы', 'not_found' => 'Квизы не найдены',
        ],
        'public' => false, 'show_ui' => true, 'show_in_rest' => true,
        'menu_icon' => 'dashicons-forms', 'supports' => [ 'title' ],
        'capability_type' => 'post', 'map_meta_cap' => true,
    ] );
    register_post_meta( 'wpq_quiz', '_wpq_questions', [
        'type' => 'array', 'single' => true, 'show_in_rest' => false,
        'auth_callback' => static fn() => current_user_can( 'edit_posts' ),
    ] );
    register_assets();
    register_block_type( __DIR__ . '/block', [ 'render_callback' => static fn( $a ) => render_quiz( (int) ( $a['quizId'] ?? 0 ) ) ] );
    add_shortcode( 'wordpress_quiz', static fn( $a ) => wp_interactivity_process_directives( render_quiz( (int) ( $a['id'] ?? 0 ) ) ) );
} );
