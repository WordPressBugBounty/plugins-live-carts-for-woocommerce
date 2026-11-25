<?php
/*
 * Plugin Name:       Live Carts for WooCommerce: Track Active, Abandoned, and Converted Carts & Wishlists in Real Time!
 * Version:           2.1.1
 * Description:       Track active, abandoned, and converted WooCommerce carts and wishlists (Wishlist, Save for Later) in real time from the WordPress admin
 * Author:            BerryPress
 * Plugin URI:        https://berrypress.com/
 * License:           GPLv3+
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 * Requires Plugins:  woocommerce
 * Text Domain:       live-carts-for-woocommerce
 * Domain Path:       /languages
 * GitHub Plugin URI: BerryPress/live-carts-for-woocommerce
 * Requires at least: 6.5.0
 * Tested up to:      7.1
 * Requires PHP:      7.0
 * WC requires at least: 7.0
 * WC tested up to:   11.2
 */

namespace BerryPress\LiveCarts;

defined( 'ABSPATH' ) || exit;

class LiveCarts {
	const VERSION = '2.1.1', CART_ABANDON_TIME = 7200, CART_ARCHIVE_DAYS = 30, MAX_CARTS_WITH_REVISIONS = 100, ADMIN_CAPABILITY = 'manage_woocommerce';

	private $currentCart, $currentCartId;
	private static $instance;

	public static function instance() {
		if (!isset(self::$instance)) {
			self::$instance = new self();
		}
		return self::$instance;
	}
	public static $plugin_url;

	public function __construct() {

		self::$plugin_url = plugin_dir_url( __FILE__ );
		// Declare HPOS compatibility
		add_action('before_woocommerce_init', function(){
			if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
				\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
			}
		});

		add_action('current_screen', [$this, 'onCurrentScreen']);
		add_filter('set_screen_option_berrypress_page_live_carts_for_woocommerce_per_page', [$this, 'filterPerPageScreenOption'], 10, 3);
		add_action('admin_menu', [$this, 'onAdminMenu']);
		
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- the hooked function will check nonce before taking action
		if (isset($_POST['phplugins_carts_settings_save'])) {
			add_action('admin_init', [$this, 'saveSettings']);
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- the hooked function will check nonce before taking action
		if ( isset( $_POST['phplugins_carts_trash_cart'] ) ) {
			add_action( 'admin_init', [ $this, 'trashCartFromDetailPage' ] );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- the hooked function will check nonce before taking action
		if ( isset( $_POST['phplugins_carts_restore_cart'] ) ) {
			add_action( 'admin_init', [ $this, 'restoreCartFromDetailPage' ] );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- the hooked function will check nonce before taking action
		if ( isset( $_POST['phplugins_carts_delete_cart_permanently'] ) ) {
			add_action( 'admin_init', [ $this, 'permanentlyDeleteCartFromDetailPage' ] );
		}
		
		if ( get_option('phplugins_carts_docsbot_on') ) {
			add_filter('docsbot_widget_config', [$this, 'docsbotWidgetConfig'], 10, 4);
		}
		
		add_action('phplugins_livecarts_hourly', [$this, 'hourlyScheduledTasks']);
		add_action('wp_ajax_phplugins_carts_rerun_migration', [$this, 'rerunMigration']);
		add_action('wp_ajax_phplugins_carts_download_debug_log', [$this, 'downloadDebugLog']);

		add_action('admin_enqueue_scripts', [$this, 'adminScripts']);
		
		add_action('rest_api_init', function() {
			require_once(__DIR__.'/includes/stats-controller.php');
			StatsController::register();

			if (get_option( 'phplugins_carts_rest_api' )) {
				require_once(__DIR__.'/includes/api/class-rest-controller-base.php');
				require_once(__DIR__.'/includes/api/class-carts-controller.php');
				require_once(__DIR__.'/includes/api/class-shopper-lists-controller.php');
				(new Api\CartsController())->register_routes();
				(new Api\ShopperListsController())->register_routes();
			}
		});

		if (get_option( 'phplugins_carts_webhooks_on' )) {
			require_once(__DIR__.'/includes/api/class-webhooks.php');
			Api\Webhooks::instance();
		}

		if (is_admin()) {
			// Load BerryPress admin framework only once across all plugins to prevent class redeclaration errors.
			if (!class_exists('BerryPressLiveCarts\\Admin\\Page')) {
				require_once(__DIR__.'/includes/berrypress-admin-framework/Page.php');
				\BerryPressLiveCarts\Admin\Page::maybeRegisterBerryPressAdminMenu();
			}
			require_once(__DIR__.'/includes/analytics.php');
			new Analytics();
			
			if (!wp_doing_ajax()) {
				// Deferred to admin_init so other plugins (e.g. Pro) have already
				// registered their 'phplugins_live_carts_review_request_config' filters.
				add_action('admin_init', function() {
					require_once( __DIR__ . '/includes/review-request.php' );
					new ReviewRequest([
						'product_name' => 'Live Carts for WooCommerce',
						'product_name_short' => 'Live Carts',
						'plugin_id' => 'phplugins_live_carts',
						'review_url' => 'https://wordpress.org/support/plugin/live-carts-for-woocommerce/reviews/',
						'support_url' => 'https://help.berrypress.com/',
						'screen_id' => 'berrypress_page_live-carts-for-woocommerce',
						'screen_admin_url' => 'admin.php?page=live-carts-for-woocommerce',
						'screen_capability' => 'manage_woocommerce'
					]);
				});
			}
		}
		
		$lastSeenVersion = get_option('phplugins_carts_version', '1.0.5');
		if ($lastSeenVersion !== self::VERSION) {
			add_action('init', [$this, 'handleVersionUpgrade'], 1);
		}

		// Add Settings link on Plugins screen (single site and network)
		add_filter('plugin_action_links_'.plugin_basename(__FILE__), [$this, 'addPluginActionLinks']);

		// Add Changelog/Support/Upgrade links to the plugin row meta on the Plugins screen
		add_filter('plugin_row_meta', [$this, 'addPluginRowMeta'], 10, 2);

		// Improve compatibility with woocommerce-paypal-payments plugin
		add_action('wc_ajax_ppc-simulate-cart', [$this, 'disableCartProcessing'], 1);
		
		// Critical cart processing hooks - these are unhooked in disableCartProcessing() and have exception/error handling
		add_action('woocommerce_cart_loaded_from_session', [$this, 'onCartLoaded']);
		add_action('woocommerce_cart_reset', [$this, 'requestUpdateCartContents']);
		add_action('woocommerce_after_calculate_totals', [$this, 'requestUpdateCartContents']);
		add_action('woocommerce_order_status_changed', [$this, 'onOrderStatusChanged'], 10, 4);
		
		add_shortcode('berrypress_live_cart_id', [$this, 'cartIdShortcode']);
		
		add_action('woocommerce_checkout_order_created', [$this, 'handleNewOrder']);
		add_action('woocommerce_store_api_checkout_order_processed', [$this, 'handleNewOrder']);
		
		add_action( 'wp_loaded', array( $this, 'maybeRecoverCart' ) );

		require_once(__DIR__.'/includes/shopper-lists.php');
		ShopperLists::instance();
		
		require_once(__DIR__.'/includes/recovery-emails.php');
		RecoveryEmails::instance();

		if ( !get_option( 'phplugins_carts_no_track_cart_emails' ) ) {
			require_once(__DIR__.'/includes/visitor-email.php');
			CartEmail::instance();
		}
	}

	public function addPluginActionLinks($links) {
		$settingsUrl = admin_url('admin.php?page=live-carts-for-woocommerce&settings=1');
		$links[] = '<a href="'.esc_url($settingsUrl).'">'.esc_html__('Settings', 'live-carts-for-woocommerce').'</a>';
		return $links;
	}

	public function addPluginRowMeta($links, $file) {
		if (plugin_basename(__FILE__) !== $file) {
			return $links;
		}

		$links[] = '<a href="https://wordpress.org/plugins/live-carts-for-woocommerce/#changelog" target="_blank">'.esc_html__('Changelog', 'live-carts-for-woocommerce').'</a>';
		$links[] = '<a href="https://wordpress.org/support/plugin/live-carts-for-woocommerce/" target="_blank">'.esc_html__('Support', 'live-carts-for-woocommerce').'</a>';
		$links[] = '<a href="https://berrypress.com/live-carts/" target="_blank">'.esc_html__('Upgrade to Pro', 'live-carts-for-woocommerce').'</a>';

		return $links;
	}
 
	
	public function handleVersionUpgrade() {
		global $wpdb;
		
		
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- no placeholders needed
		$allTables = $wpdb->get_col('SHOW TABLES');
		if (in_array($wpdb->prefix.'phplugins_carts', $allTables)) {
			require_once(__DIR__.'/includes/setup.php');
			Setup::upgradeDatabaseTables( get_option('phplugins_carts_version', '1.0.5') );
			update_option('phplugins_carts_version', self::VERSION);
			delete_transient( 'phplugins_lc_lists_backfill_complete' );
		}
	}
	
	public function addCartIdDisplayClass($classes) {
		$classes[] = 'phplugins-live-carts-show-id';
		return $classes;
	}
	
	public function outputCartId() {
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- html output (escaped in function)
		echo( $this->getCartIdFrontendDisplay() );
	}
	
	public function getCartIdFrontendDisplay($before='') {
		return $before.apply_filters('phplugins_live_carts_frontend_cart_id_html',
			'<div class="phplugins-live-carts-cart-id">
				<span>'.esc_html__('Cart ID:', 'live-carts-for-woocommerce').'</span>
				<span>'.esc_html(self::formatCartId($this->currentCartId)).'</span>
			</div>', $this->currentCartId);
	}
	
	public function cartIdShortcode() {
		return $this->currentCartId ? '<span class="phplugins-live-carts-cart-id-inline">'.esc_html(self::formatCartId($this->currentCartId)).'</span>' : '';
	}
	
	public static function formatCartId($cartId) {
		// Formatted cart IDs MUST be HTML-safe!
		return str_pad( strtoupper(dechex($cartId)), 8, '0', STR_PAD_LEFT );
	}
	
	public static function unformatCartId($cartId) {
		return hexdec($cartId);
	}
	
	public function disableCartProcessing() {
		remove_action('woocommerce_cart_loaded_from_session', [$this, 'onCartLoaded']);
		remove_action('woocommerce_after_calculate_totals', [$this, 'requestUpdateCartContents']);
		remove_action('woocommerce_cart_reset', [$this, 'requestUpdateCartContents']);
		remove_action('woocommerce_order_status_changed', [$this, 'onOrderStatusChanged']);
		
		add_filter('phplugins_live_carts_should_create_cart', '__return_false', 999);
	}
	
	public function saveSettings() {
		global $wpdb;
		
		if (current_user_can(self::ADMIN_CAPABILITY)) {
			check_admin_referer('phplugins-carts-settings-save', 'phplugins_carts_settings_save');

			if (!empty($_GET['healthstatus'])) {
				if (empty($_POST['phplugins_carts_debug'])) {
					delete_option('phplugins_carts_debug');
					delete_option('phplugins_carts_debug_level');
				} else {
					if (!get_option('phplugins_carts_debug')) {
						update_option('phplugins_carts_debug', sanitize_key(wp_generate_password(20, false)), false);
					}
					// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated against allowlist
					$level = isset($_POST['phplugins_carts_debug_level']) && $_POST['phplugins_carts_debug_level'] === 'all' ? 'all' : 'errors';
					update_option('phplugins_carts_debug_level', $level, false);
				}
				
				if (empty($_POST['phplugins_carts_debug']) || !empty($_POST['phplugins_carts_debug_clear'])) {
					$log = $this->getDebugLogPath();
					if ($log) {
						@wp_delete_file($log);
					}
				}
				
				do_action( 'phplugins_live_carts_save_settings_healthstatus' );
			} else if (!empty($_GET['settings'])) {

				if ( ! empty( $_POST['phplugins_carts_collect_ip'] ) ) {
					delete_option( 'phplugins_carts_no_ip' );
				} else {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$wpdb->query( 'UPDATE ' . $wpdb->prefix . 'phplugins_carts SET ip_address=""' );
					update_option( 'phplugins_carts_no_ip', 1 );
				}

				if ( ! empty( $_POST['phplugins_carts_collect_url'] ) ) {
					delete_option( 'phplugins_carts_no_url' );
				} else {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$wpdb->query( 'UPDATE ' . $wpdb->prefix . 'phplugins_carts SET last_url=""' );
					update_option( 'phplugins_carts_no_url', 1 );
				}
				
				if ( ! empty( $_POST['phplugins_carts_track_cart_emails'] ) ) {
					delete_option( 'phplugins_carts_no_track_cart_emails' );
				} else {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$wpdb->query( 'UPDATE ' . $wpdb->prefix . 'phplugins_carts SET email=NULL' );
					update_option( 'phplugins_carts_no_track_cart_emails', 1, false );
				}
				

				if (empty($_POST['phplugins_carts_logged_in_only'])) {
					delete_option('phplugins_carts_logged_in_only');
				} else {
					update_option('phplugins_carts_logged_in_only', 1);
				}

				if (empty($_POST['phplugins_carts_show_id'])) {
					delete_option('phplugins_carts_show_id');
				} else {
					update_option('phplugins_carts_show_id', 1);
				}
				
				$senderName = sanitize_text_field( wp_unslash( $_POST['phplugins_carts_recovery_sender_name'] ?? '' ) );
				if ($senderName) {
					update_option( 'phplugins_carts_recovery_sender_name', $senderName, false );
				} else {
					delete_option( 'phplugins_carts_recovery_sender_name' );
				}
				
				$senderEmail = sanitize_email( wp_unslash( $_POST['phplugins_carts_recovery_sender_email'] ?? '' ) );
				if ($senderEmail) {
					update_option( 'phplugins_carts_recovery_sender_email', $senderEmail, false );
				} else {
					delete_option( 'phplugins_carts_recovery_sender_email' );
				}
	
				if ( empty( $_POST['phplugins_carts_show_recent_orders'] ) ) {
					update_option( 'phplugins_carts_hide_recent_orders', 1 );
				} else {
					delete_option( 'phplugins_carts_hide_recent_orders' );
				}

				// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated against allowlist
				$emailPrecedence = isset( $_POST['phplugins_carts_email_precedence'] ) && 'entered' === $_POST['phplugins_carts_email_precedence'] ? 'entered' : 'user';
				update_option( 'phplugins_carts_email_precedence', $emailPrecedence, false );

				// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated against allowlist
				$conversionStatusMode = isset( $_POST['phplugins_carts_conversion_status_mode'] ) && 'custom' === $_POST['phplugins_carts_conversion_status_mode'] ? 'custom' : 'automatic';

				// Persist custom selections only in custom mode so switching back keeps the previous list.
				if ( 'custom' === $conversionStatusMode ) {
					$selectedConversionStatuses = isset( $_POST['phplugins_carts_conversion_order_statuses'] )
						? array_map( 'sanitize_key', (array) wp_unslash( $_POST['phplugins_carts_conversion_order_statuses'] ) )
						: [];
					$selectedConversionStatuses = array_values( array_intersect( $selectedConversionStatuses, $this->getAllowedConversionOrderStatusSlugs() ) );
					if ( empty( $selectedConversionStatuses ) ) {
						delete_option( 'phplugins_carts_conversion_status_mode' );
						delete_option( 'phplugins_carts_conversion_order_statuses' );
					} else {
						update_option( 'phplugins_carts_conversion_status_mode', 'custom', false );
						update_option( 'phplugins_carts_conversion_order_statuses', $selectedConversionStatuses, false );
					}
				} else {
					delete_option( 'phplugins_carts_conversion_status_mode' );
				}
				
				if (empty($_POST['phplugins_carts_docsbot_on'])) {
					delete_option('phplugins_carts_docsbot_on');
				} else {
					update_option('phplugins_carts_docsbot_on', 1, true);
				}
				
				if (empty($_POST['phplugins_carts_rest_api'])) {
					delete_option('phplugins_carts_rest_api');
				} else {
					update_option('phplugins_carts_rest_api', 1, true);
				}
				
				if (empty($_POST['phplugins_carts_webhooks_on'])) {
					delete_option('phplugins_carts_webhooks_on');
					
					
				} else {
					update_option('phplugins_carts_webhooks_on', 1, true);
					
					if (!class_exists('BerryPress\LiveCarts\Api\Webhooks')) {
						require_once(__DIR__.'/includes/api/class-webhooks.php');
						Api\Webhooks::instance();
					}
				}
            	
				do_action( 'phplugins_live_carts_save_settings_settings' );
			}
			
		}
	}
	
	public function adminScripts() {
		$currentScreen = get_current_screen();
		$min = defined('SCRIPT_DEBUG') && SCRIPT_DEBUG ? '' : '.min';
		if ($currentScreen && ($currentScreen->id == 'berrypress_page_live-carts-for-woocommerce' || $currentScreen->id == 'woocommerce_page_wc-admin')) {
			// Enqueue BerryPress Admin Framework styles and script
			wp_enqueue_style('berrypress-lc-admin-page', self::$plugin_url.'includes/berrypress-admin-framework/assets/css/global-admin-page'.$min.'.css', ['berrypress-lc-admin-global'], self::VERSION);
			wp_enqueue_script('berrypress-lc-admin-page', self::$plugin_url.'includes/berrypress-admin-framework/assets/js/global-admin-page'.$min.'.js', ['jquery'], self::VERSION, true);
			wp_localize_script('berrypress-lc-admin-page', 'berrypressLcAdminPage', [
				'dismissTopBarLabel' => __( 'Dismiss this notice', 'live-carts-for-woocommerce' ),
			]);
			wp_enqueue_script('phplugins-live-carts-admin', self::$plugin_url.'assets/js/admin'.$min.'.js', ['wp-hooks', 'wp-i18n', 'react'], self::VERSION, true);
			wp_set_script_translations('phplugins-live-carts-admin', 'live-carts-for-woocommerce');
			wp_enqueue_style('phplugins-live-carts-admin', self::$plugin_url.'assets/css/admin'.$min.'.css', null, self::VERSION);
			wp_enqueue_script('berrypress-bp-tooltip', self::$plugin_url.'assets/js/bp-tooltip'.$min.'.js', [], self::VERSION, true);
		}

		wp_enqueue_style('berrypress-lc-admin-global', self::$plugin_url.'includes/berrypress-admin-framework/assets/css/global-admin'.$min.'.css', [], self::VERSION);
	}
	
	public function frontendScripts() {
		$min = defined('SCRIPT_DEBUG') && SCRIPT_DEBUG ? '' : '.min';
		wp_enqueue_style('phplugins-live-carts', self::$plugin_url.'assets/css/frontend'.$min.'.css', null, self::VERSION);
	}
	
	public function debugLog($message, $file, $line, $level = 'error') {
		try {
			$logPath = $this->getDebugLogPath();
			if (!$logPath || !$message) {
				return;
			}
			
			$debugLevel = get_option('phplugins_carts_debug_level', 'errors');
			if ($debugLevel === 'errors' && $level !== 'error') {
				return;
			}
			
			// Log rotation: truncate to ~500 KB when file exceeds 1 MB
			if (file_exists($logPath) && filesize($logPath) > 1048576) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
				$contents = file_get_contents($logPath);
				$truncated = substr($contents, -512000);
				$newlinePos = strpos($truncated, "\n");
				if ($newlinePos !== false) {
					$truncated = substr($truncated, $newlinePos + 1);
				}
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
				file_put_contents($logPath, "[log truncated]\n" . $truncated);
			}
			
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- cannot readily be used here due to lack of append support
			file_put_contents($logPath, wp_date('c')."\t".self::VERSION."\t[".$level."]\t".add_query_arg([])."\t".basename($file).':'.$line."\t".$message."\n", FILE_APPEND);
		} catch (\Exception $ex) {
		} catch (\Error $err) { }
	}
	
	public function getDebugLogPath() {
		$log = get_option('phplugins_carts_debug');
		if ($log) {
			return __DIR__.'/debug-'.str_replace(['/', '\\'], '', $log).'.txt';
		}
		return null;
	}
	
	public function getDebugLogContents($maxLines = 200) {
		$path = $this->getDebugLogPath();
		if (!$path || !file_exists($path)) {
			return '';
		}
		$lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
		if (!$lines) {
			return '';
		}
		if (count($lines) > $maxLines) {
			$lines = array_slice($lines, -$maxLines);
		}
		return implode("\n", $lines);
	}
	
	public function downloadDebugLog() {
		if (!current_user_can(self::ADMIN_CAPABILITY)) {
			wp_die();
		}
		check_admin_referer('phplugins_carts_download_debug_log');
		$path = $this->getDebugLogPath();
		if (!$path || !file_exists($path)) {
			wp_die(esc_html__('No debug log found.', 'live-carts-for-woocommerce'));
		}
		header('Content-Type: text/plain');
		header('Content-Disposition: attachment; filename="live-carts-debug.txt"');
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		readfile($path);
		exit;
	}

	public static function isMigrationNeeded() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$tableExists = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s", DB_NAME, $wpdb->prefix . 'phplugins_cart_contents_items'));
		return (bool) !$tableExists || !get_option('phplugins_carts_migration_1_2_0_complete');
	}
	
	public function rerunMigration() {
		global $wpdb;
		if (!current_user_can(self::ADMIN_CAPABILITY)) {
			wp_die();
		}
		check_admin_referer('phplugins_carts_rerun_migration');
		
		require_once(__DIR__.'/includes/setup.php');
		delete_option('phplugins_carts_migration_1_2_0_complete');
		
		$wpdb->show_errors = false;
		$wpdb->suppress_errors = true;
		
		try {
			Setup::upgradeDatabaseTables('1.1.9');
		} catch (\Exception $ex) {
			// $ex is the caught exception; collected below.
		} catch (\Error $err) {
			$ex = $err;
		}
		
		$errors = Setup::$dbErrors;
		if (isset($ex)) {
			$errors[] = $ex->getMessage();
		}
		if (!get_option('phplugins_carts_migration_1_2_0_complete')) {
			if (empty($errors)) {
				$errors[] = __('The migration did not complete due to an unknown error.', 'live-carts-for-woocommerce');
			}
			set_transient('phplugins_carts_migration_errors', $errors, 300);
			delete_transient('phplugins_carts_migration_success');
		} else {
			delete_transient('phplugins_carts_migration_errors');
			set_transient('phplugins_carts_migration_success', true, 300);
		}
		
		wp_safe_redirect(admin_url('admin.php?page=live-carts-for-woocommerce&status=1'));
		exit;
	}
	
	public function handleNewOrder($order) {
		try {
			if (!empty($this->currentCartId) && !$order->get_meta('phplugins_live_carts_cart_id')) {
				$order->update_meta_data('phplugins_live_carts_cart_id', $this->currentCartId);
				$order->save_meta_data();
			}
			$orderId = $order->get_id();
			$status = $order->get_status();
			$conversionStatusConfig = $this->getConversionOrderStatusConfiguration();
			$this->debugLog( 'handleNewOrder: order_id=' . $orderId . ', status=' . $status . ', is_converted_status=' . ( in_array( $status, $conversionStatusConfig['statuses'], true ) ? 'Y' : 'N' ) . ', conversion_statuses=' . implode( '|', $conversionStatusConfig['statuses'] ) . ', conversion_source=' . $conversionStatusConfig['source'] . ', cart_id=' . ( $this->currentCartId ?? 'null' ), __FILE__, __LINE__, 'info' );
			if ( in_array( $status, $conversionStatusConfig['statuses'], true ) ) {
				$this->onOrderStatusChanged($orderId, 'pending', $status, $order);
			}
		} catch (\Exception $ex) {
			$this->debugLog($ex->getMessage(), __FILE__, __LINE__);
		} catch (\Error $err) {
			$this->debugLog($err->getMessage(), __FILE__, __LINE__);
		}
	}
	
	public static function normalizeOrderStatusSlug( $status ) {
		return preg_replace( '/^wc-/', '', sanitize_key( (string) $status ) );
	}

	public function getAllowedConversionOrderStatusSlugs() {
		return array_values(
			array_unique(
				array_filter(
					array_map( [ self::class, 'normalizeOrderStatusSlug' ], array_keys( wc_get_order_statuses() ) )
				)
			)
		);
	}

	public function getConversionOrderStatusConfiguration() {
		$mode = get_option( 'phplugins_carts_conversion_status_mode', 'automatic' );
		if ( 'custom' === $mode ) {
			$statuses = get_option( 'phplugins_carts_conversion_order_statuses', [] );
			$statuses = is_array( $statuses ) ? array_map( [ self::class, 'normalizeOrderStatusSlug' ], $statuses ) : [];
			$statuses = array_values( array_unique( array_intersect( $statuses, $this->getAllowedConversionOrderStatusSlugs() ) ) );
			if ( ! empty( $statuses ) ) {
				return [
					'statuses' => $statuses,
					'source'   => 'custom',
				];
			}
		}

		return [
			'statuses' => $this->getAutomaticConversionOrderStatuses(),
			'source'   => 'automatic',
		];
	}

	public function getAutomaticConversionOrderStatuses() {
		$statuses = array_merge( wc_get_is_paid_statuses(), [ 'on-hold' ] );
		$statuses = array_map( [ self::class, 'normalizeOrderStatusSlug' ], $statuses );

		return array_values( array_unique( array_filter( $statuses ) ) );
	}
	
	public function onOrderStatusChanged($orderId, $from, $to, $order) {
		global $wpdb;
		try {
			$orderCartId = $order->get_meta('phplugins_live_carts_cart_id');
			$conversionStatusConfig = $this->getConversionOrderStatusConfiguration();
			$this->debugLog( 'onOrderStatusChanged: order_id=' . $orderId . ', from=' . $from . ', to=' . $to . ', is_converted_status=' . ( in_array( $to, $conversionStatusConfig['statuses'], true ) ? 'Y' : 'N' ) . ', conversion_statuses=' . implode( '|', $conversionStatusConfig['statuses'] ) . ', conversion_source=' . $conversionStatusConfig['source'] . ', cart_id=' . ( $this->currentCartId ?? 'null' ) . ', order_cart_id=' . $orderCartId, __FILE__, __LINE__, 'info' );
				
			if ( $orderCartId && ! in_array( $from, $conversionStatusConfig['statuses'], true ) && in_array( $to, $conversionStatusConfig['statuses'], true ) ) {

				if ($orderCartId == $this->currentCartId) {
				$this->cartSeen();
				remove_action('wp_footer', [$this, 'cartSeen']);
				$this->updateCartContents();
				remove_action('shutdown', [$this, 'updateCartContents']);
					WC()->session->set( 'phplugins_cart_converted', true );
					$this->resetCurrentCart();
				}

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->update(
					$wpdb->prefix.'phplugins_carts',
					[
						'status' => 'converted',
						'order_id' => $order->get_id()
					],
					[ 'cart_id' => $orderCartId ],
					[
						'status' => '%s',
						'order_id' => '%d'
					],
					[ 'cart_id' => '%d' ]
				);


				do_action('phplugins_live_carts_cart_converted', $orderCartId);

			}
		
		} catch (\Exception $ex) {
			$this->debugLog($ex->getMessage(), __FILE__, __LINE__);
		} catch (\Error $err) {
			$this->debugLog($err->getMessage(), __FILE__, __LINE__);
		}
	}
	public function filterPerPageScreenOption($f, $screenOption, $perPage) {
		return min(absint($perPage), 999);
	}

	public function getTimestampFormat() {
		return get_option('date_format').' '.get_option('time_format');
	}

	/**
	 * Formats a GMT/UTC MySQL datetime for display using the site timezone and WP date/time formats.
	 *
	 * @param string|null $gmtDatetime Datetime string stored in GMT (e.g. from current_time( 'mysql', true )).
	 * @return string
	 */
	public function formatGmtDatetime( $gmtDatetime ) {
		if ( empty( $gmtDatetime ) ) {
			return '';
		}

		$datetime = date_create( $gmtDatetime, new \DateTimeZone( 'UTC' ) );
		if ( false === $datetime ) {
			return '';
		}

		// wp_date() applies the site timezone and localized month/day names (Settings → General).
		return wp_date( $this->getTimestampFormat(), $datetime->getTimestamp() );
	}

	public function onCurrentScreen($screen) {
		if ($screen->id == 'berrypress_page_live-carts-for-woocommerce' && current_user_can(self::ADMIN_CAPABILITY)) {
			$this->scheduledCartStatusTransitionsAndCleanup();

			add_screen_option('per_page');
			require_once(__DIR__.'/includes/admin-page.php');
			
			if ( isset( $_GET['cart_id'] ) || isset( $_GET['recovery_emails'] ) || isset( $_GET['settings'] ) || isset( $_GET['addons'] ) || isset( $_GET['healthstatus'] ) ) {
				$table = null;
			} else {
				require_once( __DIR__ . '/includes/admin-list.php' );
				$table = new CartsListTable();
				$action = $table->current_action();
				if ( $action ) {
					check_admin_referer( 'bulk-' . ShopperLists::getAdminViewType() . 's' );
					global $wpdb;
					$ids = array_map( 'intval', $_REQUEST['post'] ?? [] );

					require_once __DIR__ . '/includes/admin-page.php';
					$redirectArgs = AdminPage::getListContextQueryArgsForView( ShopperLists::getAdminViewType() );

					switch ( $action ) {
						case 'trash':
							$redirectArgs['trashed'] = count( self::trashCarts( $ids ) );
							break;
						case 'untrash':
							$redirectArgs['untrashed'] = count( self::restoreCarts( $ids ) );
							break;
						case 'delete_permanently':
							$redirectArgs['deleted'] = count( self::deleteCarts( $ids ) );
							break;
						case 'empty_trash':
							$isListView = ShopperLists::getAdminViewType() === 'list';
							// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
							$allTrashedIds = array_map( 'intval', (array) $wpdb->get_col(
								$wpdb->prepare(
										'SELECT cart_id FROM ' . $wpdb->prefix . 'phplugins_carts WHERE trashed=1 AND type LIKE %s',
										$isListView ? $wpdb->esc_like( ShopperLists::TYPE_LIST_PREFIX ) . '%' : ShopperLists::TYPE_CART
									)
							) );
							$deletedCount = 0;
							foreach ( array_chunk( $allTrashedIds, 500 ) as $chunk ) {
								$deletedCount += count( self::deleteCarts( $chunk ) );
							}
							$redirectArgs['deleted'] = $deletedCount;
							break;
					}

					wp_safe_redirect( AdminPage::getUrl( $redirectArgs ) );
					exit;
				}
			}
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
			$adminPageClass = apply_filters('live_carts_for_woocommerce_admin_page_class', AdminPage::class);
			new $adminPageClass($table);

			add_filter('admin_body_class', [$this, 'addAdminBodyClass']);
		}
	}
	
	public function onAdminMenu() {
		add_submenu_page('berrypress', __('Live Carts for WooCommerce', 'live-carts-for-woocommerce'), __('Live Carts', 'live-carts-for-woocommerce'), self::ADMIN_CAPABILITY, 'live-carts-for-woocommerce', '__return_empty_string');
	}

	public function getCartStatuses() {
		return [
			'active'    => __( 'Active', 'live-carts-for-woocommerce' ),
			'converted' => __( 'Converted', 'live-carts-for-woocommerce' ),
			'abandoned' => __( 'Abandoned', 'live-carts-for-woocommerce' ),
			'trashed'   => __( 'Trash', 'live-carts-for-woocommerce' ),
		];
	}
	
	private static function setCartsTrashed($cartIds, $trashed=true) {
		global $wpdb;

		$cartIds = array_values( array_filter( array_map( 'intval', $cartIds ) ) );
		if ( empty( $cartIds ) ) {
			return;
		}
		
		$trashed = (int) (bool) $trashed;
		
		$successIds = [];
		
		foreach ( $cartIds as $cartId ) {
			if (
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->query( $wpdb->prepare('UPDATE ' . $wpdb->prefix . 'phplugins_carts SET trashed=%d WHERE cart_id=%d', $trashed, $cartId) ) === 1
			) {
				$successIds[] = $cartId;
				do_action( 'phplugins_live_carts_cart_'.($trashed ? 'trashed' : 'untrashed'), $cartId );
			}
		}
		
		return $successIds;
	}

	/**
	 * Moves one or more carts/lists to the Trash.
	 *
	 * The `status` column is left untouched; trashing is tracked separately via
	 * the `trashed` flag so the cart's status is preserved while it's in the Trash.
	 *
	 * @param int[] $cartIds Cart IDs to trash.
	 */
	public static function trashCarts( array $cartIds ) {
		return self::setCartsTrashed($cartIds);
	}

	/**
	 * Restores one or more carts/lists from the Trash.
	 *
	 * Simply clears the `trashed` flag; the `status` column was never changed
	 * while the cart was trashed, so it doesn't need to be restored.
	 *
	 * @param int[] $cartIds Cart IDs to restore.
	 */
	public static function restoreCarts( array $cartIds ) {
		return self::setCartsTrashed($cartIds, false);
	}

	/**
	 * Permanently deletes one or more carts/lists and their stored contents.
	 *
	 * Only rows flagged as `trashed` are removed; non-trashed IDs are ignored.
	 *
	 * @param int[] $cartIds Cart IDs to delete.
	 * @return int Number of carts permanently deleted.
	 */
	public static function deleteCarts( array $cartIds ) {
		global $wpdb;

		$cartIds = array_values( array_filter( array_map( 'intval', $cartIds ) ) );
		if ( empty( $cartIds ) ) {
			return [];
		}

		$idPlaceholders = implode( ',', array_fill( 0, count( $cartIds ), '%d' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$trashedIds = $wpdb->get_col(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- placeholder count matches $cartIds
				'SELECT cart_id FROM ' . $wpdb->prefix . 'phplugins_carts WHERE cart_id IN (' . $idPlaceholders . ') AND trashed=1',
				...$cartIds
			)
		);

		$trashedIds = array_values( array_filter( array_map( 'intval', $trashedIds ) ) );
		
		$successIds = [];
		
		foreach ($trashedIds as $cartId) {

			if (
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->query(
					$wpdb->prepare(
						'DELETE FROM ' . $wpdb->prefix . 'phplugins_cart_contents WHERE cart_id=%d',
						$cartId
					)
				)

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				&& $wpdb->query(
					$wpdb->prepare(
						'DELETE FROM ' . $wpdb->prefix . 'phplugins_carts WHERE cart_id=%d',
						$cartId
					)
				)
			
			) {
				$successIds[] = $cartId;
				do_action( 'phplugins_live_carts_cart_deleted', $cartId );
			}
		
		}

		return $successIds;
	}

	/**
	 * Handles move-to-trash POST from the cart detail page.
	 */
	public function trashCartFromDetailPage() {
		if ( ! current_user_can( self::ADMIN_CAPABILITY ) ) {
			return;
		}

		check_admin_referer( 'phplugins_carts_trash_cart' );

		$cartId = isset( $_POST['phplugins_carts_trash_cart_id'] )
			? (int) $_POST['phplugins_carts_trash_cart_id']
			: 0;
		if ( ! $cartId ) {
			return;
		}

		require_once __DIR__ . '/includes/admin-page.php';
		$args            = AdminPage::getListContextQueryArgsForView( ShopperLists::getAdminViewType() );
		$args['trashed'] = count( self::trashCarts( [ $cartId ] ) );
		wp_safe_redirect( AdminPage::getUrl( $args ) );
		exit;
	}

	/**
	 * Handles restore POST from the cart detail page.
	 */
	public function restoreCartFromDetailPage() {
		if ( ! current_user_can( self::ADMIN_CAPABILITY ) ) {
			return;
		}

		check_admin_referer( 'phplugins_carts_restore_cart' );

		$cartId = isset( $_POST['phplugins_carts_restore_cart_id'] )
			? (int) $_POST['phplugins_carts_restore_cart_id']
			: 0;
		if ( ! $cartId ) {
			return;
		}

		require_once __DIR__ . '/includes/admin-page.php';
		$args             = AdminPage::getListContextQueryArgsForView( ShopperLists::getAdminViewType() );
		$args['cart_id']  = $cartId;
		$args['untrashed'] = count( self::restoreCarts( [ $cartId ] ) );
		wp_safe_redirect( AdminPage::getUrl( $args ) );
		exit;
	}

	/**
	 * Handles permanent delete POST from the cart detail page.
	 */
	public function permanentlyDeleteCartFromDetailPage() {
		if ( ! current_user_can( self::ADMIN_CAPABILITY ) ) {
			return;
		}

		check_admin_referer( 'phplugins_carts_delete_cart_permanently' );

		$cartId = isset( $_POST['phplugins_carts_delete_cart_id'] )
			? (int) $_POST['phplugins_carts_delete_cart_id']
			: 0;
		if ( ! $cartId ) {
			return;
		}

		require_once __DIR__ . '/includes/admin-page.php';
		$args = AdminPage::getListContextQueryArgsForView( ShopperLists::getAdminViewType() );
		$args['deleted'] = count( self::deleteCarts( [ $cartId ] ) );
		wp_safe_redirect( AdminPage::getUrl( $args ) );
		exit;
	}

	public function addAdminBodyClass($classes) {
		$classes .= ' berrypress-page ';

		return $classes;
	}

	public function resetCurrentCart() {
		unset($this->currentCart, $this->currentCartId);
	}

	public function onCartLoaded($cart) {
		try {
			$this->currentCart = $cart;
			$this->currentCartId = WC()->session->get( 'phplugins_cart_id', null );
			$this->debugLog('onCartLoaded: session cart_id=' . ($this->currentCartId ?? 'null') . ', cart_empty=' . ($cart->is_empty() ? 'yes' : 'no') . ', items=' . count($cart->get_cart_contents()), __FILE__, __LINE__, 'info');
			if (empty($this->currentCartId) || !$this->validateCurrentCart()) {
				try {
					$this->createNewCart($cart);
					if (!empty($this->currentCartId)) {
						$this->updateCartContents();
					}
				} catch (\Exception $ex) {
					$this->debugLog($ex->getMessage(), __FILE__, __LINE__);
					return;
				}
			}
			
			$this->maybeAddCartHooks();
			
			do_action('phplugins_live_carts_cart_loaded', $cart);
		} catch (\Exception $ex) {
			$this->debugLog($ex->getMessage(), __FILE__, __LINE__);
		} catch (\Error $err) {
			$this->debugLog($err->getMessage(), __FILE__, __LINE__);
		}
	}
	
	public function maybeAddCartHooks() {
		if (!empty($this->currentCartId) && !has_action('wp_footer', [$this, 'cartSeen'])) {
			add_action('wp_footer', [$this, 'cartSeen']);
		
			if ( get_option('phplugins_carts_show_id') ) {
				add_action('woocommerce_after_cart_table', [$this, 'outputCartId']);
				add_filter('render_block_woocommerce/cart', [$this, 'getCartIdFrontendDisplay']);
				add_filter('render_block_woocommerce/mini-cart-contents', [$this, 'getCartIdFrontendDisplay']);
				add_action('body_class', [$this, 'addCartIdDisplayClass']);
				add_action('wp_enqueue_scripts', [$this, 'frontendScripts']);
			}
		}
	}
	
	public function docsbotWidgetConfig($widgetConfig, $t1, $t2, $botConfig) {
		try {
			if ( ($botConfig['bot_privacy'] ?? '') === 'private' ) {
				$currentCartId = $this->getSessionCartId();
				if ($currentCartId) {
					if (!is_array($widgetConfig['identify'] ?? null)) {
						$widgetConfig['identify'] = [];
					}
					$widgetConfig['identify']['live_carts_id'] = self::formatCartId($currentCartId);
				}
			}
		} catch (\Exception $ex) {
			$this->debugLog($ex->getMessage(), __FILE__, __LINE__);
		} catch (\Error $err) {
			$this->debugLog($err->getMessage(), __FILE__, __LINE__);
		}
		return $widgetConfig;
	}

	/**
	 * Returns the current visitor's Live Carts cart ID from their WooCommerce session, or null.
	 *
	 * WooCommerce only starts its session on front-end requests, not REST API requests
	 * (WC()->session is null there), even though the browser sends the session cookie.
	 * It can't be started with WC()->initialize_session() either: its handler treats a
	 * cookie belonging to a logged-in customer as invalid when WordPress considers the
	 * request logged out -- which a REST request without a nonce always is -- and then
	 * destroys that customer's session and empties their cart. So when there's no session,
	 * read it without starting it: validate the cookie (HMAC and expiry) and load the
	 * stored session row, with no cookie writes, no cart, and no destructive paths.
	 *
	 * @return int|null
	 */
	private function getSessionCartId() {
		if ( ! function_exists( 'WC' ) ) {
			return null;
		}

		// A normal front-end request: WooCommerce has already started the session.
		$wc = WC();
		if ( $wc->session ) {
			return (int) $wc->session->get( 'phplugins_cart_id', 0 ) ?: null;
		}

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce core filter
		$handlerClass = apply_filters( 'woocommerce_session_handler', 'WC_Session_Handler' );
		if ( ! is_string( $handlerClass ) || ! class_exists( $handlerClass ) || ! method_exists( $handlerClass, 'get_session_cookie' ) || ! method_exists( $handlerClass, 'get_session' ) ) {
			return null; // A custom session handler we don't know how to read.
		}

		$handler = new $handlerClass();
		$cookie  = $handler->get_session_cookie(); // [ customer_id, expiration, expiring, hash ], or false if missing/tampered.
		if ( ! $cookie || time() > (int) $cookie[1] ) {
			return null;
		}

		$data = $handler->get_session( $cookie[0], [] );
		if ( ! is_array( $data ) || ! isset( $data['phplugins_cart_id'] ) ) {
			return null;
		}

		return (int) $data['phplugins_cart_id'] ?: null;
	}

	public function validateCurrentCart() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->get_row( $wpdb->prepare('SELECT status, type FROM '.$wpdb->prefix.'phplugins_carts WHERE cart_id=%d', $this->currentCartId) );
		$valid = $result && $result->status != 'converted' && ShopperLists::isCartType( $result->type ?? '' );
		if (!$valid) {
			// When the session cart was converted server-side
			if ($result && $result->status === 'converted') {
				WC()->session->set( 'phplugins_cart_converted', true );
				WC()->session->set( 'phplugins_cart_id', null );
			}
			unset($this->currentCartId);
		}
		return $valid;
	}

	public function createNewCart($cart) {
		global $wpdb;
		
		$isConvertedCart = WC()->session->get( 'phplugins_cart_converted', false );
		if ($isConvertedCart && $cart->is_empty()) {
			WC()->session->set( 'phplugins_cart_converted', null );
		}
		
		if (!apply_filters('phplugins_live_carts_should_create_cart', !$cart->is_empty() && !$isConvertedCart, $cart)) {
			return;
		}

		if ( get_option( 'phplugins_carts_logged_in_only' ) && ! get_current_user_id() ) {
			return;
		}
		
			do {
			$cartId = random_int(1, hexdec('FFFFFFFF'));

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$foundCartId = $wpdb->get_col($wpdb->prepare('SELECT cart_id FROM '.$wpdb->prefix.'phplugins_carts WHERE cart_id=%d', $cartId));
		} while ($foundCartId);

		// Best-effort: a failure resolving the visitor's email must never block cart creation.
		$visitorEmail = null;
		if (class_exists(__NAMESPACE__.'\CartEmail')) {
			try {
				$visitorEmail = CartEmail::instance()->resolveEmailForNewCart();
			} catch (\Exception $ex) {
				$this->debugLog($ex->getMessage(), __FILE__, __LINE__);
			} catch (\Error $err) {
				$this->debugLog($err->getMessage(), __FILE__, __LINE__);
			}
		}

		list($insertData, $insertDataFormat) = apply_filters('phplugins_live_carts_cart_insert', [
			[
				'cart_id' => $cartId,
				'user_id' => get_current_user_id(),
				'created' => current_time('mysql', true),
				'ip_address' => get_option('phplugins_carts_no_ip') ? '' : apply_filters('phplugins_live_carts_cart_ip_address', sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'] ?? ''))),
				'status' => 'active',
				'type' => ShopperLists::TYPE_CART,
				'email' => $visitorEmail ?: null
			],
			[
				'cart_id' => '%d',
				'user_id' => '%d',
				'created' => '%s',
				'ip_address' => '%s',
				'status' => '%s',
				'type' => '%s',
				'email' => '%s'
			]
		]);
		
		
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->insert(
			$wpdb->prefix.'phplugins_carts',
			$insertData,
			$insertDataFormat
		);
		
		if (!$result) {
			throw new \Exception( esc_html__('Cart could not be created', 'live-carts-for-woocommerce') );
		}
		
		$this->currentCartId = $cartId;
		WC()->session->set( 'phplugins_cart_id', $this->currentCartId );
		
		$this->debugLog('createNewCart: new cart_id=' . $cartId . ', user_id=' . get_current_user_id(), __FILE__, __LINE__, 'info');

		do_action('phplugins_live_carts_cart_created', $cartId);

		$this->cartSeen();
	}

	public function cartSeen() {
		global $wpdb;
		
		if (empty($this->currentCartId)) {
			return;
		}
		
		try {
			$updateValues = [
				'last_seen' => current_time('mysql', true),
				'status' => 'active',
				'archived' => 0,
				'trashed' => 0,
				'user_id' => get_current_user_id(),
				'ip_address' => get_option('phplugins_carts_no_ip') ? '' : apply_filters('phplugins_live_carts_cart_ip_address', sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'] ?? '')))
			];
		
			$updateFormats = [
				'last_seen' => '%s',
				'status' => '%s',
				'archived' => '%d',
				'trashed' => '%d',
				'user_id' => '%s',
				'ip_address' => '%s'
			];
			
			if (!get_option('phplugins_carts_no_url') && !is_404() && !is_admin() && !wp_doing_ajax() && !wp_is_serving_rest_request()) {
				$updateValues['last_url'] = add_query_arg([]);
				$updateFormats['last_url'] = '%s';
			}
			
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$result = $wpdb->update(
				$wpdb->prefix.'phplugins_carts',
				$updateValues,
				[ 'cart_id' => $this->currentCartId ],
				$updateFormats,
				[ 'cart_id' => '%d' ]
			);
			
			do_action('phplugins_live_carts_cart_seen', $this->currentCartId);

			if ($result === false) {
				throw new \Exception( esc_html__('Cart seen could not be updated', 'live-carts-for-woocommerce') );
			}
		} catch (\Exception $ex) {
			$this->debugLog($ex->getMessage(), __FILE__, __LINE__);
		} catch (\Error $err) {
			$this->debugLog($err->getMessage(), __FILE__, __LINE__);
		}
	}
	
	public function requestUpdateCartContents() {
		if (!has_action('shutdown', [$this, 'updateCartContents'])) {
			add_action('shutdown', [$this, 'updateCartContents']);
		}
	}
	
	public function updateCartContents() {
		global $wpdb;
		
		try {
			if (!isset($this->currentCart)) {
				return;
			}
			
			if (empty($this->currentCartId)) {
				try {
					$this->createNewCart($this->currentCart);
					$this->maybeAddCartHooks();
				} catch (\Exception $ex) {
					$this->debugLog($ex->getMessage(), __FILE__, __LINE__);
					return;
				}
			}
			
			if (empty($this->currentCartId)) {
				return;
			}
			
			// Check if migration has been completed
			if (!get_option('phplugins_carts_migration_1_2_0_complete')) {
				return; // Migration not completed yet, skip cart contents update
			}
			
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$result = $wpdb->update(
				$wpdb->prefix.'phplugins_carts',
				[
					'value' => $this->currentCart->get_total('edit') - $this->currentCart->get_total_tax(),
					'coupon' => implode(', ', $this->currentCart->get_applied_coupons())
				],
				[ 'cart_id' => $this->currentCartId ],
				[
					'value' => '%f',
					'coupon' => '%s'
				],
				[ 'cart_id' => '%d' ]
			);
			
			if ($result === false) {
				throw new \Exception( __('Cart could not be updated', 'live-carts-for-woocommerce') );
			}

			$currentContents = $this->currentCart->get_cart_contents();
			
			$debugItems = [];
			foreach ($currentContents as $item) {
				$debugItems[] = 'pid=' . $item['product_id'] . (empty($item['variation_id']) ? '' : ',vid=' . $item['variation_id']) . ',qty=' . $item['quantity'];
			}
			$this->debugLog('updateCartContents: cart_id=' . $this->currentCartId . ', items=[' . implode('; ', $debugItems) . ']', __FILE__, __LINE__, 'info');

			self::saveCartContents($this->currentCartId, $currentContents) && do_action('phplugins_live_carts_cart_contents_updated', $this->currentCartId);
		} catch (\Exception $ex) {
			$this->debugLog($ex->getMessage(), __FILE__, __LINE__);
		} catch (\Error $err) {
			$this->debugLog($err->getMessage(), __FILE__, __LINE__);
		}
	}

	private static function arraysEqual($arr1, $arr2) {
		if (array_diff_key($arr1, $arr2) || array_diff_key($arr2, $arr1)) {
			return false;
		}
		foreach ($arr1 as $field => $value) {
			if (is_array($value) != is_array($arr2[$field])) {
				return false;
			}
			if (is_array($value)) {
				if (!self::arraysEqual($value, $arr2[$field])) {
					return false;
				}
				continue;
			}
			if ($value != $arr2[$field]) {
				return false;
			}
		}
		return true;
	}
		
	/**
	 * Compares the items being saved against the previously stored items and fires
	 * granular `phplugins_live_carts_items_added` / `_removed` / `_updated` actions
	 * for the difference. Shared by carts and shopper lists (both go through
	 * saveCartContents()); Api\Webhooks listens on these to fire the item-level
	 * webhook events (cart.items.* / list.items.*) for whichever type the cart_id
	 * turns out to be.
	 *
	 * Items are matched the same way as getCartContentsHistory(): by variation_id
	 * when present, otherwise product_id. The added/removed actions pass the raw
	 * item array (as from getCartContentsArray()); the updated action passes
	 * `['item' => ..., 'item_before' => ...]` pairs, since a quantity change needs
	 * both the new and old item to describe.
	 *
	 * @param int      $cartId
	 * @param array    $currentContents Cart-item-key-indexed contents array being saved.
	 * @param int|null $lastContentsId  contents_id of the previous revision, or null/0 if there isn't one.
	 * @return array[] List of [ hook name, payload ] pairs. The diff has to be computed before the
	 *                 new contents are written (the non-revision mode overwrites the previous
	 *                 items in place), but the hooks must only fire once they are saved: webhook
	 *                 listeners re-read the cart, and would otherwise see its stale contents.
	 */
	private static function computeItemChangeEvents( $cartId, $currentContents, $lastContentsId ) {
		$events = [];

		$currentById     = [];
		$currentByIdItem = [];
		foreach ( $currentContents as $item ) {
			$itemId = empty( $item['variation_id'] ) ? ( $item['product_id'] ?? 0 ) : $item['variation_id'];
			$currentById[ $itemId ]     = ( $currentById[ $itemId ] ?? 0 ) + ( $item['quantity'] ?? 0 );
			$currentByIdItem[ $itemId ] = $item;
		}

		// Re-fetch the previous revision's items fresh (rather than reusing any
		// in-progress comparison arrays from the caller, which may mutate entries
		// as they're matched) so this diff is always computed from clean data.
		$lastById     = [];
		$lastByIdItem = [];
		if ( $lastContentsId ) {
			foreach ( self::getCartContentsArray( $lastContentsId ) as $item ) {
				$itemId = empty( $item['variation_id'] ) ? ( $item['product_id'] ?? 0 ) : $item['variation_id'];
				$lastById[ $itemId ]     = ( $lastById[ $itemId ] ?? 0 ) + ( $item['quantity'] ?? 0 );
				$lastByIdItem[ $itemId ] = $item;
			}
		}

		$addedIds   = array_diff_key( $currentById, $lastById );
		$removedIds = array_diff_key( $lastById, $currentById );
		$changedIds = array_diff_assoc( array_intersect_key( $currentById, $lastById ), array_intersect_key( $lastById, $currentById ) );

		if ( $addedIds ) {
			$added = [];
			foreach ( $addedIds as $itemId => $quantity ) {
				$added[] = $currentByIdItem[ $itemId ] ?? [ 'product_id' => (int) $itemId, 'variation_id' => 0, 'quantity' => (float) $quantity ];
			}
			$events[] = [ 'phplugins_live_carts_items_added', $added ];
		}

		if ( $removedIds ) {
			$removed = [];
			foreach ( $removedIds as $itemId => $quantity ) {
				$removed[] = $lastByIdItem[ $itemId ] ?? [ 'product_id' => (int) $itemId, 'variation_id' => 0, 'quantity' => (float) $quantity ];
			}
			$events[] = [ 'phplugins_live_carts_items_removed', $removed ];
		}

		if ( $changedIds ) {
			$changed = [];
			foreach ( $changedIds as $itemId => $quantity ) {
				$changed[] = [
					'item'        => $currentByIdItem[ $itemId ] ?? [],
					'item_before' => $lastByIdItem[ $itemId ] ?? [],
				];
			}
			$events[] = [ 'phplugins_live_carts_items_updated', $changed ];
		}

		return $events;
	}

	public static function saveCartContents($cartId, $currentContents) {
		global $wpdb;
		
			
		// Get the last cart contents to check if changed
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$lastContentsId = $wpdb->get_var( $wpdb->prepare('SELECT contents_id FROM '.$wpdb->prefix.'phplugins_cart_contents WHERE cart_id=%d ORDER BY contents_id DESC LIMIT 1', $cartId) );
		
		$contentsChanged = true;
		if ($lastContentsId) {
			// Check if contents actually changed by comparing items
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$lastItems = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT product_id, variation_id, quantity, line_subtotal, line_subtotal_tax, line_total, line_tax, meta 
					FROM '.$wpdb->prefix.'phplugins_cart_contents_items 
					WHERE contents_id=%d
					ORDER BY item_id ASC',
					$lastContentsId
				),
				ARRAY_A
			);
			
			// Compare with current contents
			$currentContentsArray = [];
			foreach ($currentContents as &$item) {
				$meta = $item;
				unset($meta['product_id'], $meta['variation_id'], $meta['quantity'], 
					  $meta['line_subtotal'], $meta['line_subtotal_tax'], 
					  $meta['line_total'], $meta['line_tax']);
					  
				if (empty($meta['data'])) {
					unset($meta['data']);
				}
				
				$item['_php_lc_meta'] = wp_json_encode($meta);
				
				$currentContentsArray[] = [
					'product_id' => isset($item['product_id']) ? (string) $item['product_id'] : '0',
					'variation_id' => isset($item['variation_id']) ? (string) $item['variation_id'] : '0',
					'quantity' => isset($item['quantity']) ? (string) $item['quantity'] : '0',
					'line_subtotal' => isset($item['line_subtotal']) ? (string) $item['line_subtotal'] : '0',
					'line_subtotal_tax' => isset($item['line_subtotal_tax']) ? (string) $item['line_subtotal_tax'] : '0',
					'line_total' => isset($item['line_total']) ? (string) $item['line_total'] : '0',
					'line_tax' => isset($item['line_tax']) ? (string) $item['line_tax'] : '0',
					'meta' => $item['_php_lc_meta']
				];
			}
			unset($item);
			
			if (count($lastItems) != count($currentContentsArray)) {
				$contentsChanged = true;
			} else {
				$contentsChanged = false;
				foreach ($currentContentsArray as $item) {
					foreach ($lastItems as &$lastItem) {
						if ($lastItem) {
							$itemMatch = true;
							foreach ($item as $itemField => $itemValue) {
								if ($itemField != 'meta' && $itemValue != $lastItem[$itemField]) {
									$itemMatch = false;
									break;
								}
							}
							if ($itemMatch && self::arraysEqual(json_decode($item['meta'], true), json_decode($lastItem['meta'], true))) {
								$lastItem = null;
								continue 2;
							}
						}
					}
					$contentsChanged = true;
					break;
				}
			}
			
		}

		if ($contentsChanged) {
			$itemChangeEvents = self::computeItemChangeEvents( $cartId, $currentContents, $lastContentsId );

			if (apply_filters('phplugins_live_carts_track_revisions', true, $cartId)) {
				// Insert new cart contents record
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$result = $wpdb->insert(
					$wpdb->prefix.'phplugins_cart_contents',
					[
						'cart_id' => $cartId,
						'ts' => current_time('mysql', true)
					],
					[
						'cart_id' => '%d',
						'ts' => '%s'
					]
				);
				
				if ($result === false) {
					throw new \Exception( esc_html__('Cart contents could not be created', 'live-carts-for-woocommerce') );
				}
				
				$contentsId = $wpdb->insert_id;
			} else {
				// Update existing cart contents record
				if ($lastContentsId) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$result = $wpdb->update(
						$wpdb->prefix.'phplugins_cart_contents',
						['ts' => current_time('mysql', true)],
						['contents_id' => $lastContentsId],
						['ts' => '%s'],
						['contents_id' => '%d']
					);
					
					// Delete old items
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$wpdb->delete(
						$wpdb->prefix.'phplugins_cart_contents_items',
						['contents_id' => $lastContentsId],
						['%d']
					);
					
					$contentsId = $lastContentsId;
				} else {
					// No existing record, create new one
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$result = $wpdb->insert(
						$wpdb->prefix.'phplugins_cart_contents',
						[
							'cart_id' => $cartId,
							'ts' => current_time('mysql', true)
						],
						[
							'cart_id' => '%d',
							'ts' => '%s'
						]
					);
					
					if ($result === false) {
						throw new \Exception( esc_html__('Cart contents could not be created', 'live-carts-for-woocommerce') );
					}
					
					$contentsId = $wpdb->insert_id;
				}
			}
			
			// Insert cart items
			foreach ($currentContents as $item) {
				if (!isset($item['_php_lc_meta'])) {
					$meta = $item;
					unset($meta['product_id'], $meta['variation_id'], $meta['quantity'], 
						  $meta['line_subtotal'], $meta['line_subtotal_tax'], 
						  $meta['line_total'], $meta['line_tax']);
						  
					if (empty($meta['data'])) {
						unset($meta['data']);
					}
					
					$item['_php_lc_meta'] = wp_json_encode($meta);
				}
				
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->insert(
					$wpdb->prefix.'phplugins_cart_contents_items',
					[
						'contents_id' => $contentsId,
						'product_id' => isset($item['product_id']) ? (int) $item['product_id'] : 0,
						'variation_id' => isset($item['variation_id']) ? (int) $item['variation_id'] : 0,
						'quantity' => isset($item['quantity']) ? (float) $item['quantity'] : 0,
						'line_subtotal' => isset($item['line_subtotal']) ? (float) $item['line_subtotal'] : 0,
						'line_subtotal_tax' => isset($item['line_subtotal_tax']) ? (float) $item['line_subtotal_tax'] : 0,
						'line_total' => isset($item['line_total']) ? (float) $item['line_total'] : 0,
						'line_tax' => isset($item['line_tax']) ? (float) $item['line_tax'] : 0,
						'meta' => $item['_php_lc_meta']
					],
					['%d', '%d', '%d', '%f', '%f', '%f', '%f', '%f', '%s']
				);
			}
			
			foreach ( $itemChangeEvents as list( $hook, $payload ) ) {
				try {
					do_action( $hook, $cartId, $payload );
				} catch (\Throwable $x) {}
			}
			
			return true;
		}
		return false;
	}
	
	/**
	 * Handles cart recovery from URL parameter.
	 */
	public function maybeRecoverCart() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- not a CSRF risk because the cart key authenticates the request
		if ( isset( $_GET['recover_cart'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- not a CSRF risk because the cart key authenticates the request
			$key = sanitize_key( $_GET['recover_cart'] );
			global $wpdb;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$cart_data = $wpdb->get_row( $wpdb->prepare( "SELECT cart_id, session_key, coupon FROM {$wpdb->prefix}phplugins_carts WHERE recovery_key = %s AND trashed = 0", $key ) );
			
			if ( $cart_data ) {
				$wc = WC();

				$session_key = $wc->session->get_customer_id();
				if ( $session_key !== $cart_data->session_key ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$session = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}woocommerce_sessions WHERE session_key = %s", $cart_data->session_key ) );
					if ( $session ) {
						$session_value = maybe_unserialize( $session->session_value );
						unset( $session_value['phplugins_cart_id'] );
						// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
						$wpdb->update( $wpdb->prefix . 'woocommerce_sessions', array( 'session_value' => maybe_serialize( $session_value ) ), array( 'session_key' => $cart_data->session_key ) );
					}
				}

				$wc->session->set( 'phplugins_cart_id', $cart_data->cart_id );
				LiveCarts::instance()->resetCurrentCart();

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->update( $wpdb->prefix . 'phplugins_carts', array( 'user_id'     => get_current_user_id(),
				                                                         'status'      => 'active',
				                                                         'session_key' => $session_key,
				                                                         'last_seen'   => current_time( 'mysql', true ),
				                                                         'archived'    => 0
				), array( 'cart_id' => $cart_data->cart_id ) );

				// Get contents - new structure only (requires FREE 1.2.0+)
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$contents_id = $wpdb->get_var( $wpdb->prepare( "SELECT contents_id FROM {$wpdb->prefix}phplugins_cart_contents WHERE cart_id = %d ORDER BY contents_id DESC LIMIT 1", $cart_data->cart_id ) );
				$contents = LiveCarts::getCartContentsArray( $contents_id );
				$wc->cart->empty_cart();

				foreach ( $contents as $item ) {
					$cart_item_data = array_diff_key( $item, [
						'line_total'    => true,
						'line_subtotal' => true,
						'key'           => true,
						'product_id'    => true,
						'variation_id'  => true,
						'variation'     => true,
						'quantity'      => true,
						'data'          => true,
						'data_hash'     => true,
					] );
					$wc->cart->add_to_cart( $item['product_id'], $item['quantity'], $item['variation_id'], $item['variation'], $cart_item_data );
				}
				
				if (strlen($cart_data->coupon)) {
					$wc->cart->apply_coupon($cart_data->coupon);
				}
				
				wp_safe_redirect( remove_query_arg( 'recover_cart' ) );
				exit;
			}
		}
	}

	public function scheduledCartStatusTransitionsAndCleanup() {
		global $wpdb;

		// Abandoned carts
		
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$abandonedCarts = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT cart_id
				FROM '.$wpdb->prefix.'phplugins_carts
				WHERE status="active"
					AND type="cart"
					AND last_seen < %s',
				wp_date('Y-m-d H:i:s', time() - apply_filters('phplugins_live_carts_abandon_time', self::CART_ABANDON_TIME), new \DateTimeZone('UTC'))
			)
		);
			foreach (array_chunk($abandonedCarts, 20) as $abandonedCartsChunk) {
				// Build placeholders string (safe - contains only %d)
				$placeholders = implode(',', array_fill(0, count($abandonedCartsChunk), '%d'));
				// Build SQL string with sprintf, then prepare with values
				$sql = sprintf(
					'UPDATE %sphplugins_carts SET status="abandoned" WHERE cart_id IN (%s)',
					$wpdb->prefix,
					$placeholders
				);
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql built with sprintf above, placeholders are safe (%d only)
				$preparedQuery = $wpdb->prepare( $sql, ...$abandonedCartsChunk );
			
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- query is prepared above
				$wpdb->query($preparedQuery);
			if (has_action('phplugins_live_carts_cart_abandoned')) {
				array_walk($abandonedCartsChunk, function($cartId) {
					do_action('phplugins_live_carts_cart_abandoned', $cartId);
				});
			}
		}

		// Archived carts (Pro may override abandon/archive timing for carts only).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE '.$wpdb->prefix.'phplugins_carts
				SET archived=1
				WHERE type=%s
					AND last_seen < %s',
				ShopperLists::TYPE_CART,
				wp_date('Y-m-d H:i:s', time() - apply_filters('phplugins_live_carts_archive_time', (self::CART_ARCHIVE_DAYS * 86400)), new \DateTimeZone('UTC'))
			)
		);

		// Archived shopper lists always use the free default retention window.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		/*
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE '.$wpdb->prefix.'phplugins_carts
				SET archived=1
				WHERE type LIKE %s
					AND last_seen < %s',
				$wpdb->esc_like( ShopperLists::TYPE_LIST_PREFIX ) . '%',
				wp_date('Y-m-d H:i:s', time() - (self::CART_ARCHIVE_DAYS * 86400), new \DateTimeZone('UTC'))
			)
		);
		*/

		// Delete archived/orphaned cart contents
		
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			'DELETE FROM '.$wpdb->prefix.'phplugins_cart_contents
			WHERE NOT EXISTS(
				SELECT 1 FROM '.$wpdb->prefix.'phplugins_carts c
				WHERE c.cart_id='.$wpdb->prefix.'phplugins_cart_contents.cart_id AND NOT c.archived
			)'
		);

		// Maybe clear cart revisions
		$maxCartsWithRevisions = apply_filters('phplugins_live_carts_max_carts_with_revisions', self::MAX_CARTS_WITH_REVISIONS);
		if ($maxCartsWithRevisions) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query(
				$wpdb->prepare(
					'DELETE cc FROM '.$wpdb->prefix.'phplugins_cart_contents cc
					JOIN (SELECT cart_id, MAX(contents_id) AS contents_id FROM '.$wpdb->prefix.'phplugins_cart_contents GROUP BY cart_id) cc_max ON(cc_max.cart_id=cc.cart_id)
					JOIN '.$wpdb->prefix.'phplugins_carts c_type ON(c_type.cart_id=cc.cart_id AND c_type.type=%s)
					LEFT JOIN (SELECT cart_id FROM '.$wpdb->prefix.'phplugins_carts ORDER BY last_seen DESC LIMIT %d) c ON(c.cart_id=cc.cart_id)
					WHERE c.cart_id IS NULL AND cc.contents_id != cc_max.contents_id',
					ShopperLists::TYPE_CART,
					$maxCartsWithRevisions
				)
			);
		}
	}
		
	public function hourlyScheduledTasks() {
		$this->scheduledCartStatusTransitionsAndCleanup();
		RecoveryEmails::instance()->checkAndSendRecoveryEmails();
	}
	
	public static function generateCartRecoveryKey($cartId) {
		global $wpdb;
		
		do {
			$recovery_key = wp_generate_password( 32, false );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$exists = $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM ' . $wpdb->prefix . 'phplugins_carts WHERE recovery_key=%s', $recovery_key ) );
		} while ( $exists );
		
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update( $wpdb->prefix . 'phplugins_carts', array( 'recovery_key' => $recovery_key ), array( 'cart_id' => $cartId ) );
		
		return $recovery_key;
	}

	/**
	 * Mirrors WC_Cart::generate_cart_id() without instantiating WC_Cart.
	 *
	 * `new \WC_Cart()` registers WooCommerce cart/session hooks (including one on
	 * `shutdown`) that fatal when WC()->session isn't initialised, which is the case
	 * on REST, cron and most admin requests. This is only a fallback for stored items
	 * that have no `key` in their meta.
	 */
	private static function generateCartItemKey( $productId, $variationId = 0, $variation = [], $cartItemData = [] ) {
		$idParts = [ $productId ];

		if ( $variationId && 0 !== $variationId ) {
			$idParts[] = $variationId;
		}

		if ( is_array( $variation ) && ! empty( $variation ) ) {
			$variationKey = '';
			foreach ( $variation as $key => $value ) {
				$variationKey .= trim( $key ) . trim( $value );
			}
			$idParts[] = $variationKey;
		}

		if ( is_array( $cartItemData ) && ! empty( $cartItemData ) ) {
			$cartItemDataKey = '';
			foreach ( $cartItemData as $key => $value ) {
				if ( is_array( $value ) || is_object( $value ) ) {
					$value = http_build_query( $value );
				}
				$cartItemDataKey .= trim( $key ) . trim( $value );
			}
			$idParts[] = $cartItemDataKey;
		}

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce core filter
		return apply_filters( 'woocommerce_cart_id', md5( implode( '_', $idParts ) ), $productId, $variationId, $variation, $cartItemData );
	}

	/**
	 * Helper method to get cart contents from new table structure.
	 *
	 * @param int $contents_id The contents_id to load items for.
	 * @return array Cart contents array.
	 */
	public static function getCartContentsArray( $contents_id ) {
		global $wpdb;
		
		if ( empty( $contents_id ) ) {
			return array();
		}
		
		// Load from phplugins_cart_contents_items (new structure only - requires FREE 1.2.0+)
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$items = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT product_id, variation_id, quantity, line_subtotal, line_subtotal_tax, line_total, line_tax, meta 
				FROM ' . $wpdb->prefix . 'phplugins_cart_contents_items 
				WHERE contents_id=%d
				ORDER BY item_id ASC',
				$contents_id
			),
			ARRAY_A
		);
		
		$contents = array();
		foreach ( $items as $item ) {
			$meta = json_decode( $item['meta'], true );
			if ( ! is_array( $meta ) ) {
				$meta = array();
			}
			
			// Merge meta with the main item data and use the cart item key
			$key = $meta['key'] ?? self::generateCartItemKey( $item['product_id'], $item['variation_id'], $meta['variation'] ?? [], $meta );
			$contents[ $key ] = array_merge(
				$meta,
				array(
					'product_id' => $item['product_id'],
					'variation_id' => $item['variation_id'],
					'quantity' => $item['quantity'],
					'line_subtotal' => $item['line_subtotal'],
					'line_subtotal_tax' => $item['line_subtotal_tax'],
					'line_total' => $item['line_total'],
					'line_tax' => $item['line_tax']
				)
			);
		}
		
		return $contents;
	}
	
	public static function getCartContentsHistory( $cart_id ) {
		global $wpdb;
		
		$history = [];
		
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$revisions = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT contents_id, ts 
				FROM ' . $wpdb->prefix . 'phplugins_cart_contents
				WHERE cart_id=%d
				ORDER BY ts ASC',
				$cart_id
			),
			ARRAY_N
		);
		
		if ($revisions) {
			$products = [];
			$lastContents = [];
			$lastContentItems = [];
			foreach ( $revisions as $revision ) {
				$contents = [];
				$contentItems = [];
				foreach (self::getCartContentsArray($revision[0]) as $item) {
					$itemId = empty( $item['variation_id'] ) ? $item['product_id'] : $item['variation_id'];
					$contents[$itemId] = ($contents[$itemId] ?? 0) + $item['quantity'];
					$contentItems[$itemId] = $item;
				}
				
				$addedItems = array_diff_key($contents, $lastContents);
				$removedItems = array_diff_key($lastContents, $contents);
				$changedItems = array_diff_assoc(array_intersect_key($contents, $lastContents), array_intersect_key($lastContents, $contents));
				
				foreach (array_diff( array_keys($contents), array_keys($products) ) as $itemId) {
					$products[$itemId] = wc_get_product($itemId);
				}
				
				$diffs = [];
				foreach ($addedItems as $itemId => $quantity) {
					$diffs[] = ['add', empty($products[$itemId]) ? null : $products[$itemId], $quantity, $contentItems[$itemId] ?? []];
				}
				foreach ($removedItems as $itemId => $quantity) {
					$diffs[] = ['remove', empty($products[$itemId]) ? null : $products[$itemId], $lastContentItems[$itemId] ?? []];
				}
				foreach ($changedItems as $itemId => $quantity) {
					$diffs[] = ['change-quantity', empty($products[$itemId]) ? null : $products[$itemId], $lastContents[$itemId], $quantity, $contentItems[$itemId] ?? [], $lastContentItems[$itemId] ?? []];
				}
				
				if ($diffs) {
					$history[] = [$revision[1], $diffs];
				}
				$lastContents = $contents;
				$lastContentItems = $contentItems;
			}
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$sentEmails = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT s.email_id, s.sent_at, p.post_title
				FROM ' . $wpdb->prefix . 'phplugins_recovery_emails_sent s
				LEFT JOIN ' . $wpdb->posts . ' p ON p.ID = s.email_id AND p.post_type = %s
				WHERE s.cart_id = %d
				ORDER BY s.sent_at ASC',
				RecoveryEmails::POST_TYPE,
				$cart_id
			)
		);

		if ( $sentEmails ) {
			foreach ( $sentEmails as $sentEmail ) {
				$history[] = [
					$sentEmail->sent_at,
					[
						[
							'recovery-email',
							isset( $sentEmail->post_title ) ? (string) $sentEmail->post_title : '',
							(int) $sentEmail->email_id,
						],
					],
				];
			}

			usort(
				$history,
				function ( $a, $b ) {
					return strcmp( (string) $a[0], (string) $b[0] );
				}
			);
		}
		
		return $history;
	}

	public static function calculateContentsValue( $contents ) {
		$value = 0.0;
		foreach ( $contents as $item ) {
			$value += isset( $item['line_total'] ) ? (float) $item['line_total'] : ( isset( $item['line_subtotal'] ) ? (float) $item['line_subtotal'] : 0 );
		}
		return $value;
	}

public static function outputCouponCodeLinks($cString) {
                if ( empty( $cString ) ) {
                        return;
                }
                $couponCodes = array_filter( array_map( 'trim', explode( ',', $cString ) ), function($c) { return ((string) $c) !== ''; } );
                $couponLinks = array();
                foreach ( $couponCodes as $couponCode ) {
                        $couponId = function_exists( 'wc_get_coupon_id_by_code' ) ? wc_get_coupon_id_by_code( $couponCode ) : 0;
                        $editLink = $couponId ? get_edit_post_link( $couponId ) : '';
                        if ( $editLink ) {
                                $couponLinks[] = '<a href="' . esc_url( $editLink ) . '">' . esc_html( $couponCode ) . '</a>';
                        } else {
                                $couponLinks[] = esc_html( $couponCode );
                        }
                }
				
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- html output, escaped via esc_html and esc_url above
                echo implode( ', ', $couponLinks );
}

	public static function onPluginActivate() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$allTables = $wpdb->get_col('SHOW TABLES');
		if (!in_array($wpdb->prefix.'phplugins_carts', $allTables)) {
			require_once(__DIR__.'/includes/setup.php');
			Setup::createDatabaseTables();
			Setup::upgradeDatabaseTables('1.0.0');
			update_option('phplugins_carts_version', self::VERSION);
		}

		wp_schedule_event(time() + 3600, 'hourly', 'phplugins_livecarts_hourly');
	}

	public static function onPluginDeactivate() {
		wp_clear_scheduled_hook('phplugins_livecarts_hourly');
	}
}

LiveCarts::instance();

register_activation_hook(__FILE__, ['BerryPress\LiveCarts\LiveCarts', 'onPluginActivate']);
register_deactivation_hook(__FILE__, ['BerryPress\LiveCarts\LiveCarts', 'onPluginDeactivate']);
