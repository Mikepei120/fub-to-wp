<?php
/**
 * FUB to WP Uninstall Script
 * 
 * This file runs when the plugin is deleted from WordPress admin.
 * It removes all plugin data, tables, and cache.
 */

// If uninstall not called from WordPress, exit
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Remove all plugin options
$options_to_delete = array(
    'fub_api_key',
    'fub_account_id',
    'fub_account_name', 
    'fub_account_email',
    'fub_license_key',
    'fub_license_status',
    'fub_pixel_id',
    'fub_default_source',
    'fub_default_tags',
    'fub_assigned_user',
    'fub_setup_completed',
    'fub_plugin_version',
    // OAuth credentials
    'fub_oauth_client_id',
    'fub_oauth_client_secret',
    'fub_oauth_access_token',
    'fub_oauth_refresh_token',
    'fub_oauth_token_expires',
    'fub_oauth_state'
);

foreach ($options_to_delete as $option) {
    delete_option($option);
}

// Remove all transients
global $wpdb;
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_fub_%' OR option_name LIKE '_transient_timeout_fub_%'");

// Drop plugin tables
$table_names = array(
    'fub_leads',
    'fub_tags'
);

foreach ($table_names as $table) {
    $table_name = $wpdb->prefix . $table;
    $wpdb->query("DROP TABLE IF EXISTS {$table_name}");
}

// Clear WordPress cache
if (function_exists('wp_cache_flush')) {
    wp_cache_flush();
}

// Clear rewrite rules
flush_rewrite_rules();