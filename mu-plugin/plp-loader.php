<?php
/**
 * PLP-MU-LOADER v3
 * Устанавливается плагином P3 Load Profiler в wp-content/mu-plugins/.
 * Работает только при активном скане и валидном токене, в остальное время полностью пассивен.
 * Не редактируйте вручную: при следующем открытии страницы плагина файл будет обновлён.
 */

if ( ! defined( 'ABSPATH' ) ) {
	return;
}

if ( empty( $_GET['plp_profile'] ) || ! is_string( $_GET['plp_profile'] ) ) {
	return;
}

if ( isset( $_SERVER['REQUEST_METHOD'] ) && 'GET' !== strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) ) {
	return;
}

if ( function_exists( 'is_admin' ) && is_admin() ) {
	return;
}

if ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) {
	return;
}

$plp_scan = get_option( 'plp_active_scan' );
if ( ! is_array( $plp_scan ) || empty( $plp_scan['id'] ) || empty( $plp_scan['token'] ) || empty( $plp_scan['profiler_file'] ) ) {
	return;
}

if ( ! hash_equals( (string) $plp_scan['token'], (string) wp_unslash( $_GET['plp_profile'] ) ) ) {
	return;
}

if ( ! defined( 'PLP_PROFILING' ) ) {
	define( 'PLP_PROFILING', true );
}
if ( ! defined( 'DONOTCACHEPAGE' ) ) {
	define( 'DONOTCACHEPAGE', true );
}

$plp_target = isset( $plp_scan['target_file'] ) ? (string) $plp_scan['target_file'] : '';
$plp_run    = isset( $_GET['plp_run'] ) ? absint( $_GET['plp_run'] ) : 0;
$plp_url_i  = isset( $_GET['plp_url'] ) ? absint( $_GET['plp_url'] ) : 0;
$plp_step   = isset( $_GET['plp_step'] ) ? absint( $_GET['plp_step'] ) : 0;
$plp_probe  = isset( $_GET['plp_probe'] ) && '1' === (string) $_GET['plp_probe'];

$GLOBALS['plp_ctx'] = array(
	'scan_id' => (int) $plp_scan['id'],
	'start'   => microtime( true ),
	'target'  => $plp_target,
	'run'     => $plp_run,
	'url_i'   => $plp_url_i,
	'step'    => $plp_step,
);

$plp_profiler = (string) $plp_scan['profiler_file'];

/*
 * Быстрый зонд: измеряем только загрузку плагинов и init, не запуская шаблоны темы.
 * Так замер работает даже если страницу собирает отключённый конструктор.
 */
if ( $plp_probe ) {
	add_action(
		'wp_loaded',
		static function () {
			if ( ! headers_sent() ) {
				if ( function_exists( 'nocache_headers' ) ) {
					nocache_headers();
				}
				header( 'X-PLP-Profile: 1' );
				header( 'Content-Type: text/plain; charset=utf-8' );
			}
			echo 'PLP probe OK';
			exit;
		},
		PHP_INT_MAX
	);
}

/*
 * WordPress забирает список активных плагинов до загрузки обычных плагинов,
 * поэтому единственный способ изолировать плагин — фильтр на этой ранней стадии.
 */
add_filter(
	'option_active_plugins',
	static function ( $plugins ) use ( $plp_profiler, $plp_target ) {
		if ( ! is_array( $plugins ) ) {
			return $plugins;
		}
		$list = array( $plp_profiler );
		if ( '' !== $plp_target && $plp_target !== $plp_profiler ) {
			$list[] = $plp_target;
		}
		return $list;
	},
	PHP_INT_MAX
);

/*
 * Изолированный прогон нельзя пускать в базу: плагины с проверкой зависимостей
 * («нужен WooCommerce/Elementor») решают, что зависимость пропала, и вызывают
 * deactivate_plugins(). Блокируем запись этих опций на время прогона: update_option()
 * получает обратно текущее значение и завершается без записи.
 */
add_filter(
	'pre_update_option_active_plugins',
	static function ( $value, $old_value ) {
		return $old_value;
	},
	PHP_INT_MAX,
	2
);

add_filter(
	'pre_update_option_active_sitewide_plugins',
	static function ( $value, $old_value ) {
		return $old_value;
	},
	PHP_INT_MAX,
	2
);

add_filter(
	'pre_update_option_recently_activated',
	static function ( $value, $old_value ) {
		return $old_value;
	},
	PHP_INT_MAX,
	2
);

add_action(
	'send_headers',
	static function () {
		if ( function_exists( 'nocache_headers' ) ) {
			nocache_headers();
		}
		header( 'X-PLP-Profile: 1' );
	}
);

global $wpdb;
if ( is_object( $wpdb ) ) {
	$wpdb->save_queries = true;
}

add_action(
	'shutdown',
	static function () {
		global $wpdb;

		if ( ! is_object( $wpdb ) || empty( $GLOBALS['plp_ctx'] ) ) {
			return;
		}

		$ctx   = $GLOBALS['plp_ctx'];
		$table = $wpdb->prefix . 'plp_results';

		$queries = (int) $wpdb->num_queries;
		$qtime   = 0.0;
		if ( is_array( $wpdb->queries ) ) {
			foreach ( $wpdb->queries as $record ) {
				if ( isset( $record[1] ) ) {
					$qtime += (float) $record[1];
				}
			}
		}

		$duration = microtime( true ) - (float) $ctx['start'];
		$memory   = function_exists( 'memory_get_peak_usage' ) ? (int) memory_get_peak_usage( true ) : 0;
		$status   = function_exists( 'http_response_code' ) ? (int) http_response_code() : 0;
		$target   = '' !== $ctx['target'] ? (string) $ctx['target'] : '__baseline__';

		$error = '';
		$last  = function_exists( 'error_get_last' ) ? error_get_last() : null;
		if (
			is_array( $last ) &&
			isset( $last['type'], $last['message'] ) &&
			in_array( $last['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR ), true )
		) {
			$where = isset( $last['file'] ) ? basename( (string) $last['file'] ) . ':' . (int) $last['line'] : '';
			$error = 'PHP: ' . (string) $last['message'] . ( '' !== $where ? ' (' . $where . ')' : '' );
			$error = function_exists( 'mb_substr' ) ? mb_substr( $error, 0, 200 ) : substr( $error, 0, 200 );
		}

		try {
			$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
			if ( ! $exists ) {
				return;
			}

			$scheme = function_exists( 'is_ssl' ) && is_ssl() ? 'https' : 'http';
			$host   = isset( $_SERVER['HTTP_HOST'] ) ? (string) $_SERVER['HTTP_HOST'] : '';
			$uri    = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '';
			$url    = $scheme . '://' . $host . $uri;

			$wpdb->replace(
				$table,
				array(
					'scan_id'       => (int) $ctx['scan_id'],
					'plugin_file'   => $target,
					'plugin_name'   => '',
					'run_index'     => (int) $ctx['run'],
					'url_index'     => (int) $ctx['url_i'],
					'url'           => $url,
					'load_time_ms'  => round( $duration * 1000, 3 ),
					'peak_memory_kb'=> (int) round( $memory / 1024 ),
					'query_count'   => $queries,
					'query_time_ms' => round( $qtime * 1000, 3 ),
					'http_status'   => $status,
					'error'         => $error,
					'created_at'    => current_time( 'mysql' ),
				),
				array( '%d', '%s', '%s', '%d', '%d', '%s', '%f', '%d', '%d', '%f', '%d', '%s', '%s' )
			);
		} catch ( \Throwable $e ) {
			// Запись замера не должна превращать ответ сайта в ошибку.
		}
	},
	PHP_INT_MAX
);
