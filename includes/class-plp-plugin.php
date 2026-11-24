<?php
/**
 * Точка входа: подключение подсистем.
 */

defined( 'ABSPATH' ) || exit;

class PLP_Plugin {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function boot() {
		add_action( 'plugins_loaded', array( $this, 'init' ) );
	}

	public function init() {
		PLP_Scan_Manager::instance()->register_hooks();

		if ( is_admin() ) {
			PLP_Admin::instance()->register_hooks();
		}
	}
}
