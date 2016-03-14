<?php
/*
Plugin Name: RHP Google Tag Manager
Plugin URI: http://wordpress.org/derpy/
Description: Deploys the RHP CDT Tracking for GA.  Those are all good things.
Author: jtg
Version: 0.1
Author URI: http://rockhousepartners.net/
*/
if( !class_exists('rhp_gtm') ) {
	class rhp_gtm {

		// Change this along with the above
		const VERSION = '0.1';

		protected $nonce_string = 'rhp_gtm_nonce';

		public static $option_defaults = array(
								'ga_uaid' => ''
							);

		/**
		 * Get instantiated
		 */
		public function __construct() {

			if( is_admin() ) {
				add_action( 'admin_menu', array($this,'wpadmin_menu') );

				// Allow a force of updates
				if( isset($_GET['force-check']) ) {
					$this->self_updates();
				}

			} else {
				add_action( 'body_open', array($this,'print_tag') );
				add_action( 'woo_top', array($this,'print_tag') );
			}

			// Set up our self updating features
			add_filter( __CLASS__ . '_updates', array($this,'self_updates') );

        }

		/**
		 * Handle updates from our repo
		 */
		public function self_updates() {

			if( defined( 'WP_INSTALLING' ) )
				return false;

			$rhp_repo = wp_remote_get('https://s3.amazonaws.com/rockhouse/wp/plugins/wp-rhp-tagmanager/version.txt');

			if( is_wp_error($rhp_repo) or !isset($rhp_repo['body']) ) {
				$body = var_export($rhp_repo,true);
				$body .= "\n\n--------------------\n\n";
				$body .= var_export($_SERVER,true);
				@wp_mail('admin@rockhousepartners.com','RHP WP Tag Manager - Update Check Failure',$body);
			} else {
				$ver = trim($rhp_repo['body']);
				if( version_compare(self::VERSION,$ver,'<') ) {

					$update_plugins = get_transient('update_plugins');

					if ( ! isset( $update_plugins->response ) || ! is_array( $update_plugins->response ) )
						$update_plugins->response = array();

					$update_plugins->response['wp-rhp-tagmanager/wp-rhp-tagmanager.php'] = (object) array(
						'slug' 			=>	'wp-rhp-tagmanager',
						'plugin'		=>	'wp-rhp-tagmanager/wp-rhp-tagmanager.php',
						'new_version' 	=>	$ver,
						'url' 			=>	'https://bitbucket.org/rhprocks/wp-rhp-tagmanager',
						'package' 		=>	'https://s3.amazonaws.com/rockhouse/wp/plugins/wp-rhp-tagmanager/latest.zip'
					);

					set_transient('update_plugins', $update_plugins);
				}
			}
		}

		/**
		 * Install functions
		 */
		public static function install(){

			// Schedule our updates
			if ( ! wp_next_scheduled( __CLASS__ . '_updates' ) ) {
				wp_schedule_event( time(), 'hourly', __CLASS__ . '_updates');
			}


		}

		/**
		 * Uninstall functions
		 */
		public static function uninstall(){

			// Unschedule our updates
			$next_update = wp_next_scheduled( __CLASS__ . '_updates' );
			if ( $next_update ) {
				wp_unschedule_event( $next_update, 'hourly', __CLASS__ . '_updates');
			}

		}

		/**
		 * Register menus
		 */
		public function wpadmin_menu() {
			add_management_page('RHP Google Tag Manager', 'RHP Tag Manager', 'manage_options', 'rhp_gtm', array($this,'wpadmin_page'));
		}

		/**
		 * This is what we came here for
		 */
		public function print_tag() {
			// Set primary domain
			$current_blog = $_SERVER['HTTP_HOST'];

			// Get UA for Property
			$uaid = false;
			$opts = get_option(get_class() . '_options', self::$option_defaults);
			if( stripos($opts['ga_uaid'],'UA-') === 0 )
				$uaid = $opts['ga_uaid'];

			if( $uaid and ! is_user_logged_in() ) {
				echo <<<HTML
<script type="text/javascript">
  dataLayer = [{'ga-uaid': '{$uaid}','ga-primary-domain': '{$current_blog}'}];
</script>
<!-- Google Tag Manager -->
<noscript><iframe src="//www.googletagmanager.com/ns.html?id=GTM-5SQFWG"
height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>
<script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':
new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],
j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src=
'//www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);
})(window,document,'script','dataLayer','GTM-5SQFWG');</script>
<!-- End Google Tag Manager -->

HTML;
			}
		}

		/**
		 * Save options to single arr
		 */
		public function save_settings() {
			if( $_POST and !empty($_POST['rhp_gtm_form_nonce']) and wp_verify_nonce( $_POST['rhp_gtm_form_nonce'],$this->nonce_string ) ) {
				$opts = self::$option_defaults;
				foreach( array_keys(self::$option_defaults) as $option ) {
					if( isset($_POST[$option]) )
						$opts[$option] = $_POST[$option];
				}
				update_option(get_class() . '_options',$opts);
				echo '<div class="updated"><p><strong>Settings Saved.</strong></p></div>';
				return true;
			}
			return false;
		}

		/**
		 * Output an admin form wrapping our interior options panes
		 */
		public function output_form( $content ) {
			$nonceid = wp_create_nonce($this->nonce_string);
			echo <<<HTML
			<div class="wrap">
				<form name="rhp_gtm_opts" method="post" action="">
					{$content}
					<input type="hidden" name="rhp_gtm_form_nonce" id="rhp_gtm_form_nonce" value="{$nonceid}" />
				</form>
			</div>
HTML;
		}

		/**
		 * Quick check for permissions, for URL tomfoolery
		 *
		 * @param type $cap Capability
		 */
		public function check_perm($cap) {
			if( !current_user_can($cap) ) {
				$msg = <<<MSG
			<div class="wrap">
					<h2>Permission Denied</h2>
					<p>
							You do not have permission to change these settings.  Please contact your site administrator.
					</p>
			</div>
MSG;
				wp_die($msg);
			}
		}

		/**
		 * Settings page
		 */
		public function wpadmin_page() {
			$this->check_perm('manage_options');
			$this->save_settings();

			$opts = get_option(get_class() . '_options', self::$option_defaults);
			$goodtogo = true;

			// Check for primary domain
			$current_blog = $_SERVER['HTTP_HOST'];
			$domain = '<span class="dashicons dashicons-smiley"></span> Primary Domain: ' . $current_blog;
			if( defined('DOMAIN_MAPPING') )
				$domain .= ' (Domain Mapping active, blog_id = ' . $current_blog->blog_id . ')';

			// See if we have a GA ID
			if( stripos($opts['ga_uaid'],'UA-') === 0 ) {
				$gacheck = '<span class="dashicons dashicons-smiley"></span> Valid GA Property present: <code>' . $opts['ga_uaid'] . '</code>';
			} else {
				$gacheck = '<span class="dashicons dashicons-dismiss"></span> No GA Property set';
				$goodtogo = false;
			}

			// Check theme
			$theme = wp_get_theme();

			// Canvas compatibility is easy
			if( $theme->parent_theme == 'Canvas' ) {
				$themecheck = '<span class="dashicons dashicons-smiley"></span> The <code>woo_top</code> tag is in this theme';
			} else {
				$peek_theme = 'grep -h -A1 body_class ' . $theme->get_stylesheet_directory() . '/header*.php | grep -c body_open';
				$curr_check = (bool) trim( shell_exec( $peek_theme ) );
				$themecheck = '<span class="dashicons dashicons-dismiss"></span> Required tag <code><&#63;php do_action(\'body_open\') &#63;></code> not found in current theme. Contact your friendly local developer for assistance.';
				if( $curr_check ) {
					$themecheck = '<span class="dashicons dashicons-smiley"></span> The <code>body_open</code> tag is in this theme';
				}
			}

			// Not finished, should check for a count of header* files in current theme first.
			$is_child = $theme->get_template();
			if( FALSE and !empty( $is_child ) ) {
				$curr = array_keys( $theme->get_files('php',0,false) );
				$peek_parent = 'grep -h -A1 body_class ' . $theme->get_template_directory() . '/header*.php | grep -c body_open';
				$parent_check = (bool) trim( shell_exec( $peek_parent ) );
//				var_dump($parent_check);
			}

			// Set status
			$stat = '<span style="padding: 3px;font-size: 1.1em; background-color:black;color:red;font-weight:bold"> NOT ACTIVE </span>';
			if( $goodtogo )
				$stat = '<span style="padding: 3px;font-size: 1.1em; background-color:black;color:#7ad03a;font-weight:bold"> ACTIVE </span>';



			$body = <<<HTML

				<h2>Rockhouse GTM Container</h2>
				<p>Container is {$stat}</p>
				<p>
					<ol>
						<li>{$domain}</li>
						<li>{$themecheck}</li>
						<li>{$gacheck}</li>
					</ol>
				</p>
                <table class="form-table">
                    <tr valign="top">
                        <th scope="row">Google Analytics Property ID</th>
                        <td>
                            <input type="text" name="ga_uaid" id="ga_uaid" value="{$opts['ga_uaid']}"/>
                        </td>
                    </tr>
                </table>
                <p class="submit">
                    <input type="submit" name="Submit" class="button-primary" value="Save Settings" />
                </p>
HTML;
			$this->output_form($body);

		}
	}
}
// Fire it up, boss
new rhp_gtm();

// Setup / Cleanup
register_activation_hook( __FILE__, array( 'rhp_gtm','install' ) );
register_deactivation_hook( __FILE__, array( 'rhp_gtm','uninstall' ) );
