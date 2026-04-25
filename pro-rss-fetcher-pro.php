<?php
/*
Plugin Name: Pro RSS Fetcher
Description: Advanced RSS Auto Importer (Categories, Author, Filters, Real Date, Source Links, Featured Image)
Version: 5.0
Author: Mohadese Ahmadi
*/

if (!defined('ABSPATH')) exit;

define('PRSS_PATH', plugin_dir_path(__FILE__));
define('PRSS_URL', plugin_dir_url(__FILE__));

require_once PRSS_PATH . 'includes/class-feed-manager.php';
require_once PRSS_PATH . 'includes/class-processor.php';
require_once PRSS_PATH . 'includes/class-cron.php';
require_once PRSS_PATH . 'includes/class-logger.php';
require_once PRSS_PATH . 'includes/helpers.php';
require_once PRSS_PATH . 'admin/settings-page.php';

// Activate: Create cron real schedule
register_activation_hook(__FILE__, function() {
    PRSS_Cron::schedule();
});

// Deactivate: Remove cron
register_deactivation_hook(__FILE__, function() {
    PRSS_Cron::clear();
});
