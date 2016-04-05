<?php
/**
 * This file contains material which is the pre-existing property of Psycle Interactive Limited.
 * Copyright (c) 2015 Psycle Interactive. All rights reserved.
 *
 * @package PsyclePlugins
 * @subpackage Main
 */

namespace Psycle\WordPress\Mu;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}
if ( ! defined( 'WP_CLI' ) ) {
	return; // Bail if WP-CLI is not present. Doubly check in case directly loaded.
}

/**
 * Description of class-pii-scrub
 *
 * @author davidpage
 */
class PII_Scrub extends \WP_CLI_Command {

	/**
	 * Whether to apply the scrubbing or output the database queries to be viewed.
	 *
	 * @since 2.0
	 * @var bool
	 */
	private $dry_run = false;

	/**
	 * Scrub PII data from a database replacing most data with series of 'XXXXX ' strings.
	 * Depending on field/column names will replace email addresses with changed user part,
	 * with URLs will set to 'http://www.example.org/', for telephones a randomised number.
	 * Does not directly affect users with a @psycle domain email address.
	 *
	 * ## OPTIONS
	 *
	 * [--postfields=<fields>]
	 * : A comma separated list of additional custom postmeta keys
	 *  (wp_postmeta) to be scrubbed.
	 *
	 * [--userfields=<fields>]
	 * : A comma separated list of additional custom usermeta keys
	 *  (wp_usermeta) to be scrubbed.
	 *
	 * [--customtablefields=<tablename-fields>]
	 * : Additional tables and fields can be scrubbed by passing a tablename
	 *  with colon, then comma separated fields finishing with a semi-colon.
	 *  Because the semi-colon ; is a special character within shell scripts
	 *  this option should always be enclosed within single quotes '.
	 *  The database prefix can be excluded from the tablename.
	 *
	 * [--yes]
	 * : Do not prompt for final confirmation.
	 *
	 * [--dryrun]
	 * : This runs through the rest of the script normally, but the final database
	 *   queries are instead outputted to the command line to be viewed/checked.
	 *
	 * [--live]
	 * : Allow running even when the database config is set to use Live details.
	 *  THIS IS DANGEROUS!
	 *
	 * ## EXAMPLES
	 *
	 *   # Standard for most WordPress sites.
	 *     wp pii-scrub
	 *
	 *   # Additional usermeta and postmeta fields.
	 *     wp pii-scrub --userfields=apple_id,telephone --postfields=distribution_email
	 *
	 *   # Wildcard matching within additional usermeta and postmeta fields.
	 *     wp pii-scrub --userfields=%_name --postfields=memo_category%
	 *
	 *   # Additional custom table with column names to be scrubbed.
	 *     wp pii-scrub --customtablefields='audit_trail:user_email,operation;'
	 *
	 * @global \wpdb $wpdb The WordPress database abstraction instance.
	 *
	 * @param array $_          Any positional arguments passed to the command, not used.
	 * @param array $assoc_args Any arguments passed to the command in the format --key=value or --flag.
	 *
	 * @return void
	 */
	public function __invoke( $_, $assoc_args ) {
		global $wpdb;

		// Check for Psycle standardised wp-config.php, used to ensure Live checks.
		if ( ! defined( 'LIVE_ENVIRONMENT' ) ) {
			\WP_CLI::error( sprintf( __( 'wp-config.php for \'%s\' does not appear to be in the correct Psycle format. Unable to determine if site is running as Live or not.', 'psycle' ), get_bloginfo( 'name' ) ) );
		}

		// Check for running on Live.
		if ( LIVE_ENVIRONMENT ) {
			// Display warning in large red box.
			\WP_CLI::error_multi_line( array( sprintf( __( 'Database for \'%s\' is currently set as Live.', 'psycle' ), get_bloginfo( 'name' ) ) ) );

			// Check for Live database bypass.
			if ( ! \WP_CLI\Utils\get_flag_value( $assoc_args, 'live' ) ) {
				\WP_CLI::error( __( 'Re-run the same command with \'--live\' if you really wish to continue.', 'psycle' ) );
			}

			// If using --live ensure that --yes isn't also used.
			if ( \WP_CLI\Utils\get_flag_value( $assoc_args, 'live' ) && \WP_CLI\Utils\get_flag_value( $assoc_args, 'yes' ) ) {
				unset( $assoc_args['yes'] );
			}
		}

		// Core PII data.
		$changes = array(
			'_core_users_data' => array(
				'label' => __( 'Users data', 'psycle' ),
				'desc' => sprintf( __( 'Users data in %1$susers.', 'psycle' ), $wpdb->base_prefix ),
				'params' => false,
			),
			'_core_usermeta_data' => array(
				'label' => __( 'Users meta data', 'psycle' ),
				'desc' => sprintf( __( 'Users meta data in %1$susermeta.', 'psycle' ), $wpdb->base_prefix ),
				'params' => 'extra_user_meta',
			),
			'_core_commenters_data' => array(
				'label' => __( 'Commenters data', 'psycle' ),
				'desc' => sprintf( __( 'Commenters data in %1$scomments.', 'psycle' ), $wpdb->base_prefix ),
				'params' => false,
			),
		);

		$extra_post_meta = $extra_user_meta = $extra_table_fields = false;
		// Detect for additional usermeta fields.
		if ( isset( $assoc_args['userfields'] ) ) {
			$extra_user_meta = explode( ',', $assoc_args['userfields'] );
			$extra_user_meta = array_map( 'trim', $extra_user_meta );
		}

		// Detect for additional postmeta fields.
		if ( isset( $assoc_args['postfields'] ) ) {
			$extra_post_meta = explode( ',', $assoc_args['postfields'] );
			$extra_post_meta = array_map( 'trim', $extra_post_meta );
			$changes['_core_postmeta_data'] = array(
				'label' => __( 'Customised Postmeta data', 'psycle' ),
				'desc' => sprintf( __( 'Custom meta data within %1$spostmeta.', 'psycle' ), $wpdb->base_prefix ),
				'params' => 'extra_post_meta',
			);
		}

		// Detect for additional tables and their column names.
		if ( isset( $assoc_args['customtablefields'] ) ) {
			$tables = explode( ';', trim( $assoc_args['customtablefields'], ';' ) );
			foreach ( $tables as $data ) {
				list ( $tablename, $columns ) = explode( ':', trim( $data ) );
				$columns = explode( ',', $columns );
				$columns = array_map( 'trim', $columns );
				$extra_table_fields[ trim( $tablename ) ] = $columns;
			}
			$changes['_custom_table_data'] = array(
				'label' => __( 'Customised Table data', 'psycle' ),
				'desc' => __( 'Custom tables and columns data.', 'psycle' ),
				'params' => 'extra_table_fields',
			);
		}

		// Detection of major plugins storing PII data.
		if ( $this->_has_woocommerce() ) {
			$changes['_plugin_woocommerce'] = array(
				'label' => __( 'WooCommerce data', 'psycle' ),
				'desc' => sprintf( __( 'WooCommerce data within %1$susermeta and %1$sposts (i.e. orders).', 'psycle' ), $wpdb->base_prefix ),
				'params' => false,
			);
		}

		if ( $this->_has_buddypress() ) {
			$changes['_plugin_buddypress'] = array(
				'label' => __( 'BuddyPress data', 'psycle' ),
				'desc' => sprintf( __( 'BuddyPress data within %1$sbp_xprofile_data.', 'psycle' ), $wpdb->base_prefix ),
				'params' => false,
			);
		}

		// Display summary of changes that will be made.
		\WP_CLI::line( __( 'Summary of data to be scrubbed: ', 'psycle' ) );
		foreach ( $changes as $func => $opts ) {
			if ( ! empty( $$opts['params'] ) ) {
				\WP_CLI::line( ' * ' . $opts['desc'] . __( ' Including the following extra fields:', 'psycle' ) );
				foreach ( $$opts['params'] as $k => $field ) {
					if ( is_array( $field ) ) {
						$field = $k . ' - ' . implode( ',', $field );
					}
					\WP_CLI::line( ' * * ' . $field );
				}
			} else {
				\WP_CLI::line( ' * ' . $opts['desc'] );
			}
		}
		\WP_CLI::line( '' );

		// Are we dry running it.
		if ( isset( $assoc_args['dryrun'] ) ) {
			$this->dry_run = true;
			\WP_CLI::line( __( 'Note: This is a dry run, no changes will be made. Database queries will be outputted instead.', 'psycle' ) );
			\WP_CLI::line( '' );
		}

		// Confirm that the scrubbing should go ahead.
		\WP_CLI::confirm( __( 'Are you sure you wish to proceed?', 'psycle' ), $assoc_args );

		// Track how long it takes to do the scrubbing.
		\timer_start();

		// Loop through and scrub all the data.
		foreach ( $changes as $func => $opts ) {
			\WP_CLI::log( sprintf( __( 'Scrubbing %1$s...', 'psycle' ), $opts['label'] ) );
			// Check for extra parameters.
			if ( ! empty( $opts['params'] ) && ! empty( $$opts['params'] ) ) {
				\call_user_func( array( $this, $func ), $$opts['params'] );
			} else {
				\call_user_func( array( $this, $func ) );
			}
		}

		\WP_CLI::line( '' );
		\WP_CLI::success( sprintf( __( 'PII data all scrubbed. Memory used: %1$s, time taken: %2$s secs.', 'psycle' ), \size_format( \memory_get_usage() ), \timer_stop() ) );
	}

	/**
	 * This scrubs the wp_users table changing the core fields that WordPress uses.
	 *
	 * @global \wpdb $wpdb The WordPress database abstraction instance.
	 *
	 * @return void
	 */
	private function _core_users_data() {
		global $wpdb;

		// Give everyone the same password. We use User Switching plugin to test as another user.
		$new_password = \wp_hash_password( \wp_generate_password() );

		// Note: Only affects users who don't have a @psycle email address.
		$users_table_update = <<<USERS
UPDATE {$wpdb->users} SET
	user_login = REPLACE( user_login, user_login, CONCAT( 'user-', ID ) ),
	user_nicename = REPLACE( user_nicename, user_nicename, CONCAT( 'user-', ID ) ),
	display_name = REPLACE( display_name, display_name, CONCAT( 'user-', ID ) ),
	user_email = REPLACE( user_email, SUBSTRING_INDEX( user_email, '@', 1 ), CONCAT( 'user-', ID ) ),
	user_pass = '{$new_password}',
	user_url = IF ( user_url <> '', 'http://www.example.org/', user_url )
WHERE user_email NOT LIKE '%@psycle%'

USERS;

		if ( $this->dry_run ) {
			\WP_CLI::line( $users_table_update );
		} else {
			$wpdb->query( $users_table_update ); // Note: unprepared SQL ok due to passing in complete sql. Direct db call ok, not using cache ok, there's no other way.
		}
	}

	/**
	 * This scrubs the wp_usermeta table, replacing all meta_values with a string (almost) the same length made up of repeating 'XXXXX '.
	 *
	 * @param array $extra_meta Extra user meta fields to scrub.
	 * @return void
	 */
	private function _core_usermeta_data( $extra_meta = null ) {

		// These are the fields WordPress uses "out of the box".
		$core_meta = array(
			'first_name',
			'last_name',
			'nickname',
			'description',
		);

		// Core WP contact methods, such as 'aim', 'jabber'. They were removed in 3.6, but plugins might dynamically add new ones.
		$core_meta = array_merge( $core_meta, array_keys( wp_get_user_contact_methods( null ) ) );

		// Merge in any passed extra meta (from the command line).
		if ( ! empty( $extra_meta ) ) {
			// Check for wildcards within meta keys, expand them out to full keys.
			foreach ( $extra_meta as $k => $field ) {
				if ( false !== stripos( $field, '%' ) ) {
					unset( $extra_meta[ $k ] );
					$extra_meta = array_merge( $extra_meta, array_values( $this->_resolve_wildcard_metas( 'usermeta', $field ) ) );
				}
			}

			$all_meta = array_merge( $core_meta, $extra_meta );
		} else {
			$all_meta = $core_meta;
		}

		$this->_scrub_usermeta( $all_meta );
	}

	/**
	 * This scrubs the wp_postmeta table, replacing all meta_values with a string (almost) the same length made up of repeating 'XXXXX '.
	 *
	 * @param array $meta The post meta fields to scrub.
	 * @return void
	 */
	private function _core_postmeta_data( $meta ) {

		// Check for wildcards within meta keys, expand them out to full keys.
		foreach ( $meta as $k => $field ) {
			if ( false !== stripos( $field, '%' ) ) {
				unset( $meta[ $k ] );
				$meta = array_merge( $meta, array_values( $this->_resolve_wildcard_metas( 'postmeta', $field ) ) );
			}
		}

		// Ensures that all passed keys are correctly lowercase alphanumeric with dashes or underscores, no spaces.
		$meta = array_map( 'sanitize_key', $meta );
		$meta = array_unique( array_filter( $meta ) );

		// May not have anything to do.
		if ( ! empty( $meta ) ) {
			$this->_scrub_postmeta( $meta );
		}
	}

	/**
	 * This scrubs the wp_comments table changing the core fields that WordPress uses.
	 *
	 * @global \wpdb $wpdb The WordPress database abstraction instance.
	 *
	 * @return void
	 */
	private function _core_commenters_data() {
		global $wpdb;

		// Note: Only affects commenters who don't have a @psycle email address.
		$comments_table_update = <<<COMMENTS
UPDATE {$wpdb->comments} SET
	comment_author = REPEAT( 'XXXXX ', LENGTH( comment_author ) / 6 ),
	comment_author_email = REPLACE( comment_author_email, SUBSTRING_INDEX( comment_author_email, '@', 1 ), CONCAT( 'user-', user_id ) ),
	comment_author_url = IF ( comment_author_url <> '', 'http://www.example.org/', comment_author_url )
WHERE comment_author_email NOT LIKE '%@psycle%'

COMMENTS;

		if ( $this->dry_run ) {
			\WP_CLI::line( $comments_table_update );
		} else {
			$wpdb->query( $comments_table_update ); // Note: unprepared SQL ok due to passing in complete sql. Direct db call ok, not using cache ok, there's no other way.
		}
	}

	/**
	 * This loops though custom tables scrubbing any column names in the passed data.
	 *
	 * @global \wpdb $wpdb The WordPress database abstraction instance.
	 *
	 * @param array $data An associate array of tablenames and column names to scrub.
	 * @return void
	 */
	private function _custom_table_data( $data ) {
		global $wpdb;

		foreach ( $data as $tablename => $columns ) {
			$tablename = str_replace( $wpdb->prefix, '', $tablename );

			// Check that the custom table first exists.
			$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . $tablename ) ); // Note: Direct db call ok, not using cache ok, there's no other way.
			if ( null === $table_exists ) {
				continue;
			}

			// Grab the existing columns, used later.
			$exist_columns = $wpdb->get_col( "SHOW COLUMNS FROM {$wpdb->prefix}{$tablename}" ); // Note: unprepared SQL ok due to passing in complete sql. Direct db call ok, not using cache ok, there's no other way.

			$set = array();
			foreach ( $columns as $column ) {
				// Check that the column we want exists.
				if ( ! in_array( $column, $exist_columns ) ) {
					continue;
				}
				$column = str_replace( '`', '', $column ); // Break any bad data escaping.

				// Switch based on column name.
				if ( false !== stripos( $column, 'email' ) ) {
					$set[] = "\n `$column` = IF ( `$column` LIKE '%@psycle%', `$column`, REPLACE( `$column`, SUBSTRING_INDEX( `$column`, '@', 1 ), '$column' ) )";

				} elseif ( false !== stripos( $column, 'url' ) ||
							false !== stripos( $column, 'website' ) ) {
					$set[] = "\n `$column` = 'http://www.example.org/'";

				} elseif ( false !== stripos( $column, 'phone' ) ||
							false !== stripos( $column, 'tel' ) ||
							false !== stripos( $column, 'mobile' ) ||
							false !== stripos( $column, 'fax' ) ) {
					$set[] = "\n `$column` = CONCAT( '+44 (0) 555 ', FLOOR( RAND() * 999999 ) )";

				} else {
					$set[] = "\n`$column` = REPEAT( 'XXXXX ', LENGTH( `$column` ) / 6 )";
				}
			} // Loop all columns.

			// Check that there is something to do.
			if ( ! empty( $set ) ) {
				$custom_table_update = "UPDATE {$wpdb->prefix}{$tablename} SET " . implode( ', ', $set ) . "\n";
				if ( $this->dry_run ) {
					\WP_CLI::line( $custom_table_update );
				} else {
					$wpdb->query( $custom_table_update ); // Note: unprepared SQL ok due to passing in complete sql. Direct db call ok, not using cache ok, there's no other way.
				}
			}
		}
	}

	/**
	 * Checks if BuddyPress data exists within the database. This is true if BuddyPress is running or even if it's been active in the past.
	 *
	 * @global \wpdb $wpdb The WordPress database abstraction instance.
	 *
	 * @return boolean
	 */
	private function _has_buddypress() {
		global $wpdb;

		// Tables will exist as long as the plugin hasn't been uninstalled.
		$tables = $wpdb->get_col( "SHOW TABLES LIKE '{$wpdb->prefix}bp_%'" ); // Note: Direct db call ok, not using cache ok, there's no other way.

		return ! empty( $tables );
	}

	/**
	 * This scrubs the wp_bp_xprofile_data table, specifically regarding BuddyPress data.
	 *
	 * @global \wpdb $wpdb The WordPress database abstraction instance.
	 *
	 * @return void
	 */
	private function _plugin_buddypress() {
		global $wpdb;

		$profile_table_update = <<<PROFILEDATA
UPDATE {$wpdb->prefix}bp_xprofile_data SET
	value = REPEAT( 'XXXXX ', LENGTH( value ) / 6 )
WHERE value <> ''
	AND user_id NOT IN ( SELECT ID FROM {$wpdb->users} WHERE user_email LIKE '%@psycle%' )

PROFILEDATA;

		if ( $this->dry_run ) {
			\WP_CLI::line( $profile_table_update );
		} else {
			$wpdb->query( $profile_table_update ); // Note: unprepared SQL ok due to passing in complete sql. Direct db call ok, not using cache ok, there's no other way.
		}
	}

	/**
	 * Checks if WooCommerce data exists within the database. This is true if WooCommerce is running or even if it's been active in the past, as long as it wasn't uninstalled. Uninstalling WooCommerce causes it to remove all its data.
	 *
	 * @global \wpdb $wpdb The WordPress database abstraction instance.
	 *
	 * @return boolean
	 */
	private function _has_woocommerce() {
		global $wpdb;

		// Tables will exist as long as the plugin hasn't been uninstalled.
		$tables = $wpdb->get_col( "SHOW TABLES LIKE '{$wpdb->prefix}woocommerce%'" ); // Note: Direct db call ok, not using cache ok, there's no other way.

		return ! empty( $tables );
	}

	/**
	 * This scrubs the wp_usermeta and wp_postmeta tables, specifically regarding WooCommerce data.
	 *
	 * @global \wpdb $wpdb The WordPress database abstraction instance.
	 *
	 * @return void
	 */
	private function _plugin_woocommerce() {
		global $wpdb;

		$user_meta = array(
			'billing_company',
			'billing_address_1',
			'billing_address_2',
			'billing_city',
			'billing_postcode',
			'billing_state',
			'billing_country',
			'billing_first_name',
			'billing_last_name',
			'billing_email',
			'billing_phone',

			'shipping_company',
			'shipping_address_1',
			'shipping_address_2',
			'shipping_city',
			'shipping_postcode',
			'shipping_state',
			'shipping_country',
			'shipping_first_name',
			'shipping_last_name',
			'shipping_email',
			'shipping_phone',
		);

		// These are the fields WooCommerce uses "out of the box".
		$post_meta = array(
			// These are "core" WooCommerce.
			'_customer_ip_address',
			'_customer_user_agent',

			// These are related to PayPal payments. Note the 'bad' keys as they use uppercase and spaces.
			'Payer PayPal address',
			'Payer first name',
			'Payer last name',
		);

		// User meta is duplicated to each individual order.
		foreach ( $user_meta as $key ) {
			$post_meta[] = '_' . $key;
		}

		$this->_scrub_usermeta( $user_meta );

		$this->_scrub_postmeta( $post_meta );
	}

	/**
	 * Utility function. This searches against a table for matching meta keys to be used in scrubbing.
	 *
	 * @global \wpdb $wpdb The WordPress database abstraction instance.
	 *
	 * @param string $table The table of meta data to search.
	 * @param string $field The meta ket fieldname to match against.
	 * @return array $wildcard_fields
	 */
	private function _resolve_wildcard_metas( $table, $field ) {
		global $wpdb;

		$tablename = $wpdb->$table;
		$wildcard_fields = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT meta_key FROM $tablename WHERE meta_key LIKE %s", $field ) ); // Note: unprepared SQL ok due to tablename. Direct db call ok, not using cache ok, there's no other way.
		return $wildcard_fields;
	}

	/**
	 * Utility function. This scrubs the wp_postmeta table, replacing all non-blank meta_values with either a random
	 * email address to the same domain, an example URL, a 555 random telephone number or a string (almost) the same
	 * length as the original value made up of repeating 'XXXXX '.
	 *
	 * @global \wpdb $wpdb The WordPress database abstraction instance.
	 *
	 * @param array $fields The post meta fields to scrub.
	 * @return void
	 */
	private function _scrub_postmeta( $fields ) {
		global $wpdb;

		// Need to treat email, url and tel fields differently, so split them out.
		$all_fields = array();
		foreach ( $fields as $field ) {
			if ( false !== stripos( $field, 'email' ) ) {
				$all_fields['email'][] = $field;

			} elseif ( false !== stripos( $field, 'url' ) ||
						false !== stripos( $field, 'website' ) ) {
				$all_fields['url'][] = $field;

			} elseif ( false !== stripos( $field, 'phone' ) ||
						false !== stripos( $field, 'tel' ) ||
						false !== stripos( $field, 'mobile' ) ||
						false !== stripos( $field, 'fax' ) ) {
				$all_fields['tel'][] = $field;

			} else {
				$all_fields['other'][] = $field;
			}
		}

		// Loop through the resultant separate field types, setting their new value appropriately.
		foreach ( $all_fields as $type => $fields ) {
			$meta_fields = implode( "', '", $fields );
			$new_meta_value = '';

			switch ( $type ) {
				case 'email' :
					$new_meta_value = "IF ( meta_value LIKE '%@psycle%', meta_value, REPLACE( meta_value, SUBSTRING_INDEX( meta_value, '@', 1 ), CONCAT( meta_key, '-', post_id ) ) )";
					break;

				case 'url' :
					$new_meta_value = "'http://www.example.org/'";
					break;

				case 'tel' :
					$new_meta_value = "CONCAT( '+44 (0) 555 ', FLOOR( RAND() * 999999 ) )";
					break;

				case 'other' :
				default:
					$new_meta_value = "REPEAT( 'XXXXX ', LENGTH( meta_value ) / 6 )";
					break;
			}

			$postmeta_table_update = <<<POSTMETA
UPDATE {$wpdb->postmeta} SET
	meta_value = {$new_meta_value}
WHERE meta_key IN ( '{$meta_fields}' ) AND meta_value <> ''

POSTMETA;

			if ( $this->dry_run ) {
				\WP_CLI::line( $postmeta_table_update );
			} else {
				$wpdb->query( $postmeta_table_update ); // Note: unprepared SQL ok due to passing in complete sql. Direct db call ok, not using cache ok, there's no other way.
			}
		} // Loop the field types
	}

	/**
	 * Utility function. This scrubs the wp_usermeta table, replacing all non-blank meta_values with either a random
	 * email address to the same domain, an example URL, a 555 random telephone number or a string (almost) the same
	 * length as the original value made up of repeating 'XXXXX '.
	 *
	 * @global \wpdb $wpdb The WordPress database abstraction instance.
	 *
	 * @param array $fields The user meta fields to scrub.
	 * @return void
	 */
	private function _scrub_usermeta( $fields ) {
		global $wpdb;

		// Need to treat email, url and tel fields differently, so split them out.
		$all_fields = array();
		foreach ( $fields as $field ) {
			if ( false !== stripos( $field, 'email' ) ) {
				$all_fields['email'][] = $field;

			} elseif ( false !== stripos( $field, 'url' ) ||
						false !== stripos( $field, 'website' ) ) {
				$all_fields['url'][] = $field;

			} elseif ( false !== stripos( $field, 'phone' ) ||
						false !== stripos( $field, 'tel' ) ||
						false !== stripos( $field, 'mobile' ) ||
						false !== stripos( $field, 'fax' ) ) {
				$all_fields['tel'][] = $field;

			} else {
				$all_fields['other'][] = $field;
			}
		}

		// Loop through the resultant separate field types, setting their new value appropriately.
		foreach ( $all_fields as $type => $fields ) {
			$meta_fields = implode( "', '", $fields );
			$new_meta_value = '';

			switch ( $type ) {
				case 'email' :
					$new_meta_value = "IF ( meta_value LIKE '%@psycle%', meta_value, REPLACE( meta_value, SUBSTRING_INDEX( meta_value, '@', 1 ), CONCAT( meta_key, '-', user_id ) ) )";
					break;

				case 'url' :
					$new_meta_value = "'http://www.example.org/'";
					break;

				case 'tel' :
					$new_meta_value = "CONCAT( '+44 (0) 555 ', FLOOR( RAND() * 999999 ) )";
					break;

				case 'other' :
				default:
					$new_meta_value = "REPEAT( 'XXXXX ', LENGTH( meta_value ) / 6 )";
					break;
			}

			// Note: Only affects meta not against users who have a @psycle email address.
			$usermeta_table_update = <<<USERMETA
UPDATE {$wpdb->usermeta} SET
	meta_value = {$new_meta_value}
WHERE meta_key IN ( '{$meta_fields}' ) AND meta_value <> ''
	AND user_id NOT IN ( SELECT ID FROM {$wpdb->users} WHERE user_email LIKE '%@psycle%' )

USERMETA;

			if ( $this->dry_run ) {
				\WP_CLI::line( $usermeta_table_update );
			} else {
				$wpdb->query( $usermeta_table_update ); // Note: unprepared SQL ok due to passing in complete sql. Direct db call ok, not using cache ok, there's no other way.
			}
		} // Loop the field types
	}
}
