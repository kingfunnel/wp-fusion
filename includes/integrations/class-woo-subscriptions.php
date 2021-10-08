<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

class WPF_Woo_Subscriptions extends WPF_Integrations_Base {

	/**
	 * Gets things started
	 *
	 * @access  public
	 * @return  void
	 */
	public function init() {

		$this->slug = 'woo-subscriptions';

		// Subscription statuses.
		add_action( 'woocommerce_subscription_status_updated', array( $this, 'subscription_status_updated' ), 20, 3 ); // 20 so it runs after the renewal date has been updated?
		add_action( 'woocommerce_subscriptions_switched_item', array( $this, 'subscription_item_switched' ), 10, 3 );

		// This hook "is triggered when the trial period for a subscription has
		// reached its end date. A subscription can have a trial period if a
		// trial length was set on the subscription product at the time it was
		// purchased or a trial end date was otherwise manually set on the
		// subscription." See
		// https://docs.woocommerce.com/document/subscriptions/develop/action-reference/#section-3.
		add_action( 'woocommerce_scheduled_subscription_trial_end', array( $this, 'trial_end' ) );

		add_action( 'woocommerce_subscription_renewal_payment_failed', array( $this, 'subscription_renewal_payment_failed' ), 10, 2 );
		add_action( 'woocommerce_subscription_date_updated', array( $this, 'maybe_handle_early_renewal' ) );

		// Bypass meta sync on a renewal order.
		add_filter( 'wpf_woocommerce_sync_customer_data', array( $this, 'maybe_skip_customer_data_sync' ), 10, 2 );

		// Sync fields when a subscription is manually edited.
		add_action( 'save_post', array( $this, 'save_post' ), 10, 3 );

		// Sync the subscription fields when a renewal order is re-processed by WPF.
		add_action( 'wpf_woocommerce_process_order_actions_again', array( $this, 'process_order_action' ) );

		// Don't do anything when posts are deleted / trashed.
		add_action( 'before_delete_post', array( $this, 'before_delete_post' ), 5 );
		add_action( 'wp_trash_post', array( $this, 'before_delete_post' ), 5 );

		// Meta fields.
		add_filter( 'wpf_meta_field_groups', array( $this, 'add_meta_field_group' ), 10 );
		add_filter( 'wpf_meta_fields', array( $this, 'prepare_meta_fields' ), 50 );

		// Admin settings.
		add_action( 'wpf_woocommerce_panel', array( $this, 'panel_content' ), 7 );

		// Batch operations.
		add_filter( 'wpf_export_options', array( $this, 'export_options' ) );
		add_action( 'wpf_batch_woo_subscriptions_init', array( $this, 'batch_init' ) );
		add_action( 'wpf_batch_woo_subscriptions', array( $this, 'batch_step' ) );

		add_action( 'wpf_batch_woo_subscriptions_meta_init', array( $this, 'batch_init_meta' ) );
		add_action( 'wpf_batch_woo_subscriptions_meta', array( $this, 'batch_step_meta' ) );

		// Admin tools.
		add_action( 'wpf_settings_page_init', array( $this, 'settings_page_init' ) );

	}


	/**
	 * Applies tags for a subscription based on subscription status
	 *
	 * @access public
	 * @return void
	 */

	public function apply_tags_for_subscription_status( $subscription, $status = false ) {

		if ( false == $status ) {
			$status = $subscription->get_status();
		}

		$user_id = $subscription->get_user_id();

		// Check the status to figure out which tags to apply and remove for each product
		$apply_tags  = array();
		$remove_tags = array();

		foreach ( $subscription->get_items() as $line_item ) {

			$product_id = $line_item->get_product_id();

			$settings = get_post_meta( $product_id, 'wpf-settings-woo', true );

			if ( empty( $settings ) ) {
				continue;
			}

			if ( 'active' == $status ) {

				// Active
				$apply_keys  = array( 'apply_tags' );
				$remove_keys = array( 'apply_tags_cancelled', 'apply_tags_hold', 'apply_tags_expired', 'apply_tags_pending_cancellation', 'apply_tags_payment_failed' );

			} elseif ( 'on-hold' == $status ) {

				// On Hold
				$apply_keys = array( 'apply_tags_hold' );

				if ( ! empty( $settings['remove_tags'] ) ) {
					$remove_keys = array( 'apply_tags' );
				}
			} elseif ( 'expired' == $status ) {

				// Expired
				$apply_keys = array( 'apply_tags_expired' );

				if ( ! empty( $settings['remove_tags'] ) ) {
					$remove_keys = array( 'apply_tags' );
				}
			} elseif ( 'cancelled' == $status ) {

				// Cancelled
				$apply_keys = array( 'apply_tags_cancelled' );

				if ( ! empty( $settings['remove_tags'] ) ) {
					$remove_keys = array( 'apply_tags' );
				}
			} elseif ( 'pending-cancel' == $status ) {

				// Pending cancel (don't remove original tags)
				$apply_keys = array( 'apply_tags_pending_cancellation' );

			} elseif ( 'payment-failed' == $status ) {

				// Payment failed (this isn't a real subscription status but we're including it here to have all the tagging in one place)
				$apply_keys = array( 'apply_tags_payment_failed' );

			}

			// Figure out which tags to apply and remove
			if ( ! empty( $apply_keys ) ) {

				foreach ( $apply_keys as $key ) {

					if ( ! empty( $settings[ $key ] ) ) {

						$apply_tags = array_merge( $apply_tags, $settings[ $key ] );

					}
				}
			}

			if ( ! empty( $remove_keys ) ) {

				foreach ( $remove_keys as $key ) {

					if ( ! empty( $settings[ $key ] ) ) {

						$remove_tags = array_merge( $remove_tags, $settings[ $key ] );

					}
				}
			}

			// Variations
			if ( ! empty( $line_item['variation_id'] ) ) {

				$variation_settings = get_post_meta( $line_item['variation_id'], 'wpf-settings-woo', true );

				if ( is_array( $variation_settings ) && ! empty( $variation_settings['apply_tags_variation'][ $line_item['variation_id'] ] ) ) {

					$variation_tags = $variation_settings['apply_tags_variation'][ $line_item['variation_id'] ];

					if ( 'active' == $status ) {

						$apply_tags = array_merge( $apply_tags, $variation_tags );

					} elseif ( in_array( $status, array( 'cancelled', 'expired', 'on-hold' ) ) && true == $settings['remove_tags'] ) {

						$remove_tags = array_merge( $remove_tags, $variation_tags );

					}
				}
			}
		}

		/**
		 * Filters the tags to apply to the user based on subscription status.
		 *
		 * @since 3.36.0
		 *
		 * @param array           $apply_tags   The tags to apply.
		 * @param string          $status       The subscription status.
		 * @param WC_Subscription $subscription The subscription object.
		 */

		$apply_tags = apply_filters( 'wpf_woocommerce_subscription_status_apply_tags', $apply_tags, $status, $subscription );

		/**
		 * Filters the tags to remove from the user based on subscription status.
		 *
		 * @since 3.36.0
		 *
		 * @param array           $remove_tags  The tags to remove.
		 * @param string          $status       The subscription status.
		 * @param WC_Subscription $subscription The subscription object.
		 */

		$remove_tags = apply_filters( 'wpf_woocommerce_subscription_status_remove_tags', $remove_tags, $status, $subscription );

		// If there's nothing to be done, don't bother logging it
		if ( empty( $apply_tags ) && empty( $remove_tags ) ) {
			return true;
		}

		if ( ! doing_action( 'woocommerce_subscription_status_updated' ) ) {

			// This already gets logged in subscription_status_updated() so we don't need it twice
			wpf_log( 'info', $user_id, 'Applying tags for WooCommerce subscription <a href="' . admin_url( 'post.php?post=' . $subscription->get_id() . '&action=edit' ) . '" target="_blank">#' . $subscription->get_id() . '</a> with status <strong>' . ucwords( $status ) . '</strong>.' );

		}

		if ( ! empty( $remove_tags ) ) {
			wp_fusion()->user->remove_tags( $remove_tags, $user_id );
		}

		if ( ! empty( $apply_tags ) ) {
			wp_fusion()->user->apply_tags( $apply_tags, $user_id );
		}

	}

	/**
	 * Sends relevant data from a subscription to the connected CRM
	 *
	 * @access public
	 * @return void
	 */

	public function sync_subscription_fields( $subscription ) {

		$status  = $subscription->get_status();
		$user_id = $subscription->get_user_id();

		$update_data = array(
			'sub_start_date'   => $subscription->get_date( 'date_created' ),
			'sub_end_date'     => $subscription->get_date( 'end' ),
			'sub_renewal_date' => $subscription->get_date( 'next_payment' ),
			'sub_status'       => $subscription->get_status(),
			'sub_id'           => $subscription->get_id(),
		);

		foreach ( $subscription->get_items() as $line_item ) {

			$product_id                      = $line_item->get_product_id();
			$update_data['sub_product_name'] = get_the_title( $product_id );
			break;

		}

		wp_fusion()->user->push_user_meta( $subscription->get_user_id(), $update_data );

	}

	/**
	 * Triggered when a subscription is activated / or otherwise has a status change
	 *
	 * @access public
	 * @return void
	 */

	public function subscription_status_updated( $subscription, $status, $old_status ) {

		$user_id = $subscription->get_user_id();

		// Don't run on staging sites

		if ( WC_Subscriptions::is_duplicate_site() && false == $force ) {

			wpf_log( 'notice', $user_id, 'WooCommerce subscription <a href="' . admin_url( 'post.php?post=' . $subscription->get_id() . '&action=edit' ) . '" target="_blank">#' . $subscription->get_id() . '</a> status changed to <strong>' . ucwords( $status ) . '</strong>, staging site detected so no tags will be modified.' );
			return;

		}

		// This is to prevent situations where a failed initial transaction creates an "on-hold" subscription, which is converted to "cancelled" by Woo 15 minutes later,
		// but in the meantime the customer may have had a successful payment. In that case we don't want to remove any tags or apply Cancelled tags if the customer is still subscribed to this product.
		// Also helps with cancelling duplicate subscriptions, for example if someone accidentally checked out twice.

		if ( 'active' !== $status ) {

			foreach ( $subscription->get_items() as $line_item ) {

				$product_id = $line_item->get_product_id();

				if ( wcs_user_has_subscription( $user_id, $product_id, 'active' ) ) {

					wpf_log( 'notice', $user_id, 'WooCommerce subscription <a href="' . admin_url( 'post.php?post=' . $subscription->get_id() . '&action=edit' ) . '" target="_blank">#' . $subscription->get_id() . '</a> status changed to <strong>' . ucwords( $status ) . '</strong>, but user still has another active subscription to product <strong>' . get_the_title( $product_id ) . '</strong>, so the status change will be ignored.' );
					return;

				}
			}
		}

		// Subscriptions go on hold during renewal payments, so we're going to wait to see if the payment was successful before doing anything

		if ( 'on-hold' == $status && ( doing_action( 'woocommerce_scheduled_subscription_payment' ) || wcs_cart_contains_early_renewal() ) ) {

			wpf_log( 'info', $user_id, 'WooCommerce subscription <a href="' . admin_url( 'post.php?post=' . $subscription->get_id() . '&action=edit' ) . '" target="_blank">#' . $subscription->get_id() . '</a> status set to <strong>' . ucwords( $status ) . '</strong>. Waiting to see if renewal payment is successful...' );

			add_action( 'woocommerce_scheduled_subscription_payment', array( $this, 'subscription_status_hold' ), 100 ); // 100 so it runs after the payment has been processed

			return;

		}

		wpf_log( 'info', $user_id, 'WooCommerce subscription <a href="' . admin_url( 'post.php?post=' . $subscription->get_id() . '&action=edit' ) . '" target="_blank">#' . $subscription->get_id() . '</a> status changed from <strong>' . ucwords( $old_status ) . '</strong> to <strong>' . ucwords( $status ) . '</strong>' );

		// Sync meta
		$this->sync_subscription_fields( $subscription );

		// Apply tags
		$this->apply_tags_for_subscription_status( $subscription, $status );

		// Allow other integrations to run based on the subscription status change
		foreach ( $subscription->get_items() as $line_item ) {

			$product_id = $line_item->get_product_id();

			if ( 'active' != $status && 'pending-cancel' != $status ) {

				do_action( 'wpf_woocommerce_product_subscription_inactive', $product_id, $subscription );

			} elseif ( 'active' == $status ) {

				do_action( 'wpf_woocommerce_product_subscription_active', $product_id, $subscription );

			}
		}

	}

	/**
	 * Processes changes to 'on-hold' two minutes later to prevent tags being removed and reapplied during renewal payments
	 *
	 * @access public
	 * @return void
	 */

	public function subscription_status_hold( $subscription_id ) {

		$subscription = wcs_get_subscription( $subscription_id );

		if ( ! wcs_is_subscription( $subscription ) ) {
			return;
		}

		$user_id = $subscription->get_user_id();

		if ( 'on-hold' != $subscription->get_status() ) {

			wpf_log( 'info', $user_id, 'Subscription <a href="' . admin_url( 'post.php?post=' . $subscription->get_id() . '&action=edit' ) . '" target="_blank">#' . $subscription->get_id() . '</a> no longer <strong>On-hold</strong>. Nothing to be done.' );

			if ( wpf_is_field_active( 'sub_renewal_date' ) ) {
				$this->sync_subscription_fields( $subscription );
			}

			return;

		}

		wpf_log( 'info', $user_id, 'Subscription <a href="' . admin_url( 'post.php?post=' . $subscription->get_id() . '&action=edit' ) . '" target="_blank">#' . $subscription->get_id() . '</a> still <strong>On-hold</strong>. Processing actions.' );

		// Update meta
		$this->sync_subscription_fields( $subscription );

		// Update tags
		$this->apply_tags_for_subscription_status( $subscription, 'on-hold' );

		// Allow other integrations to run based on the subscription status change
		foreach ( $subscription->get_items() as $line_item ) {

			$product_id = $line_item->get_product_id();

			do_action( 'wpf_woocommerce_product_subscription_inactive', $product_id, $subscription );

		}

	}

	/**
	 * Triggered when a subscription is switched. Tags for the new product are handled by the core Woo integration, so this just removes tags from the previous level (if enabled)
	 *
	 * @access public
	 * @return void
	 */

	public function subscription_item_switched( $subscription, $new_item, $old_item ) {

		$user_id  = $subscription->get_user_id();
		$settings = get_post_meta( $old_item['product_id'], 'wpf-settings-woo', true );

		// Sync meta
		$this->sync_subscription_fields( $subscription );

		// If we're removing tags from the old subscription
		if ( ! empty( $settings ) && ! empty( $settings['remove_tags'] ) ) {

			wpf_log( 'info', $user_id, 'WooCommerce subscription <a href="' . admin_url( 'post.php?post=' . $subscription->get_id() . '&action=edit' ) . '">#' . $subscription->get_id() . '</a> switched from <strong>' . $old_item['order_item_name'] . '</strong> to <strong>' . $new_item['name'] . '</strong>' );

			$remove_tags = array();

			if ( ! empty( $settings['apply_tags'] ) ) {
				$remove_tags = array_merge( $remove_tags, $settings['apply_tags'] );
			}

			// Maybe remove variation tags
			if ( isset( $old_item['variation_id'] ) && $old_item['variation_id'] != 0 ) {

				$variation_settings = get_post_meta( $old_item['variation_id'], 'wpf-settings-woo', true );

				if ( is_array( $variation_settings ) && isset( $variation_settings['apply_tags_variation'][ $old_item['variation_id'] ] ) ) {

					$remove_tags = array_merge( $remove_tags, $variation_settings['apply_tags_variation'][ $old_item['variation_id'] ] );

				}
			}

			// Make sure we're not removing anything that was just applied
			$settings = get_post_meta( $new_item['product_id'], 'wpf-settings-woo', true );

			$new_tags = array();

			if ( ! empty( $settings['apply_tags'] ) ) {
				$new_tags = array_merge( $new_tags, $settings['apply_tags'] );
			}

			if ( isset( $new_item['variation_id'] ) && $new_item['variation_id'] != 0 ) {

				$variation_settings = get_post_meta( $new_item['variation_id'], 'wpf-settings-woo', true );

				if ( is_array( $variation_settings ) && isset( $variation_settings['apply_tags_variation'][ $new_item['variation_id'] ] ) ) {

					$new_tags = array_merge( $new_tags, $variation_settings['apply_tags_variation'][ $new_item['variation_id'] ] );

				}
			}

			$remove_tags = array_diff( $remove_tags, $new_tags );

			if ( ! empty( $remove_tags ) ) {

				wp_fusion()->user->remove_tags( $remove_tags, $user_id );

			}
		}

	}

	/**
	 * Triggered when a subscription trial ends
	 *
	 * @access public
	 * @return void
	 */

	public function trial_end( $subscription_id ) {

		if ( WC_Subscriptions::is_duplicate_site() ) {
			return;
		}

		$subscription_object = wcs_get_subscription( $subscription_id );

		if ( ! wcs_is_subscription( $subscription_object ) ) {
			return;
		}

		$user_id = $subscription_object->get_user_id();

		wpf_log( 'info', $user_id, 'WooCommerce trial ended for subscription <a href="' . admin_url( 'post.php?post=' . $subscription_id . '&action=edit' ) . '" target="_blank">#' . $subscription_id . '</a>' );

		foreach ( $subscription_object->get_items() as $line_item ) {

			$settings = get_post_meta( $line_item['product_id'], 'wpf-settings-woo', true );

			if ( ! empty( $settings ) ) {
				wp_fusion()->user->apply_tags( $settings['apply_tags_converted'], $user_id );
			}
		}

	}

	/**
	 * Triggered when a subscription renewal payment fails
	 *
	 * @access public
	 * @return void
	 */

	public function subscription_renewal_payment_failed( $subscription, $last_order ) {

		$user_id = $subscription->get_user_id();

		if ( WC_Subscriptions::is_duplicate_site() ) {
			wpf_log( 'notice', $user_id, 'WooCommerce subscription <a href="' . admin_url( 'post.php?post=' . $subscription->get_id() . '&action=edit' ) . '" target="_blank">#' . $subscription->get_id() . '</a> status changed to <strong>Payment Failed</strong>, staging site detected so no tags will be modified.' );
			return;
		}

		$this->apply_tags_for_subscription_status( $subscription, 'payment-failed' );

	}

	/**
	 * Sync subscription fields during an early renewal (the Active subscription
	 * fields are applied by the main WooCommerce integration).
	 *
	 * @since 3.37.25
	 *
	 * @param WC_Subscription $subscription The subscription.
	 *                                      renewal.
	 */
	public function maybe_handle_early_renewal( $subscription ) {

		$last_order = $subscription->get_last_order( 'all' );

		if ( ! wcs_order_contains_early_renewal( $last_order ) ) {
			return;
		}

		$this->sync_subscription_fields( $subscription );

	}



	/**
	 * Bypass customer meta sync on a renewal order in WPF_WooCommerce.
	 *
	 * @since  3.36.2
	 *
	 * @param  bool     $sync_data Whether or not to sync the customer data to
	 *                             the CRM.
	 * @param  WC_Order $order     The order object.
	 * @return bool     Whether or not to bypass the order data sync.
	 */
	public function maybe_skip_customer_data_sync( $sync_data, $order ) {

		if ( wcs_order_contains_renewal( $order ) ) {

			$sync_data = false;

			// Unless order_id or order_date are enabled for sync

			if ( wpf_is_field_active( 'order_id' ) || wpf_is_field_active( 'order_date' ) ) {
				$sync_data = true;
			}
		}

		return $sync_data;

	}


	/**
	 * Sync data when a subscription is manually edited in the admin
	 *
	 * @access public
	 * @return void
	 */

	public function save_post( $post_id, $post, $update ) {

		if ( 'shop_subscription' !== $post->post_type ) {
			return;
		}

		if ( ! is_admin() ) {
			return;
		}

		if ( did_action( 'woocommerce_subscription_status_updated' ) || doing_action( 'woocommerce_subscription_status_updated' ) ) {
			return;
		}

		// Sync subscription data
		$subscription = wcs_get_subscription( $post_id );

		if ( wcs_is_subscription( $subscription ) ) {
			$this->sync_subscription_fields( $subscription );
		}

	}

	/**
	 * Sync the subscription fields when a renewal order is re-processed by WPF.
	 *
	 * @since 3.38.9
	 *
	 * @param WC_Order $order  The order.
	 */
	public function process_order_action( $order ) {

		if ( wcs_order_contains_renewal( $order ) ) {

			$subscriptions = wcs_get_subscriptions_for_order( $order, array( 'order_type' => 'any' ) );

			if ( ! empty( $subscriptions ) ) {

				foreach ( $subscriptions as $subscription ) {
					$this->sync_subscription_fields( $subscription );
				}
			}
		}

	}


	/**
	 * Unbind actions when subscriptions are deleted
	 *
	 * @access public
	 * @return void
	 */

	public function before_delete_post( $post_id ) {

		if ( get_post_type( $post_id ) == 'shop_subscription' ) {

			remove_action( 'woocommerce_subscription_status_updated', array( $this, 'subscription_status_updated' ), 10, 3 );

		}

	}

	/**
	 * Adds WooCommerce field group to meta fields list
	 *
	 * @access  public
	 * @return  array Field groups
	 */

	public function add_meta_field_group( $field_groups ) {

		if ( ! isset( $field_groups['woocommerce_subs'] ) ) {
			$field_groups['woocommerce_subs'] = array(
				'title'  => 'WooCommerce Subscriptions',
				'fields' => array(),
			);
		}

		return $field_groups;

	}

	/**
	 * Sets field labels and types for WooCommerce custom fields
	 *
	 * @access  public
	 * @return  array Meta fields
	 */

	public function prepare_meta_fields( $meta_fields ) {

		$meta_fields['sub_id'] = array(
			'label' => __( 'Subscription ID', 'wp-fusion' ),
			'type'  => 'int',
			'group' => 'woocommerce_subs',
		);

		$meta_fields['sub_status'] = array(
			'label' => __( 'Subscription Status', 'wp-fusion' ),
			'type'  => 'text',
			'group' => 'woocommerce_subs',
		);

		$meta_fields['sub_product_name'] = array(
			'label' => __( 'Subscription Product Name', 'wp-fusion' ),
			'type'  => 'text',
			'group' => 'woocommerce_subs',
		);

		$meta_fields['sub_start_date'] = array(
			'label' => __( 'Subscription Start Date', 'wp-fusion' ),
			'type'  => 'date',
			'group' => 'woocommerce_subs',
		);

		$meta_fields['sub_end_date'] = array(
			'label' => __( 'Subscription End Date', 'wp-fusion' ),
			'type'  => 'date',
			'group' => 'woocommerce_subs',
		);

		$meta_fields['sub_renewal_date'] = array(
			'label' => __( 'Next Payment Date', 'wp-fusion' ),
			'type'  => 'date',
			'group' => 'woocommerce_subs',
		);

		return $meta_fields;

	}



	/**
	 * Writes subscriptions options to WPF/Woo panel
	 *
	 * @access public
	 * @return mixed
	 */

	public function panel_content( $post_id ) {

		$settings = array(
			'remove_tags'                     => 0,
			'apply_tags_cancelled'            => array(),
			'apply_tags_hold'                 => array(),
			'apply_tags_expired'              => array(),
			'apply_tags_converted'            => array(),
			'apply_tags_pending_cancellation' => array(),
			'apply_tags_payment_failed'       => array(),
		);

		if ( get_post_meta( $post_id, 'wpf-settings-woo', true ) ) {
			$settings = array_merge( $settings, get_post_meta( $post_id, 'wpf-settings-woo', true ) );
		}

		$classes = 'show_if_subscription show_if_variable-subscription';

		// Support for WooCommerce Subscribe All The Things extension
		if ( class_exists( 'WCS_ATT' ) ) {
			$classes .= ' show_if_simple show_if_variable show_if_bundle';
		}

		echo '<div class="options_group ' . $classes . '">';

		echo '<p class="form-field"><label><strong>Subscription</strong></label></p>';

		echo '<p>' . sprintf( __( 'For more information on these settings, %1$ssee our documentation%2$s.', 'wp-fusion' ), '<a href="https://wpfusion.com/documentation/ecommerce/woocommerce-subscriptions/" target="_blank">', '</a>' ) . '</p>';

		echo '<p class="form-field"><label for="wpf-apply-tags-woo">' . __( 'Remove tags', 'wp-fusion' ) . '</label>';
		echo '<input class="checkbox" type="checkbox" id="wpf-remove-tags-woo" name="wpf-settings-woo[remove_tags]" value="1" ' . checked( $settings['remove_tags'], 1, false ) . ' />';
		echo '<span class="description">' . __( 'Remove original tags (above) when the subscription is cancelled, put on hold, expires, or is switched', 'wp-fusion' ) . '.</span>';
		echo '</p>';

		// Payment failed
		echo '<p class="form-field"><label>Payment failed</label>';
		wpf_render_tag_multiselect(
			array(
				'setting'   => $settings['apply_tags_payment_failed'],
				'meta_name' => 'wpf-settings-woo',
				'field_id'  => 'apply_tags_payment_failed',
			)
		);
		echo '<span class="description">' . __( 'Apply these tags when a renewal payment fails', 'wp-fusion' ) . '.</span>';
		echo '</p>';

		// Cancelled
		echo '<p class="form-field"><label>Cancelled</label>';
		wpf_render_tag_multiselect(
			array(
				'setting'   => $settings['apply_tags_cancelled'],
				'meta_name' => 'wpf-settings-woo',
				'field_id'  => 'apply_tags_cancelled',
			)
		);
		echo '<span class="description">' . __( 'Apply these tags when a subscription is cancelled', 'wp-fusion' ) . '.</span>';
		echo '</p>';

		// Put on hold
		echo '<p class="form-field"><label>Put on hold</label>';
		wpf_render_tag_multiselect(
			array(
				'setting'   => $settings['apply_tags_hold'],
				'meta_name' => 'wpf-settings-woo',
				'field_id'  => 'apply_tags_hold',
			)
		);
		echo '<span class="description">' . __( 'Apply these tags when a subscription is put on hold', 'wp-fusion' ) . '.</span>';
		echo '</p>';

		// Expires
		echo '<p class="form-field"><label>Pending cancellation</label>';
		wpf_render_tag_multiselect(
			array(
				'setting'   => $settings['apply_tags_pending_cancellation'],
				'meta_name' => 'wpf-settings-woo',
				'field_id'  => 'apply_tags_pending_cancellation',
			)
		);
		echo '<span class="description">' . __( 'Apply these tags when a subscription has been cancelled by the user but there is still time remaining in the subscription', 'wp-fusion' ) . '.</span>';
		echo '</p>';

		// Expires
		echo '<p class="form-field"><label>Expired</label>';
		wpf_render_tag_multiselect(
			array(
				'setting'   => $settings['apply_tags_expired'],
				'meta_name' => 'wpf-settings-woo',
				'field_id'  => 'apply_tags_expired',
			)
		);
		echo '<span class="description">' . __( 'Apply these tags when a subscription expires', 'wp-fusion' ) . '.</span>';
		echo '</p>';

		echo '<p class="form-field"><label>Free trial over</label>';
		wpf_render_tag_multiselect(
			array(
				'setting'   => $settings['apply_tags_converted'],
				'meta_name' => 'wpf-settings-woo',
				'field_id'  => 'apply_tags_converted',
			)
		);
		echo '<span class="description">' . __( 'Apply these tags when free trial ends', 'wp-fusion' ) . '.</span>';
		echo '</p>';

		echo '</div>';

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

		$options['woo_subscriptions'] = array(
			'label'   => 'WooCommerce Subscriptions statuses',
			'title'   => 'Subscriptions',
			'tooltip' => __( 'Updates user tags for all subscriptions based on current subscription status, using the tags configured on each subscription product. Does not sync any custom fields.', 'wp-fusion' ),
		);

		$options['woo_subscriptions_meta'] = array(
			'label'   => 'WooCommerce Subscriptions meta',
			'title'   => 'Subscriptions',
			'tooltip' => __( 'Syncs the subscription product name, start date, status, and next renewal dates for all subscriptions (if enabled). Does not modify any tags.', 'wp-fusion' ),
		);

		return $options;

	}




	/**
	 * Counts total number of subscriptions to be processed
	 *
	 * @access public
	 * @return array Subscriptions
	 */

	public function batch_init() {

		$args = array(
			'numberposts' => - 1,
			'post_type'   => 'shop_subscription',
			'post_status' => 'any',
			'fields'      => 'ids',
			'order'       => 'ASC',
		);

		$subscriptions = get_posts( $args );

		return $subscriptions;

	}

	/**
	 * Processes subscription actions in batches
	 *
	 * @access public
	 * @return void
	 */

	public function batch_step( $subscription_id ) {

		$subscription = wcs_get_subscription( $subscription_id );

		if ( wcs_is_subscription( $subscription ) ) {
			$this->apply_tags_for_subscription_status( $subscription );
		}

	}


	/**
	 * Counts total number of subscriptions to be processed
	 *
	 * @access public
	 * @return array Subscriptions
	 */

	public function batch_init_meta() {

		$args = array(
			'nopaging'    => true,
			'post_type'   => 'shop_subscription',
			'post_status' => 'any',
			'fields'      => 'ids',
			'order'       => 'ASC',
		);

		$subscriptions = get_posts( $args );

		return $subscriptions;

	}

	/**
	 * Processes subscription actions in batches
	 *
	 * @access public
	 * @return void
	 */

	public function batch_step_meta( $subscription_id ) {

		$subscription = wcs_get_subscription( $subscription_id );

		if ( wcs_is_subscription( $subscription ) ) {
			$this->sync_subscription_fields( $subscription );
		}

	}

	/**
	 * Support utilities
	 *
	 * @access public
	 * @return void
	 */

	public function settings_page_init() {

		if ( isset( $_GET['woo_subs_report'] ) ) {

			$args = array(
				'numberposts' => - 1,
				'post_type'   => 'shop_subscription',
				'post_status' => 'any',
				'fields'      => 'ids',
				'order'       => 'ASC',
			);

			$subscriptions = get_posts( $args );

			$status_counts   = array();
			$users_by_status = array();
			$total_users     = array();

			foreach ( $subscriptions as $subscription_id ) {

				$subscription = wcs_get_subscription( $subscription_id );

				if ( ! wcs_is_subscription( $subscription ) ) {
					continue;
				}

				$status = $subscription->get_status();

				if ( ! isset( $status_counts[ $status ] ) ) {
					$status_counts[ $status ] = 0;
				}

				$status_counts[ $status ]++;

				if ( ! isset( $users_by_status[ $status ] ) ) {
					$users_by_status[ $status ] = array();
				}

				$user_id = $subscription->get_user_id();

				if ( ! in_array( $user_id, $users_by_status[ $status ] ) ) {
					$users_by_status[ $status ][] = $user_id;
				}

				if ( ! in_array( $user_id, $total_users ) ) {
					$total_users[] = $user_id;
				}
			}

			// Get users with no CID
			$no_cid_users = array();

			foreach ( $total_users as $user_id ) {

				if ( empty( get_user_meta( $user_id, wp_fusion()->crm->slug . '_contact_id', true ) ) ) {
					$no_cid_users[] = $user_id;
				}
			}

			// Get inactive / unmarketable users
			$inactive_users = array();

			foreach ( $total_users as $user_id ) {

				if ( ! empty( get_user_meta( $user_id, wp_fusion()->crm->slug . '_inactive', true ) ) ) {
					$inactive_users[] = $user_id;
				}
			}

			echo '<div id="setting-error-settings_updated" class="updated settings-error">';

			// Sub statuses
			echo '<h4>Woo Subs Debug Report</h4>';

			echo '<h5>Subscriptions by status</h5>';

			echo '<ul>';

			foreach ( $status_counts as $status => $count ) {

				echo '<li><strong>' . $status . '</strong>: ' . $count . ' subscription(s)</li>';

			}

			echo '</ul>';

			echo '<h5>User counts by status</h5>';

			echo '<ul>';

			foreach ( $users_by_status as $status => $users ) {

				echo '<li><strong>' . $status . '</strong>: ' . count( $users ) . ' user(s) ';

				$no_cid = array_intersect( $users, $no_cid_users );

				if ( ! empty( $no_cid ) ) {

					echo '(incl. ' . count( $no_cid ) . ' with no contact record)';

				}

				$inactive = array_intersect( $users, $inactive_users );

				if ( ! empty( $inactive ) ) {

					echo ' (incl. ' . count( $inactive ) . ' Inactive)';

				}

				echo '</li>';

			}

			echo '</ul>';

			echo '<h5>Total unique subscription users: ' . count( $total_users ) . '</h5>';

			echo '<h5>Subscription users with no contact ID: ' . count( $no_cid_users ) . '</h5>';

			echo '<h5>Subscription users who are Inactive in ' . wp_fusion()->crm->name . ': ' . count( $inactive_users ) . '</h5>';

			echo '</div>';

		}

	}

}

new WPF_Woo_Subscriptions();
