# WP_CLI - PII Data Scrub

* Contributors: psycle
* Tags: psycle
* Requires at least: 3.8
* Tested up to: 4.3.1
* Stable tag: 2.0.1

## Description

WP_CLI command to scrub PII data from a WordPress database. Calling "wp pii-scrub" will automatically detect major plugins (BuddyPress, WooCommerce) and scrub/replace any PII (personally identifiable information) so that a database compromise doesn't cause issues. Additional custom meta data to check/scrub can be defined at runtime.

## Installation

Generally follow the normal procedure for WordPress plugins.

1. Upload the directory `psycle-pii-scrub` to the `/wp-content/plugins/` directory (or wherever that directory may be)
1. Activate the plugin through the 'Plugins' menu in WordPress.
1. Alternatively the directory can be placed within `/wp-content/mu-plugins/` and the psycle-pii-scrub.php file can be copied/moved up a directory.

## Changelog

### 2.0.1
* Update to composer.json to mark as install as MU plugin

### 2.0
* Removal of To Do/Ideas from README.md as they are now within the Gitlab project.
* Dry run ability added. Add --dryrun flag and the resultant changes won't be made, instead the database queries will be outputted.
* Detection of field type from field/column name on wp_usermeta, wp_postmeta and custom tables. Switches from using 'XXXXX ' to instead one of the following:
  * For Email addresses scrubs the first part (before the @) leaving the domain.
  * For URLs replaces with 'http://www.example.org/'.
  * For telephone/mobile/fax numbers replaces with a randomised number starting '+44 (0) 555 '.
* Corrected missed fields from WooCommerce dataset scrub, 'shipping_email' and 'shipping_phone'.
* Scrubs for 'user_url' in wp_users and 'comment_author_url' in wp_comments only occur if those fields are not already blank.
* Checks within customtablefields for existence of table and column names, excludes those not known.
* Add explanation text for use of --customtablefields that it needs enclosing with single quotes '.

### 1.1
* It's possible the command might be run on a site that doesn't follow the Psycle standard layout for wp-config.php. Detect this and exit.

### 1.0
* Initial version of plugin.

