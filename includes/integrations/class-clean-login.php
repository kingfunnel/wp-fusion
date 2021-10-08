<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Clean Login integration class
 *
 * @since 3.21.2
 */

class WPF_Clean_Login extends WPF_Integrations_Base {

	/**
	 * Get things started.
	 *
	 * @since 3.21.2
	 */
	public function init() {

		$this->slug = 'clean-login';

		add_filter( 'wpf_user_register', array( $this, 'filter_form_fields' ), 10, 2 );
		add_filter( 'wpf_user_update', array( $this, 'filter_form_fields' ), 10, 2 );

	}


	/**
	 * Filters registration data before sending to the CRM.
	 *
	 * @since  3.21.2
	 *
	 * @param  array    $post_data  The post data.
	 * @param  integer  $user_id    The user ID.
	 * @return array    Registration / Update data.
	 */
	public function filter_form_fields( $post_data, $user_id ) {

		$field_map = array(
			'pass1'    => 'user_pass',
			'email'    => 'user_email',
			'username' => 'user_login',
		);

		$post_data = $this->map_meta_fields( $post_data, $field_map );

		return $post_data;

	}

}

new WPF_Clean_Login();
