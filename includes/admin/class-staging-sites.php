<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * This class handles detecting when the site URL has changed, and activating staging mode.
 *
 * @since 3.38.35
 */
class WPF_Staging_Sites {

	/**
	 * Constructs a new instance.
	 *
	 * @since 3.38.35
	 */
	public function __construct() {

		add_action( 'admin_init', array( $this, 'maybe_activate_staging_mode' ) );
		add_action( 'wpf_settings_notices', array( $this, 'show_compatibility_notices' ) );

	}

	/**
	 * Track the current version of the plugin, and log updates to the logs.
	 *
	 * @since 3.38.35
	 */
	public function maybe_activate_staging_mode() {

		$site_url = wpf_get_option( 'site_url' );

		if ( ! empty( $site_url ) && home_url() !== $site_url ) {

			wpf_log( 'notice', get_current_user_id(), 'Site URL changed from <strong>' . $site_url . '</strong> to <strong>' . home_url() . '</strong>. Activating staging mode.', array( 'source' => 'staging-mode' ) );

			wp_fusion()->settings->set( 'staging_mode', true );

		} elseif ( empty( $version ) ) {

			// First install.
			wp_fusion()->settings->set( 'site_url', home_url() );

		}

	}

	/**
	 * Shows compatibility notices with other plugins, on the WPF settings page
	 *
	 * @since 3.33.4
	 * @return mixed HTML output
	 */
	public function show_compatibility_notices() {

		if ( wpf_get_option( 'staging_mode' ) ) {

			echo '<div class="notice notice-warning wpf-notice"><p>';

			echo wp_kses_post( sprintf( __( '<strong>Heads up:</strong> WP Fusion is currently in Staging Mode. No data will be sent to or loaded from %s.', 'wp-fusion' ), esc_html( wp_fusion()->crm->name ) ) );

			echo '</p></div>';

		}

	}

}

new WPF_Staging_Sites();
