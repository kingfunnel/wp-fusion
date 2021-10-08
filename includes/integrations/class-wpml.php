<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}


class WPF_WPML extends WPF_Integrations_Base {

	/**
	 * Gets things started
	 *
	 * @access  public
	 * @since   1.0
	 * @return  void
	 */

	public function init() {

		$this->slug = 'wpml';

		add_action( 'pre_get_posts', array( $this, 'pre_get_posts' ) );

		add_filter( 'wpf_meta_field_groups', array( $this, 'add_meta_field_group' ), 10 );
		add_filter( 'wpf_meta_fields', array( $this, 'prepare_meta_fields' ), 10 );
		add_action( 'init', array( $this, 'sync_language' ) );

	}


	/**
	 * Suppress filters when automated enrollments are happening due to tag changes (this fixes a bug where LearnDash doesn't see the user enrolled in a French course if an update_tags webhook comes in on the English site)
	 *
	 * @access  public
	 * @since   3.35.7
	 * @return  void
	 */

	public function pre_get_posts( $query ) {

		if ( is_object( $query ) && doing_action( 'wpf_tags_modified' ) ) {
			$query->set( 'suppress_filters', true );
		}

	}

	/**
	 * Add Language Code field to contact fields list
	 *
	 * @access  public
	 * @since   1.0
	 * @return  array Field Groups
	 */

	public function add_meta_field_group( $field_groups ) {

		if( !isset( $field_groups['wpml'] ) ) {
			$field_groups['wpml'] = array( 'title' => 'WPML', 'fields' => array() );
		}

		return $field_groups;

	}

	/**
	 * Adds WPML meta fields to WPF contact fields list
	 *
	 * @access  public
	 * @return  array Meta Fields
	 */

	public function prepare_meta_fields( $meta_fields ) {

		$meta_fields['language_code'] = array( 'label' => 'Language Code', 'type' => 'text', 'group' => 'wpml' );
		$meta_fields['language_name'] = array( 'label' => 'Language Name', 'type' => 'text', 'group' => 'wpml' );

		return $meta_fields;

	}

	/**
	 * Detects current language and syncs if necessary
	 *
	 * @access  public
	 * @since   1.0
	 * @return  void
	 */

	public function sync_language() {

		if( ! wpf_is_user_logged_in() ) {
			return;
		}

		$language_code = get_user_meta( wpf_get_current_user_id(), 'language_code', true );
		$language_name = get_user_meta( wpf_get_current_user_id(), 'language_name', true );

		if( $language_code != ICL_LANGUAGE_CODE || $language_name != ICL_LANGUAGE_NAME ) {

			update_user_meta( wpf_get_current_user_id(), 'language_code', ICL_LANGUAGE_CODE );
			update_user_meta( wpf_get_current_user_id(), 'language_name', ICL_LANGUAGE_NAME );

			wp_fusion()->user->push_user_meta( wpf_get_current_user_id(), array( 'language_code' => ICL_LANGUAGE_CODE, 'language_name' => ICL_LANGUAGE_NAME ) );

		}

	}

}

new WPF_WPML;