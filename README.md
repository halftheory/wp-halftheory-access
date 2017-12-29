# wp-access
Wordpress plugin for shortcode [access] and extended access options.

This plugin enables simple and effective control over user access to content contained in any post type.

Features:
- Display different content to different users from the same URL using the [access] shortcode.
- Restrict content based on the current user role, user name, or user ID.
- Restrict entire posts and their children, also removing them from menus.
- Display a custom message to blocked users or redirect them to the login page.
- Compatible with WP Multisite, Buddypress, Bbpress.

# Shortcode examples

[access logged=in blocked_message="Only for members"] content [/access]

[access logged=out blocked_message="Only for visitors"] content [/access]

[access roles=editor,guest blocked_message="Only for editors and guests"] content [/access]

[access username=user1,user2 user_id=10,50,100 blocked_message="Only for specific users"] content [/access]

[access roles=super_admin blocked_message="Only for admins"] content [/access]

# Custom filters

The following filters are available for plugin/theme customization:
- wpaccess_shortcode
- wpaccess_blocked_message
- wpaccess_admin_menu_parent
- wpaccess_post_types
- wpaccess_deactivation
- wpaccess_uninstall
