<?php
/*
Available filters:
wpaccess_shortcode
wpaccess_blocked_message
*/

// Exit if accessed directly.
defined('ABSPATH') || exit;

if (!class_exists('Halftheory_Helper_Plugin')) {
	@include_once(dirname(__FILE__).'/class-halftheory-helper-plugin.php');
}

if (!class_exists('WP_Access') && class_exists('Halftheory_Helper_Plugin')) :
final class WP_Access extends Halftheory_Helper_Plugin {

	public static $plugin_basename;
	public static $prefix;
	public static $active = false;
	public static $blocked_posts = array();

	/* setup */

	public function init($plugin_basename = '', $prefix = '') {
		parent::init($plugin_basename, $prefix);
		self::$active = $this->get_option(static::$prefix, 'active', false);
	}

	protected function setup_actions() {
		parent::setup_actions();

		// stop if not active
		if (empty(self::$active)) {
			return;
		}

		// admin postmeta
		if (!$this->is_front_end()) {
			add_action('add_meta_boxes', array($this,'add_meta_boxes'));
			add_action('save_post', array($this,'save_post'), 10, 3);
		}

		// filters
		$this->shortcode = 'access';
		if (!shortcode_exists($this->shortcode)) {
			add_shortcode($this->shortcode, array($this,'shortcode'));
		}
		if ($this->is_front_end()) {
			add_action('template_redirect', array($this,'template_redirect'), 20);
			$func = function() {
				add_action('pre_get_posts', array($this,'pre_get_posts'), 20);
				add_filter('posts_results', array($this,'the_posts'), 20, 2);
				add_filter('the_posts', array($this,'the_posts'), 20, 2);
			};
			if (wp_doing_ajax()) {
				$func();
			}
			else {
				add_action('template_redirect', $func, 30);
			}
			add_filter('the_content', array($this,'the_content_wpautop'), 9);
			add_filter('the_content', array($this,'the_content'), 20);
			add_filter('the_excerpt', array($this,'the_content'), 20);
			add_filter('wp_get_nav_menu_items', array($this,'wp_get_nav_menu_items'));
			add_filter('wp_login_errors', array($this,'wp_login_errors'), 10, 2);
		}
	}

	/* admin */

	public function menu_page() {
 		global $title;
		?>
		<div class="wrap">
			<h2><?php echo $title; ?></h2>
		<?php
 		$plugin = new static(static::$plugin_basename, static::$prefix, false);

		if ($plugin->save_menu_page()) {
        	$save = function() use ($plugin) {
				// get values
				$options_arr = $plugin->get_options_array();
				$options = array();
				foreach ($options_arr as $value) {
					$name = $plugin::$prefix.'_'.$value;
					if (!isset($_POST[$name])) {
						continue;
					}
					if ($this->empty_notzero($_POST[$name])) {
						continue;
					}
					$options[$value] = $_POST[$name];
				}
				// save it
	            $updated = '<div class="updated"><p><strong>Options saved.</strong></p></div>';
	            $error = '<div class="error"><p><strong>Error: There was a problem.</strong></p></div>';
				if (!empty($options)) {
		            if ($plugin->update_option($plugin::$prefix, $options)) {
		            	echo $updated;
		            }
		        	else {
		        		// were there changes?
		        		$options_old = $plugin->get_option($plugin::$prefix, null, array());
		        		ksort($options_old);
		        		ksort($options);
		        		if ($options_old !== $options) {
		            		echo $error;
		            	}
		            	else {
			            	echo $updated;
		            	}
		        	}
				}
				else {
		            if ($plugin->delete_option($plugin::$prefix)) {
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
		$options = $plugin->get_option($plugin::$prefix, null, array());
		$options = array_merge( array_fill_keys($options_arr, null), $options );
		?>
	    <form id="<?php echo $plugin::$prefix; ?>-admin-form" name="<?php echo $plugin::$prefix; ?>-admin-form" method="post" action="<?php echo $_SERVER['REQUEST_URI']; ?>">
		<?php
		// Use nonce for verification
		wp_nonce_field($plugin::$plugin_basename, $plugin->plugin_name.'::'.__FUNCTION__);
		?>
	    <div id="poststuff">

        <p><label for="<?php echo $plugin::$prefix; ?>_active"><input type="checkbox" id="<?php echo $plugin::$prefix; ?>_active" name="<?php echo $plugin::$prefix; ?>_active" value="1"<?php checked($options['active'], 1); ?> /> <?php echo $plugin->plugin_title; ?> <?php _e('active?'); ?></label></p>

        <div class="postbox">
        	<div class="inside">
	            <h4><label for="<?php echo $plugin::$prefix; ?>_blocked_message"><?php _e('Blocked message defaults'); ?></label></h4>
	            <p><span class="description"><?php _e('This message will be shown to blocked users.'); ?> <?php _e('It can be overridden by blocked messages set on individual posts.'); ?></span></p>
	            <textarea rows="3" cols="70" name="<?php echo $plugin::$prefix; ?>_blocked_message" id="<?php echo $plugin::$prefix; ?>_blocked_message"><?php echo $options['blocked_message']; ?></textarea>
        	</div>
        </div>

        <div class="postbox">
        	<div class="inside">
	            <h4><?php _e('Allowed Post Types'); ?></h4>
	            <p><span class="description"><?php _e('Access rules will only be applied to the following post types.'); ?></span></p>
	            <?php
	            $post_types = array();
	            $arr = get_post_types(array('public' => true), 'objects');
	            foreach ($arr as $key => $value) {
	            	$post_types[$key] = $value->label;
	            }
	            $options['allowed_post_types'] = $plugin->make_array($options['allowed_post_types']);
	            foreach ($post_types as $key => $value) {
					echo '<label style="display: inline-block; width: 50%;"><input type="checkbox" name="'.$plugin::$prefix.'_allowed_post_types[]" value="'.$key.'"';
					if (in_array($key, $options['allowed_post_types'])) {
						checked($key, $key);
					}
					echo '> '.$value.'</label>';
	            }
	            ?>
        	</div>
        </div>

        <div class="postbox">
        	<div class="inside">
	            <h4><?php _e('Hidden Roles'); ?></h4>
	            <p><span class="description"><?php _e('The following roles will be hidden from post pages.'); ?></span></p>
	            <?php
				global $wp_roles;
				if (!isset($wp_roles)) {
					$wp_roles = new WP_Roles();
				}
				$options['hidden_roles'] = $plugin->make_array($options['hidden_roles']);
				foreach ($wp_roles->role_names as $key => $value) {
					echo '<label style="display: inline-block; width: 50%;"><input type="checkbox" name="'.$plugin::$prefix.'_hidden_roles[]" value="'.$key.'"';
					if (in_array($key, $options['hidden_roles'])) {
						checked($key, $key);
					}
					echo '> '.$value.'</label>';
	            }
	            ?>
        	</div>
        </div>

        <?php submit_button(__('Update'), array('primary','large'), 'save'); ?>

        </div><!-- poststuff -->
    	</form>

		</div><!-- wrap -->
		<?php
	}

	public function add_meta_boxes($post_type) {
		$allowed_post_types = $this->get_option(static::$prefix, 'allowed_post_types', array());
		if (!in_array($post_type, $allowed_post_types)) {
			return;
		}
		add_meta_box(
			static::$prefix,
			$this->plugin_title,
			array($this, 'add_meta_box'),
			$post_type
		);
	}

	public function add_meta_box() {
		global $post, $wp_roles;

		$postmeta_arr = $this->get_postmeta_array();
		$postmeta = get_post_meta($post->ID, static::$prefix, true);
		$postmeta = array_merge( array_fill_keys($postmeta_arr, null), $this->make_array($postmeta) );

		// Use nonce for verification
		wp_nonce_field(static::$plugin_basename, $this->plugin_name.'::'.__FUNCTION__);

		echo '<p>';
		_e('This content is only available to:');
		echo '</p>';

		echo '<p>';
		if (!isset($wp_roles)) {
			$wp_roles = new WP_Roles();
		}
		$hidden_roles = $this->get_option(static::$prefix, 'hidden_roles', array());
		$postmeta['roles'] = $this->make_array($postmeta['roles']);
		foreach ($wp_roles->role_names as $role => $name) {
			if (in_array($role, $hidden_roles)) {
				continue;
			}
			echo '<label style="display: inline-block; width: 50%;"><input type="checkbox" name="'.static::$prefix.'_roles[]" value="'.$role.'"';
			if (in_array($role, $postmeta['roles'])) {
				checked($role, $role);
			}
			echo '> '.$name.'</label>';
		}
		echo '</p>';

		echo '<p>';
		echo '<label style="display: inline-block; width: 50%;"><input type="checkbox" name="'.static::$prefix.'_logged_in" value="1"'.checked(1, $postmeta['logged_in'], false).'> '.__('Logged in users').'</label>';
		echo '<label style="display: inline-block; width: 50%;"><input type="checkbox" name="'.static::$prefix.'_logged_out" value="1"'.checked(1, $postmeta['logged_out'], false).'> '.__('Logged out users').'</label>';
		echo '</p>';

		echo '<p>';
		echo '<label><input type="checkbox" name="'.static::$prefix.'_recursive" value="1"'.checked(1, $postmeta['recursive'], false).'> '.__('Apply these access rules to all child posts. This also removes blocked posts from menus.').'</label>';
		echo '</p>';

		$url = network_admin_url('admin.php?page='.static::$prefix);
		echo '<p>Blocked message:<br/>
		<textarea rows="3" cols="70" name="'.static::$prefix.'_blocked_message" id="'.static::$prefix.'_blocked_message">'.$postmeta['blocked_message'].'</textarea>
		<br/><span class="description">'.__('This message will be shown to blocked users.').' It overrides any message that was set <a href="'.esc_url($url).'">here</a>.</span></p>';

		echo '<p>';
		echo '<label><input type="checkbox" name="'.static::$prefix.'_login_redirect" value="1"'.checked(1, $postmeta['login_redirect'], false).'> '.__('Redirect blocked users to the login page.').'</label>';
		echo '</p>';
	}

	public function save_post($post_ID, $post, $update) {
		// verify if this is an auto save routine. 
		// If it is our form has not been submitted, so we dont want to do anything
		if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
			return;
		}
    	if (empty($update)) {
    		return;
    	}
		// verify this came from the our screen and with proper authorization
		// because save_post can be triggered at other times
		if (isset($_POST)) {
			if (isset($_POST[$this->plugin_name.'::add_meta_box'])) {
				if (wp_verify_nonce($_POST[$this->plugin_name.'::add_meta_box'], static::$plugin_basename)) {
					// get values
					$postmeta_arr = $this->get_postmeta_array();
					$postmeta = array();
					foreach ($postmeta_arr as $value) {
						$name = static::$prefix.'_'.$value;
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
						update_post_meta($post_ID, static::$prefix, $postmeta);
					}
					else {
						 delete_post_meta($post_ID, static::$prefix);
					}
				}
			}
		}
	}

	/* shortcode */

	public function shortcode($atts = array(), $content = '', $shortcode = '') {
		$atts = $this->make_array($atts);
		// roles dominates role
		if (!isset($atts['roles']) && isset($atts['role'])) {
			$atts['roles'] = $atts['role'];
		}
		if (isset($atts['role'])) {
			unset($atts['role']);
		}
		$defaults = array(
			'roles' => '',
			'username' => '',
			'user_id' => '',
			'logged' => '',
			'blocked_message' => '',
		);
		// removes keys not found in defaults
		$atts = shortcode_atts($defaults, $atts, $this->shortcode);
		// resolve user input
		if (!empty($atts)) {
			$trim_quotes = function($str) use (&$trim_quotes) {
				if (is_string($str)) {
					$str = trim($str, " '".'"');
				}
				elseif (is_array($str)) {
					$str = array_map($trim_quotes, $str);
				}
				return $str;
			};
			$atts = array_map($trim_quotes, $atts);
			if (isset($atts['roles'])) {
				$atts['roles'] = $this->make_array($atts['roles']);
			}
			if (isset($atts['username'])) {
				$atts['username'] = $this->make_array($atts['username']);
			}
			if (isset($atts['user_id'])) {
				$atts['user_id'] = $this->make_array($atts['user_id']);
			}
		}
		$atts = array_filter($atts);

		if ($this->is_blocked($atts)) {
			$content = $atts['blocked_message'];
		}
		if (!empty($content)) {
			// apply all content filters before and including do_shortcode (priority 11)
			$content = $this->apply_filters_the_content($content, null, 'do_shortcode');
		}
		return apply_filters('wpaccess_shortcode', $content);
	}

	/* actions */

	public function template_redirect() {
		if (is_404()) {
			return;
		}
		if (is_search()) {
			return;
		}
		list($post_ID, $post) = $this->get_post_ID_post();

		$allowed_post_types = $this->get_option(static::$prefix, 'allowed_post_types', array());
		if (!in_array($post->post_type, $allowed_post_types)) {
			return;
		}

		// current post
		$postmeta = $this->post_has_access_rules($post_ID);
		if ($postmeta !== false) {
			if ($this->is_blocked($postmeta)) {
				self::$blocked_posts[$post_ID] = $postmeta;
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
						self::$blocked_posts[$value] = $postmeta;
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

	public function pre_get_posts($wp_query) {
		if (empty(self::$blocked_posts)) {
			return;
		}
		$wp_query->set('post__not_in', array_keys(self::$blocked_posts));
		$post_parent__not_in = array();
		foreach (self::$blocked_posts as $key => $value) {
			$postmeta = $this->post_has_recursive_access_rules($key, $value);
			if ($postmeta !== false) {
				$post_parent__not_in[] = $key;
			}
		}
		if (!empty($post_parent__not_in)) {
			$wp_query->set('post_parent__not_in', $post_parent__not_in);
		}
	}

	public function the_posts($posts, $wp_query) {
		if (empty($posts)) {
			return $posts;
		}

		$i = count($posts);
		$allowed_post_types = $this->get_option(static::$prefix, 'allowed_post_types', array());

		foreach ($posts as $key => $post) {
			if (!in_array($post->post_type, $allowed_post_types)) {
				continue;
			}
			$post_ID = $post->ID;
			// current post
			// check saved
			if (array_key_exists($post_ID, self::$blocked_posts)) {
				unset($posts[$key]);
				continue;
			}
			$postmeta = $this->post_has_access_rules($post_ID);
			if ($postmeta !== false) {
				if ($this->is_blocked($postmeta)) {
					self::$blocked_posts[$post_ID] = $postmeta;
					unset($posts[$key]);
					continue;
				}
			}
			// recursive ancestors
			$ancestors = $this->get_ancestors($post_ID, $post->post_type);
			if (!empty($ancestors)) {
				foreach ($ancestors as $value) {
					// check saved
					if (array_key_exists($value, self::$blocked_posts)) {
						$postmeta = $this->post_has_recursive_access_rules($value, self::$blocked_posts[$value]);
						if ($postmeta !== false) {
							unset($posts[$key]);
							break;
						}
						continue;
					}
					$postmeta = $this->post_has_recursive_access_rules($value);
					if ($postmeta !== false) {
						if ($this->is_blocked($postmeta)) {
							self::$blocked_posts[$value] = $postmeta;
							unset($posts[$key]);
							break;
						}
					}
				}
			}
		}

		if ($i != count($posts)) {
			$posts = array_combine( array_keys(array_fill(0, count($posts), null)), $posts );
			$wp_query->found_posts = count($posts);
		}
		return $posts;
	}

	public function the_content_wpautop($str = '') {
		if (!has_shortcode($str, $this->shortcode)) {
			return $str;
		}
		// because as of April 2018 we don't trust wpautop + shortcode_unautop
		// remove space before + after shortcode
		$str = preg_replace("/[\n\r\t ]*(\[".$this->shortcode.")/is", "$1", $str);
		$str = preg_replace("/(\[\/".$this->shortcode."\])[\n\r\t ]*/is", "$1", $str);
		add_filter('the_content', array($this,'the_content_shortcode_unautop'));
		return $str;
	}
	public function the_content_shortcode_unautop($str = '') {
		// remove p/br before + after shortcode
		$str = preg_replace("/(<p>|<br \/>)(\[".$this->shortcode.")/is", "$2", $str);
		$str = preg_replace("/(\[\/".$this->shortcode."\])(<\/p>|<br \/>)/is", "$1", $str);
		remove_filter('the_content', array($this,'the_content_shortcode_unautop'));
		return $str;
	}

	public function the_content($str = '') {
		list($post_ID, $post) = $this->get_post_ID_post();

		$allowed_post_types = $this->get_option(static::$prefix, 'allowed_post_types', array());
		if (!in_array($post->post_type, $allowed_post_types)) {
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
		if (array_key_exists($post_ID, self::$blocked_posts)) {
			return $the_content_blocked_message(self::$blocked_posts[$post_ID]['blocked_message']);
		}
		$postmeta = $this->post_has_access_rules($post_ID);
		if ($postmeta !== false) {
			if ($this->is_blocked($postmeta)) {
				self::$blocked_posts[$post_ID] = $postmeta;
				return $the_content_blocked_message($postmeta['blocked_message']);
			}
		}

		// recursive ancestors
		$ancestors = $this->get_ancestors($post_ID, $post->post_type);
		if (!empty($ancestors)) {
			foreach ($ancestors as $value) {
				// check saved
				if (array_key_exists($value, self::$blocked_posts)) {
					$postmeta = $this->post_has_recursive_access_rules($value, self::$blocked_posts[$value]);
					if ($postmeta !== false) {
						return $the_content_blocked_message(self::$blocked_posts[$value]['blocked_message']);
					}
					continue;
				}
				$postmeta = $this->post_has_recursive_access_rules($value);
				if ($postmeta !== false) {
					if ($this->is_blocked($postmeta)) {
						self::$blocked_posts[$value] = $postmeta;
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
        	$postmeta = array();
			// check saved
			if (array_key_exists($post_ID, self::$blocked_posts)) {
				$postmeta = $this->post_has_recursive_access_rules($post_ID, self::$blocked_posts[$post_ID]);
				if ($postmeta !== false) {
					continue;
				}
			}
			else {
				$postmeta = $this->post_has_recursive_access_rules($post_ID);
				if ($postmeta !== false) {
					if ($this->is_blocked($postmeta)) {
						self::$blocked_posts[$post_ID] = $postmeta;
						continue;
					}
				}
			}
			$ancestors = get_ancestors($post_ID, $item->object);
			if (!empty($ancestors)) {
				$blocked = false;
				foreach ($ancestors as $value) {
					// check saved
					if (array_key_exists($value, self::$blocked_posts)) {
						$postmeta = $this->post_has_recursive_access_rules($value, self::$blocked_posts[$value]);
						if ($postmeta !== false) {
							$blocked = true;
							break;
						}
						continue;
					}
					$postmeta = $this->post_has_recursive_access_rules($value);
					if ($postmeta !== false) {
						if ($this->is_blocked($postmeta)) {
							self::$blocked_posts[$value] = $postmeta;
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
		$cookie_name = static::$prefix.'::'.__FUNCTION__;
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
		$errors->add(static::$prefix, $str, 'message');
		if (!headers_sent()) {
			setcookie($cookie_name, null, time() - 3600, COOKIEPATH);
		}
		return $errors;
	}

    /* functions */

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
		$postmeta = get_post_meta($post_ID, static::$prefix, true);
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

	private function post_has_recursive_access_rules($post_ID, $postmeta = array()) {
		if (empty($postmeta)) {
			$postmeta = $this->post_has_access_rules($post_ID);
		}
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
			$blocked_message = $this->get_option(static::$prefix, 'blocked_message', '');
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
		if (is_user_logged_in()) {
			return;
		}
		$str = $this->blocked_message($postmeta['blocked_message']);
		// save the message in a cookie
		if (!empty($str)) {
			$cookie_name = static::$prefix.'::wp_login_errors';
			setcookie($cookie_name, $str, time() + (24 * 3600), COOKIEPATH);
		}
		$login_redirect = wp_login_url();
		$login_redirect = add_query_arg(array('redirect_to' => $this->get_current_uri()), $login_redirect);
		if (wp_redirect($login_redirect)) {
			exit;
		}
	}

	private function is_blocked($postmeta = array()) {
		$postmeta_arr = $this->get_postmeta_array();
		$shortcode_arr = array(
			'username',
			'user_id',
			'logged',
		);
		$postmeta = array_merge( array_fill_keys($postmeta_arr, null),  array_fill_keys($shortcode_arr, null), $this->make_array($postmeta) );

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
			if (is_multisite()) {
				$user = $this->get_ms_userdata($user_id);
			}
			else {
				$user = get_userdata($user_id);
			}
		}
		elseif (is_user_logged_in()) {
			global $current_user;
			$user_id = $current_user->ID;
			if (is_multisite()) {
				$user = $this->get_ms_userdata($user_id);
			}
			else {
				$user = $current_user;
			}
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
	
	private function get_ms_userdata($user_id) {
		$args = array(
			'include' => array($user_id),
			'number' => 1,
		);
		$blog = get_active_blog_for_user($user_id);
		if (!empty($blog)) {
			$args['blog_id'] = $blog->blog_id;
		}
		$res = get_users($args);
		if (!empty($res)) {
			return $res[0];
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