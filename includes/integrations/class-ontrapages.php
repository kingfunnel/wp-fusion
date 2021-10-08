<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * ONTRApages integration
 *
 * @since 3.37.4
 */

class WPF_Ontrapages extends WPF_Integrations_Base {

	/**
	 * Get things started.
	 *
	 * @since 3.37.4
	 */
	public function init() {

		$this->slug = 'ontrapages';

		add_action( 'init', array( $this, 'update_hooks' ), 15 );

	}

	/**
	 * Move the ONTRApages redirect hook to priority 20.
	 *
	 * @since 3.37.4
	 */
	public function update_hooks() {

		remove_action( 'template_redirect', array( 'ONTRApage', 'addOPContainerTemplate' ), 10 );
		add_action( 'template_redirect', array( 'ONTRApage', 'addOPContainerTemplate' ), 20 );

	}

}

new WPF_Ontrapages();
