<?php
/**
 * Админ-интерфейс: запуск скана, прогресс, отчёт, история, экспорт.
 */

defined( 'ABSPATH' ) || exit;

class PLP_Admin {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function register_hooks() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'load-toplevel_page_plp-profiler', array( $this, 'maybe_sync' ) );
		add_action( 'admin_post_plp_export', array( $this, 'export_csv' ) );
		add_action( 'admin_post_plp_log', array( $this, 'export_log' ) );
	}

	public function menu() {
		add_menu_page(
			'Профайлер плагинов',
			'Профайлер',
			'manage_options',
			'plp-profiler',
			array( $this, 'render' ),
			'dashicons-performance',
			81
		);
	}

	public function maybe_sync() {
		if ( current_user_can( 'manage_options' ) ) {
			PLP_Installer::sync_mu_loader();
		}
	}

	public function assets( $hook ) {
		if ( 'toplevel_page_plp-profiler' !== $hook ) {
			return;
		}

		wp_enqueue_style( 'plp-admin', PLP_URL . 'assets/css/admin.css', array(), PLP_VERSION );
		wp_enqueue_script( 'plp-admin', PLP_URL . 'assets/js/admin.js', array(), PLP_VERSION, true );

		$active = get_option( 'plp_active_scan' );
		$active_scan = null;
		if ( is_array( $active ) && ! empty( $active['id'] ) ) {
			$row = PLP_Scan_Manager::instance()->get_scan( (int) $active['id'] );
			if ( $row && 'running' === $row['status'] ) {
				$config = json_decode( (string) $row['config'], true );
				$active_scan = array(
					'id'    => (int) $row['id'],
					'total' => is_array( $config ) && isset( $config['steps'] ) ? count( $config['steps'] ) : 0,
				);
			}
		}

		wp_localize_script(
			'plp-admin',
			'plpAdmin',
			array(
				'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
				'nonce'       => wp_create_nonce( 'plp_scan' ),
				'activeScan'  => $active_scan,
				'i18n'        => array(
					'error'       => 'Ошибка запроса. Обновите страницу и попробуйте снова.',
					'selectOne'   => 'Отметьте хотя бы один плагин.',
					'starting'    => 'Создаю скан…',
					'running'     => 'Идёт скан',
					'done'        => 'Готово, открываю отчёт…',
					'aborted'     => 'Скан прерван.',
					'confirmAbort'=> 'Прервать текущий скан?',
					'confirmDelete' => 'Удалить скан и все его результаты?',
					'confirmRestore' => 'Включить обратно плагины из этого скана?',
					'restored'    => 'Включено плагинов: %d',
					'http'        => 'HTTP %s',
					'hint500'     => 'HTTP 500: страница не открывается без отключённых плагинов. Попробуйте режим «Быстрый зонд».',
					'network'     => 'Сетевая ошибка',
					'step'        => '%1$s · прогон %2$d/%3$d',
					'progress'    => '%1$d из %2$d',
					'finishedAll' => 'Все шаги выполнены.',
				),
			)
		);
	}

	/* ------------------------------------------------------------- Страницы */

	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Недостаточно прав.' );
		}

		$scan_id = isset( $_GET['scan'] ) ? absint( $_GET['scan'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$log     = isset( $_GET['log'] ) ? sanitize_text_field( wp_unslash( $_GET['log'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		echo '<div class="wrap plp-wrap">';

		if ( $scan_id && '' !== $log ) {
			$this->render_log( $scan_id, $log );
		} elseif ( $scan_id ) {
			$this->render_results( $scan_id );
		} else {
			$this->render_dashboard();
		}

		echo '<p class="plp-author">Автор: <a href="https://aliprofi.ru" target="_blank" rel="noopener noreferrer">Али Профи</a> · <a href="https://aliprofi.ru" target="_blank" rel="noopener noreferrer">aliprofi.ru</a></p>';
		echo '</div>';
	}

	private function render_dashboard() {
		$settings     = PLP_Installer::settings();
		$manager      = PLP_Scan_Manager::instance();
		$active_scan  = get_option( 'plp_active_scan' );
		$mu_error     = PLP_Installer::mu_error();
		$mu_installed = PLP_Installer::mu_installed();

		$running = null;
		if ( is_array( $active_scan ) && ! empty( $active_scan['id'] ) ) {
			$row = $manager->get_scan( (int) $active_scan['id'] );
			if ( $row && 'running' === $row['status'] ) {
				$running = $row;
			} else {
				delete_option( 'plp_active_scan' );
			}
		}

		$active_files = (array) get_option( 'active_plugins', array() );
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$all_plugins  = get_plugins();
		$own          = plugin_basename( PLP_FILE );

		$plugins = array();
		foreach ( $active_files as $file ) {
			if ( $file === $own || ! isset( $all_plugins[ $file ] ) ) {
				continue;
			}
			$plugins[] = array(
				'file'    => $file,
				'name'    => $all_plugins[ $file ]['Name'],
				'version' => isset( $all_plugins[ $file ]['Version'] ) ? $all_plugins[ $file ]['Version'] : '',
			);
		}
		usort(
			$plugins,
			static function ( $a, $b ) {
				return strcasecmp( $a['name'], $b['name'] );
			}
		);

		$urls_text = implode( "\n", array_map( 'strval', (array) $settings['urls'] ) );
		$mode      = isset( $_GET['mode'] ) && 'probe' === $_GET['mode'] ? 'probe' : 'page'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		echo '<h1>Профайлер плагинов <span class="plp-tag">P3-style</span></h1>';
		echo '<p class="plp-lead">Поочерёдный замер вклада каждого плагина во время генерации страницы, память и SQL-запросы. Базовая линия — тот же сайт без плагинов, дельта — вклад конкретного плагина.</p>';

		if ( ! $mu_installed ) {
			$this->mu_help_block( $mu_error );
		}

		if ( $running ) {
			$config = json_decode( (string) $running['config'], true );
			$total  = is_array( $config ) && isset( $config['steps'] ) ? count( $config['steps'] ) : 0;
			$pointer = is_array( $config ) && isset( $config['pointer'] ) ? (int) $config['pointer'] : 0;
			echo '<div class="notice notice-warning plp-active-banner" id="plp-active-banner">';
			echo '<p><strong>Скан #' . esc_html( $running['id'] ) . ' не завершён.</strong> Выполнено ' . esc_html( $pointer ) . ' из ' . esc_html( $total ) . ' шагов.</p>';
			echo '<p><button type="button" class="button button-primary" id="plp-resume" data-scan-id="' . esc_attr( $running['id'] ) . '">Продолжить скан</button> ';
			echo '<button type="button" class="button plp-abort-active" data-scan-id="' . esc_attr( $running['id'] ) . '">Прервать</button></p>';
			echo '</div>';
		}

		$recent = $manager->get_recent_scans( 1 );
		if ( ! empty( $recent ) ) {
			$last         = $recent[0];
			$last_time    = strtotime( (string) $last['created_at'] );
			$last_config  = json_decode( (string) $last['config'], true );
			$current_list = array_map( 'strval', (array) get_option( 'active_plugins', array() ) );
			$inactive     = array();

			if ( is_array( $last_config ) && ! empty( $last_config['targets'] ) ) {
				foreach ( $last_config['targets'] as $target_item ) {
					$file = (string) $target_item['file'];
					if ( PLP_BASELINE === $file || in_array( $file, $current_list, true ) ) {
						continue;
					}
					if ( file_exists( WP_PLUGIN_DIR . '/' . $file ) ) {
						$inactive[] = $file;
					}
				}
			}

			$still_relevant = ! $last_time || $last_time >= time() - 7 * DAY_IN_SECONDS;

			if ( ! empty( $inactive ) && $still_relevant ) {
				echo '<div class="notice notice-warning">';
				echo '<p><strong>После скана #' . esc_html( $last['id'] ) . ' отключено ' . esc_html( count( $inactive ) ) . ' плагин(ов) из числа проверявшихся.</strong> Изолированный прогон мог выключить их из-за проверки зависимостей.</p>';
				echo '<p><button type="button" class="button" id="plp-restore" data-scan-id="' . esc_attr( $last['id'] ) . '">Включить их обратно</button></p>';
				echo '</div>';
			}
		}

		echo '<div class="plp-grid">';

		/* Левая колонка — форма */
		echo '<div class="plp-card">';
		echo '<h2>Новый скан</h2>';

		if ( ! $mu_installed ) {
			echo '<p class="plp-muted">Сканы недоступны, пока mu-plugin загрузчик не установлен.</p>';
		} elseif ( empty( $plugins ) ) {
			echo '<p class="plp-muted">Нет активных плагинов для теста (кроме профайлера).</p>';
		} else {
			echo '<form id="plp-scan-form" autocomplete="off">';
			echo '<fieldset class="plp-fieldset">';
			echo '<legend>Плагины для теста <span class="plp-muted">(' . esc_html( count( $plugins ) ) . ')</span></legend>';
			echo '<label class="plp-check-all"><input type="checkbox" id="plp-check-all" checked> Выбрать все</label>';
			echo '<div class="plp-plugin-list">';
			foreach ( $plugins as $plugin ) {
				echo '<label class="plp-plugin"><input type="checkbox" name="plugins[]" value="' . esc_attr( $plugin['file'] ) . '" checked> ';
				echo '<span class="plp-plugin-name">' . esc_html( $plugin['name'] ) . '</span>';
				if ( '' !== $plugin['version'] ) {
					echo ' <span class="plp-plugin-ver">' . esc_html( $plugin['version'] ) . '</span>';
				}
				echo '</label>';
			}
			echo '</div>';
			echo '</fieldset>';

			echo '<p class="plp-row"><label for="plp-runs">Прогонов на плагин</label> ';
			echo '<input type="number" id="plp-runs" name="runs" min="1" max="' . esc_attr( PLP_Scan_Manager::MAX_RUNS ) . '" value="' . esc_attr( (int) $settings['runs'] ) . '" class="small-text">';
			echo ' <span class="plp-muted">1–' . esc_html( PLP_Scan_Manager::MAX_RUNS ) . ', берётся медиана</span></p>';

			echo '<fieldset class="plp-fieldset plp-mode">';
			echo '<legend>Режим замера</legend>';
			echo '<label><input type="radio" name="mode" value="page"' . checked( 'page', $mode, false ) . '> Страницы сайта <span class="plp-muted">— реалистично: тема, шаблоны, виджеты</span></label>';
			echo '<label><input type="radio" name="mode" value="probe"' . checked( 'probe', $mode, false ) . '> Быстрый зонд <span class="plp-muted">— только загрузка плагинов и init, без шаблонов темы</span></label>';
			echo '<p class="plp-muted">Если прогоны возвращают HTTP 500, страница не собирается без отключённых плагинов (конструктор страниц, шаблон WooCommerce). «Быстрый зонд» измерит чистый вклад плагинов и не упадёт.</p>';
			echo '</fieldset>';

			echo '<p id="plp-urls-row"><label for="plp-urls">Страницы для теста <span class="plp-muted">(по одной в строке, до ' . esc_html( PLP_Scan_Manager::MAX_URLS ) . ')</span></label>';
			echo '<textarea id="plp-urls" name="urls" rows="3" class="large-text code">' . esc_textarea( $urls_text ) . '</textarea>';
			echo '<span class="plp-muted">Только этот сайт. Используйте финальные URL без редиректов.</span></p>';

			echo '<p><button type="button" class="button button-primary button-hero" id="plp-start"' . ( $mu_installed ? '' : ' disabled' ) . '>Запустить скан</button></p>';
			echo '<p class="plp-error" id="plp-form-error" hidden></p>';
			echo '</form>';
		}

		echo '<div id="plp-progress" class="plp-progress" hidden>';
		echo '<h3>Прогресс</h3>';
		echo '<p class="plp-progress-line"><span id="plp-progress-label">Подготовка…</span> <span class="plp-muted" id="plp-progress-count"></span></p>';
		echo '<div class="plp-bar"><span id="plp-bar" style="width:0%"></span></div>';
		echo '<ul id="plp-log" class="plp-log" aria-live="polite"></ul>';
		echo '<p><button type="button" class="button" id="plp-abort">Прервать скан</button></p>';
		echo '</div>';

		echo '</div>'; // card

		/* Правая колонка — как это работает */
		echo '<div class="plp-card plp-side">';
		echo '<h2>Как это работает</h2>';
		echo '<ol class="plp-steps">';
		echo '<li>Устанавливается mu-plugin загрузчик — он срабатывает раньше обычных плагинов.</li>';
		echo '<li>Каждый шаг — обычный GET на выбранную страницу с одноразовым токеном. На время шага активны только профайлер и один проверяемый плагин.</li>';
		echo '<li>Сервер записывает время генерации, пиковую память, число и суммарное время SQL-запросов.</li>';
		echo '<li>Базовая линия — тот же сайт без плагинов. Дельта показывает личный вклад плагина.</li>';
		echo '<li>Итог — медиана по прогонам, сортировка по влиянию на время, доля в общей нагрузке.</li>';
		echo '</ol>';
		echo '<h3>Ограничения</h3>';
		echo '<ul class="plp-limits">';
		echo '<li>Сайт получит серию запросов: плагины × прогоны × страницы + базовая линия. Лучше тестировать копию сайта или в непиковые часы.</li>';
		echo '<li>Изолированный режим может ломать плагины с зависимостями — такие прогоны помечаются как неудачные и не портят медиану.</li>';
		echo '<li>Ответы 3xx исключаются: укажите финальные URL.</li>';
		echo '<li>Ставится DONOTCACHEPAGE, но агрессивные кешеры на уровне сервера всё равно могут мешать точности.</li>';
		echo '<li>Запись в список активных плагинов на время прогона блокируется, а после скана исходный список автоматически восстанавливается: плагины с проверкой зависимостей не отключаются насовсем.</li>';
		echo '</ul>';

		echo '<h3>Окружение</h3>';
		echo '<ul class="plp-env">';
		echo '<li>WordPress: ' . esc_html( get_bloginfo( 'version' ) ) . '</li>';
		echo '<li>PHP: ' . esc_html( PHP_VERSION ) . '</li>';
		echo '<li>Лимит памяти: ' . esc_html( ini_get( 'memory_limit' ) ) . '</li>';
		echo '<li>Активных плагинов: ' . esc_html( count( $active_files ) ) . '</li>';
		echo '<li>mu-plugin загрузчик: ' . ( $mu_installed ? '<span class="plp-ok">установлен</span>' : '<span class="plp-bad">нет</span>' ) . '</li>';
		echo '</ul>';
		echo '</div>';

		echo '</div>'; // grid

		$this->render_history( $manager );
	}

	private function render_history( $manager ) {
		$scans = $manager->get_recent_scans( 20 );
		if ( empty( $scans ) ) {
			return;
		}

		echo '<div class="plp-card plp-history">';
		echo '<h2>История сканов</h2>';
		echo '<table class="widefat striped">';
		echo '<thead><tr><th>#</th><th>Дата</th><th>Статус</th><th>Плагинов</th><th>Шагов</th><th>Действия</th></tr></thead><tbody>';

		$labels = array(
			'running'  => 'Выполняется',
			'finished' => 'Завершён',
			'aborted'  => 'Прерван',
		);

		foreach ( $scans as $scan ) {
			$config  = json_decode( (string) $scan['config'], true );
			$targets = is_array( $config ) && isset( $config['targets'] ) ? count( $config['targets'] ) - 1 : 0;
			$steps   = is_array( $config ) && isset( $config['steps'] ) ? count( $config['steps'] ) : 0;
			$status  = isset( $labels[ $scan['status'] ] ) ? $labels[ $scan['status'] ] : $scan['status'];

			echo '<tr>';
			echo '<td>' . esc_html( $scan['id'] ) . '</td>';
			echo '<td>' . esc_html( mysql2date( 'd.m.Y H:i', $scan['created_at'] ) ) . '</td>';
			echo '<td>' . esc_html( $status ) . '</td>';
			echo '<td>' . esc_html( max( 0, $targets ) ) . '</td>';
			echo '<td>' . esc_html( $steps ) . '</td>';
			echo '<td><a href="' . esc_url( $manager->results_url( (int) $scan['id'] ) ) . '">Открыть</a> · ';
			echo '<a href="#" class="plp-delete" data-scan-id="' . esc_attr( $scan['id'] ) . '">Удалить</a></td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
		echo '</div>';
	}

	private function mu_help_block( $mu_error ) {
		$template = PLP_Installer::mu_template();
		$code     = file_exists( $template ) ? file_get_contents( $template ) : '';

		echo '<div class="notice notice-error plp-mu-block">';
		echo '<p><strong>mu-plugin загрузчик не установлен — сканы не смогут измерить плагины.</strong></p>';
		if ( '' !== $mu_error ) {
			echo '<p>' . esc_html( $mu_error ) . '</p>';
		}
		echo '<p>Скопируйте код ниже в файл <code>' . esc_html( PLP_Installer::mu_path() ) . '</code>:</p>';
		echo '<p><button type="button" class="button" id="plp-mu-toggle">Показать код</button> ';
		echo '<button type="button" class="button" id="plp-mu-retry">Повторить автоустановку</button></p>';
		echo '<textarea id="plp-mu-code" rows="10" class="large-text code" hidden readonly>' . esc_textarea( (string) $code ) . '</textarea>';
		echo '</div>';
	}

	/* ------------------------------------------------------------- Отчёт */

	private function render_results( $scan_id ) {
		$manager = PLP_Scan_Manager::instance();
		$scan    = $manager->get_scan( $scan_id );

		if ( ! $scan ) {
			echo '<h1>Скан не найден</h1>';
			echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=plp-profiler' ) ) . '">← К списку сканов</a></p>';
			return;
		}

		$summary = json_decode( (string) $scan['summary'], true );
		$config  = json_decode( (string) $scan['config'], true );
		$status  = (string) $scan['status'];

		echo '<h1>Отчёт по скану #' . esc_html( $scan_id ) . '</h1>';
		echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=plp-profiler' ) ) . '">← К профайлеру</a></p>';

		if ( 'finished' !== $status || ! is_array( $summary ) ) {
			echo '<div class="notice notice-warning"><p>Скан ещё не завершён или был прерван. Статус: <strong>' . esc_html( $status ) . '</strong>.</p></div>';
			return;
		}

		$baseline = isset( $summary['baseline'] ) ? $summary['baseline'] : array( 'time' => 0, 'memory' => 0, 'queries' => 0, 'qtime' => 0 );
		$runs     = isset( $summary['baseline_runs'] ) ? $summary['baseline_runs'] : array( 'ok' => 0, 'failed' => 0, 'redirects' => 0, 'expected' => 0 );
		$targets  = isset( $summary['targets'] ) ? $summary['targets'] : array();
		$totals   = isset( $summary['totals'] ) ? $summary['totals'] : array( 'steps' => 0, 'saved' => 0, 'missing' => 0 );
		$mode     = isset( $summary['mode'] ) ? (string) $summary['mode'] : 'page';
		$rows     = $manager->get_results( $scan_id );
		$logged   = $manager->details_map( $scan_id );

		/* Сводка */
		echo '<div class="plp-cards-row">';

		echo '<div class="plp-card plp-baseline">';
		echo '<h2>Базовая линия</h2>';
		echo '<p class="plp-muted">Сайт без плагинов (только ядро, тема и профайлер)</p>';
		echo '<ul class="plp-metrics">';
		echo '<li><span>' . esc_html( $this->fmt_ms( $baseline['time'] ) ) . '</span> время страницы</li>';
		echo '<li><span>' . esc_html( $this->fmt_mb( $baseline['memory'] ) ) . '</span> пиковая память</li>';
		echo '<li><span>' . esc_html( number_format_i18n( $baseline['queries'] ) ) . '</span> SQL-запросов</li>';
		echo '<li><span>' . esc_html( $this->fmt_ms( $baseline['qtime'] ) ) . '</span> время SQL</li>';
		echo '</ul>';
		echo '<p class="plp-muted">Успешных прогонов: ' . esc_html( $runs['ok'] ) . ' из ' . esc_html( $runs['expected'] ) . '</p>';
		echo '</div>';

		echo '<div class="plp-card">';
		echo '<h2>Скан</h2>';
		echo '<ul class="plp-metrics">';
		echo '<li><span>' . esc_html( $scan['created_at'] ) . '</span> начат</li>';
		echo '<li><span>' . esc_html( 'probe' === $mode ? 'Быстрый зонд' : 'Страницы сайта' ) . '</span> режим</li>';
		echo '<li><span>' . esc_html( (int) $summary['runs'] ) . '</span> прогонов на цель</li>';
		echo '<li><span>' . esc_html( count( (array) $summary['urls'] ) ) . '</span> страниц</li>';
		echo '<li><span>' . esc_html( count( $targets ) ) . '</span> плагинов</li>';
		echo '</ul>';
		echo '<p class="plp-muted">Шагов сохранено: ' . esc_html( $totals['saved'] ) . ' из ' . esc_html( $totals['steps'] ) . '</p>';
		echo '</div>';

		echo '</div>';

		/* Предупреждения */
		$warnings = array();
		if ( 0 === (int) $runs['ok'] ) {
			$warnings[] = 'Ни один прогон базовой линии не удался: страница, вероятно, не открывается без отключённых плагинов (конструктор страниц, шаблон WooCommerce и т.п.). Запустите скан в режиме «Быстрый зонд» — он измерит загрузку плагинов без шаблонов темы.';
		} elseif ( $runs['failed'] > 0 ) {
			$warnings[] = sprintf( 'Неудачных прогонов базовой линии: %d. Дельта может быть завышена.', $runs['failed'] );
		}
		if ( $runs['redirects'] > 0 ) {
			$warnings[] = sprintf( 'Базовая линия вернула редирект %d раз(а). Укажите финальный URL без 3xx.', $runs['redirects'] );
		}
		if ( $totals['missing'] > 0 ) {
			$warnings[] = sprintf( 'Не получено измерений для %d шагов (фатальные ошибки или mu-plugin не сработал).', $totals['missing'] );
		}
		$no_data = 0;
		foreach ( $targets as $target ) {
			if ( 'failed' === $target['severity'] ) {
				$no_data++;
			}
		}
		if ( $no_data > 0 ) {
			$warnings[] = sprintf( 'Для %d плагин(ов) нет ни одного успешного прогона: скорее всего, изолированный режим уронил плагин или его зависимости.', $no_data );
		}
		if ( ! empty( $summary['restored'] ) ) {
			$warnings[] = sprintf( 'Автоматически возвращены в активные %d плагин(ов), которые изолированный прогон мог отключить.', (int) $summary['restored'] );
		}
		if ( $warnings ) {
			echo '<div class="notice notice-warning"><ul class="plp-warn-list">';
			foreach ( $warnings as $warning ) {
				echo '<li>' . esc_html( $warning ) . '</li>';
			}
			echo '</ul></div>';
		}

		$error_notes = array();
		foreach ( $rows as $row ) {
			$note = trim( (string) $row['error'] );
			if ( '' === $note ) {
				continue;
			}
			$error_notes[ $note ] = isset( $error_notes[ $note ] ) ? $error_notes[ $note ] + 1 : 1;
		}
		if ( $error_notes ) {
			arsort( $error_notes );
			echo '<div class="notice notice-error"><p><strong>Что вернул сервер на неудачных прогонах:</strong></p><ul class="plp-warn-list">';
			$shown = 0;
			foreach ( $error_notes as $note => $count ) {
				echo '<li><code>' . esc_html( $note ) . '</code>' . ( $count > 1 ? ' — ' . esc_html( $count ) . ' раз(а)' : '' ) . '</li>';
				$shown++;
				if ( $shown >= 5 ) {
					break;
				}
			}
			echo '</ul></div>';
		}

		/* Рекомендации */
		$heavy = array_filter(
			$targets,
			static function ( $item ) {
				return in_array( $item['severity'], array( 'high', 'medium' ), true );
			}
		);

		echo '<div class="plp-card plp-reco">';
		echo '<h2>Что оптимизировать в первую очередь</h2>';
		if ( empty( $heavy ) ) {
			echo '<p>Существенной нагрузки не выявлено: все плагины укладываются в пороги (до +60 мс и +25 запросов к базовой линии).</p>';
		} else {
			echo '<ol class="plp-reco-list">';
			foreach ( $heavy as $item ) {
				echo '<li>';
				echo '<strong>' . esc_html( $item['name'] ) . '</strong> — ';
				echo esc_html( $this->fmt_delta( $item['delta']['time'], ' мс' ) ) . ' к времени, ';
				echo esc_html( $this->fmt_delta( $item['delta']['queries'], ' запр.' ) ) . ' к базе, ';
				echo 'доля ' . esc_html( number_format_i18n( $item['share'], 1 ) ) . '%.';
				if ( $item['delta']['queries'] >= 25 ) {
					echo ' Много запросов: ищите лишние WP_Query/опции без кеша.';
				} elseif ( $item['delta']['time'] >= 60 ) {
					echo ' Тяжёлая инициализация: проверьте автозагрузку, хуки на init и внешние вызовы.';
				}
				echo '</li>';
			}
			echo '</ol>';
		}
		echo '</div>';

		/* Таблица */
		$max_delta = 0.0;
		foreach ( $targets as $target ) {
			$max_delta = max( $max_delta, (float) $target['delta']['time'] );
		}
		$max_delta = max( 1.0, $max_delta );

		echo '<div class="plp-card">';
		echo '<h2>Вклад плагинов</h2>';
		echo '<table class="widefat striped plp-table">';
		echo '<thead><tr>';
		echo '<th>Плагин</th><th>Время</th><th>Δ времени</th><th>Память</th><th>SQL</th><th>Δ SQL</th><th>Доля</th><th>Оценка</th>';
		echo '</tr></thead><tbody>';

		if ( empty( $targets ) ) {
			echo '<tr><td colspan="8">Нет данных.</td></tr>';
		}

		foreach ( $targets as $target ) {
			$severity = (string) $target['severity'];
			$width    = (float) $target['delta']['time'] > 0
				? max( 2, min( 100, (float) $target['delta']['time'] / $max_delta * 100 ) )
				: 0;

			echo '<tr>';
			echo '<td><strong>' . esc_html( $target['name'] ) . '</strong><br><code class="plp-file">' . esc_html( $target['file'] ) . '</code>';
			if ( in_array( (string) $target['file'], $logged, true ) ) {
				echo '<br><a href="' . esc_url( $manager->log_url( $scan_id, (string) $target['file'] ) ) . '">Лог нагрузки →</a>';
			}
			if ( (int) $target['runs_failed'] > 0 ) {
				echo '<br><span class="plp-bad">неудачных прогонов: ' . esc_html( (int) $target['runs_failed'] ) . '</span>';
			}
			if ( (int) $target['redirects'] > 0 ) {
				echo '<br><span class="plp-muted">редиректов: ' . esc_html( (int) $target['redirects'] ) . '</span>';
			}
			echo '</td>';
			echo '<td>' . esc_html( $this->fmt_ms( $target['values']['time'] ) ) . '</td>';
			echo '<td><div class="plp-delta-bar"><span style="width:' . esc_attr( $width ) . '%"></span></div>' . esc_html( $this->fmt_delta( $target['delta']['time'], ' мс' ) ) . '</td>';
			echo '<td>' . esc_html( $this->fmt_mb( $target['values']['memory'] ) ) . '</td>';
			echo '<td>' . esc_html( number_format_i18n( $target['values']['queries'] ) ) . '</td>';
			echo '<td>' . esc_html( $this->fmt_delta( $target['delta']['queries'], '' ) ) . '</td>';
			echo '<td>' . esc_html( number_format_i18n( $target['share'], 1 ) ) . '%</td>';
			echo '<td><span class="plp-badge plp-' . esc_attr( $severity ) . '">' . esc_html( $this->severity_label( $severity ) ) . '</span></td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
		echo '<p class="plp-muted">Δ — разница с базовой линией по медиане. Отрицательные значения возможны из-за погрешности измерений.</p>';
		echo '</div>';

		/* Детали прогонов */
		$by = array();
		foreach ( $rows as $row ) {
			$by[ (string) $row['plugin_file'] ][] = $row;
		}

		echo '<div class="plp-card">';
		echo '<h2>Сырые прогоны</h2>';
		echo '<details><summary>Показать все измерения</summary>';
		echo '<table class="widefat striped plp-table plp-table-compact">';
		echo '<thead><tr><th>Цель</th><th>Страница</th><th>Прогон</th><th>Время</th><th>Память</th><th>SQL</th><th>HTTP</th><th>Ошибка</th></tr></thead><tbody>';

		$names = is_array( $config ) && isset( $config['names'] ) ? $config['names'] : array();
		$urls  = is_array( $config ) && isset( $config['urls'] ) ? $config['urls'] : array();

		foreach ( $by as $file => $list ) {
			$label = PLP_BASELINE === $file ? 'Базовая линия' : ( isset( $names[ $file ] ) ? $names[ $file ] : $file );
			foreach ( $list as $row ) {
				echo '<tr>';
				echo '<td>' . esc_html( $label ) . '</td>';
				echo '<td>#' . esc_html( (int) $row['url_index'] + 1 ) . '</td>';
				echo '<td>' . esc_html( (int) $row['run_index'] + 1 ) . '</td>';
				echo '<td>' . esc_html( $this->fmt_ms( (float) $row['load_time_ms'] ) ) . '</td>';
				echo '<td>' . esc_html( $this->fmt_mb( (int) $row['peak_memory_kb'] ) ) . '</td>';
				echo '<td>' . esc_html( number_format_i18n( (int) $row['query_count'] ) ) . '</td>';
				echo '<td>' . esc_html( (int) $row['http_status'] ) . '</td>';
				echo '<td>' . esc_html( (string) $row['error'] ) . '</td>';
				echo '</tr>';
			}
		}

		echo '</tbody></table>';
		echo '</details>';
		echo '</div>';

		/* Действия */
		echo '<p class="plp-actions">';
		echo '<a class="button" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=plp_export&scan=' . $scan_id ), 'plp_export_' . $scan_id ) ) . '">Скачать CSV</a> ';
		echo '<a class="button button-link-delete" href="#" id="plp-delete-scan" data-scan-id="' . esc_attr( $scan_id ) . '">Удалить скан</a>';
		echo '</p>';

		if ( 'probe' === $mode ) {
			echo '<p class="plp-muted">Режим «Быстрый зонд»: замерялась только загрузка плагинов и init, без вывода темы и виджетов. Страница: ' . esc_html( home_url( '/' ) ) . '</p>';
		} elseif ( ! empty( $urls ) ) {
			echo '<p class="plp-muted">Тестировались страницы: ' . esc_html( implode( ', ', array_map( 'esc_url_raw', $urls ) ) ) . '</p>';
		}
	}

	/* ------------------------------------------------------------- Лог нагрузки */

	private function render_log( $scan_id, $plugin_file ) {
		$manager = PLP_Scan_Manager::instance();
		$scan    = $manager->get_scan( $scan_id );

		if ( ! $scan ) {
			echo '<h1>Скан не найден</h1>';
			echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=plp-profiler' ) ) . '">← К списку сканов</a></p>';
			return;
		}

		echo '<h1>Лог нагрузки</h1>';
		echo '<p><a href="' . esc_url( $manager->results_url( $scan_id ) ) . '">← К отчёту скана #' . esc_html( $scan_id ) . '</a></p>';

		$config = json_decode( (string) $scan['config'], true );
		$names  = is_array( $config ) && isset( $config['names'] ) ? (array) $config['names'] : array();

		if ( ! isset( $names[ $plugin_file ] ) ) {
			echo '<div class="notice notice-error"><p>Плагин <code>' . esc_html( $plugin_file ) . '</code> не входит в скан #' . esc_html( $scan_id ) . '.</p></div>';
			return;
		}

		$name    = (string) $names[ $plugin_file ];
		$details = $manager->get_details( $scan_id, $plugin_file );

		echo '<h2>' . esc_html( $name ) . '</h2>';
		echo '<p class="plp-muted"><code>' . esc_html( $plugin_file ) . '</code> · скан #' . esc_html( $scan_id ) . ' от ' . esc_html( (string) $scan['created_at'] ) . '</p>';

		$summary = json_decode( (string) $scan['summary'], true );
		$target  = null;
		if ( is_array( $summary ) && ! empty( $summary['targets'] ) ) {
			foreach ( $summary['targets'] as $item ) {
				if ( (string) $item['file'] === $plugin_file ) {
					$target = $item;
					break;
				}
			}
		}

		if ( $target ) {
			echo '<div class="plp-card"><h2>Итог замера</h2><ul class="plp-metrics plp-metrics-inline">';
			echo '<li><span>' . esc_html( $this->fmt_ms( $target['values']['time'] ) ) . '</span> время страницы</li>';
			echo '<li><span>' . esc_html( $this->fmt_delta( $target['delta']['time'], ' мс' ) ) . '</span> к базовой линии</li>';
			echo '<li><span>' . esc_html( $this->fmt_mb( $target['values']['memory'] ) ) . '</span> пиковая память</li>';
			echo '<li><span>' . esc_html( number_format_i18n( $target['values']['queries'] ) ) . '</span> SQL-запросов</li>';
			echo '<li><span>' . esc_html( $this->fmt_delta( $target['delta']['queries'], '' ) ) . '</span> к базовой линии</li>';
			echo '<li><span>' . esc_html( number_format_i18n( $target['share'], 1 ) ) . '%</span> доля в нагрузке</li>';
			echo '</ul></div>';
		}

		if ( ! is_array( $details ) ) {
			echo '<div class="notice notice-warning"><p><strong>Для этого плагина лог не записан.</strong></p>';
			echo '<p>Лог снимается только в первом прогоне плагина и только в сканах версии 0.3.0 и новее. Если скан старый или у плагина не было ни одного успешного прогона, запустите новый скан.</p></div>';
			return;
		}

		$queries = isset( $details['queries'] ) && is_array( $details['queries'] ) ? $details['queries'] : array();
		$origins = isset( $queries['origins'] ) && is_array( $queries['origins'] ) ? $queries['origins'] : array();
		$slow    = isset( $queries['slow'] ) && is_array( $queries['slow'] ) ? $queries['slow'] : array();
		$files   = isset( $details['files'] ) && is_array( $details['files'] ) ? $details['files'] : array();
		$hooks   = isset( $details['hooks'] ) && is_array( $details['hooks'] ) ? $details['hooks'] : array();

		$txt_url  = wp_nonce_url(
			admin_url( 'admin-post.php?action=plp_log&format=txt&scan=' . $scan_id . '&plugin=' . rawurlencode( $plugin_file ) ),
			'plp_log_' . $scan_id
		);
		$json_url = wp_nonce_url(
			admin_url( 'admin-post.php?action=plp_log&format=json&scan=' . $scan_id . '&plugin=' . rawurlencode( $plugin_file ) ),
			'plp_log_' . $scan_id
		);

		echo '<p class="plp-actions">';
		echo '<a class="button button-primary" href="' . esc_url( $txt_url ) . '">Скачать для разработчика (TXT)</a> ';
		echo '<a class="button" href="' . esc_url( $json_url ) . '">JSON</a>';
		echo '</p>';
		echo '<p class="plp-muted">Передайте отчёт разработчику плагина: в нём видно, из каких файлов и строк плагин делает SQL-запросы, какие из них самые медленные, какие файлы подключает и какие хуки вешает.</p>';

		if ( ! empty( $origins ) ) {
			echo '<div class="plp-card"><h2>SQL по местам вызова</h2>';
			echo '<p class="plp-muted">Запросов всего: ' . esc_html( number_format_i18n( isset( $queries['total'] ) ? (int) $queries['total'] : 0 ) ) . ', время сопоставлено: ' . esc_html( number_format_i18n( isset( $queries['timed'] ) ? (int) $queries['timed'] : 0 ) ) . '.</p>';
			echo '<table class="widefat striped plp-table"><thead><tr><th>Откуда</th><th>Запросов</th><th>Время</th><th>Пример запроса</th></tr></thead><tbody>';
			foreach ( $origins as $row ) {
				echo '<tr>';
				echo '<td><code>' . esc_html( (string) $row['origin'] ) . '</code></td>';
				echo '<td>' . esc_html( number_format_i18n( (int) $row['count'] ) ) . '</td>';
				echo '<td>' . esc_html( $this->fmt_ms( (float) $row['ms'] ) ) . '</td>';
				echo '<td><code class="plp-sql">' . esc_html( (string) $row['sample'] ) . '</code></td>';
				echo '</tr>';
			}
			echo '</tbody></table></div>';
		}

		if ( ! empty( $slow ) ) {
			echo '<div class="plp-card"><h2>Самые медленные запросы</h2>';
			echo '<table class="widefat striped plp-table"><thead><tr><th>Время</th><th>Откуда</th><th>Запрос</th></tr></thead><tbody>';
			foreach ( $slow as $row ) {
				echo '<tr>';
				echo '<td>' . esc_html( number_format_i18n( (float) $row['ms'], 2 ) . ' мс' ) . '</td>';
				echo '<td><code>' . esc_html( (string) $row['origin'] ) . '</code></td>';
				echo '<td><code class="plp-sql">' . esc_html( (string) $row['sql'] ) . '</code></td>';
				echo '</tr>';
			}
			echo '</tbody></table></div>';
		}

		if ( ! empty( $files ) ) {
			$largest = isset( $files['largest'] ) && is_array( $files['largest'] ) ? $files['largest'] : array();
			echo '<div class="plp-card"><h2>Файлы плагина</h2>';
			echo '<p class="plp-muted">Подключено файлов: ' . esc_html( number_format_i18n( isset( $files['count'] ) ? (int) $files['count'] : 0 ) ) . ', суммарный объём: ' . esc_html( $this->fmt_bytes( isset( $files['bytes'] ) ? (int) $files['bytes'] : 0 ) ) . '.</p>';
			if ( ! empty( $largest ) ) {
				echo '<table class="widefat striped plp-table"><thead><tr><th>Файл</th><th>Размер</th></tr></thead><tbody>';
				foreach ( $largest as $row ) {
					echo '<tr><td><code>' . esc_html( (string) $row['file'] ) . '</code></td>';
					echo '<td>' . esc_html( $this->fmt_bytes( (int) $row['bytes'] ) ) . '</td></tr>';
				}
				echo '</tbody></table>';
			}
			echo '</div>';
		}

		if ( ! empty( $hooks ) ) {
			$top = isset( $hooks['top'] ) && is_array( $hooks['top'] ) ? $hooks['top'] : array();
			echo '<div class="plp-card"><h2>Хуки</h2>';
			echo '<p class="plp-muted">Колбэков зарегистрировано: ' . esc_html( number_format_i18n( isset( $hooks['total'] ) ? (int) $hooks['total'] : 0 ) ) . '.</p>';
			if ( ! empty( $top ) ) {
				echo '<table class="widefat striped plp-table"><thead><tr><th>Хук</th><th>Колбэков</th></tr></thead><tbody>';
				foreach ( $top as $row ) {
					echo '<tr><td><code>' . esc_html( (string) $row['hook'] ) . '</code></td>';
					echo '<td>' . esc_html( number_format_i18n( (int) $row['count'] ) ) . '</td></tr>';
				}
				echo '</tbody></table>';
			}
			echo '</div>';
		}

		echo '<div class="plp-card">';
		echo '<h2>Текст отчёта</h2>';
		echo '<details><summary>Показать текст для разработчика</summary>';
		echo '<pre class="plp-log-preview">' . esc_html( $this->log_text( $scan, $plugin_file, $name, $target, $details ) ) . '</pre>';
		echo '</details>';
		echo '</div>';
	}

	private function log_text( $scan, $plugin_file, $name, $target, $details ) {
		$lines   = array();
		$lines[] = 'P3 Load Profiler — лог нагрузки плагина';
		$lines[] = '======================================';
		$lines[] = 'Сайт: ' . home_url( '/' );
		$lines[] = 'Скан: #' . (int) $scan['id'] . ' от ' . (string) $scan['created_at'];
		$lines[] = 'Плагин: ' . $name;
		$lines[] = 'Файл: ' . $plugin_file;

		if ( is_array( $target ) ) {
			$lines[] = 'Время страницы с плагином: ' . $this->fmt_ms( $target['values']['time'] ) . ' (Δ ' . $this->fmt_delta( $target['delta']['time'], ' мс' ) . ')';
			$lines[] = 'Пиковая память: ' . $this->fmt_mb( $target['values']['memory'] );
			$lines[] = 'SQL: ' . number_format_i18n( $target['values']['queries'] ) . ' запросов (Δ ' . $this->fmt_delta( $target['delta']['queries'], '' ) . '), ' . $this->fmt_ms( $target['values']['qtime'] );
			$lines[] = 'Доля в нагрузке: ' . number_format_i18n( $target['share'], 1 ) . '%';
		}

		$lines[] = 'Снято: ' . current_time( 'mysql' );
		$lines[] = '';
		$lines[] = 'Данные получены при изолированном прогоне: активны ядро, тема и только этот плагин.';
		$lines[] = 'Время SQL берётся из SAVEQUERIES; источник указан как файл:строка относительно wp-content.';
		$lines[] = '';

		$queries = isset( $details['queries'] ) && is_array( $details['queries'] ) ? $details['queries'] : array();
		$origins = isset( $queries['origins'] ) && is_array( $queries['origins'] ) ? $queries['origins'] : array();
		$slow    = isset( $queries['slow'] ) && is_array( $queries['slow'] ) ? $queries['slow'] : array();

		$lines[] = '[SQL-запросы]';
		$lines[] = 'Всего: ' . (int) ( isset( $queries['total'] ) ? $queries['total'] : 0 ) . ', с замером времени: ' . (int) ( isset( $queries['timed'] ) ? $queries['timed'] : 0 );

		if ( ! empty( $origins ) ) {
			$lines[] = '';
			$lines[] = 'По местам вызова:';
			$i = 1;
			foreach ( $origins as $row ) {
				$lines[] = sprintf(
					'%2d. %s — %d запросов, %s мс',
					$i++,
					(string) $row['origin'],
					(int) $row['count'],
					number_format_i18n( (float) $row['ms'], 2 )
				);
				if ( ! empty( $row['sample'] ) ) {
					$lines[] = '    Пример: ' . (string) $row['sample'];
				}
			}
		}

		if ( ! empty( $slow ) ) {
			$lines[] = '';
			$lines[] = 'Самые медленные запросы:';
			$i = 1;
			foreach ( $slow as $row ) {
				$lines[] = sprintf( '%2d. %s мс — %s', $i++, number_format_i18n( (float) $row['ms'], 2 ), (string) $row['origin'] );
				$lines[] = '    ' . (string) $row['sql'];
			}
		}

		$lines[] = '';

		$files = isset( $details['files'] ) && is_array( $details['files'] ) ? $details['files'] : array();
		$lines[] = '[Файлы плагина]';
		$lines[] = 'Подключено файлов: ' . (int) ( isset( $files['count'] ) ? $files['count'] : 0 ) . ', объём: ' . $this->fmt_bytes( isset( $files['bytes'] ) ? (int) $files['bytes'] : 0 );
		if ( ! empty( $files['largest'] ) && is_array( $files['largest'] ) ) {
			$lines[] = '';
			$lines[] = 'Самые крупные:';
			foreach ( $files['largest'] as $row ) {
				$lines[] = '  ' . (string) $row['file'] . ' — ' . $this->fmt_bytes( (int) $row['bytes'] );
			}
		}

		$lines[] = '';

		$hooks = isset( $details['hooks'] ) && is_array( $details['hooks'] ) ? $details['hooks'] : array();
		$lines[] = '[Хуки]';
		$lines[] = 'Колбэков зарегистрировано: ' . (int) ( isset( $hooks['total'] ) ? $hooks['total'] : 0 );
		if ( ! empty( $hooks['top'] ) && is_array( $hooks['top'] ) ) {
			$lines[] = '';
			foreach ( $hooks['top'] as $row ) {
				$lines[] = '  ' . (string) $row['hook'] . ' — ' . (int) $row['count'];
			}
		}

		$lines[] = '';

		return implode( "\n", $lines );
	}

	/* ------------------------------------------------------------- Экспорт */

	public function export_csv() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Недостаточно прав.' );
		}

		$scan_id = isset( $_GET['scan'] ) ? absint( $_GET['scan'] ) : 0;
		check_admin_referer( 'plp_export_' . $scan_id );

		$scan = PLP_Scan_Manager::instance()->get_scan( $scan_id );
		if ( ! $scan ) {
			wp_die( 'Скан не найден.' );
		}

		$summary = json_decode( (string) $scan['summary'], true );
		if ( ! is_array( $summary ) ) {
			wp_die( 'У скана нет готового отчёта.' );
		}

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=plp-scan-' . $scan_id . '.csv' );

		$out = fopen( 'php://output', 'w' );
		fwrite( $out, "\xEF\xBB\xBF" );

		fputcsv( $out, array( 'Плагин', 'Файл', 'Время, мс', 'Δ времени, мс', 'Память, КБ', 'SQL-запросов', 'Δ SQL', 'Время SQL, мс', 'Доля, %', 'Оценка', 'Успешных прогонов', 'Неудачных' ), ';' );

		fputcsv( $out, array( 'Базовая линия', '', $summary['baseline']['time'], 0, $summary['baseline']['memory'], $summary['baseline']['queries'], 0, $summary['baseline']['qtime'], '', '', $summary['baseline_runs']['ok'], $summary['baseline_runs']['failed'] ), ';' );

		foreach ( $summary['targets'] as $target ) {
			fputcsv(
				$out,
				array(
					$this->csv_cell( $target['name'] ),
					$this->csv_cell( $target['file'] ),
					$target['values']['time'],
					$target['delta']['time'],
					$target['values']['memory'],
					$target['values']['queries'],
					$target['delta']['queries'],
					$target['values']['qtime'],
					$target['share'],
					$this->severity_label( $target['severity'] ),
					$target['runs_ok'],
					$target['runs_failed'],
				),
				';'
			);
		}

		fclose( $out );
		exit;
	}

	private function csv_cell( $value ) {
		$value = (string) $value;
		if ( '' !== $value && false !== strpos( '=+-@', $value[0] ) ) {
			$value = "'" . $value;
		}
		return $value;
	}

	public function export_log() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Недостаточно прав.' );
		}

		$scan_id = isset( $_GET['scan'] ) ? absint( $_GET['scan'] ) : 0;
		check_admin_referer( 'plp_log_' . $scan_id );

		$plugin_file = isset( $_GET['plugin'] ) ? sanitize_text_field( wp_unslash( $_GET['plugin'] ) ) : '';
		$format      = isset( $_GET['format'] ) && 'json' === $_GET['format'] ? 'json' : 'txt';

		$manager = PLP_Scan_Manager::instance();
		$scan    = $manager->get_scan( $scan_id );
		if ( ! $scan ) {
			wp_die( 'Скан не найден.' );
		}

		$config = json_decode( (string) $scan['config'], true );
		$names  = is_array( $config ) && isset( $config['names'] ) ? (array) $config['names'] : array();
		if ( ! isset( $names[ $plugin_file ] ) ) {
			wp_die( 'Плагин не входит в этот скан.' );
		}

		$details = $manager->get_details( $scan_id, $plugin_file );
		if ( ! is_array( $details ) ) {
			wp_die( 'Для этого плагина лог не записан. Запустите новый скан.' );
		}

		$summary = json_decode( (string) $scan['summary'], true );
		$target  = null;
		if ( is_array( $summary ) && ! empty( $summary['targets'] ) ) {
			foreach ( $summary['targets'] as $item ) {
				if ( (string) $item['file'] === $plugin_file ) {
					$target = $item;
					break;
				}
			}
		}

		$name = (string) $names[ $plugin_file ];
		$slug = '.' === dirname( $plugin_file ) ? basename( $plugin_file, '.php' ) : dirname( $plugin_file );
		$slug = sanitize_file_name( str_replace( '/', '-', $slug ) );

		nocache_headers();

		if ( 'json' === $format ) {
			$meta = array(
				'site'        => home_url( '/' ),
				'scan_id'     => (int) $scan_id,
				'created_at'  => (string) $scan['created_at'],
				'plugin'      => $plugin_file,
				'plugin_name' => $name,
			);
			if ( is_array( $target ) ) {
				$meta['metrics'] = array(
					'time_ms'         => $target['values']['time'],
					'delta_time_ms'   => $target['delta']['time'],
					'memory_kb'       => $target['values']['memory'],
					'queries'         => $target['values']['queries'],
					'delta_queries'   => $target['delta']['queries'],
					'query_time_ms'   => $target['values']['qtime'],
					'share_percent'   => $target['share'],
				);
			}

			header( 'Content-Type: application/json; charset=utf-8' );
			header( 'Content-Disposition: attachment; filename=plp-log-' . (int) $scan_id . '-' . $slug . '.json' );
			echo wp_json_encode(
				array(
					'meta'    => $meta,
					'details' => $details,
				),
				JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
			);
			exit;
		}

		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=plp-log-' . (int) $scan_id . '-' . $slug . '.txt' );
		echo $this->log_text( $scan, $plugin_file, $name, $target, $details ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	/* ------------------------------------------------------------- Формат */

	private function fmt_ms( $value ) {
		return number_format_i18n( (float) $value, 1 ) . ' мс';
	}

	private function fmt_mb( $kb ) {
		return number_format_i18n( (float) $kb / 1024, 1 ) . ' МБ';
	}

	private function fmt_delta( $value, $unit ) {
		$value    = (float) $value;
		$sign     = $value >= 0 ? '+' : '';
		$decimals = ' мс' === $unit ? 1 : 0;
		return $sign . number_format_i18n( $value, $decimals ) . $unit;
	}

	private function fmt_bytes( $bytes ) {
		$bytes = (float) $bytes;
		if ( $bytes >= 1048576 ) {
			return number_format_i18n( $bytes / 1048576, 1 ) . ' МБ';
		}
		if ( $bytes >= 1024 ) {
			return number_format_i18n( $bytes / 1024, 0 ) . ' КБ';
		}
		return number_format_i18n( $bytes, 0 ) . ' Б';
	}

	private function severity_label( $severity ) {
		$labels = array(
			'high'   => 'Сильная нагрузка',
			'medium' => 'Средняя',
			'ok'     => 'Норма',
			'failed' => 'Нет данных',
		);
		return isset( $labels[ $severity ] ) ? $labels[ $severity ] : $severity;
	}
}
