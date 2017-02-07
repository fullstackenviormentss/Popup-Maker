<?php
/**
 * License handler for Popup Maker
 *
 * This class should simplify the process of adding license information to new Popup Maker extensions.
 *
 * Note for wordpress.org admins. This is not called in the free hosted version and is simply used for hooking in addons to one update system rather than including it in each plugin.
 * @version 1.1
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * PUM_License Class
 */
class PUM_Extension_License {

	private $file;

	private $license;

	private $item_name;

	private $item_id;

	private $item_shortname;

	private $version;

	private $author;

	private $api_url = 'https://wppopupmaker.com/edd-sl-api/';

	/**
	 * Class constructor
	 *
	 * @param string $_file
	 * @param string $_item
	 * @param string $_version
	 * @param string $_author
	 * @param string $_optname
	 * @param string $_api_url
	 */
	function __construct( $_file, $_item, $_version, $_author, $_optname = null, $_api_url = null ) {

		$this->file = $_file;

		if ( is_numeric( $_item ) ) {
			$this->item_id = absint( $_item );
		} else {
			$this->item_name = $_item;
		}

		$this->item_shortname = 'popmake_' . preg_replace( '/[^a-zA-Z0-9_\s]/', '', str_replace( ' ', '_', strtolower( $this->item_name ) ) );
		$this->version        = $_version;
		$this->license        = trim( PUM_Option::get( $this->item_shortname . '_license_key', '' ) );
		$this->author         = $_author;
		$this->api_url        = is_null( $_api_url ) ? $this->api_url : $_api_url;

		/**
		 * Allows for backwards compatibility with old license options,
		 * i.e. if the plugins had license key fields previously, the license
		 * handler will automatically pick these up and use those in lieu of the
		 * user having to reactive their license.
		 */
		if ( ! empty( $_optname ) ) {
			$opt = PUM_Option::get( $_optname );

			if ( isset( $opt ) && empty( $this->license ) ) {
				$this->license = trim( $opt );
			}
		}

		// Setup hooks
		$this->includes();
		$this->hooks();
	}

	/**
	 * Include the updater class
	 *
	 * @access  private
	 * @return  void
	 */
	private function includes() {
		if ( ! class_exists( 'PUM_Extension_Updater' ) ) {
			require_once 'class-pum-extension-updater.php';
		}
	}

	/**
	 * Setup hooks
	 *
	 * @access  private
	 * @return  void
	 */
	private function hooks() {

		// Register settings
		add_filter( 'popmake_settings_licenses', array( $this, 'settings' ), 1 );

		// Activate license key on settings save
		add_action( 'admin_init', array( $this, 'activate_license' ) );

		// Deactivate license key
		add_action( 'admin_init', array( $this, 'deactivate_license' ) );

		// Check that license is valid once per week
		add_action( 'popmake_weekly_scheduled_events', array( $this, 'weekly_license_check' ) );

		// For testing license notices, uncomment this line to force checks on every page load
		//add_action( 'admin_init', array( $this, 'weekly_license_check' ) );

		// Updater
		add_action( 'admin_init', array( $this, 'auto_updater' ), 0 );

		// Display notices to admins
		add_action( 'admin_notices', array( $this, 'notices' ) );

		add_action( 'in_plugin_update_message-' . plugin_basename( $this->file ), array(
			$this,
			'plugin_row_license_missing',
		), 10, 2 );

		// Register plugins for beta support
		add_filter( 'pum_beta_enabled_extensions', array( $this, 'register_beta_support' ) );
	}

	/**
	 * Auto updater
	 *
	 * @access  private
	 * @return  void
	 */
	public function auto_updater() {
		$betas = PUM_Options::get( 'enabled_betas', array() );

		$args = array(
			'version' => $this->version,
			'license' => $this->license,
			'author'  => $this->author,
			'beta'    => pum_extension_has_beta_support( $this->item_shortname ),
		);

		if ( ! empty( $this->item_id ) ) {
			$args['item_id'] = $this->item_id;
		} else {
			$args['item_name'] = $this->item_name;
		}

		// Setup the updater
		$popmake_updater = new PUM_Extension_Updater( $this->api_url, $this->file, $args );
	}


	/**
	 * Add license field to settings
	 *
	 * @access  public
	 *
	 * @param array $settings
	 *
	 * @return  array
	 */
	public function settings( $settings ) {
		$popmake_license_settings = array(
			$this->item_shortname . '_license_key' => array(
				'id'      => $this->item_shortname . '_license_key',
				'name'    => sprintf( __( '%1$s', 'popup-maker' ), $this->item_name ),
				'desc'    => '',
				'type'    => 'license_key',
				'options' => array( 'is_valid_license_option' => $this->item_shortname . '_license_active' ),
				'size'    => 'regular',
			),
		);

		return array_merge( $settings, $popmake_license_settings );
	}


	/**
	 * Display help text at the top of the Licenses tag
	 *
	 * @param   string $active_tab
	 *
	 * @return  void
	 */
	public function license_help_text( $active_tab = '' ) {

		static $has_ran;

		if ( 'licenses' !== $active_tab ) {
			return;
		}

		if ( ! empty( $has_ran ) ) {
			return;
		}

		echo '<p>' . sprintf( __( 'Enter your extension license keys here to receive updates for purchased extensions. If your license key has expired, please <a href="%s" target="_blank">renew your license</a>.', 'popup-maker' ), 'http://docs.wppopupmaker.com/article/177-license-renewal' ) . '</p>';

		$has_ran = true;

	}


	/**
	 * Activate the license key
	 *
	 * @access  public
	 * @return  void
	 */
	public function activate_license() {

		if ( ! isset( $_POST['popmake_settings'] ) ) {
			return;
		}
		if ( ! isset( $_POST['popmake_settings'][ $this->item_shortname . '_license_key' ] ) ) {
			return;
		}

		foreach ( $_POST as $key => $value ) {
			if ( false !== strpos( $key, 'license_key_deactivate' ) ) {
				// Don't activate a key when deactivating a different key
				return;
			}
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$details = get_option( $this->item_shortname . '_license_active' );

		if ( is_object( $details ) && 'valid' === $details->license ) {
			return;
		}

		$license = sanitize_text_field( $_POST['popmake_settings'][ $this->item_shortname . '_license_key' ] );

		if ( empty( $license ) ) {
			return;
		}

		// Data to send to the API
		$api_params = array(
			'edd_action' => 'activate_license',
			'license'    => $license,
			'item_name'  => urlencode( $this->item_name ),
			'url'        => home_url(),
		);

		// Call the API
		$response = wp_remote_post( $this->api_url, array(
			'timeout'   => 15,
			'sslverify' => false,
			'body'      => $api_params,
		) );

		// Make sure there are no errors
		if ( is_wp_error( $response ) ) {
			return;
		}

		// Tell WordPress to look for updates
		set_site_transient( 'update_plugins', null );

		// Decode license data
		$license_data = json_decode( wp_remote_retrieve_body( $response ) );

		update_option( $this->item_shortname . '_license_active', $license_data );

	}


	/**
	 * Deactivate the license key
	 *
	 * @access  public
	 * @return  void
	 */
	public function deactivate_license() {

		if ( ! isset( $_POST['popmake_settings'] ) ) {
			return;
		}

		if ( ! isset( $_POST['popmake_settings'][ $this->item_shortname . '_license_key' ] ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Run on deactivate button press
		if ( isset( $_POST[ $this->item_shortname . '_license_key_deactivate' ] ) ) {

			// Data to send to the API
			$api_params = array(
				'edd_action' => 'deactivate_license',
				'license'    => $this->license,
				'item_name'  => urlencode( $this->item_name ),
				'url'        => home_url(),
			);

			// Call the API
			$response = wp_remote_post( $this->api_url, array(
				'timeout'   => 15,
				'sslverify' => false,
				'body'      => $api_params,
			) );

			// Make sure there are no errors
			if ( is_wp_error( $response ) ) {
				return;
			}

			// Decode the license data
			$license_data = json_decode( wp_remote_retrieve_body( $response ) );

			delete_option( $this->item_shortname . '_license_active' );

		}
	}


	/**
	 * Check if license key is valid once per week
	 *
	 * @access  public
	 * @since   2.5
	 * @return  void
	 */
	public function weekly_license_check() {

		if ( ! empty( $_POST['popmake_settings'] ) ) {
			return; // Don't fire when saving settings
		}

		if ( empty( $this->license ) ) {
			return;
		}

		// data to send in our API request
		$api_params = array(
			'edd_action' => 'check_license',
			'license'    => $this->license,
			'item_name'  => urlencode( $this->item_name ),
			'url'        => home_url(),
		);

		// Call the API
		$response = wp_remote_post( $this->api_url, array(
			'timeout'   => 15,
			'sslverify' => false,
			'body'      => $api_params,
		) );

		// make sure the response came back okay
		if ( is_wp_error( $response ) ) {
			return;
		}

		$license_data = json_decode( wp_remote_retrieve_body( $response ) );

		update_option( $this->item_shortname . '_license_active', $license_data );

	}


	/**
	 * Admin notices for errors
	 *
	 * @access  public
	 * @return  void
	 */
	public function notices() {

		static $showed_invalid_message;

		if ( empty( $this->license ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$messages = array();

		$license = get_option( $this->item_shortname . '_license_active' );

		if ( is_object( $license ) && 'valid' !== $license->license && empty( $showed_invalid_message ) ) {

			if ( empty( $_GET['tab'] ) || 'licenses' !== $_GET['tab'] ) {

				$messages[] = sprintf( __( 'You have invalid or expired license keys for Popup Maker. Please go to the <a href="%s">Licenses page</a> to correct this issue.', 'popup-maker' ), admin_url( 'edit.php?post_type=popup&page=pum-settings&tab=licenses' ) );

				$showed_invalid_message = true;

			}

		}

		if ( ! empty( $messages ) ) {

			foreach ( $messages as $message ) {

				echo '<div class="error">';
				echo '<p>' . $message . '</p>';
				echo '</div>';

			}

		}

	}

	/**
	 * Displays message inline on plugin row that the license key is missing
	 *
	 * @access  public
	 * @since   2.5
	 * @return  void
	 */
	public function plugin_row_license_missing( $plugin_data, $version_info ) {

		static $showed_imissing_key_message;

		$license = get_option( $this->item_shortname . '_license_active' );

		if ( ( ! is_object( $license ) || 'valid' !== $license->license ) && empty( $showed_imissing_key_message[ $this->item_shortname ] ) ) {

			echo '&nbsp;<strong><a href="' . esc_url( admin_url( 'edit.php?post_type=popup&page=pum-settings&tab=licenses' ) ) . '">' . __( 'Enter valid license key for automatic updates.', 'popup-maker' ) . '</a></strong>';
			$showed_imissing_key_message[ $this->item_shortname ] = true;
		}

	}

	/**
	 * Adds this plugin to the beta page
	 *
	 * @access  public
	 *
	 * @param   array $products
	 *
	 * @return array
	 */
	public function register_beta_support( $products ) {
		$products[ $this->item_shortname ] = $this->item_name;

		return $products;
	}
}
