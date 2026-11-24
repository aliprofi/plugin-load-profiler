<?php
/**
 * Установка плагина: таблицы БД, настройки, mu-plugin загрузчик.
 */

defined( 'ABSPATH' ) || exit;

class PLP_Installer {

	const MU_FILE   = 'plp-loader.php';
	const MU_MARKER = 'PLP-MU-LOADER';
	const MU_VERSION = '4';
	const DB_VERSION = '2';
	const CRON_HOOK  = 'plp_cleanup';

	public static function activate() {
		self::create_tables();
		add_option( 'plp_settings', self::default_settings() );
		update_option( 'plp_db_version', self::DB_VERSION );
		self::sync_mu_loader();

		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}

	/**
	 * Обновляет схему после апдейта плагина (новая колонка details и т.п.).
	 */
	public static function maybe_upgrade() {
		if ( get_option( 'plp_db_version' ) === self::DB_VERSION ) {
			return;
		}
		self::create_tables();
		update_option( 'plp_db_version', self::DB_VERSION );
	}

	public static function deactivate() {
		global $wpdb;
		if ( is_object( $wpdb ) ) {
			$wpdb->update(
				self::table( 'scans' ),
				array(
					'status'      => 'aborted',
					'finished_at' => current_time( 'mysql' ),
				),
				array( 'status' => 'running' ),
				array( '%s', '%s' ),
				array( '%s' )
			);

			$active = get_option( 'plp_active_scan' );
			if ( is_array( $active ) && ! empty( $active['id'] ) ) {
				$row = $wpdb->get_row(
					$wpdb->prepare( 'SELECT config FROM ' . self::table( 'scans' ) . ' WHERE id = %d', (int) $active['id'] ),
					ARRAY_A
				);
				if ( $row ) {
					$config = json_decode( (string) $row['config'], true );
					if ( is_array( $config ) ) {
						self::restore_active_plugins( $config );
					}
				}
			}
		}

		delete_option( 'plp_active_scan' );
		wp_clear_scheduled_hook( self::CRON_HOOK );
		self::remove_mu_loader();
	}

	/**
	 * Возвращает в активные плагины из исходного списка скана, если их отключили
	 * во время изолированного прогона. Возвращает число восстановленных.
	 */
	public static function restore_active_plugins( $config ) {
		if ( ! is_array( $config ) || empty( $config['active_plugins'] ) || ! is_array( $config['active_plugins'] ) ) {
			return 0;
		}

		$original = array();
		foreach ( $config['active_plugins'] as $file ) {
			$file = (string) $file;
			if ( '' !== $file ) {
				$original[ $file ] = $file;
			}
		}
		if ( empty( $original ) ) {
			return 0;
		}

		$current = array_map( 'strval', (array) get_option( 'active_plugins', array() ) );
		$missing = array_diff( $original, $current );
		if ( empty( $missing ) ) {
			return 0;
		}

		$restored = array();
		foreach ( $missing as $file ) {
			if ( file_exists( WP_PLUGIN_DIR . '/' . $file ) ) {
				$restored[] = $file;
			}
		}
		if ( empty( $restored ) ) {
			return 0;
		}

		$present = array_unique( array_merge( $current, $restored ) );
		$new     = array();
		foreach ( $original as $file ) {
			if ( in_array( $file, $present, true ) ) {
				$new[] = $file;
			}
		}
		foreach ( $current as $file ) {
			if ( ! in_array( $file, $new, true ) ) {
				$new[] = $file;
			}
		}

		update_option( 'active_plugins', array_values( $new ) );
		return count( $restored );
	}

	public static function default_settings() {
		return array(
			'runs'           => 3,
			'retention_days' => 30,
			'urls'           => array( home_url( '/' ) ),
		);
	}

	public static function settings() {
		$settings = get_option( 'plp_settings', array() );
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}
		return wp_parse_args( $settings, self::default_settings() );
	}

	public static function table( $name ) {
		global $wpdb;
		return $wpdb->prefix . 'plp_' . $name;
	}

	public static function mu_dir() {
		return defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins';
	}

	public static function mu_path() {
		return trailingslashit( self::mu_dir() ) . self::MU_FILE;
	}

	public static function mu_template() {
		return PLP_DIR . 'mu-plugin/' . self::MU_FILE;
	}

	/**
	 * Копирует загрузчик в mu-plugins. Возвращает true, если файл на месте и актуален.
	 */
	public static function sync_mu_loader() {
		$source = self::mu_template();
		if ( ! file_exists( $source ) ) {
			update_option( 'plp_mu_error', 'Не найден шаблон mu-plugin в пакете плагина.', false );
			return false;
		}

		$desired = file_get_contents( $source );
		$target  = self::mu_path();

		if ( file_exists( $target ) ) {
			$current = file_get_contents( $target );
			if ( $current === $desired ) {
				update_option( 'plp_mu_error', '', false );
				return true;
			}
		}

		$dir = dirname( $target );
		if ( ! wp_mkdir_p( $dir ) || ! is_writable( $dir ) ) {
			update_option(
				'plp_mu_error',
				sprintf( 'Нет прав на запись в %s. Создайте файл %s вручную.', $dir, $target ),
				false
			);
			return false;
		}

		$ok = false !== file_put_contents( $target, $desired ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		update_option(
			'plp_mu_error',
			$ok ? '' : sprintf( 'Не удалось записать %s. Создайте файл вручную.', $target ),
			false
		);

		return $ok;
	}

	public static function remove_mu_loader() {
		$target = self::mu_path();
		if ( ! file_exists( $target ) ) {
			return;
		}
		$content = file_get_contents( $target );
		if ( false !== $content && false !== strpos( $content, self::MU_MARKER ) ) {
			@unlink( $target ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
		}
	}

	public static function mu_installed() {
		$target = self::mu_path();
		if ( ! file_exists( $target ) ) {
			return false;
		}
		$content = file_get_contents( $target );
		return false !== $content && false !== strpos( $content, self::MU_MARKER );
	}

	public static function mu_error() {
		return (string) get_option( 'plp_mu_error', '' );
	}

	public static function create_tables() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$scans   = self::table( 'scans' );
		$results = self::table( 'results' );

		$sql = array();

		$sql[] = "CREATE TABLE {$scans} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			created_at datetime NOT NULL,
			started_at datetime DEFAULT NULL,
			finished_at datetime DEFAULT NULL,
			status varchar(20) NOT NULL DEFAULT 'running',
			config longtext NOT NULL,
			summary longtext NULL,
			PRIMARY KEY  (id),
			KEY status (status),
			KEY created_at (created_at)
		) {$charset};";

		$sql[] = "CREATE TABLE {$results} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			scan_id bigint(20) unsigned NOT NULL,
			plugin_file varchar(255) NOT NULL DEFAULT '',
			plugin_name varchar(255) NOT NULL DEFAULT '',
			run_index smallint(5) unsigned NOT NULL DEFAULT 0,
			url_index smallint(5) unsigned NOT NULL DEFAULT 0,
			url text NOT NULL,
			load_time_ms decimal(12,3) NOT NULL DEFAULT 0,
			peak_memory_kb bigint(20) unsigned NOT NULL DEFAULT 0,
			query_count int(10) unsigned NOT NULL DEFAULT 0,
			query_time_ms decimal(12,3) NOT NULL DEFAULT 0,
			http_status smallint(5) unsigned NOT NULL DEFAULT 0,
			error varchar(255) NOT NULL DEFAULT '',
			details longtext NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY scan_step (scan_id,plugin_file,run_index,url_index),
			KEY scan_id (scan_id)
		) {$charset};";

		foreach ( $sql as $query ) {
			dbDelta( $query );
		}
	}
}
