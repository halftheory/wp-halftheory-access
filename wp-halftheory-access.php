<?php
/*
Plugin Name: Half/theory WP Access
Plugin URI: https://github.com/halftheory/wp-halftheory-access
GitHub Plugin URI: https://github.com/halftheory/wp-halftheory-access
Description: Half/theory WP Access Plugin.
Author: Half/theory
Author URI: https://github.com/halftheory
Version: 2.0
Network: false
*/

/*
Available filters:
wpaccess_shortcode
wpaccess_blocked_message
*/

// Exit if accessed directly.
defined('ABSPATH') || exit;

if ( ! class_exists('Halftheory_Helper_Plugin', false) && is_readable(dirname(__FILE__) . '/class-halftheory-helper-plugin.php') ) {
	include_once dirname(__FILE__) . '/class-halftheory-helper-plugin.php';
}

if ( ! class_exists('Halftheory_WP_Access', false) && class_exists('Halftheory_Helper_Plugin', false) ) :
	final class Halftheory_WP_Access extends Halftheory_Helper_Plugin {

        protected static $instance;
        public static $prefix;
        public static $active = false;
		public static $blocked_posts = array();
		public $shortcode = 'access';

        /* setup */

        protected function setup_globals( $plugin_basename = null, $prefix = null ) {
            parent::setup_globals($plugin_basename, $prefix);

            self::$active = $this->get_options_context('db', 'active');
        }

        protected function setup_actions() {
            parent::setup_actions();

            // Stop if not active.
            if ( empty(self::$active) ) {
                return;
            }

			if ( ! $this->is_front_end() ) {
				// admin.
				add_action('add_meta_boxes', array( $this, 'add_meta_boxes' ));
				add_action('save_post', array( $this, 'save_post' ), 10, 3);
			} else {
				// public.
                add_action('init', array( $this, 'init' ), 20);
				add_action('template_redirect', array( $this, 'template_redirect' ), 20);
				$func = function () {
					add_action('pre_get_posts', array( $this, 'pre_get_posts' ), 20);
					add_filter('posts_results', array( $this, 'the_posts' ), 20, 2);
					add_filter('the_posts', array( $this, 'the_posts' ), 20, 2);
				};
				if ( wp_doing_ajax() ) {
					$func();
				} else {
					add_action('template_redirect', $func, 30);
				}
				add_filter('the_content', array( $this, 'the_content' ), 20);
				add_filter('the_excerpt', array( $this, 'the_content' ), 20);
				add_filter('wp_get_nav_menu_items', array( $this, 'wp_get_nav_menu_items' ));
				add_filter('wp_login_errors', array( $this, 'wp_login_errors' ), 10, 2);
			}

            // shortcode.
			if ( ! shortcode_exists($this->shortcode) ) {
				add_shortcode($this->shortcode, array( $this, 'shortcode' ));
			}
        }

		public function plugin_deactivation( $network_wide ) {
			$this->delete_transient_uninstall();
            parent::plugin_deactivation($network_wide);
		}

        public static function plugin_uninstall() {
            static::$instance->delete_transient_uninstall();
            static::$instance->delete_postmeta_uninstall();
            static::$instance->delete_option_uninstall();
            parent::plugin_uninstall();
        }

		/* admin */

		public function menu_page() {
            $plugin = static::$instance;

            global $title;
            ?>
            <div class="wrap">
            <h2><?php echo esc_html($title); ?></h2>

            <?php
            if ( $plugin->save_menu_page() ) {
	        	$save = function () use ( $plugin ) {
					// get values.
					$options = array();
					foreach ( array_keys($plugin->get_options_context('default')) as $value ) {
						$name = $plugin::$prefix . '_' . $value;
						if ( ! isset($_POST[ $name ]) ) {
							continue;
						}
						if ( $plugin->empty_notzero($_POST[ $name ]) ) {
							continue;
						}
						$options[ $value ] = $_POST[ $name ];
					}
					// save it.
                    $updated = '<div class="updated"><p><strong>' . esc_html__('Options saved.') . '</strong></p></div>';
                    $error = '<div class="error"><p><strong>' . esc_html__('Error: There was a problem.') . '</strong></p></div>';
					if ( ! empty($options) ) {
                        $options = $plugin->get_options_context('input', null, array(), $options);
                        if ( $plugin->update_option($plugin::$prefix, $options) ) {
                            echo $updated;
                        } else {
                            echo $error;
                        }
                    } else {
                        if ( $plugin->delete_option($plugin::$prefix) ) {
                            echo $updated;
                        } else {
                            echo $updated;
                        }
                    }
				};
				$save();
	        }

            // Show the form.
            $options = $plugin->get_options_context('admin_form');
            ?>

            <form id="<?php echo esc_attr($plugin::$prefix); ?>-admin-form" name="<?php echo esc_attr($plugin::$prefix); ?>-admin-form" method="post" action="<?php echo esc_attr($_SERVER['REQUEST_URI']); ?>">
            <?php
            // Use nonce for verification.
            wp_nonce_field($plugin->plugin_basename, $plugin->plugin_name . '::' . __FUNCTION__);
            ?>
		    <div id="poststuff">

	        <p><label for="<?php echo esc_attr($plugin::$prefix); ?>_active"><input type="checkbox" id="<?php echo esc_attr($plugin::$prefix); ?>_active" name="<?php echo esc_attr($plugin::$prefix); ?>_active" value="1"<?php checked($options['active'], true); ?> /> <?php echo esc_html($plugin->plugin_title); ?> <?php esc_html_e('active?'); ?></label></p>

	        <div class="postbox">
	        	<div class="inside">
		            <h4><label for="<?php echo esc_attr($plugin::$prefix); ?>_blocked_message"><?php esc_html_e('Blocked message defaults'); ?></label></h4>
		            <p><span class="description"><?php esc_html_e('This message will be shown to blocked users. It can be overridden by blocked messages set on individual posts.'); ?></span></p>
		            <textarea rows="3" cols="70" name="<?php echo esc_attr($plugin::$prefix); ?>_blocked_message" id="<?php echo esc_attr($plugin::$prefix); ?>_blocked_message"><?php echo esc_textarea($options['blocked_message']); ?></textarea>
	        	</div>
	        </div>

	        <div class="postbox">
	        	<div class="inside">
		            <h4><?php esc_html_e('Allowed Post Types'); ?></h4>
		            <p><span class="description"><?php esc_html_e('Access rules will only be applied to the following post types.'); ?></span></p>
		            <?php
		            $post_types = array();
		            $arr = get_post_types(array( 'public' => true ), 'objects');
		            foreach ( $arr as $key => $value ) {
		            	$post_types[ $key ] = $value->label;
		            }
		            foreach ( $post_types as $key => $value ) {
						echo '<label style="display: inline-block; width: 50%;"><input type="checkbox" name="' . esc_attr($plugin::$prefix) . '_allowed_post_types[]" value="' . esc_attr($key) . '"';
						if ( in_array($key, $options['allowed_post_types'], true) ) {
							checked($key, $key);
						}
						echo '> ' . esc_html($value) . '</label>';
		            }
		            ?>
	        	</div>
	        </div>

	        <div class="postbox">
	        	<div class="inside">
		            <h4><?php esc_html_e('Hidden Roles'); ?></h4>
		            <p><span class="description"><?php esc_html_e('The following roles will be hidden from post pages.'); ?></span></p>
		            <?php
					global $wp_roles;
					$roles = isset($wp_roles) ? $wp_roles : new WP_Roles();
					foreach ( $roles->role_names as $key => $value ) {
						echo '<label style="display: inline-block; width: 50%;"><input type="checkbox" name="' . esc_attr($plugin::$prefix) . '_hidden_roles[]" value="' . esc_attr($key) . '"';
						if ( in_array($key, $options['hidden_roles'], true) ) {
							checked($key, $key);
						}
						echo '> ' . esc_html($value) . '</label>';
		            }
		            ?>
	        	</div>
	        </div>

            <?php submit_button(__('Update'), array( 'primary', 'large' ), 'save'); ?>

	        </div><!-- poststuff -->
	    	</form>

			</div><!-- wrap -->
			<?php
        }

	    public function add_meta_boxes( $post_type ) {
            $allowed_post_types = $this->get_options_context('db', 'allowed_post_types');
	        if ( ! in_array($post_type, $allowed_post_types, true) ) {
	            return;
	        }
	        add_meta_box(
	            static::$prefix,
	            $this->plugin_title,
	            array( $this, 'add_meta_box' ),
	            $post_type
	        );
	    }

	    public function add_meta_box() {
	        global $post, $wp_roles;

	        $postmeta_arr = $this->get_postmeta_array();
	        $postmeta = $this->get_postmeta($post->ID);
	        $postmeta = array_merge( array_fill_keys($postmeta_arr, null), $this->make_array($postmeta) );

	        // Use nonce for verification.
            wp_nonce_field($plugin->plugin_basename, $plugin->plugin_name . '::' . __FUNCTION__);

	        echo '<p>';
	        esc_html_e('This content is only available to:');
	        echo '</p>';

	        echo '<p>';
			$roles = isset($wp_roles) ? $wp_roles : new WP_Roles();
	        $hidden_roles = $this->get_options_context('db', 'hidden_roles');
	        $postmeta['roles'] = $this->make_array($postmeta['roles']);
	        foreach ( $roles->role_names as $role => $name ) {
	            if ( in_array($role, $hidden_roles, true) ) {
	                continue;
	            }
	            echo '<label style="display: inline-block; width: 50%;"><input type="checkbox" name="' . esc_attr(static::$prefix) . '_roles[]" value="' . esc_attr($role) . '"';
	            if ( in_array($role, $postmeta['roles'], true) ) {
	                checked($role, $role);
	            }
	            echo '> ' . esc_html($name) . '</label>';
	        }
	        echo '</p>';

	        echo '<p>';
	        echo '<label style="display: inline-block; width: 50%;"><input type="checkbox" name="' . esc_attr(static::$prefix) . '_logged_in" value="1"' . checked(1, $postmeta['logged_in'], false) . '> ' . esc_html__('Logged in users') . '</label>';
	        echo '<label style="display: inline-block; width: 50%;"><input type="checkbox" name="' . esc_attr(static::$prefix) . '_logged_out" value="1"' . checked(1, $postmeta['logged_out'], false) . '> ' . esc_html__('Logged out users') . '</label>';
	        echo '</p>';

	        echo '<p>';
	        echo '<label><input type="checkbox" name="' . esc_attr(static::$prefix) . '_recursive" value="1"' . checked(1, $postmeta['recursive'], false) . '> ' . esc_html__('Apply these access rules to all child posts. This also removes blocked posts from menus.') . '</label>';
	        echo '</p>';

	        $url = $this->is_plugin_network() ? network_admin_url('admin.php?page=' . static::$prefix) : admin_url('admin.php?page=' . static::$prefix);
	        echo '<p>Blocked message:<br/>
	        <textarea rows="3" cols="70" name="' . esc_attr(static::$prefix) . '_blocked_message" id="' . esc_attr(static::$prefix) . '_blocked_message">' . esc_textarea($postmeta['blocked_message']) . '</textarea>
	        <br/><span class="description">' . esc_html__('This message will be shown to blocked users.') . ' It overrides any message that was set <a href="' . esc_url($url) . '">here</a>.</span></p>';

	        echo '<p>';
	        echo '<label><input type="checkbox" name="' . esc_attr(static::$prefix) . '_login_redirect" value="1"' . checked(1, $postmeta['login_redirect'], false) . '> ' . esc_html__('Redirect blocked users to the login page.') . '</label>';
	        echo '</p>';
	    }

	    public function save_post( $post_ID, $post, $update ) {
	        // verify if this is an auto save routine.
	        // If it is our form has not been submitted, so we dont want to do anything
	        if ( defined('DOING_AUTOSAVE') && DOING_AUTOSAVE ) {
	            return;
	        }
	        if ( empty($update) ) {
	            return;
	        }
	        // verify this came from the our screen and with proper authorization
	        // because save_post can be triggered at other times
	        if ( isset($_POST) ) {
	            if ( isset($_POST[ $this->plugin_name . '::add_meta_box' ]) ) {
	                if ( wp_verify_nonce($_POST[ $this->plugin_name . '::add_meta_box' ], static::$plugin_basename) ) {
	                    // get values.
	                    $postmeta_arr = $this->get_postmeta_array();
	                    $postmeta = array();
	                    foreach ( $postmeta_arr as $value ) {
	                        $name = static::$prefix . '_' . $value;
	                        if ( ! isset($_POST[ $name ]) ) {
	                            continue;
	                        }
	                        if ( $this->empty_notzero($_POST[ $name ]) ) {
	                            continue;
	                        }
	                        $postmeta[ $value ] = $_POST[ $name ];
	                    }
	                    // save it.
	                    $maybe_revision = wp_is_post_revision($post_ID);
	                    if ( $maybe_revision !== false ) {
	                        $post_ID = $maybe_revision;
	                    }
	                    if ( ! empty($postmeta) ) {
	                        $this->update_postmeta($post_ID, static::$prefix, $postmeta);
	                    } else {
	                        $this->delete_postmeta($post_ID, static::$prefix);
	                    }
	                }
	            }
	        }
	    }

		/* public */

        public function init() {
            $this->add_shortcode_wpautop_control($this->shortcode);
        }

		public function template_redirect() {
			if ( is_404() ) {
				return;
			}
			if ( is_search() ) {
				return;
			}
			list($post_ID, $post) = $this->get_post_ID_post();

            $allowed_post_types = $this->get_options_context('db', 'allowed_post_types');
			if ( ! in_array($post->post_type, $allowed_post_types, true) ) {
				return;
			}

			// current post.
			$postmeta = $this->post_has_access_rules($post_ID);
			if ( $postmeta !== false ) {
				if ( $this->is_blocked($postmeta) ) {
					self::$blocked_posts[ $post_ID ] = $postmeta;
					remove_all_filters('template_include'); // some plugins (bbpress) use template_include to completely bypass the_content.
					if ( $this->post_has_login_redirect($postmeta) ) {
						return $this->template_redirect_blocked_message($postmeta);
					}
					return;
				}
			}

			// recursive ancestors.
			$ancestors = $this->get_ancestors($post_ID, $post->post_type);
			if ( ! empty($ancestors) ) {
				foreach ( $ancestors as $value ) {
					$postmeta = $this->post_has_recursive_access_rules($value);
					if ( $postmeta !== false ) {
						if ( $this->is_blocked($postmeta) ) {
							self::$blocked_posts[ $value ] = $postmeta;
							remove_all_filters('template_include');
							if ( $this->post_has_login_redirect($postmeta) ) {
								return $this->template_redirect_blocked_message($postmeta);
							}
							return;
						}
					}
				}
			}
		}

		public function pre_get_posts( $wp_query ) {
			if ( empty(self::$blocked_posts) ) {
				return;
			}
			$wp_query->set('post__not_in', array_keys(self::$blocked_posts));
			$post_parent__not_in = array();
			foreach ( self::$blocked_posts as $key => $value ) {
				$postmeta = $this->post_has_recursive_access_rules($key, $value);
				if ( $postmeta !== false ) {
					$post_parent__not_in[] = $key;
				}
			}
			if ( ! empty($post_parent__not_in) ) {
				$wp_query->set('post_parent__not_in', $post_parent__not_in);
			}
		}

		public function the_posts( $posts, $wp_query ) {
			if ( empty($posts) ) {
				return $posts;
			}

			$i = count($posts);
            $allowed_post_types = $this->get_options_context('db', 'allowed_post_types');

			foreach ( $posts as $key => $post ) {
				if ( ! in_array($post->post_type, $allowed_post_types, true) ) {
					continue;
				}
				$post_ID = $post->ID;
				// current post.
				// check saved.
				if ( array_key_exists($post_ID, self::$blocked_posts) ) {
					unset($posts[ $key ]);
					continue;
				}
				$postmeta = $this->post_has_access_rules($post_ID);
				if ( $postmeta !== false ) {
					if ( $this->is_blocked($postmeta) ) {
						self::$blocked_posts[ $post_ID ] = $postmeta;
						unset($posts[ $key ]);
						continue;
					}
				}
				// recursive ancestors.
				$ancestors = $this->get_ancestors($post_ID, $post->post_type);
				if ( ! empty($ancestors) ) {
					foreach ( $ancestors as $value ) {
						// check saved.
						if ( array_key_exists($value, self::$blocked_posts) ) {
							$postmeta = $this->post_has_recursive_access_rules($value, self::$blocked_posts[ $value ]);
							if ( $postmeta !== false ) {
								unset($posts[ $key ]);
								break;
							}
							continue;
						}
						$postmeta = $this->post_has_recursive_access_rules($value);
						if ( $postmeta !== false ) {
							if ( $this->is_blocked($postmeta) ) {
								self::$blocked_posts[ $value ] = $postmeta;
								unset($posts[ $key ]);
								break;
							}
						}
					}
				}
			}

			if ( $i !== count($posts) ) {
				$posts = array_combine( array_keys(array_fill(0, count($posts), null)), $posts );
				$wp_query->found_posts = count($posts);
			}
			return $posts;
		}

		public function the_content( $str = '' ) {
			list($post_ID, $post) = $this->get_post_ID_post();

            $allowed_post_types = $this->get_options_context('db', 'allowed_post_types');
			if ( ! in_array($post->post_type, $allowed_post_types, true) ) {
				return $str;
			}

			// return function.
			$the_content_blocked_message = function ( $blocked_message = '' ) {
				$str = $this->blocked_message($blocked_message);
				$str = $this->apply_filters_the_content($str, array( $this, 'the_content' ));
				return $str;
			};

			// current post.
			// check saved.
			if ( array_key_exists($post_ID, self::$blocked_posts) ) {
				return $the_content_blocked_message(self::$blocked_posts[ $post_ID ]['blocked_message']);
			}
			$postmeta = $this->post_has_access_rules($post_ID);
			if ( $postmeta !== false ) {
				if ( $this->is_blocked($postmeta) ) {
					self::$blocked_posts[ $post_ID ] = $postmeta;
					return $the_content_blocked_message($postmeta['blocked_message']);
				}
			}

			// recursive ancestors.
			$ancestors = $this->get_ancestors($post_ID, $post->post_type);
			if ( ! empty($ancestors) ) {
				foreach ( $ancestors as $value ) {
					// check saved.
					if ( array_key_exists($value, self::$blocked_posts) ) {
						$postmeta = $this->post_has_recursive_access_rules($value, self::$blocked_posts[ $value ]);
						if ( $postmeta !== false ) {
							return $the_content_blocked_message(self::$blocked_posts[ $value ]['blocked_message']);
						}
						continue;
					}
					$postmeta = $this->post_has_recursive_access_rules($value);
					if ( $postmeta !== false ) {
						if ( $this->is_blocked($postmeta) ) {
							self::$blocked_posts[ $value ] = $postmeta;
							return $the_content_blocked_message($postmeta['blocked_message']);
						}
					}
				}
			}
			return $str;
		}

		public function wp_get_nav_menu_items( $items ) {
	        $show_items = array();
	        foreach ( $items as $key => $item ) {
	        	$post_ID = $item->object_id;
	        	$postmeta = array();
				// check saved.
				if ( array_key_exists($post_ID, self::$blocked_posts) ) {
					$postmeta = $this->post_has_recursive_access_rules($post_ID, self::$blocked_posts[ $post_ID ]);
					if ( $postmeta !== false ) {
						continue;
					}
				} else {
					$postmeta = $this->post_has_recursive_access_rules($post_ID);
					if ( $postmeta !== false ) {
						if ( $this->is_blocked($postmeta) ) {
							self::$blocked_posts[ $post_ID ] = $postmeta;
							continue;
						}
					}
				}
				$ancestors = get_ancestors($post_ID, $item->object);
				if ( ! empty($ancestors) ) {
					$blocked = false;
					foreach ( $ancestors as $value ) {
						// check saved.
						if ( array_key_exists($value, self::$blocked_posts) ) {
							$postmeta = $this->post_has_recursive_access_rules($value, self::$blocked_posts[ $value ]);
							if ( $postmeta !== false ) {
								$blocked = true;
								break;
							}
							continue;
						}
						$postmeta = $this->post_has_recursive_access_rules($value);
						if ( $postmeta !== false ) {
							if ( $this->is_blocked($postmeta) ) {
								self::$blocked_posts[ $value ] = $postmeta;
								$blocked = true;
								break;
							}
						}
					}
					if ( $blocked ) {
						continue;
					}
				}
	            $show_items[ $key ] = $item;
	        }
	        return $show_items;
	    }

		public function wp_login_errors( $errors, $redirect_to ) {
			$cookie_name = static::$prefix . '::' . __FUNCTION__;
			if ( ! isset($_COOKIE[ $cookie_name ]) ) {
				return $errors;
			}
			if ( ! is_object($errors) ) {
				$errors = new WP_Error();
			}
			$str = array( $_COOKIE[ $cookie_name ] );
			$referer = wp_get_referer();
			if ( ! empty($referer) ) {
				$str[] = '<a href="' . esc_url($referer) . '">' . esc_html__('Go back to the previous page.') . '</a>';
			}
			$str = array_map('trim', $str);
			$str = array_filter($str);
			$str = nl2br(implode("\n", $str));
			$errors->add(static::$prefix, $str, 'message');
			if ( ! headers_sent() ) {
				setcookie($cookie_name, null, time() - 3600, COOKIEPATH);
			}
			return $errors;
		}

		/* shortcode */

		public function shortcode( $atts = array(), $content = '', $shortcode = '' ) {
			$atts = $this->make_array($atts);
			// roles dominates role.
			if ( ! isset($atts['roles']) && isset($atts['role']) ) {
				$atts['roles'] = $atts['role'];
			}
			if ( isset($atts['role']) ) {
				unset($atts['role']);
			}
			$defaults = array(
				'roles' => '',
				'username' => '',
				'user_id' => '',
				'logged' => '',
				'blocked_message' => '',
			);
			// removes keys not found in defaults.
			$atts = shortcode_atts($defaults, $atts, $this->shortcode);
			// resolve user input.
			if ( ! empty($atts) ) {
				$atts = $this->trim_quotes($atts);
				if ( isset($atts['roles']) ) {
					$atts['roles'] = $this->make_array($atts['roles']);
				}
				if ( isset($atts['username']) ) {
					$atts['username'] = $this->make_array($atts['username']);
				}
				if ( isset($atts['user_id']) ) {
					$atts['user_id'] = $this->make_array($atts['user_id']);
				}
			}
			$atts = array_filter($atts);

			if ( $this->is_blocked($atts) ) {
				$content = $atts['blocked_message'];
			}
			if ( ! empty($content) ) {
				// apply all content filters before and including do_shortcode (priority 11).
				$content = $this->apply_filters_the_content($content, null, 'do_shortcode');
			}
			return apply_filters('wpaccess_shortcode', $content);
		}

        /* functions - options */

        protected function get_options_default() {
            return apply_filters(static::$prefix . '_options_default',
                array(
                    'active' => false,
                    'blocked_message' => 'Access denied.',
                    'allowed_post_types' => array(),
                    'hidden_roles' => array(),
                )
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

        /* functions */

		private function get_post_ID_post() {
			global $post;
			$post_ID = $post->ID;
			$my_post = $post;

			if ( empty($post_ID) && is_singular() ) {
				// some plugins (buddypress) hide the real post_id in queried_object_id.
				global $wp_query;
				if ( isset($wp_query->queried_object_id) ) {
					if ( ! empty($wp_query->queried_object_id) ) {
						$post_ID = $wp_query->queried_object_id;
						if ( isset($wp_query->queried_object) ) {
							$my_post = $wp_query->queried_object;
						}
					}
				}
			}
			return array( $post_ID, $my_post );
		}

		private function get_ancestors( $post_ID, $post_type = 'page' ) {
			$ancestors = array();

			// maybe find an ancestor.
			if ( empty($post_ID) ) {
				global $wp_query;
				if ( strpos($wp_query->query['pagename'], '/') !== false ) {
					$post_good = false;
					$slugs = explode('/', $wp_query->query['pagename']);
					$slug = '';
					foreach ( $slugs as $key => $value ) {
						$slug .= '/' . $value;
						$post_tmp = get_page_by_path($slug);
						if ( ! empty($post_tmp) ) {
							$post_good = $post_tmp;
						} elseif ( empty($post_tmp) && ! empty($post_good) ) {
							break;
						}
					}
					if ( ! empty($post_good) ) {
						$ancestors[] = $post_good->ID;
					}
				}
			}

			if ( empty($ancestors) ) {
				$ancestors = get_ancestors($post_ID, $post_type);
			}
			return $ancestors;
		}

		private function post_has_access_rules( $post_ID ) {
			$postmeta = $this->get_postmeta($post_ID);
			if ( empty($postmeta) ) {
				return false;
			}
			$postmeta_arr = array(
				'roles',
				'logged_in',
				'logged_out',
			);
			foreach ( $postmeta_arr as $value ) {
				if ( isset($postmeta[ $value ]) ) {
					if ( ! empty($postmeta[ $value ]) ) {
						return $postmeta;
					}
				}
			}
			return false;
		}

		private function post_has_recursive_access_rules( $post_ID, $postmeta = array() ) {
			if ( empty($postmeta) ) {
				$postmeta = $this->post_has_access_rules($post_ID);
			}
			if ( $postmeta !== false ) {
				if ( isset($postmeta['recursive']) ) {
					if ( ! empty($postmeta['recursive']) ) {
						return $postmeta;
					}
				}
			}
			return false;
		}

		private function post_has_login_redirect( $postmeta = array() ) {
			if ( isset($postmeta['login_redirect']) ) {
				if ( ! empty($postmeta['login_redirect']) ) {
					return true;
				}
			}
			return false;
		}

		private function blocked_message( $str = '' ) {
			if ( empty($str) ) {
            	$blocked_message = $this->get_options_context('db', 'blocked_message');
				if ( ! empty($blocked_message) ) {
					$str = $blocked_message;
				}
			}
			return apply_filters('wpaccess_blocked_message', $str);
		}

		private function template_redirect_blocked_message( $postmeta = array() ) {
			if ( headers_sent() ) {
				return;
			}
			if ( is_user_logged_in() ) {
				return;
			}
			$str = $this->blocked_message($postmeta['blocked_message']);
			// save the message in a cookie.
			if ( ! empty($str) ) {
				$cookie_name = static::$prefix . '::wp_login_errors';
				setcookie($cookie_name, $str, time() + DAY_IN_SECONDS, COOKIEPATH);
			}
			$login_redirect = wp_login_url();
			$login_redirect = add_query_arg(array( 'redirect_to' => $this->get_current_uri() ), $login_redirect);
			if ( wp_redirect($login_redirect) ) {
				exit;
			}
		}

		private function is_blocked( $postmeta = array() ) {
			$postmeta_arr = $this->get_postmeta_array();
			$shortcode_arr = array(
				'username',
				'user_id',
				'logged',
			);
			$postmeta = array_merge( array_fill_keys($postmeta_arr, null), array_fill_keys($shortcode_arr, null), $this->make_array($postmeta) );

			if ( ! empty($postmeta['roles']) || ! empty($postmeta['username']) || ! empty($postmeta['user_id']) ) {
				// check logged in.
				if ( ! is_user_logged_in() ) {
					return true;
				}
				// check user role.
				if ( ! empty($postmeta['roles']) ) {
					$postmeta['roles'] = $this->make_array($postmeta['roles']);
					if ( ! $this->has_role($postmeta['roles']) ) {
						return true;
					}
				}
				global $current_user;
				// check username.
				if ( ! empty($postmeta['username']) ) {
					$postmeta['username'] = $this->make_array($postmeta['username']);
					if ( ! in_array($current_user->user_login, $postmeta['username'], true) ) {
						return true;
					}
				}
				// check user id.
				if ( ! empty($postmeta['user_id']) ) {
					$postmeta['user_id'] = $this->make_array($postmeta['user_id']);
					if ( ! in_array($current_user->ID, $postmeta['user_id'], true) ) {
						return true;
					}
				}
			}

			// logged in.
			if ( ! empty($postmeta['logged_in']) || $postmeta['logged'] === 'in' ) {
				if ( ! is_user_logged_in() ) {
					return true;
				}
			}
			// logged out
			if ( ! empty($postmeta['logged_out']) || $postmeta['logged'] === 'out' ) {
				if ( is_user_logged_in() ) {
					return true;
				}
			}
			return false;
		}

		private function has_role( $roles, $user_id = null ) {
			if ( is_numeric($user_id) ) {
				if ( is_multisite() ) {
					$user = $this->get_ms_userdata($user_id);
				} else {
					$user = get_userdata($user_id);
				}
			} elseif ( is_user_logged_in() ) {
				global $current_user;
				$user_id = $current_user->ID;
				if ( is_multisite() ) {
					$user = $this->get_ms_userdata($user_id);
				} else {
					$user = $current_user;
				}
			}
			if ( empty($user) ) {
				return false;
			}
			if ( ! is_array($roles) ) {
				$roles = $this->make_array($roles);
			}
			foreach ( $roles as $role ) {
				if ( is_multisite() && $role === 'super_admin' && is_super_admin($user_id) ) {
					return true;
				}
				if ( isset($user->caps) ) {
					if ( is_array($user->caps) && isset($user->caps[ $role ]) ) {
						return true;
					}
				}
				if ( isset($user->roles) ) {
					if ( is_array($user->roles) && in_array($role, $user->roles, true) ) {
						return true;
					}
				}
			}
			return false;
		}

		private function get_ms_userdata( $user_id ) {
			$args = array(
				'include' => array( $user_id ),
				'number' => 1,
			);
			$blog = get_active_blog_for_user($user_id);
			if ( ! empty($blog) ) {
				$args['blog_id'] = $blog->blog_id;
			}
			$res = get_users($args);
			if ( ! empty($res) ) {
				return $res[0];
			}
			return false;
		}

		private function apply_filters_the_content( $str = '', $break_before = null, $break_after = null ) {
			if ( empty($str) ) {
				return $str;
			}
			global $wp_filter;
			if ( ! isset($wp_filter['the_content']) ) {
				return $str;
			}
			if ( ! is_object($wp_filter['the_content']) ) {
				return $str;
			}
			if ( empty($wp_filter['the_content']->callbacks) ) {
				return $str;
			}
		 	foreach ( $wp_filter['the_content']->callbacks as $priority => $filters ) {
		 		foreach ( $filters as $key => $filter ) {
		 			if ( ! isset($filter['function']) ) {
		 				continue;
		 			}
		 			if ( ! empty($break_before) ) {
			 			if ( is_string($break_before) && is_string($filter['function']) && $break_before === $filter['function'] ) {
		 					return $str;
			 			} elseif ( is_array($break_before) && is_array($filter['function']) && $break_before === $filter['function'] ) {
		 					return $str;
				 		}
		 			}
		 			if ( is_string($filter['function']) ) {
		 				$func = $filter['function'];
		 				$str = $func($str);
		 			} elseif ( is_array($filter['function']) ) {
		 				$func0 = $filter['function'][0];
		 				$func1 = $filter['function'][1];
		 				$str = $func0->$func1($str);
		 			}
		 			if ( ! empty($break_after) ) {
			 			if ( is_string($break_after) && is_string($filter['function']) && $break_after === $filter['function'] ) {
		 					return $str;
			 			} elseif ( is_array($break_after) && is_array($filter['function']) && $break_after === $filter['function'] ) {
		 					return $str;
				 		}
		 			}
		 		}
		 	}
		 	return $str;
		}

        /* functions-common */

	    private function trim_quotes( $str = '' ) {
            if ( function_exists(__FUNCTION__) ) {
                $func = __FUNCTION__;
                return $func($str);
            }
	        if ( is_string($str) ) {
	            $str = trim($str, " \n\r\t\v\0'" . '"');
	        } elseif ( is_array($str) ) {
	            $str = array_map(array( $this, __FUNCTION__ ), $str);
	        }
	        return $str;
	    }
    }

	// Load the plugin.
    Halftheory_WP_Access::get_instance(true, plugin_basename(__FILE__));
endif;
