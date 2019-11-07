<?php
/*
Plugin Name: Half/theory WP Access
Plugin URI: https://github.com/halftheory/wp-halftheory-access
GitHub Plugin URI: https://github.com/halftheory/wp-halftheory-access
Description: WP Access
Author: Half/theory
Author URI: https://github.com/halftheory
Version: 2.0
Network: true
*/

/*
Available filters:
wpaccess_deactivation(string $db_prefix, class $subclass)
wpaccess_uninstall(string $db_prefix, class $subclass)
*/

// Exit if accessed directly.
defined('ABSPATH') || exit;

if (!class_exists('WP_Access_Plugin')) :
final class WP_Access_Plugin {

	public function __construct() {
		@include_once(dirname(__FILE__).'/class-wp-access.php');
		if (class_exists('WP_Access')) {
			$this->subclass = new WP_Access(plugin_basename(__FILE__), '', true);
		}
	}

	public static function init() {
		$plugin = new self;
		return $plugin;
	}

	public static function activation() {
		$plugin = new self;
		return $plugin;
	}

	public static function deactivation() {
		$plugin = new self;
		if ($plugin->subclass) {
			$plugin->subclass->delete_transient_uninstall();
			apply_filters('wpaccess_deactivation', $plugin->subclass::$prefix, $plugin->subclass);
		}
		return;
	}

	public static function uninstall() {
		$plugin = new self;
		if ($plugin->subclass) {
			$plugin->subclass->delete_transient_uninstall();
			$plugin->subclass->delete_postmeta_uninstall();
			$plugin->subclass->delete_option_uninstall();
			apply_filters('wpaccess_uninstall', $plugin->subclass::$prefix, $plugin->subclass);
		}
		return;
	}

}
// Load the plugin.
add_action('init', array('WP_Access_Plugin', 'init'));
endif;

register_activation_hook(__FILE__, array('WP_Access_Plugin', 'activation'));
register_deactivation_hook(__FILE__, array('WP_Access_Plugin', 'deactivation'));
if (!function_exists('WP_Access_Plugin_uninstall')) {
	function WP_Access_Plugin_uninstall() {
		WP_Access_Plugin::uninstall();
	}
}
register_uninstall_hook(__FILE__, 'WP_Access_Plugin_uninstall');
?>