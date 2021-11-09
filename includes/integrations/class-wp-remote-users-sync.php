<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

class WPF_WP_Remote_Users_Sync extends WPF_Integrations_Base {

	/**
	 * The slug for WP Fusion's module tracking.
	 *
	 * @since 3.38.10
	 * @var string $slug
	 */

	public $slug = 'wp-remote-users-sync';

	/**
	 * The plugin name for WP Fusion's module tracking.
	 *
	 * @since 3.38.10
	 * @var string $name
	 */
	public $name = 'Wp-remote-users-sync';

	/**
	 * The link to the documentation on the WP Fusion website.
	 *
	 * @since 3.38.10
	 * @var string $docs_url
	 */
	public $docs_url = 'https://wpfusion.com/documentation/other/wp-remote-users-sync/';

	/**
	 * The WPRUS API.
	 *
	 * @since 3.38.12
	 * @var array $api
	 */
	public $api;

	/**
	 * Only send the data once per request.
	 *
	 * @since 3.38.16
	 * @var bool $fired
	 */
	public $fired = false;

	/**
	 * Gets things started
	 *
	 * @access  public
	 * @since   3.35.9
	 * @return  void
	 */
	public function init() {

		add_action( 'wprus_ready', array( $this, 'ready' ), 10, 4 );
		add_filter( 'wprus_action_data', array( $this, 'merge_contact_data' ), 10, 3 );

		add_action( 'wpf_tags_applied', array( $this, 'tags_modified' ) );
		add_action( 'wpf_tags_removed', array( $this, 'tags_modified' ) );

		// Catch incoming tag changes from remote sites.
		add_action( 'wprus_after_handle_action_notification', array( $this, 'handle_action_notification' ), 10, 3 );

	}

	/**
	 * Runs when WRPUS is ready and makes the API available to WP Fusion.
	 *
	 * @since 3.38.13
	 *
	 * @param Wprus          $wprus        The main WPRUS plugin.
	 * @param array          $api          The WPRUS API interfaces.
	 * @param Wprus_Settings $settings     The WPRUS settings.
	 * @param Wprus_Logger   $wprus_logger The logger.
	 */
	public function ready( $wprus, $api, $settings, $wprus_logger ) {

		$this->api = $api;

	}


	/**
	 * Merge the CID and tags into the create and update requests
	 *
	 * @access public
	 * @return array Data
	 */
	public function merge_contact_data( $data, $endpoint, $url ) {

		if ( 'create' === $endpoint || 'update' === $endpoint ) {

			$user = get_user_by( 'login', $data['username'] );

			if ( $user ) {

				$contact_id = wp_fusion()->user->get_contact_id( $user->ID );

				if ( ! empty( $contact_id ) ) {

					$data[ wp_fusion()->crm->slug . '_contact_id' ] = $contact_id;
					$data[ wp_fusion()->crm->slug . '_tags' ]       = wp_fusion()->user->get_tags( $user->ID );

					wpf_log( 'info', $user->ID, 'Synced tags to remote site ' . $url . ':', array( 'tag_array' => $data[ wp_fusion()->crm->slug . '_tags' ] ) );

				}
			}
		}

		return $data;

	}


	/**
	 * When the tags are modified, notify the remote site.
	 *
	 * @since 3.38.13
	 *
	 * @param int $user_id The user ID.
	 */
	public function tags_modified( $user_id ) {

		if ( ! $this->api['update']::is_doing_remote_action() && false === $this->fired ) {

			// We don't need to also do this in WPRUS, for example when editing a profile in the admin.
			remove_action( 'profile_update', array( $this->api['update'], 'notify_remote' ), PHP_INT_MAX, 2 );

			// Tags may be modified multiple times in the same request so to
			// avoid unnecessary remote syncs, we'll just queue up one for
			// shutdown.

			add_action(
				'shutdown',
				function() use ( &$user_id ) {
					$this->api['update']->notify_remote( $user_id, array() ); // we only need to do this once per pageload.
				}
			);

			$this->fired = true;

		}

	}

	/**
	 * Trigger appropriate actions when tags are modified via incoming request
	 *
	 * @access public
	 * @return void
	 */
	public function handle_action_notification( $endpoint, $data, $result ) {

		if ( true == $result && ( 'update' == $endpoint || 'create' == $endpoint ) ) {

			if ( ! empty( $data[ wp_fusion()->crm->slug . '_contact_id' ] ) ) {

				$user = get_user_by( 'login', $data['username'] );

				if ( $user ) {
					$user_id = $user->ID;
				} else {
					$user_id = wpf_get_user_id( $data[ wp_fusion()->crm->slug . '_contact_id' ] ); // try to get it from an existing contact ID.
				}

				if ( ! empty( $user_id ) ) {

					update_user_meta( $user_id, wp_fusion()->crm->slug . '_contact_id', $data[ wp_fusion()->crm->slug . '_contact_id' ] );

					if ( isset( $data[ wp_fusion()->crm->slug . '_tags' ] ) ) {

						wpf_log( 'info', $user_id, 'Loaded tags from remote site ' . $data['base_url'] . ':' );

						wp_fusion()->user->set_tags( $data[ wp_fusion()->crm->slug . '_tags' ], $user_id );

					}
				}
			}
		}

	}


}

new WPF_WP_Remote_Users_Sync();
