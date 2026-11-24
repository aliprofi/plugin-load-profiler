<?php
/**
 * Plugin Name: P3 Load Profiler — профайлер плагинов
 * Description: Замеряет, сколько времени, памяти и SQL-запросов добавляет каждый активный плагин. Прогоняет страницы по одному плагину за раз и показывает, кто сильнее всего грузит сайт и базу.
 * Version: 0.3.1
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: Али Профи
 * Author URI: https://aliprofi.ru
 * Plugin URI: https://aliprofi.ru
 * License: GPL-2.0-or-later
 * Text Domain: plugin-load-profiler
 */

defined( 'ABSPATH' ) || exit;

define( 'PLP_VERSION', '0.3.1' );
define( 'PLP_FILE', __FILE__ );
define( 'PLP_DIR', plugin_dir_path( __FILE__ ) );
define( 'PLP_URL', plugin_dir_url( __FILE__ ) );
define( 'PLP_BASELINE', '__baseline__' );

if ( version_compare( PHP_VERSION, '7.4', '<' ) ) {
	add_action(
		'admin_notices',
		static function () {
			echo '<div class="notice notice-error"><p>P3 Load Profiler требует PHP 7.4 или новее. Обновите PHP.</p></div>';
		}
	);
	return;
}

require_once PLP_DIR . 'includes/class-plp-installer.php';
require_once PLP_DIR . 'includes/class-plp-scan-manager.php';
require_once PLP_DIR . 'includes/class-plp-admin.php';
require_once PLP_DIR . 'includes/class-plp-plugin.php';

/* Обновления из GitHub Releases через стандартную кнопку «Обновить». */
if ( is_file( PLP_DIR . 'includes/plugin-update-checker/plugin-update-checker.php' ) ) {
	require_once PLP_DIR . 'includes/plugin-update-checker/plugin-update-checker.php';

	if ( class_exists( '\YahnisElsts\PluginUpdateChecker\v6\PucFactory' ) ) {
		$plp_updater = \YahnisElsts\PluginUpdateChecker\v6\PucFactory::buildUpdateChecker(
			'https://github.com/aliprofi/plugin-load-profiler/',
			__FILE__,
			'plugin-load-profiler'
		);
		$plp_updater->getVcsApi()->enableReleaseAssets( '/plugin-load-profiler\.zip$/i' );
	}
}

register_activation_hook( __FILE__, array( 'PLP_Installer', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'PLP_Installer', 'deactivate' ) );

PLP_Plugin::instance()->boot();
