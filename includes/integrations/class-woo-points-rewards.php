<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}


class WPF_Woo_Points_Rewards extends WPF_Integrations_Base {

	/**
	 * Gets things started
	 *
	 * @access  public
	 * @return  void
	 */

	public function init() {

		$this->slug = 'woo-points-rewards';

		add_filter( 'wpf_meta_fields', array( $this, 'set_contact_field_names' ), 30 );

		add_action( 'wc_points_rewards_after_set_points_balance', array( $this, 'after_set_points_balance' ), 10, 2 );

	}

	/**
	 * Syncs points when they're awarded
	 *
	 * @access public
	 * @return void
	 */

	public function after_set_points_balance( $user_id, $points_balance ) {

		wp_fusion()->user->push_user_meta( $user_id, array( 'wc_points_balance' => $points_balance ) );

	}

	/**
	 * Adds points field to contact fields list
	 *
	 * @access public
	 * @return array Settings
	 */

	public function set_contact_field_names( $meta_fields ) {

		$meta_fields['wc_points_balance'] = array( 'label' => 'Points Balance', 'type' => 'text', 'group' => 'woocommerce' );

		return $meta_fields;

	}

}

new WPF_Woo_Points_Rewards;