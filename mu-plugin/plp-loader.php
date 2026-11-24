<?php
/**
 * PLP-MU-LOADER v4
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
	'sql'     => array(),
);

/*
 * WordPress проверяет SAVEQUERIES при каждом запросе, поэтому определение из mu-plugin
 * даёт точное время каждого SQL-запроса, хотя $wpdb уже создан.
 */
if ( ! defined( 'SAVEQUERIES' ) ) {
	define( 'SAVEQUERIES', true );
}

/* Запоминаем, из какого файла плагина вызван каждый запрос. */
add_filter(
	'query',
	static function ( $query ) {
		if ( ! isset( $GLOBALS['plp_ctx']['sql'] ) || count( $GLOBALS['plp_ctx']['sql'] ) >= 2000 ) {
			return $query;
		}

		static $roots = null;
		if ( null === $roots ) {
			$norm  = static function ( $path ) {
				return function_exists( 'wp_normalize_path' ) ? wp_normalize_path( (string) $path ) : (string) $path;
			};
			$roots = array(
				'plugins' => $norm( WP_PLUGIN_DIR ),
				'content' => $norm( WP_CONTENT_DIR ),
				'mu'      => defined( 'WPMU_PLUGIN_DIR' ) ? $norm( WPMU_PLUGIN_DIR ) : '',
			);
		}

		$origin = '';
		$trace  = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 60 ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace
		foreach ( $trace as $frame ) {
			if ( empty( $frame['file'] ) ) {
				continue;
			}
			$file = function_exists( 'wp_normalize_path' ) ? wp_normalize_path( (string) $frame['file'] ) : (string) $frame['file'];
			$line = isset( $frame['line'] ) ? ':' . (int) $frame['line'] : '';
			if (
				0 === strpos( $file, $roots['plugins'] . '/' ) ||
				( '' !== $roots['mu'] && 0 === strpos( $file, $roots['mu'] . '/' ) ) ||
				0 === strpos( $file, $roots['content'] . '/themes/' )
			) {
				$origin = str_replace( $roots['content'] . '/', '', $file ) . $line;
				break;
			}
		}

		$GLOBALS['plp_ctx']['sql'][] = array(
			'sql'    => (string) $query,
			'origin' => $origin,
		);

		return $query;
	},
	1
);

$plp_profiler = (string) $plp_scan['profiler_file'];

if ( ! function_exists( 'plp_profiler_normalize_sql' ) ) {
	function plp_profiler_normalize_sql( $sql ) {
		$sql = preg_replace( '/\s+/', ' ', trim( (string) $sql ) );
		return function_exists( 'mb_substr' ) ? mb_substr( $sql, 0, 300 ) : substr( $sql, 0, 300 );
	}
}

if ( ! function_exists( 'plp_profiler_callback_file' ) ) {
	function plp_profiler_callback_file( $callback ) {
		try {
			if ( $callback instanceof Closure ) {
				$ref = new ReflectionFunction( $callback );
			} elseif ( is_array( $callback ) && isset( $callback[0], $callback[1] ) ) {
				$ref = new ReflectionMethod( $callback[0], $callback[1] );
			} elseif ( is_string( $callback ) && false !== strpos( $callback, '::' ) ) {
				$parts = explode( '::', $callback, 2 );
				$ref   = new ReflectionMethod( $parts[0], $parts[1] );
			} elseif ( is_string( $callback ) ) {
				$ref = new ReflectionFunction( $callback );
			} elseif ( is_object( $callback ) ) {
				$ref = new ReflectionMethod( $callback, '__invoke' );
			} else {
				return '';
			}

			$file = $ref->getFileName();
			if ( ! is_string( $file ) || '' === $file ) {
				return '';
			}
			return function_exists( 'wp_normalize_path' ) ? wp_normalize_path( $file ) : $file;
		} catch ( \Throwable $e ) {
			return '';
		}
	}
}

/**
 * Собирает лог нагрузки целевого плагина: SQL по местам вызова, файлы, хуки.
 */
if ( ! function_exists( 'plp_profiler_build_details' ) ) {
	function plp_profiler_build_details( $target, $logged, $sql_log ) {
		$details = array( 'plugin_file' => (string) $target );

		$times = array();
		foreach ( (array) $logged as $row ) {
			if ( ! is_array( $row ) || ! isset( $row[0], $row[1] ) ) {
				continue;
			}
			$sql = (string) $row[0];
			if ( 0 === stripos( ltrim( $sql ), 'SHOW TABLES LIKE' ) || false !== strpos( $sql, 'plp_results' ) ) {
				continue;
			}
			$times[] = array( $sql, (float) $row[1] * 1000 );
		}

		$by_origin = array();
		$slow      = array();
		$cursor    = 0;
		$total     = 0;
		$timed     = 0;

		foreach ( (array) $sql_log as $entry ) {
			$sql = isset( $entry['sql'] ) ? (string) $entry['sql'] : '';
			if ( '' === $sql || 0 === stripos( ltrim( $sql ), 'SHOW TABLES LIKE' ) || false !== strpos( $sql, 'plp_results' ) ) {
				continue;
			}

			$ms = 0.0;
			for ( $k = $cursor; $k < count( $times ); $k++ ) {
				if ( $times[ $k ][0] === $sql ) {
					$ms     = $times[ $k ][1];
					$cursor = $k + 1;
					$timed++;
					break;
				}
			}

			$origin = isset( $entry['origin'] ) && '' !== $entry['origin'] ? (string) $entry['origin'] : 'ядро/тема';
			$total++;

			if ( ! isset( $by_origin[ $origin ] ) ) {
				$by_origin[ $origin ] = array(
					'origin' => $origin,
					'count'  => 0,
					'ms'     => 0.0,
					'sample' => plp_profiler_normalize_sql( $sql ),
				);
			}
			$by_origin[ $origin ]['count']++;
			$by_origin[ $origin ]['ms'] += $ms;

			if ( $ms > 0 ) {
				$slow[] = array(
					'ms'     => round( $ms, 2 ),
					'origin' => $origin,
					'sql'    => plp_profiler_normalize_sql( $sql ),
				);
			}
		}

		foreach ( $by_origin as &$origin_row ) {
			$origin_row['ms'] = round( $origin_row['ms'], 2 );
		}
		unset( $origin_row );

		usort(
			$by_origin,
			static function ( $a, $b ) {
				if ( $a['ms'] === $b['ms'] ) {
					return $b['count'] <=> $a['count'];
				}
				return $b['ms'] <=> $a['ms'];
			}
		);
		usort(
			$slow,
			static function ( $a, $b ) {
				return $b['ms'] <=> $a['ms'];
			}
		);

		$details['queries'] = array(
			'total'   => $total,
			'timed'   => $timed,
			'origins' => array_slice( $by_origin, 0, 15 ),
			'slow'    => array_slice( $slow, 0, 10 ),
		);

		$norm   = static function ( $path ) {
			return function_exists( 'wp_normalize_path' ) ? wp_normalize_path( (string) $path ) : (string) $path;
		};
		$target = (string) $target;
		$single = '.' === dirname( $target );
		$root   = trailingslashit( $norm( WP_PLUGIN_DIR ) );
		$dir    = $norm( dirname( WP_PLUGIN_DIR . '/' . $target ) );

		$files = array();
		$bytes = 0;
		foreach ( get_included_files() as $path ) {
			$path  = $norm( $path );
			$match = $single ? ( $path === $norm( WP_PLUGIN_DIR . '/' . $target ) ) : ( 0 === strpos( $path, $dir . '/' ) );
			if ( ! $match ) {
				continue;
			}
			$size   = @filesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_filesize
			$size   = false === $size ? 0 : (int) $size;
			$bytes += $size;
			$files[] = array(
				'file'  => str_replace( $root, '', $path ),
				'bytes' => $size,
			);
		}
		usort(
			$files,
			static function ( $a, $b ) {
				return $b['bytes'] <=> $a['bytes'];
			}
		);

		$details['files'] = array(
			'count'   => count( $files ),
			'bytes'   => $bytes,
			'largest' => array_slice( $files, 0, 10 ),
		);

		$hooks      = array();
		$hook_total = 0;
		$prefix     = $single ? $norm( WP_PLUGIN_DIR . '/' . $target ) : $dir . '/';

		if ( isset( $GLOBALS['wp_filter'] ) && is_array( $GLOBALS['wp_filter'] ) ) {
			foreach ( $GLOBALS['wp_filter'] as $hook_name => $hook_obj ) {
				if ( ! is_object( $hook_obj ) || empty( $hook_obj->callbacks ) || ! is_array( $hook_obj->callbacks ) ) {
					continue;
				}
				foreach ( $hook_obj->callbacks as $callbacks ) {
					if ( ! is_array( $callbacks ) ) {
						continue;
					}
					foreach ( $callbacks as $entry ) {
						$file = plp_profiler_callback_file( isset( $entry['function'] ) ? $entry['function'] : null );
						if ( '' === $file || 0 !== strpos( $file, $prefix ) ) {
							continue;
						}
						$hook_total++;
						$hook_name               = (string) $hook_name;
						$hooks[ $hook_name ]     = isset( $hooks[ $hook_name ] ) ? $hooks[ $hook_name ] + 1 : 1;
					}
				}
			}
		}

		arsort( $hooks );
		$hooks_top = array();
		foreach ( array_slice( $hooks, 0, 15, true ) as $hook_name => $count ) {
			$hooks_top[] = array(
				'hook'  => (string) $hook_name,
				'count' => (int) $count,
			);
		}

		$details['hooks'] = array(
			'total' => $hook_total,
			'top'   => $hooks_top,
		);

		$json = function_exists( 'wp_json_encode' ) ? wp_json_encode( $details ) : json_encode( $details );
		return is_string( $json ) ? $json : '';
	}
}


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

		$details_json = '';
		if ( '__baseline__' !== $target && 0 === (int) $ctx['run'] ) {
			$logged  = is_array( $wpdb->queries ) ? $wpdb->queries : array();
			$sql_log = isset( $ctx['sql'] ) ? (array) $ctx['sql'] : array();
			$details_json = plp_profiler_build_details( $target, $logged, $sql_log );
		}

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
					'details'       => $details_json,
					'created_at'    => current_time( 'mysql' ),
				),
				array( '%d', '%s', '%s', '%d', '%d', '%s', '%f', '%d', '%d', '%f', '%d', '%s', '%s', '%s' )
			);
		} catch ( \Throwable $e ) {
			// Запись замера не должна превращать ответ сайта в ошибку.
		}
	},
	PHP_INT_MAX
);
