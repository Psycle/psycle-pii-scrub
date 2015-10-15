<?php
/**
 * This file contains material which is the pre-existing property of Psycle Interactive Limited.
 * Copyright (c) 2015 Psycle Interactive. All rights reserved.
 *
 * @package PsyclePlugins
 * @subpackage Main
 */

/*
 * Plugin Name: WP_CLI - PII Data Scrub
 * Plugin URI: http://www.psycle.com/
 * Description: WP_CLI command to scrub PII data from a WordPress database. Calling "wp pii-scrub" will automatically detect major plugins (BuddyPress, WooCommerce) and scrub/replace any PII (personally identifiable information) so that a database compromise doesn't cause issues. Additional custom meta data to check/scrub can be defined at runtime.
 * Author: David Page c.o. Psycle Interactive
 * Version: 1.1
 * Author URI: http://www.psycle.com/
 * Requires at least: 4.1
 * Tested up to: 4.3.1
 * Text Domain: psycle
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}
if ( ! defined( 'WP_CLI' ) ) {
	return; // Bail if WP-CLI is not present.
}

// Can be setup as a MU plugin so check for sub-directory.
if ( file_exists( __DIR__ . '/psycle-pii-scrub/class-pii-scrub.php' ) ) {
	require_once( __DIR__ . '/psycle-pii-scrub/class-pii-scrub.php' );
} else {
	require_once( __DIR__ . '/class-pii-scrub.php' );
}
WP_CLI::add_command( 'pii-scrub', 'Psycle\WordPress\Mu\PII_Scrub' );
