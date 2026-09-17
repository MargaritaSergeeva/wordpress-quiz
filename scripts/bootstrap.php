<?php
/** Initialize the demo site. Production setup requires explicit CLI opt-in. */

if (!defined('WP_CLI') || !WP_CLI) {
    exit(1);
}

define('WP_INSTALLING', true);
define('WP_SITEURL', getenv('WP_URL'));
define('WP_HOME', getenv('WP_URL'));
require '/var/www/html/wp-load.php';

if ('local' !== wp_get_environment_type() && '1' !== getenv('WPQ_ALLOW_DEMO_SETUP')) {
    WP_CLI::error('Production setup requires WPQ_ALLOW_DEMO_SETUP=1 for this CLI invocation.');
}

if (!is_blog_installed()) {
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $password = getenv('WP_ADMIN_PASSWORD');
    if (!$password) {
        WP_CLI::error('WP_ADMIN_PASSWORD is required.');
    }
    $result = wp_install(
        getenv('WP_TITLE'),
        getenv('WP_ADMIN_USER'),
        getenv('WP_ADMIN_EMAIL'),
        false,
        '',
        $password,
    );
    if (is_wp_error($result)) {
        WP_CLI::error($result->get_error_message());
    }
    WP_CLI::success('WordPress installed. Credentials are in the local .env file.');
} else {
    WP_CLI::log('WordPress is already installed; existing content and credentials preserved.');
}

require_once ABSPATH . 'wp-admin/includes/plugin.php';
$activated = activate_plugin('wordpress-quiz/wordpress-quiz.php');
if (is_wp_error($activated)) {
    WP_CLI::error($activated->get_error_message());
}
WP_CLI::success('WordPress Quiz activated.');
