<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

class WPF_Elementor extends WPF_Integrations_Base {


	/**
	 * Gets things started
	 *
	 * @access  public
	 * @since   1.0
	 * @return  void
	 */

	public function init() {

		$this->slug = 'elementor';

		add_filter( 'wpf_meta_box_post_types', array( $this, 'unset_wpf_meta_boxes' ) );

		// Controls
		add_action( 'elementor/element/common/_section_style/after_section_end', array( $this, 'register_section' ) ); // Widgets
		add_action( 'elementor/element/section/section_advanced/after_section_end', array( $this, 'register_section' ) ); // Sections
		add_action( 'elementor/element/column/section_advanced/after_section_end', array( $this, 'register_section' ) ); // Columns

		add_action( 'elementor/element/common/wpf_tags_section/before_section_end', array( $this, 'register_controls' ), 10, 2 );
		add_action( 'elementor/element/section/wpf_tags_section/before_section_end', array( $this, 'register_controls' ), 10, 2 );
		add_action( 'elementor/element/column/wpf_tags_section/before_section_end', array( $this, 'register_controls' ), 10, 2 );

		// Display
		add_action( 'elementor/frontend/widget/before_render', array( $this, 'before_render_widget' ) );
		add_filter( 'elementor/widget/render_content', array( $this, 'render_widget' ), 10, 2 );
		add_action( 'elementor/frontend/section/before_render', array( $this, 'render_section' ) );
		add_action( 'elementor/frontend/column/before_render', array( $this, 'render_section' ) );

		// Filter queries
		add_action( 'elementor/element/before_section_end', array( $this, 'add_filter_queries_control' ), 10, 3 );
		add_filter( 'elementor/query/query_args', array( $this, 'query_args' ), 10, 2 );

	}

	/**
	 * Removes standard WPF meta boxes from Elementor template library items
	 *
	 * @access  public
	 * @return  array Post Types
	 */

	public function unset_wpf_meta_boxes( $post_types ) {

		unset( $post_types['elementor_library'] );

		return $post_types;

	}

	/**
	 * Register controls section
	 *
	 * @access public
	 * @return void
	 */

	public function register_section( $element ) {

		$element->start_controls_section(
			'wpf_tags_section',
			[
				'label' => __( 'WP Fusion', 'wp-fusion' ),
				'tab'   => \Elementor\Controls_Manager::TAB_ADVANCED,
			]
		);

		$element->end_controls_section();

	}

	/**
	 * Register controls
	 *
	 * @access public
	 * @return void
	 */

	public function register_controls( $element, $args ) {

		if ( is_a( $element, 'Elementor\Core\DocumentTypes\Post' ) ) {
			return;
		}

		$available_tags = wpf_get_option( 'available_tags', array() );

		$data = array();

		foreach ( $available_tags as $id => $label ) {

			if ( is_array( $label ) ) {
				$label = $label['label'];
			}

			$data[ $id ] = $label;

		}

		$element->add_control(
			'wpf_visibility',
			[
				'label'       => __( 'Visibility', 'wp-fusion' ),
				'type'        => \Elementor\Controls_Manager::SELECT,
				'default'     => 'everyone',
				'options'     => array(
					'everyone'  => __( 'Everyone', 'wp-fusion' ),
					'loggedin'  => __( 'Logged In Users', 'wp-fusion' ),
					'loggedout' => __( 'Logged Out Users', 'wp-fusion' ),
				),
				'multiple'    => false,
				'label_block' => true,
			]
		);

		$element->add_control(
			'wpf_tags',
			[
				'label'       => sprintf( __( 'Required %s Tags (Any)', 'wp-fusion' ), wp_fusion()->crm->name ),
				'type'        => \Elementor\Controls_Manager::SELECT2,
				'options'     => $data,
				'multiple'    => true,
				'label_block' => true,
				'condition'   => array(
					'wpf_visibility' => [ 'loggedin', 'everyone' ],
				),
				//'description' => __( 'The user must be logged in and have at least one of the tags specified to access the content.', 'wp-fusion' ),
			]
		);

		$element->add_control(
			'wpf_tags_all',
			[
				'label'       => sprintf( __( 'Required %s Tags (All)', 'wp-fusion' ), wp_fusion()->crm->name ),
				'type'        => \Elementor\Controls_Manager::SELECT2,
				'options'     => $data,
				'multiple'    => true,
				'label_block' => true,
				'condition'   => array(
					'wpf_visibility' => [ 'loggedin', 'everyone' ],
				),
				//'description' => __( 'The user must be logged in and have <em>all</em> of the tags specified to access the content.', 'wp-fusion' ),
			]
		);

		$element->add_control(
			'wpf_tags_not',
			[
				'label'       => sprintf( __( 'Required %s Tags (Not)', 'wp-fusion' ), wp_fusion()->crm->name ),
				'type'        => \Elementor\Controls_Manager::SELECT2,
				'options'     => $data,
				'multiple'    => true,
				'label_block' => true,
				'condition'   => array(
					'wpf_visibility' => [ 'everyone', 'loggedin' ],
				),
				'description' => __( 'If the user is logged in and has any of these tags, the content will be hidden.', 'wp-fusion' ),
			]
		);

		do_action( 'wpf_elementor_controls_section', $element );

	}

	/**
	 * Determines if a user has access to an element
	 *
	 * @access public
	 * @return bool Access
	 */

	private function can_access( $element ) {

		if ( is_admin() ) {
			return true;
		}

		$visibility      = $element->get_settings( 'wpf_visibility' );
		$widget_tags     = $element->get_settings( 'wpf_tags' );
		$widget_tags_all = $element->get_settings( 'wpf_tags_all' );
		$widget_tags_not = $element->get_settings( 'wpf_tags_not' );

		if ( ( empty( $visibility ) || 'everyone' == $visibility ) && empty( $widget_tags ) && empty( $widget_tags_all ) && empty( $widget_tags_not ) ) {

			// No settings, allow access

			$can_access = apply_filters( 'wpf_elementor_can_access', true, $element );

			return apply_filters( 'wpf_user_can_access', $can_access, wpf_get_current_user_id(), false );

		}

		if ( wpf_admin_override() ) {
			return true;
		}

		// Maybe migrate the settings from the pre 3.35.7 format

		if ( empty( $visibility ) ) {

			// At least some tags are specified but there's nothing for visibility, so we'll default to "loggedin"

			$visibility = 'loggedin';

			if ( ! empty( $widget_tags_not ) && 'display' == $element->get_settings( 'wpf_loggedout' ) ) {
				$visibility = 'everyone';
			}
		}

		$can_access = true;

		if ( wpf_is_user_logged_in() ) {

			$user_tags = wp_fusion()->user->get_tags();

			if ( 'everyone' == $visibility || 'loggedin' == $visibility ) {

				// See if user has required tags

				if ( ! empty( $widget_tags ) ) {

					// Required tags (any)

					$result = array_intersect( $widget_tags, $user_tags );

					if ( empty( $result ) ) {
						$can_access = false;
					}
				}

				if ( true == $can_access && ! empty( $widget_tags_all ) ) {

					// Required tags (all)

					$result = array_intersect( $widget_tags_all, $user_tags );

					if ( count( $result ) != count( $widget_tags_all ) ) {
						$can_access = false;
					}
				}

				if ( true == $can_access && ! empty( $widget_tags_not ) ) {

					// Required tags (not)

					$result = array_intersect( $widget_tags_not, $user_tags );

					if ( ! empty( $result ) ) {
						$can_access = false;
					}
				}
			} elseif ( 'loggedout' == $visibility ) {

				// The user is logged in but the widget is set to logged-out only
				$can_access = false;

			}
		} else {

			// Not logged in

			if ( 'loggedin' == $visibility ) {

				// The user is not logged in but the widget is set to logged-in only
				$can_access = false;

			} elseif ( 'everyone' == $visibility ) {

				// Also deny access if tags are specified

				if ( ! empty( $widget_tags ) || ! empty( $widget_tags_all ) ) {
					$can_access = false;
				}
			}
		}

		$can_access = apply_filters( 'wpf_elementor_can_access', $can_access, $element );

		$can_access = apply_filters( 'wpf_user_can_access', $can_access, wpf_get_current_user_id(), false );

		if ( $can_access ) {
			return true;
		} else {
			return false;
		}

	}

	/**
	 * Hide the widget wrapper if access denied
	 *
	 * @access public
	 * @return void
	 */

	public function before_render_widget( $widget ) {

		if ( ! $this->can_access( $widget ) ) {

			$widget->add_render_attribute( '_wrapper', 'style', 'display:none' );

		}

	}

	/**
	 * Conditionall show / hide widget based on tags
	 *
	 * @access public
	 * @return mixed / bool
	 */

	public function render_widget( $content, $widget ) {

		if ( $this->can_access( $widget ) ) {
			return $content;
		} else {
			return false;
		}

	}

	/**
	 * Conditionally show / hide section based on tags
	 *
	 * @access public
	 * @return void
	 */

	public function render_section( $element ) {

		if ( $this->can_access( $element ) ) {
			return;
		} else {
			$element->add_render_attribute( '_wrapper', 'style', 'display:none' );
		}

	}

	/**
	 * Render widget controls
	 *
	 * @access public
	 * @return void
	 */

	public function add_filter_queries_control( $element, $section_id, $args ) {

		if ( $section_id !== 'section_query' ) {
			return;
		}

		$element->add_control(
			'wpf_filter_queries',
			[
				'label'       => __( 'Filter Queries', 'wp-fusion' ),
				'description' => __( 'Filter results based on WP Fusion access rules', 'wp-fusion' ),
				'type'        => \Elementor\Controls_Manager::SWITCHER,
				'label_block' => false,
				'show_label'  => true,
				'separator'   => 'before',
			]
		);

	}

	/**
	 * Filter queries if enabled
	 *
	 * @access public
	 * @return array Query Args
	 */

	public function query_args( $query_args, $widget ) {

		$settings = $widget->get_settings_for_display();

		if ( ! isset( $settings['wpf_filter_queries'] ) || 'yes' !== $settings['wpf_filter_queries'] ) {
			return $query_args;
		}

		// No need to do this again if WPF is already doing it globally

		if ( 'advanced' == wpf_get_option( 'hide_archives' ) ) {
			return $query_args;
		}

		$args = array(
			'post_type'  => $query_args['post_type'],
			'nopaging'   => true,
			'fields'     => 'ids',
			'meta_query' => array(
				array(
					'key'     => 'wpf-settings',
					'compare' => 'EXISTS',
				),
			),
		);

		$post_ids = get_posts( $args );

		if ( ! empty( $post_ids ) ) {

			if ( ! isset( $query_args['post__not_in'] ) ) {
				$query_args['post__not_in'] = array();
			}

			foreach ( $post_ids as $post_id ) {

				if ( ! wp_fusion()->access->user_can_access( $post_id ) ) {

					$query_args['post__not_in'][] = $post_id;

				}
			}
		}

		return $query_args;

	}

}

new WPF_Elementor();
