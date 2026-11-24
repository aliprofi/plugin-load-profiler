<?php
/**
 * Оркестратор сканов: AJAX-эндпоинты, план прогонов, агрегация, очистка.
 */

defined( 'ABSPATH' ) || exit;

class PLP_Scan_Manager {

	const MAX_PLUGINS = 50;
	const MAX_RUNS    = 10;
	const MAX_URLS    = 5;
	const MAX_STEPS   = 800;

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function register_hooks() {
		add_action( 'wp_ajax_plp_start_scan', array( $this, 'ajax_start_scan' ) );
		add_action( 'wp_ajax_plp_next_step', array( $this, 'ajax_next_step' ) );
		add_action( 'wp_ajax_plp_step_done', array( $this, 'ajax_step_done' ) );
		add_action( 'wp_ajax_plp_finish_scan', array( $this, 'ajax_finish_scan' ) );
		add_action( 'wp_ajax_plp_abort_scan', array( $this, 'ajax_abort_scan' ) );
		add_action( 'wp_ajax_plp_delete_scan', array( $this, 'ajax_delete_scan' ) );
		add_action( 'wp_ajax_plp_sync_mu', array( $this, 'ajax_sync_mu' ) );
		add_action( 'wp_ajax_plp_restore_scan', array( $this, 'ajax_restore_scan' ) );
		add_action( PLP_Installer::CRON_HOOK, array( $this, 'cron_cleanup' ) );
	}

	/* ---------------------------------------------------------------- AJAX */

	public function ajax_start_scan() {
		$this->guard();

		PLP_Installer::sync_mu_loader();

		if ( ! PLP_Installer::mu_installed() ) {
			wp_send_json_error(
				array( 'message' => 'mu-plugin загрузчик не установлен. Откройте страницу плагина и исправьте установку.' ),
				400
			);
		}

		$previous = get_option( 'plp_active_scan' );
		if ( is_array( $previous ) && ! empty( $previous['id'] ) ) {
			$row = $this->get_scan( (int) $previous['id'] );
			if ( $row && 'running' === $row['status'] ) {
				wp_send_json_error(
					array(
						'message' => sprintf( 'Уже выполняется скан #%d. Дождитесь завершения или прервите его.', (int) $previous['id'] ),
					),
					409
				);
			}
			delete_option( 'plp_active_scan' );
		}

		$runs = isset( $_POST['runs'] ) ? absint( $_POST['runs'] ) : 3;
		$runs = max( 1, min( self::MAX_RUNS, $runs ) );

		$raw_urls = isset( $_POST['urls'] ) ? wp_unslash( $_POST['urls'] ) : '';
		$urls     = $this->sanitize_urls( is_string( $raw_urls ) ? $raw_urls : '' );

		$raw_plugins = isset( $_POST['plugins'] ) ? (array) wp_unslash( $_POST['plugins'] ) : array();
		$plugins     = $this->sanitize_plugins( $raw_plugins );

		if ( empty( $urls ) ) {
			wp_send_json_error( array( 'message' => 'Укажите хотя бы один корректный URL страницы этого сайта.' ), 400 );
		}
		if ( empty( $plugins ) ) {
			wp_send_json_error( array( 'message' => 'Выберите хотя бы один активный плагин.' ), 400 );
		}
		if ( count( $plugins ) > self::MAX_PLUGINS ) {
			wp_send_json_error( array( 'message' => sprintf( 'Максимум %d плагинов за скан.', self::MAX_PLUGINS ) ), 400 );
		}

		$all_plugins = get_plugins();
		$names       = array();
		$targets     = array(
			array(
				'file' => PLP_BASELINE,
				'name' => 'Базовая линия — без плагинов',
			),
		);

		foreach ( $plugins as $file ) {
			$name          = isset( $all_plugins[ $file ]['Name'] ) ? $all_plugins[ $file ]['Name'] : $file;
			$names[ $file ] = $name;
			$targets[]     = array(
				'file' => $file,
				'name' => $name,
			);
		}

		$steps = array();
		foreach ( $targets as $target ) {
			foreach ( $urls as $url_index => $unused ) {
				for ( $run = 0; $run < $runs; $run++ ) {
					$steps[] = array(
						'target' => $target['file'],
						'run'    => $run,
						'url'    => $url_index,
					);
				}
			}
		}

		/* Повторная базовая линия после всех плагинов: сглаживает прогрев кешей. */
		foreach ( $urls as $url_index => $unused ) {
			for ( $run = 0; $run < $runs; $run++ ) {
				$steps[] = array(
					'target' => PLP_BASELINE,
					'run'    => $runs + $run,
					'url'    => $url_index,
				);
			}
		}

		if ( count( $steps ) > self::MAX_STEPS ) {
			wp_send_json_error(
				array( 'message' => sprintf( 'Слишком много прогонов (%d). Уменьшите число плагинов, страниц или прогонов.', count( $steps ) ) ),
				400
			);
		}

		$config = array(
			'runs'           => $runs,
			'urls'           => array_values( $urls ),
			'targets'        => $targets,
			'names'          => $names,
			'steps'          => $steps,
			'pointer'        => 0,
			'profiler_file'  => plugin_basename( PLP_FILE ),
			'active_plugins' => array_values( (array) get_option( 'active_plugins', array() ) ),
		);

		global $wpdb;
		$inserted = $wpdb->insert(
			PLP_Installer::table( 'scans' ),
			array(
				'created_at' => current_time( 'mysql' ),
				'started_at' => current_time( 'mysql' ),
				'status'     => 'running',
				'config'     => wp_json_encode( $config ),
			),
			array( '%s', '%s', '%s', '%s' )
		);

		if ( ! $inserted ) {
			wp_send_json_error( array( 'message' => 'Не удалось создать скан в базе данных.' ), 500 );
		}

		$scan_id = (int) $wpdb->insert_id;
		$token   = bin2hex( random_bytes( 16 ) );

		update_option(
			'plp_active_scan',
			array(
				'id'            => $scan_id,
				'token'         => $token,
				'profiler_file' => plugin_basename( PLP_FILE ),
				'target_file'   => '',
				'started_at'    => current_time( 'mysql' ),
			),
			false
		);

		wp_send_json_success(
			array(
				'scan_id' => $scan_id,
				'total'   => count( $steps ),
				'redirect'=> $this->results_url( $scan_id ),
			)
		);
	}

	public function ajax_next_step() {
		$this->guard();

		$scan_id = isset( $_POST['scan_id'] ) ? absint( $_POST['scan_id'] ) : 0;
		$row     = $this->get_scan( $scan_id );

		if ( ! $row ) {
			wp_send_json_error( array( 'message' => 'Скан не найден.' ), 404 );
		}
		if ( 'running' !== $row['status'] ) {
			wp_send_json_error( array( 'message' => 'Скан уже не выполняется.' ), 409 );
		}

		$config = $this->decode_config( $row );
		$steps  = $config['steps'];
		$total  = count( $steps );
		$index  = (int) $config['pointer'];

		if ( $index >= $total ) {
			wp_send_json_success(
				array(
					'done'     => true,
					'scan_id'  => $scan_id,
					'total'    => $total,
					'redirect' => $this->results_url( $scan_id ),
				)
			);
		}

		$step   = $steps[ $index ];
		$active = get_option( 'plp_active_scan' );

		if ( ! is_array( $active ) || (int) $active['id'] !== $scan_id || empty( $active['token'] ) ) {
			wp_send_json_error(
				array( 'message' => 'Состояние скана потеряно (опция plp_active_scan). Начните скан заново.' ),
				409
			);
		}

		$active['target_file'] = (string) $step['target'];
		update_option( 'plp_active_scan', $active, false );

		$url = isset( $config['urls'][ (int) $step['url'] ] ) ? $config['urls'][ (int) $step['url'] ] : home_url( '/' );
		$url = add_query_arg(
			array(
				'plp_profile' => rawurlencode( $active['token'] ),
				'plp_run'     => (int) $step['run'],
				'plp_url'     => (int) $step['url'],
				'plp_step'    => $index,
				'plp_ts'      => time(),
			),
			$url
		);

		$target_file = (string) $step['target'];
		$target_name = PLP_BASELINE === $target_file
			? 'Базовая линия — без плагинов'
			: ( isset( $config['names'][ $target_file ] ) ? $config['names'][ $target_file ] : $target_file );

		wp_send_json_success(
			array(
				'done'        => false,
				'scan_id'     => $scan_id,
				'index'       => $index,
				'total'       => $total,
				'target'      => $target_name,
				'plugin_file' => $target_file,
				'run'         => ( (int) $step['run'] % max( 1, (int) $config['runs'] ) ) + 1,
				'runs'        => (int) $config['runs'],
				'url'         => $url,
			)
		);
	}

	public function ajax_step_done() {
		$this->guard();

		$scan_id = isset( $_POST['scan_id'] ) ? absint( $_POST['scan_id'] ) : 0;
		$index   = isset( $_POST['index'] ) ? absint( $_POST['index'] ) : 0;
		$ok      = ! empty( $_POST['ok'] );
		$status  = isset( $_POST['http_status'] ) ? absint( $_POST['http_status'] ) : 0;
		$error   = isset( $_POST['error'] ) ? sanitize_text_field( wp_unslash( $_POST['error'] ) ) : '';
		$error   = mb_substr( $error, 0, 200 );

		$row = $this->get_scan( $scan_id );
		if ( ! $row || 'running' !== $row['status'] ) {
			wp_send_json_error( array( 'message' => 'Скан не найден или уже завершён.' ), 404 );
		}

		$config = $this->decode_config( $row );
		$steps  = $config['steps'];
		$total  = count( $steps );

		if ( $index >= $total ) {
			wp_send_json_error( array( 'message' => 'Некорректный индекс шага.' ), 400 );
		}

		$step = $steps[ $index ];

		global $wpdb;
		$table = PLP_Installer::table( 'results' );

		$existing = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE scan_id = %d AND plugin_file = %s AND run_index = %d AND url_index = %d",
				$scan_id,
				(string) $step['target'],
				(int) $step['run'],
				(int) $step['url']
			)
		);

		$url = isset( $config['urls'][ (int) $step['url'] ] ) ? $config['urls'][ (int) $step['url'] ] : home_url( '/' );

		if ( ! $existing ) {
			$wpdb->insert(
				$table,
				array(
					'scan_id'        => $scan_id,
					'plugin_file'    => (string) $step['target'],
					'plugin_name'    => '',
					'run_index'      => (int) $step['run'],
					'url_index'      => (int) $step['url'],
					'url'            => $url,
					'load_time_ms'   => 0,
					'peak_memory_kb' => 0,
					'query_count'    => 0,
					'query_time_ms'  => 0,
					'http_status'    => $status,
					'error'          => '' !== $error ? $error : 'Измерение не получено: проверьте mu-plugin и лог ошибок PHP.',
					'created_at'     => current_time( 'mysql' ),
				),
				array( '%d', '%s', '%s', '%d', '%d', '%s', '%f', '%d', '%d', '%f', '%d', '%s', '%s' )
			);
		} elseif ( ! $ok || '' !== $error ) {
			$wpdb->update(
				$table,
				array(
					'error'       => '' !== $error ? $error : 'Запрос профилирования не выполнен.',
					'http_status' => $status,
				),
				array( 'id' => (int) $existing ),
				array( '%s', '%d' ),
				array( '%d' )
			);
		}

		$config['pointer'] = max( (int) $config['pointer'], $index + 1 );
		$this->update_config( $scan_id, $config );

		wp_send_json_success(
			array(
				'pointer' => (int) $config['pointer'],
				'total'   => $total,
				'done'    => (int) $config['pointer'] >= $total,
			)
		);
	}

	public function ajax_finish_scan() {
		$this->guard();

		$scan_id = isset( $_POST['scan_id'] ) ? absint( $_POST['scan_id'] ) : 0;
		$row     = $this->get_scan( $scan_id );
		if ( ! $row ) {
			wp_send_json_error( array( 'message' => 'Скан не найден.' ), 404 );
		}

		$config = $this->decode_config( $row );

		if ( (int) $config['pointer'] < count( $config['steps'] ) ) {
			wp_send_json_error( array( 'message' => 'Скан ещё не завершён.' ), 409 );
		}

		$summary = $this->aggregate( $scan_id, $config );
		$summary['restored'] = PLP_Installer::restore_active_plugins( $config );

		global $wpdb;
		$wpdb->update(
			PLP_Installer::table( 'scans' ),
			array(
				'status'      => 'finished',
				'finished_at' => current_time( 'mysql' ),
				'summary'     => wp_json_encode( $summary ),
			),
			array( 'id' => $scan_id ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);

		delete_option( 'plp_active_scan' );

		wp_send_json_success( array( 'redirect' => $this->results_url( $scan_id ) ) );
	}

	public function ajax_abort_scan() {
		$this->guard();

		$scan_id = isset( $_POST['scan_id'] ) ? absint( $_POST['scan_id'] ) : 0;
		$row     = $this->get_scan( $scan_id );
		if ( ! $row ) {
			wp_send_json_error( array( 'message' => 'Скан не найден.' ), 404 );
		}

		$config = $this->decode_config( $row );
		PLP_Installer::restore_active_plugins( $config );

		global $wpdb;
		$wpdb->update(
			PLP_Installer::table( 'scans' ),
			array(
				'status'      => 'aborted',
				'finished_at' => current_time( 'mysql' ),
			),
			array( 'id' => $scan_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		$active = get_option( 'plp_active_scan' );
		if ( is_array( $active ) && (int) $active['id'] === $scan_id ) {
			delete_option( 'plp_active_scan' );
		}

		wp_send_json_success( array( 'redirect' => $this->results_url( $scan_id ) ) );
	}

	public function ajax_delete_scan() {
		$this->guard();

		$scan_id = isset( $_POST['scan_id'] ) ? absint( $_POST['scan_id'] ) : 0;
		if ( ! $scan_id ) {
			wp_send_json_error( array( 'message' => 'Некорректный скан.' ), 400 );
		}

		global $wpdb;
		$wpdb->delete( PLP_Installer::table( 'results' ), array( 'scan_id' => $scan_id ), array( '%d' ) );
		$wpdb->delete( PLP_Installer::table( 'scans' ), array( 'id' => $scan_id ), array( '%d' ) );

		$active = get_option( 'plp_active_scan' );
		if ( is_array( $active ) && (int) $active['id'] === $scan_id ) {
			delete_option( 'plp_active_scan' );
		}

		wp_send_json_success( array( 'redirect' => admin_url( 'admin.php?page=plp-profiler' ) ) );
	}

	public function ajax_sync_mu() {
		$this->guard();

		if ( PLP_Installer::sync_mu_loader() ) {
			wp_send_json_success( array( 'reload' => 1 ) );
		}

		wp_send_json_error(
			array(
				'message' => PLP_Installer::mu_error() ? PLP_Installer::mu_error() : 'Не удалось установить mu-plugin. Создайте файл вручную по инструкции на странице.',
			),
			500
		);
	}

	/**
	 * Включает обратно плагины, которые были целями скана, но сейчас отключены.
	 * Нужно для сканов, выполненных до появления защиты от самодеактивации.
	 */
	public function ajax_restore_scan() {
		$this->guard();

		if ( ! function_exists( 'activate_plugin' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$scan_id = isset( $_POST['scan_id'] ) ? absint( $_POST['scan_id'] ) : 0;
		$row     = $this->get_scan( $scan_id );
		if ( ! $row ) {
			wp_send_json_error( array( 'message' => 'Скан не найден.' ), 404 );
		}

		$config = $this->decode_config( $row );
		$current = array_map( 'strval', (array) get_option( 'active_plugins', array() ) );

		$activated = 0;
		$errors    = array();

		foreach ( $config['targets'] as $target ) {
			$file = (string) $target['file'];
			if ( PLP_BASELINE === $file || in_array( $file, $current, true ) ) {
				continue;
			}
			if ( ! file_exists( WP_PLUGIN_DIR . '/' . $file ) ) {
				continue;
			}
			$result = activate_plugin( $file );
			if ( is_wp_error( $result ) ) {
				$errors[] = $file . ': ' . $result->get_error_message();
				continue;
			}
			$activated++;
		}

		wp_send_json_success(
			array(
				'activated' => $activated,
				'errors'    => $errors,
			)
		);
	}

	/* ---------------------------------------------------------------- Данные */

	public function get_scan( $scan_id ) {
		global $wpdb;
		$table = PLP_Installer::table( 'scans' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $scan_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $row ? $row : null;
	}

	public function get_recent_scans( $limit = 20 ) {
		global $wpdb;
		$table = PLP_Installer::table( 'scans' );
		$limit = max( 1, min( 100, (int) $limit ) );
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public function get_results( $scan_id ) {
		global $wpdb;
		$table = PLP_Installer::table( 'results' );
		return $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE scan_id = %d ORDER BY url_index ASC, run_index ASC", $scan_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
	}

	private function decode_config( $row ) {
		$config = json_decode( (string) $row['config'], true );
		if ( ! is_array( $config ) || ! isset( $config['steps'], $config['urls'], $config['runs'] ) ) {
			wp_send_json_error( array( 'message' => 'Повреждённая конфигурация скана.' ), 500 );
		}
		$config['pointer'] = isset( $config['pointer'] ) ? (int) $config['pointer'] : 0;
		return $config;
	}

	private function update_config( $scan_id, $config ) {
		global $wpdb;
		$wpdb->update(
			PLP_Installer::table( 'scans' ),
			array( 'config' => wp_json_encode( $config ) ),
			array( 'id' => $scan_id ),
			array( '%s' ),
			array( '%d' )
		);
	}

	public function results_url( $scan_id ) {
		return admin_url( 'admin.php?page=plp-profiler&scan=' . (int) $scan_id );
	}

	/* ------------------------------------------------------- Агрегация */

	private function aggregate( $scan_id, $config ) {
		$rows = $this->get_results( $scan_id );

		$by_file = array();
		foreach ( $rows as $row ) {
			$by_file[ (string) $row['plugin_file'] ][] = $row;
		}

		$is_ok = static function ( $row ) {
			if ( '' !== (string) $row['error'] ) {
				return false;
			}
			$status = (int) $row['http_status'];
			if ( $status >= 200 && $status <= 299 ) {
				return true;
			}
			if ( $status >= 404 && $status <= 499 ) {
				return ! in_array( $status, array( 401, 403 ), true );
			}
			return false;
		};

		$collect = static function ( $list ) use ( $is_ok ) {
			$ok        = array();
			$failed    = 0;
			$redirects = 0;
			foreach ( $list as $row ) {
				$status = (int) $row['http_status'];
				if ( $is_ok( $row ) ) {
					$ok[] = $row;
				} elseif ( $status >= 300 && $status <= 399 ) {
					$redirects++;
				} else {
					$failed++;
				}
			}
			return array( $ok, $failed, $redirects );
		};

		$median = static function ( $values ) {
			$values = array_map( 'floatval', $values );
			sort( $values, SORT_NUMERIC );
			$count = count( $values );
			if ( 0 === $count ) {
				return 0.0;
			}
			$middle = intdiv( $count, 2 );
			if ( 0 === $count % 2 ) {
				return ( $values[ $middle - 1 ] + $values[ $middle ] ) / 2;
			}
			return $values[ $middle ];
		};

		$metrics = static function ( $ok ) use ( $median ) {
			return array(
				'time'    => round( $median( wp_list_pluck( $ok, 'load_time_ms' ) ), 1 ),
				'memory'  => round( $median( wp_list_pluck( $ok, 'peak_memory_kb' ) ) , 0 ),
				'queries' => round( $median( wp_list_pluck( $ok, 'query_count' ) ), 0 ),
				'qtime'   => round( $median( wp_list_pluck( $ok, 'query_time_ms' ) ), 1 ),
			);
		};

		$baseline_rows = isset( $by_file[ PLP_BASELINE ] ) ? $by_file[ PLP_BASELINE ] : array();
		list( $b_ok, $b_failed, $b_redirects ) = $collect( $baseline_rows );
		$base = $metrics( $b_ok );

		$urls_count       = max( 1, count( $config['urls'] ) );
		$expected         = (int) $config['runs'] * $urls_count;
		$expected_baseline = $expected * 2;

		$targets_out = array();
		$positive_sum = 0.0;

		foreach ( $config['targets'] as $target ) {
			$file = (string) $target['file'];
			if ( PLP_BASELINE === $file ) {
				continue;
			}

			$list = isset( $by_file[ $file ] ) ? $by_file[ $file ] : array();
			list( $ok, $failed, $redirects ) = $collect( $list );
			$values = $metrics( $ok );

			$delta = array(
				'time'    => round( $values['time'] - $base['time'], 1 ),
				'memory'  => (int) ( $values['memory'] - $base['memory'] ),
				'queries' => (int) ( $values['queries'] - $base['queries'] ),
				'qtime'   => round( $values['qtime'] - $base['qtime'], 1 ),
			);

			$positive_sum += max( 0, $delta['time'] );

			$severity = 'ok';
			if ( 0 === count( $ok ) ) {
				$severity = 'failed';
			} elseif (
				$delta['time'] >= max( 120, $base['time'] * 0.3 ) ||
				$delta['queries'] >= 60 ||
				( $base['time'] > 0 && $values['time'] >= $base['time'] * 2 )
			) {
				$severity = 'high';
			} elseif ( $delta['time'] >= 60 || $delta['queries'] >= 25 ) {
				$severity = 'medium';
			}

			$targets_out[] = array(
				'file'          => $file,
				'name'          => isset( $config['names'][ $file ] ) ? $config['names'][ $file ] : $file,
				'values'        => $values,
				'delta'         => $delta,
				'severity'      => $severity,
				'runs_ok'       => count( $ok ),
				'runs_expected' => $expected,
				'runs_failed'   => $failed + max( 0, $expected - count( $list ) ),
				'redirects'     => $redirects,
				'share'         => 0.0,
			);
		}

		$denominator = max( 0.001, $base['time'] + $positive_sum );
		foreach ( $targets_out as &$item ) {
			$item['share'] = round( max( 0, $item['delta']['time'] ) / $denominator * 100, 1 );
		}
		unset( $item );

		usort(
			$targets_out,
			static function ( $a, $b ) {
				return $b['delta']['time'] <=> $a['delta']['time'];
			}
		);

		return array(
			'generated_at' => current_time( 'mysql' ),
			'runs'         => (int) $config['runs'],
			'urls'         => $config['urls'],
			'baseline'     => $base,
			'baseline_runs'=> array(
				'ok'        => count( $b_ok ),
				'failed'    => $b_failed,
				'redirects' => $b_redirects,
				'expected'  => $expected_baseline,
			),
			'targets'      => $targets_out,
			'totals'       => array(
				'steps'       => count( $config['steps'] ),
				'saved'       => count( $rows ),
				'missing'     => max( 0, count( $config['steps'] ) - count( $rows ) ),
			),
		);
	}

	/* ------------------------------------------------------- Утилиты */

	private function guard() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Недостаточно прав.' ), 403 );
		}
		if ( false === check_ajax_referer( 'plp_scan', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => 'Проверка безопасности не пройдена. Обновите страницу.' ), 403 );
		}
	}

	private function sanitize_plugins( $raw ) {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$active = (array) get_option( 'active_plugins', array() );
		$all    = get_plugins();
		$own    = plugin_basename( PLP_FILE );
		$clean  = array();

		foreach ( $raw as $file ) {
			$file = trim( (string) $file );
			$file = preg_replace( '#[^A-Za-z0-9_\-/\.]#', '', $file );
			if ( '' === $file || $file === $own || strlen( $file ) > 255 ) {
				continue;
			}
			if ( ! in_array( $file, $active, true ) || ! isset( $all[ $file ] ) ) {
				continue;
			}
			$clean[ $file ] = $file;
		}

		return array_values( $clean );
	}

	private function sanitize_urls( $raw ) {
		$home      = wp_parse_url( home_url( '/' ) );
		$home_host = isset( $home['host'] ) ? strtolower( (string) $home['host'] ) : '';
		$home_port = isset( $home['port'] ) ? (int) $home['port'] : 0;

		$urls      = array();
		$had_input = false;
		$lines     = preg_split( '/\r\n|\r|\n/', (string) $raw );
		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			$had_input = true;
			$url = esc_url_raw( $line, array( 'http', 'https' ) );
			if ( '' === $url ) {
				continue;
			}
			$parts = wp_parse_url( $url );
			if ( empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
				continue;
			}
			if ( ! in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true ) ) {
				continue;
			}
			if ( strtolower( (string) $parts['host'] ) !== $home_host ) {
				continue;
			}
			$port = isset( $parts['port'] ) ? (int) $parts['port'] : 0;
			if ( $home_port && $port && $port !== $home_port ) {
				continue;
			}
			$urls[ $url ] = $url;
			if ( count( $urls ) >= self::MAX_URLS ) {
				break;
			}
		}

		if ( empty( $urls ) && ! $had_input ) {
			$urls[ home_url( '/' ) ] = home_url( '/' );
		}

		return array_values( $urls );
	}

	/* ------------------------------------------------------- Очистка */

	public function cron_cleanup() {
		global $wpdb;
		$scans   = PLP_Installer::table( 'scans' );
		$results = PLP_Installer::table( 'results' );

		$stale = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, config FROM {$scans} WHERE status = 'running' AND created_at < DATE_SUB( NOW(), INTERVAL %d HOUR )", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				3
			),
			ARRAY_A
		);
		foreach ( $stale as $stale_row ) {
			$id     = (int) $stale_row['id'];
			$config = json_decode( (string) $stale_row['config'], true );
			if ( is_array( $config ) ) {
				PLP_Installer::restore_active_plugins( $config );
			}

			$wpdb->update(
				$scans,
				array(
					'status'      => 'aborted',
					'finished_at' => current_time( 'mysql' ),
				),
				array( 'id' => $id ),
				array( '%s', '%s' ),
				array( '%d' )
			);
			$active = get_option( 'plp_active_scan' );
			if ( is_array( $active ) && (int) $active['id'] === $id ) {
				delete_option( 'plp_active_scan' );
			}
		}

		$settings = PLP_Installer::settings();
		$days     = max( 1, (int) $settings['retention_days'] );
		$old      = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM {$scans} WHERE status <> 'running' AND created_at < DATE_SUB( NOW(), INTERVAL %d DAY )", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$days
			)
		);
		foreach ( $old as $id ) {
			$wpdb->delete( $results, array( 'scan_id' => (int) $id ), array( '%d' ) );
			$wpdb->delete( $scans, array( 'id' => (int) $id ), array( '%d' ) );
		}
	}
}
