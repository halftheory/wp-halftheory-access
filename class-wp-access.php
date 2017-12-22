<?php
/*
Available filters:
wpaccess_shortcode
wpaccess_blocked_message
wpaccess_admin_menu_parent
*/

// Exit if accessed directly.
defined('ABSPATH') || exit;

if (!class_exists('WP_Access')) :
class WP_Access {

	var $blocked_posts = array();

	public function __construct() {
		$this->plugin_name = get_called_class();
		$this->plugin_title = ucwords(str_replace('_', ' ', $this->plugin_name));
		$this->prefix = sanitize_key($this->plugin_name);
		$this->prefix = preg_replace("/[^a-z0-9]/", "", $this->prefix);

		// admin options
		if (!$this->is_front_end()) {
			if (!is_multisite()) {
				add_action('admin_menu', array($this,'admin_menu'));
			}
			else {
				add_action('network_admin_menu', array($this,'admin_menu'));
			}
		}

		// stop if not active
		$active = $this->get_option('active');
		if (empty($active)) {
			return;
		}

		// admin postmeta
		if (!$this->is_front_end()) {
			add_action('add_meta_boxes', array($this,'add_meta_boxes'));
			add_action('save_post', array($this,'save_post'), 10, 3);
		}

		// filters
		$this->shortcode = 'access';
		add_shortcode($this->shortcode, array($this, 'shortcode'));
		if ($this->is_front_end()) {
			add_action('template_redirect', array($this,'template_redirect'), 20);
			add_filter('the_content', array($this,'the_content'), 20);
			add_filter('the_excerpt', array($this,'the_content'), 20);
			add_filter('wp_get_nav_menu_items', array($this,'wp_get_nav_menu_items'));
			add_filter('wp_login_errors', array($this,'wp_login_errors'), 10, 2);
		}
	}

	/* functions-common */

	private function is_front_end() {
		if (function_exists(__FUNCTION__)) {
			$func = __FUNCTION__;
			return $func();
		}
		if (is_admin() && !wp_doing_ajax()) {
			return false;
		}
		if (wp_doing_ajax()) {
			if (!empty($_SERVER["HTTP_REFERER"])) {
				$url_test = $_SERVER["HTTP_REFERER"];
			}
			else {
				$url_test = $this->get_current_uri();
			}
			if (strpos($url_test, admin_url()) !== false) {
				return false;
			}
		}
		return true;
	}

	private function get_current_uri() {
		if (function_exists(__FUNCTION__)) {
			$func = __FUNCTION__;
			return $func();
		}
	 	$res  = is_ssl() ? 'https://' : 'http://';
	 	$res .= $_SERVER['HTTP_HOST'];
	 	$res .= $_SERVER['REQUEST_URI'];
		return $res;
	}

	/* admin */

	public function admin_menu() {
		if (!is_array($GLOBALS['menu'])) {
			return;
		}

		$has_parent = false;
		$parent_name = apply_filters('wpaccess_admin_menu_parent', 'Halftheory');
		$parent_slug = $this->prefix;

		// find top level menu
	    foreach ($GLOBALS['menu'] as $value) {
	    	if ($value[0] == $parent_name) {
	    		$parent_slug = $value[2];
	    		$has_parent = true;
	    		break;
	    	}
	    }

		// add top level menu if it doesn't exist
		if (!$has_parent) {
			add_menu_page(
				$this->plugin_title,
				$parent_name,
				'manage_options',
				$parent_slug,
				__CLASS__ .'::menu_page'
			);
		}

		add_submenu_page(
			$parent_slug,
			$this->plugin_title,
			$this->plugin_title,
			'manage_options',
			$this->prefix,
			__CLASS__ .'::menu_page'
		);
	}

	public function menu_page() {
 		global $title;
		?>
		<div class="wrap">
			<h2><?php echo $title; ?></h2>
		<?php
 		$plugin = new WP_Access();

        if ($_POST['save']) {
        	$save = function() use ($plugin) {
				// verify this came from the our screen and with proper authorization
				if (!isset($_POST[$plugin->plugin_name.'::menu_page'])) {
					return;
				}
				if (!wp_verify_nonce($_POST[$plugin->plugin_name.'::menu_page'], plugin_basename(__FILE__))) {
					return;
				}
				// get values
				$options_arr = $plugin->get_options_array();
				$options = array();
				foreach ($options_arr as $value) {
					$name = $plugin->prefix.'_'.$value;
					if (!isset($_POST[$name])) {
						continue;
					}
					if (empty($_POST[$name])) {
						continue;
					}
					$options[$value] = $_POST[$name];
				}
				// save it
	            $updated = '<div class="updated"><p><strong>Options saved.</strong></p></div>';
	            $error = '<div class="error"><p><strong>Error: There was a problem.</strong></p></div>';
				if (!empty($options)) {
		            if ($plugin->update_option($options)) {
		            	echo $updated;
		            }
		        	else {
		            	echo $error;
		        	}
				}
				else {
		            if ($plugin->delete_option()) {
		            	echo $updated;
		            }
		        	else {
		            	echo $updated;
		        	}
				}
			};
			$save();
        }

		// show the form
		$options_arr = $plugin->get_options_array();
		$options = $plugin->get_option();
		$options = array_merge( array_fill_keys($options_arr, null), (array)$options );
		?>
	    <form id="<?php echo $plugin->prefix; ?>-admin-form" name="<?php echo $plugin->prefix; ?>-admin-form" method="post" action="<?php echo $_SERVER['REQUEST_URI']; ?>">
		<?php
		// Use nonce for verification
		wp_nonce_field(plugin_basename(__FILE__), $plugin->plugin_name.'::'.__FUNCTION__);
		?>
	    <div id="poststuff">

        <p><label for="<?php echo $plugin->prefix; ?>_active"><input type="checkbox" id="<?php echo $plugin->prefix; ?>_active" name="<?php echo $plugin->prefix; ?>_active" value="1"<?php checked($options['active'], 1); ?> /> <?php echo $plugin->plugin_title; ?> active?</label></p>

        <div class="postbox">
        	<div class="inside">
	            <h4><label for="<?php echo $plugin->prefix; ?>_blocked_message">Blocked message defaults</label></h4>
	            <p><span class="description"><?php _e('This message will be shown to blocked users.'); ?> <?php _e('It can be overridden by blocked messages set on individual posts.'); ?></span></p>
	            <textarea rows="3" cols="70" name="<?php echo $plugin->prefix; ?>_blocked_message" id="<?php echo $plugin->prefix; ?>_blocked_message"><?php echo $options['blocked_message']; ?></textarea>
        	</div>
        </div>

        <div class="postbox">
        	<div class="inside">
	            <h4>Allowed Post Types</h4>
	            <p><span class="description"><?php _e('Access rules will only be applied to the following post types.'); ?></span></p>
	            <?php
	            $arr = get_post_types(array('public' => true), 'objects');
	            foreach ($arr as $key => $value) {
					echo '<label style="display: inline-block; width: 50%;"><input type="checkbox" name="'.$plugin->prefix.'_allowed_post_types[]" value="'.$key.'"';
					if (in_array($key, (array)$options['allowed_post_types'])) {
						checked($key, $key);
					}
					echo '> '.$value->label.'</label>';
	            }
	            ?>
        	</div>
        </div>

        <div class="postbox">
        	<div class="inside">
	            <h4>Hidden Roles</h4>
	            <p><span class="description"><?php _e('The following roles will be hidden from post pages.'); ?></span></p>
	            <?php
				global $wp_roles;
				if (!isset($wp_roles)) {
					$wp_roles = new WP_Roles();
				}
				foreach ($wp_roles->role_names as $key => $value) {
					echo '<label style="display: inline-block; width: 50%;"><input type="checkbox" name="'.$plugin->prefix.'_hidden_roles[]" value="'.$key.'"';
					if (in_array($key, (array)$options['hidden_roles'])) {
						checked($key, $key);
					}
					echo '> '.$value.'</label>';
	            }
	            ?>
        	</div>
        </div>

        <p class="submit">
            <input type="submit" value="Update" id="publish" class="button button-primary button-large" name="save">
        </p>

        </div><!-- poststuff -->
    	</form>

		</div><!-- wrap -->
		<?php
	}

	public function add_meta_boxes($post_type) {
		$allowed_post_types = $this->get_option('allowed_post_types');
		if (!in_array($post_type, (array)$allowed_post_types)) {
			return;
		}
		add_meta_box(
			$this->prefix,
			$this->plugin_title,
			array($this, 'add_meta_box'),
			$post_type
		);
	}
	public function add_meta_box() {
		global $post, $wp_roles;

		$postmeta_arr = $this->get_postmeta_array();
		$postmeta = get_post_meta($post->ID, $this->prefix, true);
		$postmeta = array_merge( array_fill_keys($postmeta_arr, null), (array)$postmeta );

		// Use nonce for verification
		wp_nonce_field(plugin_basename(__FILE__), $this->plugin_name.'::'.__FUNCTION__);

		echo '<p>';
		_e('This content is only available to:');
		echo '</p>';

		echo '<p>';
		if (!isset($wp_roles)) {
			$wp_roles = new WP_Roles();
		}
		$hidden_roles = $this->get_option('hidden_roles');
		foreach ($wp_roles->role_names as $role => $name) {
			if (in_array($role, (array)$hidden_roles)) {
				continue;
			}
			echo '<label style="display: inline-block; width: 50%;"><input type="checkbox" name="'.$this->prefix.'_roles[]" value="'.$role.'"';
			if (in_array($role, (array)$postmeta['roles'])) {
				checked($role, $role);
			}
			echo '> '.$name.'</label>';
		}
		echo '</p>';

		echo '<p>';
		echo '<label style="display: inline-block; width: 50%;"><input type="checkbox" name="'.$this->prefix.'_logged_in" value="1"'.checked(1, $postmeta['logged_in'], false).'> '.__('Logged in users').'</label>';
		echo '<label style="display: inline-block; width: 50%;"><input type="checkbox" name="'.$this->prefix.'_logged_out" value="1"'.checked(1, $postmeta['logged_out'], false).'> '.__('Logged out users').'</label>';
		echo '</p>';

		echo '<p>';
		echo '<label><input type="checkbox" name="'.$this->prefix.'_recursive" value="1"'.checked(1, $postmeta['recursive'], false).'> '.__('Apply these access rules to all child posts. This also removes blocked posts from menus.').'</label>';
		echo '</p>';

		$url = network_admin_url('admin.php?page='.$this->prefix);
		echo '<p>Blocked message:<br/>
		<textarea rows="3" cols="70" name="'.$this->prefix.'_blocked_message" id="'.$this->prefix.'_blocked_message">'.$postmeta['blocked_message'].'</textarea>
		<br/><span class="description">'.__('This message will be shown to blocked users.').' It overrides any message that was set <a href="'.esc_url($url).'">here</a>.</span></p>';

		echo '<p>';
		echo '<label><input type="checkbox" name="'.$this->prefix.'_login_redirect" value="1"'.checked(1, $postmeta['login_redirect'], false).'> '.__('Redirect blocked users to the login page.').'</label>';
		echo '</p>';
	}
	public function save_post($post_ID, $post, $update) {
		if ($update === false) {
			return;
		}
		// verify if this is an auto save routine. 
		// If it is our form has not been submitted, so we dont want to do anything
		if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
			return;
		}
		// verify this came from the our screen and with proper authorization
		// because save_post can be triggered at other times
		if (!isset($_POST[$this->plugin_name.'::add_meta_box'])) {
			return;
		}
		if (!wp_verify_nonce($_POST[$this->plugin_name.'::add_meta_box'], plugin_basename(__FILE__))) {
			return;
		}		
		// get values
		$postmeta_arr = $this->get_postmeta_array();
		$postmeta = array();
		foreach ($postmeta_arr as $value) {
			$name = $this->prefix.'_'.$value;
			if (!isset($_POST[$name])) {
				continue;
			}
			if (empty($_POST[$name])) {
				continue;
			}
			$postmeta[$value] = $_POST[$name];
		}
		// save it
		$maybe_revision = wp_is_post_revision($post_ID);
		if ($maybe_revision !== false) {
			$post_ID = $maybe_revision;
		}
		if (!empty($postmeta)) {
			update_post_meta($post_ID, $this->prefix, $postmeta);
		}
		else {
			 delete_post_meta($post_ID, $this->prefix);
		}
	}

	/* shortcode */

	public function shortcode($atts = array(), $content = '', $shortcode = '') {
		$defaults = array(
			'roles' => '',
			'role' => '',
			'username' => '',
			'user_id' => '',
			'logged' => '',
			'blocked_message' => false,
		);
		//$atts = shortcode_atts($defaults, $atts, $this->shortcode); // removes keys not found in defaults
		$atts = array_merge($defaults, (array)$atts);

		$remove_quotes = function($str) {
			return trim($str, "'".'"');
		};
		$atts = array_map($remove_quotes, $atts);

		// roles dominates role
		if (empty($atts['roles']) && !empty($atts['role'])) {
			$atts['roles'] = $atts['role'];
		}
		unset($atts['role']);

		if ($this->is_blocked($atts)) {
			$content = $this->blocked_message($atts['blocked_message']);
		}
		if (!empty($content)) {
			// apply all content filters before and including do_shortcode (priority 11)
			$content = $this->apply_filters_the_content($content, null, 'do_shortcode');
		}
		return apply_filters('wpaccess_shortcode', $content);
	}

	/* actions + filters */

	public function template_redirect() {
		if (is_404()) {
			return;
		}
		if (is_search()) {
			return;
		}
		list($post_ID, $post) = $this->get_post_ID_post();

		$allowed_post_types = $this->get_option('allowed_post_types');
		if (!in_array($post->post_type, (array)$allowed_post_types)) {
			return;
		}

		// current post
		$postmeta = $this->post_has_access_rules($post_ID);
		if ($postmeta !== false) {
			if ($this->is_blocked($postmeta)) {
				$this->blocked_posts[$post_ID] = $postmeta;
				remove_all_filters('template_include'); // some plugins (bbpress) use template_include to completely bypass the_content
				if ($this->post_has_login_redirect($postmeta)) {
					return $this->template_redirect_blocked_message($postmeta);
				}
				return;
			}
		}

		// recursive ancestors
		$ancestors = $this->get_ancestors($post_ID, $post->post_type);
		if (!empty($ancestors)) {
			foreach ($ancestors as $value) {
				$postmeta = $this->post_has_recursive_access_rules($value);
				if ($postmeta !== false) {
					if ($this->is_blocked($postmeta)) {
						$this->blocked_posts[$value] = $postmeta;
						remove_all_filters('template_include');
						if ($this->post_has_login_redirect($postmeta)) {
							return $this->template_redirect_blocked_message($postmeta);
						}
						return;
					}
				}
			}
		}
		return;
	}

	public function the_content($str = '') {
		list($post_ID, $post) = $this->get_post_ID_post();

		$allowed_post_types = $this->get_option('allowed_post_types');
		if (!in_array($post->post_type, (array)$allowed_post_types)) {
			return $str;
		}

		// return function
		$the_content_blocked_message = function($blocked_message = '') {
			$str = $this->blocked_message($blocked_message);
			$str = $this->apply_filters_the_content($str, array($this, 'the_content'));
			return $str;
		};

		// current post
		// check saved
		if (array_key_exists($post_ID, $this->blocked_posts)) {
			return $the_content_blocked_message($this->blocked_posts[$post_ID]['blocked_message']);
		}
		$postmeta = $this->post_has_access_rules($post_ID);
		if ($postmeta !== false) {
			if ($this->is_blocked($postmeta)) {
				$this->blocked_posts[$post_ID] = $postmeta;
				return $the_content_blocked_message($postmeta['blocked_message']);
			}
		}

		// recursive ancestors
		$ancestors = $this->get_ancestors($post_ID, $post->post_type);
		if (!empty($ancestors)) {
			foreach ($ancestors as $value) {
				// check saved
				if (array_key_exists($value, $this->blocked_posts)) {
					return $the_content_blocked_message($this->blocked_posts[$value]['blocked_message']);
				}
				$postmeta = $this->post_has_recursive_access_rules($value);
				if ($postmeta !== false) {
					if ($this->is_blocked($postmeta)) {
						$this->blocked_posts[$value] = $postmeta;
						return $the_content_blocked_message($postmeta['blocked_message']);
					}
				}
			}
		}
		return $str;
	}

	public function wp_get_nav_menu_items($items) {
        $showItems = [];
        foreach ($items as $key => $item) {
        	$post_ID = $item->object_id;
			$postmeta = $this->post_has_recursive_access_rules($post_ID);
			if ($postmeta !== false) {
				// check saved
				if (array_key_exists($post_ID, $this->blocked_posts)) {
					continue;
				}
				elseif ($this->is_blocked($postmeta)) {
					$this->blocked_posts[$post_ID] = $postmeta;
					continue;
				}
			}
			$ancestors = get_ancestors($post_ID, $item->object);
			if (!empty($ancestors)) {
				$blocked = false;
				foreach ($ancestors as $value) {
					$postmeta = $this->post_has_recursive_access_rules($value);
					if ($postmeta !== false) {
						// check saved
						if (array_key_exists($value, $this->blocked_posts)) {
							$blocked = true;
							break;
						}
						elseif ($this->is_blocked($postmeta)) {
							$this->blocked_posts[$value] = $postmeta;
							$blocked = true;
							break;
						}
					}
				}
				if ($blocked) {
					continue;
				}
			}
            $showItems[$key] = $item;
        }
        return $showItems;
    }

	public function wp_login_errors($errors, $redirect_to) {
		$cookie_name = $this->prefix.'::'.__FUNCTION__;
		if (!isset($_COOKIE[$cookie_name])) {
			return $errors;
		}
		if (!is_object($errors)) {
			$errors = new WP_Error();
		}
		$str = array($_COOKIE[$cookie_name]);
		$referer = wp_get_referer();
		if (!empty($referer)) {
			$str[] = '<a href="'.esc_url($referer).'">'.__('Go back to the previous page.').'</a>';
		}
		$str = array_map('trim', $str);
		$str = array_filter($str);
		$str = nl2br(implode("\n", $str));
		$errors->add($this->prefix, $str, 'message');
		if (!headers_sent()) {
			setcookie($cookie_name, null, time() - 3600, COOKIEPATH);
		}
		return $errors;
	}

    /* functions */

	private function make_array($str = '') {
		if (is_array($str)) {
			return $str;
		}
		if (empty($str)) {
			return array();
		}
		$arr = explode(",", $str);
		$arr = array_map('trim', $arr);
		$arr = array_filter($arr);
		return $arr;
	}

	private function get_option($key = '') {
		if (!isset($this->option)) {
			if (is_multisite()) {
				$option = get_site_option($this->prefix, array());
			}
			else {
				$option = get_option($this->prefix, array());
			}
			$this->option = $option;
		}
		if (!empty($key)) {
			if (array_key_exists($key, $this->option)) {
				return $this->option[$key];
			}
			return false;
		}
		return $this->option;
	}
	private function update_option($option) {
		if (is_multisite()) {
			$bool = update_site_option($this->prefix, $option);
		}
		else {
			$bool = update_option($this->prefix, $option);
		}
		if ($bool !== false) {
			$this->option = $option;
		}
		return $bool;
	}
	private function delete_option() {
		if (is_multisite()) {
			$bool = delete_site_option($this->prefix);
		}
		else {
			$bool = delete_option($this->prefix);
		}
		if ($bool !== false && isset($this->option)) {
			unset($this->option);
		}
		return $bool;
	}

    private function get_options_array() {
		return array(
			'active',
			'blocked_message',
			'allowed_post_types',
			'hidden_roles',
		);
    }
    private function get_postmeta_array() {
		return array(
			'roles',
			'logged_in',
			'logged_out',
			'recursive',
			'blocked_message',
			'login_redirect',
		);
    }

	private function get_post_ID_post() {
		global $post;
		$post_ID = $post->ID;

		if (empty($post_ID) && is_singular()) {
			// some plugins (buddypress) hide the real post_id in queried_object_id
			global $wp_query;
			if (isset($wp_query->queried_object_id)) {
				if (!empty($wp_query->queried_object_id)) {
					$post_ID = $wp_query->queried_object_id;
					if (isset($wp_query->queried_object)) {
						$post = $wp_query->queried_object;
					}
				}
			}
		}
		return array($post_ID, $post);
	}
	private function get_ancestors($post_ID, $post_type = 'page') {
		$ancestors = array();

		// maybe find an ancestor
		if (empty($post_ID)) {
			global $wp_query;
			if (strpos($wp_query->query['pagename'], '/') !== false) {
				$post_good = false;
				$slugs = explode('/', $wp_query->query['pagename']);
				$slug = '';
				foreach ($slugs as $key => $value) {
					$slug .= '/'.$value;
					$post_tmp = get_page_by_path($slug);
					if (!empty($post_tmp)) {
						$post_good = $post_tmp;
					}
					elseif (empty($post_tmp) && !empty($post_good)) {
						break;
					}
				}
				if (!empty($post_good)) {
					$ancestors[] = $post_good->ID;
				}
			}
		}

		if (empty($ancestors)) {
			$ancestors = get_ancestors($post_ID, $post_type);
		}
		return $ancestors;
	}

	private function post_has_access_rules($post_ID) {
		$postmeta = get_post_meta($post_ID, $this->prefix, true);
		if (empty($postmeta)) {
			return false;
		}
		$postmeta_arr = array(
			'roles',
			'logged_in',
			'logged_out',
		);
		foreach ($postmeta_arr as $value) {
			if (isset($postmeta[$value])) {
				if (!empty($postmeta[$value])) {
					return $postmeta;
				}
			}
		}
		return false;
	}
	private function post_has_recursive_access_rules($post_ID) {
		$postmeta = $this->post_has_access_rules($post_ID, $login_redirect);
		if ($postmeta !== false) {
			if (isset($postmeta['recursive'])) {
				if (!empty($postmeta['recursive'])) {
					return $postmeta;
				}
			}
		}
		return false;
	}
	private function post_has_login_redirect($postmeta = array()) {
		if (isset($postmeta['login_redirect'])) {
			if (!empty($postmeta['login_redirect'])) {
				return true;
			}
		}
		return false;
	}

	private function blocked_message($str = '') {
		if (empty($str)) {
			$blocked_message = $this->get_option('blocked_message');
			if (!empty($blocked_message)) {
				$str = $blocked_message;
			}
		}
		return apply_filters('wpaccess_blocked_message', $str);
	}
	private function template_redirect_blocked_message($postmeta = array()) {
		if (headers_sent()) {
			return;
		}
		$str = $this->blocked_message($postmeta['blocked_message']);
		// save the message in a cookie
		if (!empty($str)) {
			$cookie_name = $this->prefix.'::wp_login_errors';
			setcookie($cookie_name, $str, time() + (24 * 3600), COOKIEPATH);
		}
		$login_redirect = wp_login_url();
		$login_redirect = add_query_arg(array('redirect_to' => $this->get_current_uri()), $login_redirect);
		wp_redirect($login_redirect);
		exit();
	}

	private function is_blocked($postmeta = array()) {
		$postmeta_arr = $this->get_postmeta_array();
		$shortcode_arr = array(
			'username',
			'user_id',
			'logged',
		);
		$postmeta = array_merge( array_fill_keys($postmeta_arr, null),  array_fill_keys($shortcode_arr, null), (array)$postmeta );

		if (!empty($postmeta['roles']) || !empty($postmeta['username']) || !empty($postmeta['user_id'])) {
			// check logged in
			if (!is_user_logged_in()) {
				return true;
			}
			// check user role
			if (!empty($postmeta['roles'])) {
				$postmeta['roles'] = $this->make_array($postmeta['roles']);
				if (!$this->has_role($postmeta['roles'])) {
					return true;
				}
			}
			global $current_user;
			// check username
			if (!empty($postmeta['username'])) {
				$postmeta['username'] = $this->make_array($postmeta['username']);
				if (!in_array($current_user->user_login, $postmeta['username'])) {
					return true;
				}
			}
			// check user id
			if (!empty($postmeta['user_id'])) {
				$postmeta['user_id'] = $this->make_array($postmeta['user_id']);
				if (!in_array($current_user->ID, $postmeta['user_id'])) {
					return true;
				}		
			}
		}

		// logged in
		if (!empty($postmeta['logged_in']) || $postmeta['logged'] == 'in') {
			if (!is_user_logged_in()) {
				return true;
			}
		}
		// logged out
		if (!empty($postmeta['logged_out']) || $postmeta['logged'] == 'out') {
			if (is_user_logged_in()) {
				return true;
			}
		}
		return false;
	}

	private function has_role($roles, $user_id = null) {
		if (is_numeric($user_id)) {
			$user = get_userdata($user_id);
		}
		elseif (is_user_logged_in()) {
			global $current_user;
			$user = $current_user;
			$user_id = $current_user->ID;
		}
		if (empty($user)) {
			return false;
		}
		if (!is_array($roles)) {
			$roles = $this->make_array($roles);
		}
		foreach ($roles as $role) {
			if (is_multisite() && $role == 'super_admin' && is_super_admin($user_id)) {
				return true;
			}
			if (isset($user->caps)) {
				if (is_array($user->caps) && isset($user->caps[$role])) {
					return true;
				}
			}
			if (isset($user->roles)) {
				if (is_array($user->roles) && in_array($role, $user->roles)) {
					return true;
				}
			}
		}
		return false;
	}

	private function apply_filters_the_content($str = '', $break_before = null, $break_after = null) {
		if (empty($str)) {
			return $str;
		}
		global $wp_filter;
		if (!isset($wp_filter['the_content'])) {
			return $str;
		}
		if (!is_object($wp_filter['the_content'])) {
			return $str;
		}
		if (empty($wp_filter['the_content']->callbacks)) {
			return $str;
		}
	 	foreach ($wp_filter['the_content']->callbacks as $priority => $filters) {
	 		foreach ($filters as $key => $filter) {
	 			if (!isset($filter['function'])) {
	 				continue;
	 			}
	 			if (!empty($break_before)) {
		 			if (is_string($break_before) && is_string($filter['function']) && $break_before == $filter['function']) {
	 					return $str;
		 			}
			 		elseif (is_array($break_before) && is_array($filter['function']) && $break_before == $filter['function']) {
	 					return $str;
			 		}
	 			}
	 			if (is_string($filter['function'])) {
	 				$func = $filter['function'];
	 				$str = $func($str);
	 			}
	 			elseif (is_array($filter['function'])) {
	 				$func0 = $filter['function'][0];
	 				$func1 = $filter['function'][1];
	 				$str = $func0->$func1($str);
	 			}
	 			if (!empty($break_after)) {
		 			if (is_string($break_after) && is_string($filter['function']) && $break_after == $filter['function']) {
	 					return $str;
		 			}
			 		elseif (is_array($break_after) && is_array($filter['function']) && $break_after == $filter['function']) {
	 					return $str;
			 		}
	 			}
	 		}
	 	}
	 	return $str;
	}

}
endif;
?>