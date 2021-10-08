<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

GFForms::include_feed_addon_framework();

class WPF_GForms_Integration extends GFFeedAddOn {

	protected $_version                  = WP_FUSION_VERSION;
	protected $_min_gravityforms_version = '1.7.9999';
	protected $_slug                     = 'wpfgforms';
	protected $_full_path                = __FILE__;
	protected $_title                    = 'CRM Integration';
	protected $_short_title              = 'WP Fusion';
	protected $postvars                  = array();
	public $feed_lists;

	protected $_capabilities_settings_page = array( 'manage_options' );
	protected $_capabilities_form_settings = array( 'manage_options' );
	protected $_capabilities_plugin_page   = array( 'manage_options' );
	protected $_capabilities_app_menu      = array( 'manage_options' );
	protected $_capabilities_app_settings  = array( 'manage_options' );
	protected $_capabilities_uninstall     = array( 'manage_options' );

	protected $setting_key;

	/**
	 * The slug name for WP Fusion's module tracking.
	 *
	 * @since 3.36.5
	 * @var slug
	 */

	public $slug = 'gravity-forms';


	/**
	 * Get parent running
	 *
	 * @access  public
	 * @return  void
	 */

	public function init() {

		parent::init();

		// Batch operations
		add_filter( 'wpf_export_options', array( $this, 'export_options' ) );
		add_filter( 'wpf_batch_gravity_forms_init', array( $this, 'batch_init' ) );
		add_action( 'wpf_batch_gravity_forms', array( $this, 'batch_step' ) );

		// Payments
		add_action( 'gform_post_payment_status', array( $this, 'paypal_payment_received' ), 10, 8 );
		add_action( 'gform_stripe_fulfillment', array( $this, 'stripe_payment_received' ), 10, 8 );

		// User registration
		add_action( 'gform_user_registered', array( $this, 'user_registered' ), 20, 4 ); // 20 so it runs after the BuddyPress actions in GF_User_Registration
		add_action( 'gform_user_updated', array( $this, 'user_updated' ), 10, 4 );
		add_filter( 'gform_user_registration_update_user_id', array( $this, 'update_user_id' ) );

		// Merge tag
		add_action( 'gform_admin_pre_render', array( $this, 'add_merge_tags' ) );
		add_filter( 'gform_replace_merge_tags', array( $this, 'replace_merge_tags' ), 10, 7 );
		add_action( 'gform_post_enqueue_scripts', array( $this, 'maybe_pre_fill_forms' ) );

		// Meta box
		add_filter( 'gform_entry_detail_meta_boxes', array( $this, 'register_meta_box' ), 10, 3 );
		add_action( 'admin_init', array( $this, 'maybe_process_entry' ) );

		if ( version_compare( GFCommon::$version, '2.5' ) >= 0 ) {
			$this->setting_key = '_gform_setting'; // 2.5 and up
		} else {
			$this->setting_key = '_gaddon_setting';
		}

		if ( class_exists( 'GP_Nested_Forms' ) ) {
			add_action( 'gform_after_submission', array( $this, 'process_nested_forms' ), 10, 2 );
		}

	}


	/**
	 * Triggered when form is submitted
	 *
	 * @access  public
	 * @return  void
	 */

	public function process_feed( $feed, $entry, $form ) {

		gform_update_meta( $entry['id'], 'wpf_complete', false );

		$update_data   = array();
		$email_address = false;

		// Check payment status
		if ( isset( $feed['meta']['payment_status'] ) && 'always' != $feed['meta']['payment_status'] ) {

			$paid_statuses = array( 'Paid', 'Approved', 'Active' );

			if ( 'paid_only' == $feed['meta']['payment_status'] ) {

				if ( empty( $entry['payment_status'] ) || ! in_array( $entry['payment_status'], $paid_statuses ) ) {
					// Form is set to Paid Only and payment status is not paid
					return;
				}
			} elseif ( 'fail_only' == $feed['meta']['payment_status'] && 'Processing' != $entry['payment_status'] ) {

				if ( ! empty( $entry['payment_status'] ) && in_array( $entry['payment_status'], $paid_statuses ) ) {
					// Form is set to Fail Only and payment status is not failed
					return;

				}
			}
		}

		// PDFs

		if ( class_exists( 'GPDFAPI' ) ) {

			$model_pdf = \GPDFAPI::get_mvc_class( 'Model_PDF' );
			$pdfs      = $model_pdf->get_pdf_display_list( $entry );

			foreach ( $pdfs as $pdf ) {
				$entry[ $pdf['settings']['id'] ] = $pdf['view'];
			}
		}

		// Tidy up some stuff before field mapping

		foreach ( $entry as $field_id => $value ) {

			// Maybe use labels instead of value

			if ( ! empty( $feed['meta']['sync_labels'] ) ) {

				$field = GFAPI::get_field( $form, $field_id );

				if ( $field ) {
					$entry[ $field_id ] = $field->get_value_export( $entry, $field_id, true );
				}
			}

			// Combine multiselects where appropriate

			if ( strpos( $field_id, '.' ) !== false && ! empty( $value ) ) {

				$field_id = explode( '.', $field_id );

				if ( ! isset( $entry[ $field_id[0] ] ) ) {
					$entry[ $field_id[0] ] = array();
				}

				$entry[ $field_id[0] ][ $field_id[1] ] = $value;

			}
		}

		// Prepare update array
		foreach ( $feed['meta']['wpf_fields'] as $id => $data ) {

			// Convert dashes back into points for isset
			$id = str_replace( '-', '.', $id );

			if ( isset( $entry[ $id ] ) && ( ! empty( $entry[ $id ] ) || $entry[ $id ] == 0 ) && ! empty( $data['crm_field'] ) ) {

				if ( 'multiselect' == $data['type'] && is_string( $entry[ $id ] ) && 0 === strpos( $entry[ $id ], '[' ) ) {

					// Convert multiselects into array format
					$entry[ $id ] = str_replace( '"', '', $entry[ $id ] );
					$entry[ $id ] = str_replace( '[', '', $entry[ $id ] );
					$entry[ $id ] = str_replace( ']', '', $entry[ $id ] );
					$entry[ $id ] = explode( ',', $entry[ $id ] );

				} elseif ( 'multiselect' == $data['type'] && is_array( $entry[ $id ] ) ) {

					// GForms has associative arrays sometimes for some reason

					$entry[ $id ] = array_values( $entry[ $id ] );

				}

				$value = apply_filters( 'wpf_format_field_value', $entry[ $id ], $data['type'], $data['crm_field'] );

				if ( ! empty( $value ) || 0 === $value || '0' === $value ) {

					// Don't sync empty values unless they're actually the number 0

					if ( 'fileupload' == $data['type'] ) {
						$value = stripslashes( $value );
					}

					$update_data[ $data['crm_field'] ] = $value;

					// For determining the email address, we'll try to find a field
					// mapped to the main lookup field in the CRM, but if not we'll take
					// the first email address on the form.

					if ( is_email( $value ) && wpf_get_lookup_field() == $data['crm_field'] ) {
						$email_address = $value;
					} elseif ( false == $email_address && 'email' == $data['type'] && is_email( $value ) ) {
						$email_address = $value;
					}
				}
			}
		}

		// Form meta

		foreach ( $feed['meta']['wpf_fields'] as $id => $data ) {

			if ( strpos( $id, '{' ) === 0 && ! empty( $data['crm_field'] ) ) {
				$update_data[ $data['crm_field'] ] = GFCommon::replace_variables( $id, $form, $entry );
			}
		}

		// Possibly deal with lists if the CRM supports it
		if ( isset( $feed['meta']['wpf_lists'] ) && ! empty( $feed['meta']['wpf_lists'] ) ) {

			$this->feed_lists = $feed['meta']['wpf_lists'];

			add_filter( 'wpf_add_contact_lists', array( $this, 'filter_lists' ) );
			add_filter( 'wpf_update_contact_lists', array( $this, 'filter_lists' ) );

		}

		if ( ! isset( $feed['meta']['wpf_tags'] ) ) {
			$feed['meta']['wpf_tags'] = array();
		}

		$args = array(
			'email_address'    => $email_address,
			'update_data'      => $update_data,
			'apply_tags'       => $feed['meta']['wpf_tags'],
			'integration_slug' => 'gform',
			'integration_name' => 'Gravity Forms',
			'form_id'          => $form['id'],
			'form_title'       => $form['title'],
			'form_edit_link'   => admin_url( 'admin.php?page=gf_edit_forms&id=' . $form['id'] ),
		);

		$contact_id = WPF_Forms_Helper::process_form_data( $args );

		if ( is_wp_error( $contact_id ) ) {

			$this->add_feed_error( $contact_id->get_error_message(), $feed, $entry, $form );

		} else {

			gform_update_meta( $entry['id'], 'wpf_complete', true );

			gform_update_meta( $entry['id'], 'wpf_contact_id', $contact_id );

			$this->add_note( $entry['id'], 'Entry synced to ' . wp_fusion()->crm->name . ' (contact ID ' . $contact_id . ')' );

		}

		// Return after login + auto login

		if ( isset( $_COOKIE['wpf_return_to'] ) && doing_wpf_auto_login() ) {

			$post_id = absint( $_COOKIE['wpf_return_to'] );
			$url     = get_permalink( $post_id );

			setcookie( 'wpf_return_to', '', time() - ( 15 * 60 ) );

			if ( ! empty( $url ) && wpf_user_can_access( $post_id ) ) {

				add_filter(
					'gform_confirmation', function( $confirmation, $form, $entry ) use ( &$url ) {

						$confirmation = array( 'redirect' => $url );

						return $confirmation;

					}, 10, 3
				);

			}
		}

	}

	/**
	 * Triggered when PayPal payment is received
	 *
	 * @access  public
	 * @return  void
	 */

	public function paypal_payment_received( $feed, $entry, $status, $transaction_id, $subscriber_id, $amount, $pending_reason, $reason ) {

		if ( 'Paid' == $status || 'Completed' == $status ) {

			$form  = GFAPI::get_form( $entry['form_id'] );
			$feeds = $this->get_feeds( $entry['form_id'] );

			foreach ( $feeds as $feed ) {

				if ( 'wpfgforms' == $feed['addon_slug'] && isset( $feed['meta']['payment_status'] ) && 'always' != $feed['meta']['payment_status'] ) {

					if ( $this->is_feed_condition_met( $feed, $form, $entry ) ) {
						$this->process_feed( $feed, $entry, $form );
					}

				}
			}
		}

	}

	/**
	 * Triggered when Stripe payment is received
	 *
	 * @access  public
	 * @return  void
	 */

	public function stripe_payment_received( $session, $entry, $feed, $form ) {

		$feeds = $this->get_feeds( $entry['form_id'] );

		foreach ( $feeds as $feed ) {

			if ( 'wpfgforms' == $feed['addon_slug'] && isset( $feed['meta']['payment_status'] ) && 'always' != $feed['meta']['payment_status'] ) {

				if ( $this->is_feed_condition_met( $feed, $form, $entry ) ) {
					$this->process_feed( $feed, $entry, $form );
				}

			}
		}

	}

	/**
	 * Process nested forms (Gravity Perks Nested Forms addon).
	 *
	 * @since 3.37.21
	 * @since 3.37.29 Moved to gform_after_submission hook.
	 *
	 * @param array $entry  The entry.
	 * @param array $form   The form.
	 */
	public function process_nested_forms( $entry, $form ) {

		if ( gp_nested_forms()->is_nested_form_submission() || ! gp_nested_forms()->has_nested_form_field( $form ) ) {
			return;
		}

		$_entry        = new GPNF_Entry( $entry );
		$child_entries = $_entry->get_child_entries();
		foreach ( $child_entries as $child_entry ) {

			$form  = gp_nested_forms()->get_nested_form( $child_entry['form_id'] );
			$feeds = GFAPI::get_feeds( null, $form['id'], 'wpfgforms' );

			foreach ( $feeds as $feed ) {
				if ( $this->is_feed_condition_met( $feed, $form, $child_entry ) ) {
					$this->process_feed( $feed, $child_entry, $form );
				}
			}
		}
	}


	/**
	 * Displays table for mapping fields
	 *
	 * @access  public
	 * @return  void
	 */

	public function settings_wpf_fields( $field ) {

		$form = $this->get_current_form();

		// Quiz Handling
		$quiz_fields = GFAPI::get_fields_by_type( $form, array( 'quiz' ) );

		if ( ! empty( $quiz_fields ) ) {

			$quiz_fields = array(
				'gquiz_score'   => 'Quiz Score Total',
				'gquiz_percent' => 'Quiz Score Percentage',
				'gquiz_grade'   => 'Quiz Grade',
				'gquiz_is_pass' => 'Quiz Pass/Fail',
			);

		}

		do_action( 'wpf_gform_settings_before_table', $form );

		echo '<table class="settings-field-map-table wpf-field-map" cellspacing="0" cellpadding="0">';

		echo '<tbody>';

		echo '<tr><td colspan="2"><strong><br />' . __( 'Form Fields', 'wp-fusion' ) . '</strong></td></tr>';

		$email_found = false;

		foreach ( $form['fields'] as $field ) {

			if ( $field['type'] == 'html' || $field['type'] == 'page' || $field['type'] == 'section' ) {
				continue;
			}

			// Fix for date dropdown fields

			if ( $field->type == 'date' ) {
				$field->inputs = null;
			}

			if ( $field->inputs == null ) {

				// Handing for simple fields (no subfields)
				if ( $field->type == 'email' ) {
					$email_found = true;
				}

				$label = $field->label;

				if ( empty( $label ) ) {
					$label = '<em>(Field ID ' . $field->id . ' - ' . ucwords( $field->type ) . ')</em>';
				}

				echo '<tr>';
				echo '<td><label>' . $label . '<label></td>';
				echo '<td><i class="fa fa-angle-double-right"></i></td>';
				echo '<td>';

				wpf_render_crm_field_select( $this->get_setting( 'wpf_fields[' . $field->id . '][crm_field]' ), $this->setting_key . '_wpf_fields', $field->id );

				do_action( 'wpf_gform_settings_after_field_select', $field->id, $this, $form );

				$this->settings_hidden(
					array(
						'label'         => '',
						'name'          => 'wpf_fields[' . $field->id . '][type]',
						'default_value' => $field->type,
					)
				);

				echo '</td>';
				echo '</tr>';

			} else {

				// Fields with subfields (Name, Address, etc.)
				$label = $field->label;

				if ( empty( $label ) ) {
					$label = '<em>(Field ID ' . $field->id . ' - ' . ucwords( $field->type ) . ')</em>';
				}

				// For multi-check checkboxes allow either the whole field or just the subfields
				if ( $field->type == 'checkbox' && count( $field->inputs ) > 1 ) {

					echo '<tr>';
					echo '<td><label>' . $label . ' (checkboxes)<label></td>';
					echo '<td><i class="fa fa-angle-double-right"></i></td>';
					echo '<td>';
					wpf_render_crm_field_select( $this->get_setting( 'wpf_fields[' . $field->id . '][crm_field]' ), $this->setting_key . '_wpf_fields', $field->id );

					do_action( 'wpf_gform_settings_after_field_select', $field->id, $this, $form );

					$this->settings_hidden(
						array(
							'label'         => '',
							'name'          => 'wpf_fields[' . $field->id . '][type]',
							'default_value' => 'multiselect',
						)
					);

					echo '</td>';
					echo '</tr>';

				}

				foreach ( $field->inputs as $input ) {

					if ( ! isset( $input['isHidden'] ) || $input['isHidden'] == false ) {

						if ( $field->type == 'email' ) {
							$email_found = true;
						}

						if ( $input['label'] == 'First' ) {
							$std  = 'First Name';
							$name = 'FirstName';
						} elseif ( $input['label'] == 'Last' ) {
							$std  = 'Last Name';
							$name = 'LastName';
						} else {
							$std  = '';
							$name = '';
						}

						echo '<tr>';
						echo '<td><label>' . $label . ' - ' . $input['label'] . '<label></td>';
						echo '<td><i class="fa fa-angle-double-right"></i></td>';

						echo '<td>';
						wpf_render_crm_field_select( $this->get_setting( 'wpf_fields[' . str_replace( '.', '-', $input['id'] ) . '][crm_field]' ), $this->setting_key . '_wpf_fields', str_replace( '.', '-', $input['id'] ) );

						do_action( 'wpf_gform_settings_after_field_select', $field->id, $this, $form );

						$this->settings_hidden(
							array(
								'label'         => '',
								'name'          => 'wpf_fields[' . str_replace( '.', '-', $input['id'] ) . '][type]',
								'default_value' => $field->type,
							)
						);

						echo '</td>';
						echo '</tr>';

					}
				}
			}
		}

		if ( ! empty( $quiz_fields ) ) {

			echo '<tr><td colspan="2"><strong><br />' . __( 'Quiz Fields', 'wp-fusion' ) . '</strong></td></tr>';

			foreach ( $quiz_fields as $id => $label ) {

				echo '<tr>';
				echo '<td><label>' . $label . '<label></td>';
				echo '<td><i class="fa fa-angle-double-right"></i></td>';
				echo '<td>';
				wpf_render_crm_field_select( $this->get_setting( 'wpf_fields[' . $id . '][crm_field]' ), $this->setting_key . '_wpf_fields', $id );

				do_action( 'wpf_gform_settings_after_field_select', $field->id, $this, $form );

				$this->settings_hidden(
					array(
						'label'         => '',
						'name'          => 'wpf_fields[' . $id . '][type]',
						'default_value' => 'text',
					)
				);

				echo '</td>';
				echo '</tr>';

			}
		}

		// Gravity Forms PDF

		if ( class_exists( 'GPDFAPI' ) ) {

			$pdfs = GPDFAPI::get_form_pdfs( $form['id'] );

			if ( ! empty( $pdfs ) ) {

				echo '<tr><td colspan="2"><strong><br />' . __( 'PDF URLs', 'wp-fusion' ) . '</strong></td></tr>';

				foreach ( $pdfs as $id => $pdf ) {

					echo '<tr>';
					echo '<td><label>' . $pdf['name'] . '<label></td>';
					echo '<td><i class="fa fa-angle-double-right"></i></td>';
					echo '<td>';

					wpf_render_crm_field_select( $this->get_setting( 'wpf_fields[' . $id . '][crm_field]' ), $this->setting_key . '_wpf_fields', $id );

					do_action( 'wpf_gform_settings_after_field_select', $field->id, $this, $form );

					$this->settings_hidden(
						array(
							'label'         => '',
							'name'          => 'wpf_fields[' . $id . '][type]',
							'default_value' => 'text',
						)
					);

					echo '</td>';
					echo '</tr>';

				}
			}
		}

		// Meta

		echo '<tr><td colspan="2"><strong><br />' . __( 'Meta', 'wp-fusion' ) . '</strong></td></tr>';

		$tags = GFCommon::get_merge_tags( array(), false );

		foreach ( $tags['other']['tags'] as $tag ) {

			echo '<tr>';
			echo '<td><label>' . $tag['label'] . '<label></td>';
			echo '<td><i class="fa fa-angle-double-right"></i></td>';
			echo '<td>';

			wpf_render_crm_field_select( $this->get_setting( 'wpf_fields[' . $tag['tag'] . '][crm_field]' ), $this->setting_key . '_wpf_fields', $tag['tag'] );

			do_action( 'wpf_gform_settings_after_field_select', $tag['tag'], $this, $form );

			$this->settings_hidden(
				array(
					'label'         => '',
					'name'          => 'wpf_fields[' . $tag['tag'] . '][type]',
					'default_value' => 'text',
				)
			);

			echo '</td>';
			echo '</tr>';

		}

		echo '</tbody>';
		echo '</table>';

		if ( $email_found == false ) {
			echo '<div class="alert danger"><strong>Warning:</strong> No <i>email</i> type field found on this form. Entries from guest users will not be sent to ' . wp_fusion()->crm->name . '.</div>';
		}

		do_action( 'wpf_gform_settings_after_table', $form );

	}

	/**
	 * Saves settings
	 *
	 * @access  public
	 * @return  array Settings
	 */

	public function save_wpf_fields( $field, $setting ) {

		foreach ( $setting as $index => $fields ) {

			if ( ! empty( $fields['crm_field'] ) ) {
				$setting[ $index ]['crm_field'] = $setting[ $index ]['crm_field'];
			} else {
				unset( $setting[ $index ] );
			}
		}

		return $setting;

	}


	/**
	 * Renders tag multi select field
	 *
	 * @access  public
	 * @return  void
	 */

	public function settings_wpf_tags( $field ) {

		wpf_render_tag_multiselect(
			array(
				'setting'   => $this->get_setting( $field['name'] ),
				'meta_name' => $this->setting_key . '_' . $field['name'],
			)
		);

	}

	/**
	 * Renders tag multi select field
	 *
	 * @access  public
	 * @return  void
	 */

	public function settings_wpf_lists( $field ) {

		echo '<select multiple id="' . $this->setting_key . '_wpf_lists" class="select4 select4-hidden-accessible" name="' . $this->setting_key . '_wpf_lists[]" data-placeholder="Select lists" tabindex="-1" aria-hidden="true">';

		$lists     = wpf_get_option( 'available_lists', array() );
		$selection = $this->get_setting( $field['name'] );

		if ( empty( $selection ) ) {
			$selection = array();
		} elseif ( ! is_array( $selection ) ) {
			$selection = array( $selection );
		}

		foreach ( $lists as $list_id => $label ) {
			echo '<option ' . selected( true, in_array( $list_id, $selection ), false ) . ' value="' . $list_id . '">' . $label . '</option>';
		}

		echo '</select>';

	}

	/**
	 * Overrides the default lists with those present on the form, if applicable
	 *
	 * @access  public
	 * @return  array Lists
	 */

	public function filter_lists( $lists ) {

		return $this->feed_lists;

	}

	/**
	 * Defines settings for the feed
	 *
	 * @access  public
	 * @return  array Feed settings
	 */

	public function feed_settings_fields() {

		$fields = array();

		$fields[] = array(
			'label'   => __( 'Feed Name', 'wp-fusion' ),
			'type'    => 'text',
			'name'    => 'feedName',
			'tooltip' => __( 'Enter a name to remember this feed by.', 'wp-fusion' ),
			'class'   => 'small',
		);

		$fields[] = array(
			'name'          => 'wpf_fields',
			'label'         => __( 'Map Fields', 'wp-fusion' ),
			'type'          => 'wpf_fields',
			'tooltip'       => __( 'Select a CRM field from the dropdown, or leave blank to disable sync', 'wp-fusion' ),
			'save_callback' => array( $this, 'save_wpf_fields' ),
		);

		// See if we need to show the Sync Labels setting

		$form = $this->get_current_form();

		$options_found = false;

		foreach ( $form['fields'] as $field ) {

			if ( 'checkboxes' == $field['type'] || 'select' == $field['type'] || 'radio' == $field['type'] || 'multiselect' == $field['type'] ) {
				$options_found = true;
				break;
			}
		}

		$fields[] = array(
			'type'    => 'checkbox',
			'name'    => 'sync_labels',
			'label'   => __( 'Sync Labels', 'wp-fusion' ),
			'tooltip' => __( 'By default WP Fusion syncs the values of selected checkboxes, radios, and dropdowns. Enable this setting to sync the option labels instead.', 'wp-fusion' ),
			'choices' => array(
				array(
					'label' => __( 'Sync option labels instead of values', 'wp-fusion' ),
					'name'  => 'sync_labels',
				),
			),
		);

		$fields[] = array(
			'name'    => 'wpf_tags',
			'label'   => __( 'Apply Tags', 'wp-fusion' ),
			'type'    => 'wpf_tags',
			'tooltip' => sprintf( __( 'Select tags to be applied in %s when this form is submitted.', 'wp-fusion' ), wp_fusion()->crm->name ),
		);

		if ( is_array( wp_fusion()->crm->supports ) && in_array( 'add_lists', wp_fusion()->crm->supports ) ) {

			$fields[] = array(
				'name'    => 'wpf_lists',
				'label'   => 'Add to Lists',
				'type'    => 'wpf_lists',
				'tooltip' => sprintf( __( 'Select %s lists to add new contacts to.', 'wp-fusion' ), wp_fusion()->crm->name ),
			);
		}

		// Maybe add payment fields
		$feeds        = GFAPI::get_feeds( null, $_GET['id'] );
		$has_payments = false;

		foreach ( $feeds as $feed ) {
			if ( isset( $feed['addon_slug'] ) && $feed['addon_slug'] == 'gravityformsstripe' || isset( $feed['addon_slug'] ) && $feed['addon_slug'] == 'gravityformspaypal' ) {
				$has_payments = true;
				break;
			}
		}

		if ( $has_payments ) {

			$fields[] = array(
				'name'          => 'payment_status',
				'label'         => 'Payment Status',
				'type'          => 'radio',
				'default_value' => 'always',
				'choices'       => array(
					array(
						'label' => esc_html__( 'Process this feed regardless of payment status', 'wp-fusion' ),
						'value' => 'always',
					),
					array(
						'label' => esc_html__( 'Process this feed only if the payment is successful', 'wp-fusion' ),
						'value' => 'paid_only',
					),
					array(
						'label' => esc_html__( 'Process this feed only if the payment fails', 'wp-fusion' ),
						'value' => 'fail_only',
					),
				),
			);
		}

		$fields[] = array(
			'type'           => 'feed_condition',
			'name'           => 'condition',
			'label'          => 'Opt-In Condition',
			'checkbox_label' => 'Enable Condition',
			'instructions'   => 'Process this feed if',
		);

		$fields = apply_filters( 'wpf_gform_settings_fields', $fields );

		return array(
			array(
				'title'  => wp_fusion()->crm->name . ' Integration',
				'fields' => $fields,
			),
		);
	}

	/**
	 * Creates columns for feed
	 *
	 * @access  public
	 * @return  array Feed settings
	 */

	public function feed_list_columns() {
		return array(
			'feedName' => __( 'Name', 'wp-fusion' ),
			'gftags'   => __( 'Applies Tags', 'wp-fusion' ),
		);
	}

	/**
	 * Override this function to allow the feed to being duplicated.
	 *
	 * @access public
	 * @param int|array $id The ID of the feed to be duplicated or the feed object when duplicating a form.
	 * @return boolean|true
	 */
	public function can_duplicate_feed( $id ) {
		return true;
	}

	/**
	 * Displays tags in custom column
	 *
	 * @access  public
	 * @return  string Configured tags
	 */


	public function get_column_value_gftags( $feed ) {

		$tags = rgars( $feed, 'meta/wpf_tags' );

		if ( empty( $tags ) ) {
			return '<em>-none-</em>';
		}

		$tag_labels = array();
		foreach ( (array) $tags as $tag ) {
			$tag_labels[] = wp_fusion()->user->get_tag_label( $tag );
		}

		return '<b>' . implode( ', ', $tag_labels ) . '</b>';
	}

	/**
	 * Set WPF logo for note avatar.
	 *
	 * @since  3.37.6
	 *
	 * @return string URL to logo.
	 */
	public function note_avatar() {

		return WPF_DIR_URL . '/assets/img/logo-sm-trans.png';

	}

	/**
	 * Loads stylesheets
	 *
	 * @access  public
	 * @return  array Styles
	 */

	public function styles() {

		if ( ! is_admin() ) {
			return parent::styles();
		}

		$styles = array(
			array(
				'handle'  => 'wpf_gforms_css',
				'src'     => WPF_DIR_URL . 'assets/css/wpf-gforms.css',
				'version' => $this->_version,
				'enqueue' => array(
					array( 'tab' => 'wpfgforms' ),
				),
			),
			array(
				'handle'  => 'select4',
				'src'     => WPF_DIR_URL . 'includes/admin/options/lib/select2/select4.min.css',
				'version' => '4.0.1',
				'enqueue' => array(
					array( 'tab' => 'wpfgforms' ),
				),
			),
			array(
				'handle'  => 'wpf-admin',
				'src'     => WPF_DIR_URL . 'assets/css/wpf-admin.css',
				'version' => $this->_version,
				'enqueue' => array(
					array( 'tab' => 'wpfgforms' ),
				),
			),
		);

		return array_merge( parent::styles(), $styles );
	}

	/**
	 * Loads scripts
	 *
	 * @access  public
	 * @return  array Scripts
	 */

	public function scripts() {
		$scripts = array(
			array(
				'handle'  => 'select4',
				'src'     => WPF_DIR_URL . 'includes/admin/options/lib/select2/select4.min.js',
				'version' => '4.0.1',
				'deps'    => array( 'jquery' ),
				'enqueue' => array(
					array(
						'admin_page' => array( 'form_settings' ),
						'tab'        => 'wpfgforms',
					),
				),
			),
			array(
				'handle'  => 'wpf-admin',
				'src'     => WPF_DIR_URL . 'assets/js/wpf-admin.js',
				'version' => $this->_version,
				'deps'    => array( 'jquery', 'select4' ),
				'enqueue' => array(
					array(
						'admin_page' => array( 'form_settings' ),
						'tab'        => 'wpfgforms',
					),
				),
			),
		);

		return array_merge( parent::scripts(), $scripts );
	}

	/**
	 * Push updated meta data after user registration
	 *
	 * @access  public
	 * @return  void
	 */

	public function user_registered( $user_id, $feed, $entry, $password ) {

		wp_fusion()->user->push_user_meta( $user_id );

		if ( ! empty( $password ) ) {
			wp_fusion()->user->push_user_meta( $user_id, array( 'user_pass' => $password ) );
		}

	}

	/**
	 * Push updated meta data after profile update
	 *
	 * @access  public
	 * @return  void
	 */

	public function user_updated( $user_id, $feed, $entry, $password ) {

		wp_fusion()->user->push_user_meta( $user_id );

		if ( ! empty( $password ) ) {
			wp_fusion()->user->push_user_meta( $user_id, array( 'user_pass' => $password ) );
		}

	}


	/**
	 * Disable user updating during auto login with GForms user registration
	 *
	 * @access  public
	 * @return  int User ID
	 */

	public function update_user_id( $user_id ) {

		if ( doing_wpf_auto_login() ) {
			$user_id = false;
		}

		return $user_id;

	}


	/**
	 * Add contact ID merge tag to dropdown
	 *
	 * @access  public
	 * @return  object Form
	 */

	public function add_merge_tags( $form ) {

		if ( ! did_action( 'admin_head' ) ) {
			return $form;
		}

		?>
		<script type="text/javascript">

			gform.addFilter('gform_merge_tags', 'wpf_add_merge_tags');

			function wpf_add_merge_tags(mergeTags, elementId, hideAllFields, excludeFieldTypes, isPrepop, option){
				mergeTags["other"].tags.push({ tag: '{contact_id}', label: 'Contact ID' });
				return mergeTags;
			}
		</script>

		<?php

		// return the form object from the php hook
		return $form;

	}


	/**
	 * Add contact ID merge tag to dropdown
	 *
	 * @access  public
	 * @return  object Form
	 */

	public function replace_merge_tags( $text, $form, $entry, $url_encode, $esc_html, $nl2br, $format ) {

		if ( false !== strpos( $text, '{contact_id}' ) ) {

			// Contact ID.

			$contact_id = gform_get_meta( $entry['id'], 'wpf_contact_id' );
			$text       = str_replace( '{contact_id}', $contact_id, $text );

		}

		return $text;

	}


	/**
	 * If we're in an auto-login session, set the $current_user global before
	 * the form is displayed so that {user:***} merge tags work automatically.
	 *
	 * @since 3.38.5
	 */
	public function maybe_pre_fill_forms() {

		if ( doing_wpf_auto_login() ) {

			global $current_user;
			// phpcs:ignore
			$current_user = wpf_get_current_user();

		}

	}


	/**
	 * Add a meta box to the entry with the sync status.
	 *
	 * @since  3.37.3
	 *
	 * @param  array $meta_boxes The properties for the meta boxes.
	 * @param  array $entry      The entry currently being viewed/edited.
	 * @param  array $form       The form object used to process the current
	 *                           entry.
	 *
	 * @uses   GFFeedAddOn::get_active_feeds()
	 * @uses   GFHelpScout::initialize_api()
	 * @return array
	 */
	public function register_meta_box( $meta_boxes, $entry, $form ) {

		if ( $this->get_active_feeds( $form['id'] ) ) {
			$meta_boxes[ $this->_slug ] = array(
				'title'    => esc_html__( 'WP Fusion', 'wp-fusion' ),
				'callback' => array( $this, 'add_details_meta_box' ),
				'context'  => 'side',
			);
		}

		return $meta_boxes;
	}

	/**
	 * The callback used to echo the content to the meta box.
	 *
	 * @since 3.37.3
	 *
	 * @param array $args   An array containing the form and entry objects.
	 * @return HTML output.
	 */
	public function add_details_meta_box( $args ) {

		?>

		<strong><?php printf( __( 'Synced to %s:', 'wp-fusion' ), wp_fusion()->crm->name ); ?></strong>&nbsp;

		<?php if ( gform_get_meta( $args['entry']['id'], 'wpf_complete' ) ) : ?>
			<span><?php _e( 'Yes', 'wp-fusion' ); ?></span>
			<span class="dashicons dashicons-yes-alt"></span>
		<?php else : ?>
			<span><?php _e( 'No', 'wp-fusion' ); ?></span>
			<span class="dashicons dashicons-no"></span>
		<?php endif; ?>

		<br /><br />

		<?php $contact_id = gform_get_meta( $args['entry']['id'], 'wpf_contact_id' ); ?>

		<?php if ( $contact_id ) : ?>

			<strong><?php _e( 'Contact ID:', 'wp-fusion' ); ?></strong>&nbsp;
			<span><?php echo $contact_id; ?></span>

			<?php if ( isset( wp_fusion()->crm->edit_url ) ) : ?>
				- <a href="<?php printf( wp_fusion()->crm->edit_url, $contact_id ); ?>" target="_blank"><?php _e( 'View', 'wp-fusion' ); ?> &rarr;</a>
			<?php endif; ?>

			<br /><br />

		<?php endif; ?>

		<?php

		$url_args = array(
			'gf_wpf' => 'process',
			'lid'    => $args['entry']['id'],
		);

		$url = add_query_arg( $url_args );

		?>

		<a href="<?php echo esc_url( $url ); ?>" class="button"><?php _e( 'Process WP Fusion actions again', 'wp-fusion' ); ?></a>

		<?php

	}

	/**
	 * Handle the Process WP Fusion actions again button.
	 *
	 * @since 3.37.3
	 *
	 * @uses  GFAddOn::get_current_entry()
	 * @uses  GFAPI::get_form()
	 * @uses  GFFeedAddOn::maybe_process_feed()
	 */
	public function maybe_process_entry() {

		// If we're not on the entry view page, return.
		if ( rgget( 'page' ) !== 'gf_entries' || rgget( 'view' ) !== 'entry' || rgget( 'gf_wpf' ) !== 'process' ) {
			return;
		}

		// Get the current form and entry.
		$form  = GFAPI::get_form( rgget( 'id' ) );
		$entry = GFAPI::get_entry( rgget( 'lid' ) );

		// Process feeds.
		$this->maybe_process_feed( $entry, $form );

	}

	/**
	 * Return the plugin's icon for the plugin/form settings menu.
	 *
	 * @since 3.37.21
	 *
	 * @return string
	 */
	public function get_menu_icon() {

		return wpf_logo_svg();

	}


	/**
	 * //
	 * // BATCH TOOLS
	 * //
	 **/

	/**
	 * Adds Woo Subscriptions checkbox to available export options
	 *
	 * @access public
	 * @return array Options
	 */

	public function export_options( $options ) {

		$options['gravity_forms'] = array(
			'label'   => 'Gravity Forms entries',
			'title'   => 'Entries',
			'tooltip' => 'Find Gravity Forms entries that have not been successfully processed by WP Fusion and syncs them to ' . wp_fusion()->crm->name . ' based on their configured feeds.',
		);

		return $options;

	}

	/**
	 * Gets total list of entries to be processed
	 *
	 * @access public
	 * @return array Subscriptions
	 */

	public function batch_init() {

		$entry_ids = array();

		$feeds = GFAPI::get_feeds( null, null, 'wpfgforms' );

		if ( empty( $feeds ) ) {
			return $entry_ids;
		}

		$form_ids = array();

		foreach ( $feeds as $feed ) {
			$form_ids[] = $feed['form_id'];
		}

		$search_criteria = array(
			'field_filters' => array(
				array(
					'key'      => 'wpf_complete',
					'value'    => '1',
					'operator' => '!=',
				),
			),
		);

		$entry_ids = GFAPI::get_entry_ids( $form_ids, $search_criteria );

		return $entry_ids;

	}

	/**
	 * Processes entry feeds
	 *
	 * @access public
	 * @return void
	 */

	public function batch_step( $entry_id ) {

		$entry = GFAPI::get_entry( $entry_id );
		$form  = GFAPI::get_form( $entry['form_id'] );
		$feeds = GFAPI::get_feeds( null, $entry['form_id'], 'wpfgforms' );

		foreach ( $feeds as $feed ) {

			if ( $this->is_feed_condition_met( $feed, $form, $entry ) ) {
				$this->process_feed( $feed, $entry, $form );
			}
		}

	}

}

wp_fusion()->integrations->{'gravity-forms'} = new WPF_GForms_Integration();
