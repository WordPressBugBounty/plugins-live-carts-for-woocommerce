<?php

namespace BerryPress\LiveCarts;

use BerryPressLiveCarts\Admin\Page as BerryPressPage;

defined( 'ABSPATH' ) || exit;

class AdminPage extends BerryPressPage {

	protected $table, $cartData, $viewType;

	function __construct($table=null) {
		$this->viewType = ShopperLists::getAdminViewType();
		// Prepare data used in body()
		
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- just checking which page we're on
		if ( isset( $_GET['recovery_emails'] ) ) {
			require_once( __DIR__ . '/recovery-emails-admin-page.php' );
			RecoveryEmailsAdminPage::processActions();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- non-persistent
		} else if ( isset( $_GET['cart_id'] ) ) {
			require_once __DIR__ . '/admin-list.php';
			global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.Security.NonceVerification.Recommended -- non-persistent
			$this->cartData = $wpdb->get_row( $wpdb->prepare( 'SELECT c.*, cc.contents_id, cc.ts FROM ' . $wpdb->prefix . 'phplugins_carts c LEFT JOIN ' . $wpdb->prefix . 'phplugins_cart_contents cc ON c.cart_id = cc.cart_id WHERE c.cart_id=%d AND type LIKE %s AND archived=0 ORDER BY cc.contents_id DESC LIMIT 1', (int) $_GET['cart_id'], $this->isListView() ? $wpdb->esc_like( ShopperLists::TYPE_LIST_PREFIX ) . '%' : ShopperLists::TYPE_CART) );
		} else if ($table) {
			$this->table = $table;
			$this->table->prepare_items();
		}

		add_action( get_plugin_page_hookname( 'live-carts-for-woocommerce', 'berrypress' ), function () {
			$this->render();
		} );
	}
	
	public static function getUrl( array $args = [] ) {
		return add_query_arg( $args, admin_url( 'admin.php?page=live-carts-for-woocommerce' ) );
	}

	protected function isListView() {
		return $this->viewType === 'list';
	}

	protected function getListBackUrl() {
		$args = $this->getListContextQueryArgs();
		unset( $args['cart_id'] );
		return self::getUrl( $args );
	}

	/**
	 * Query arguments that preserve the current admin list view (filters, search, pagination).
	 *
	 * @return array<string,mixed>
	 */
	protected function getListContextQueryArgs() {
		return self::getListContextQueryArgsForView( $this->viewType );
	}

	/**
	 * @param string $viewType 'cart' or 'list'.
	 * @return array<string,mixed>
	 */
	public static function getListContextQueryArgsForView( $viewType ) {
		$args = [];

		if ( $viewType === 'list' ) {
			$args['type'] = 'list';
		}

		$statuses = LiveCarts::instance()->getCartStatuses();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list context from current admin URL
		if ( ! empty( $_GET['status'] ) && isset( $statuses[ sanitize_key( wp_unslash( $_GET['status'] ) ) ] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list context from current admin URL
			$args['status'] = sanitize_key( wp_unslash( $_GET['status'] ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list context from current admin URL
		if ( ! empty( $_GET['s'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list context from current admin URL
			$args['s'] = sanitize_text_field( wp_unslash( $_GET['s'] ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list context from current admin URL
		if ( ! empty( $_GET['paged'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list context from current admin URL
			$args['paged'] = max( 1, (int) $_GET['paged'] );
		}

		return apply_filters( 'phplugins_live_carts_list_context_query_args', $args, $viewType );
	}

	/**
	 * @param int $cartId Cart or list ID.
	 * @return string
	 */
	protected function getCartDetailUrl( $cartId ) {
		return self::getUrl( array_merge( $this->getListContextQueryArgs(), [ 'cart_id' => (int) $cartId ] ) );
	}

	private function renderAddonsPage() {
		include_once(plugin_dir_path(__FILE__) . '../includes/berrypress-admin-framework/addons-page.php');
	}

	public function body() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- just checking which page we're on
		if ( isset( $_GET['recovery_emails'] ) ) {
			$page = new RecoveryEmailsAdminPage();
			$page->render();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- just checking which page we're on
		} else if ( isset( $_GET['cart_id'] ) ) {
			$this->cartPage();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- just checking which page we're on
		} elseif ( isset( $_GET['settings'] ) ) {
			$this->settingsPage();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- just checking which page we're on
		} elseif ( isset( $_GET['healthstatus'] ) ) {
			$this->statusPage();
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- just checking which page we're on
        elseif ( isset( $_GET['addons'] ) && $this->showExternalLinks ) {
	        $this->renderAddonsPage();
		} else {
			$this->listPage();
		}

	}

	public function listPage() {
		$isListView = $this->isListView();
		?>
        <div class="wrap">
            <h2></h2>
            <div class="berrypress-page-content-header">
                <h1><?php echo esc_html( $isListView ? __( 'Shopper Lists', 'live-carts-for-woocommerce' ) : __( 'Live Carts', 'live-carts-for-woocommerce' ) ); ?></h1>
                <button id="berrypress-show-settings-link" class="berrypress-btn berrypress-btn-secondary">Table
                    Settings
                </button>
            </div>
			<?php $this->renderCartActionNotices(); ?>
            <form method="get">
                <input type="hidden" name="page" value="live-carts-for-woocommerce">
				<?php if ( $isListView ) { ?>
                    <input type="hidden" name="type" value="list">
				<?php } ?>
				<?php
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- preserve list filters for bulk actions
				if ( ! empty( $_REQUEST['status'] ) ) {
					?>
                    <input type="hidden" name="status" value="<?php echo esc_attr( sanitize_key( wp_unslash( $_REQUEST['status'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- preserve list filters for bulk actions ?>">
					<?php
				}
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- preserve list filters for bulk actions
				if ( ! empty( $_REQUEST['s'] ) ) {
					?>
                    <input type="hidden" name="s" value="<?php echo esc_attr( sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- preserve list filters for bulk actions ?>">
					<?php
				}
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- preserve list filters for bulk actions
				if ( ! empty( $_REQUEST['paged'] ) ) {
					?>
                    <input type="hidden" name="paged" value="<?php echo (int) max( 1, (int) $_REQUEST['paged'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- preserve list filters for bulk actions ?>">
					<?php
				}
				?>
				<?php $this->table->views();
				do_action( 'phplugins_live_carts_list_after_view_links' );
				$this->table->search_box(
					$isListView
						? esc_html__( 'Search List ID', 'live-carts-for-woocommerce' )
						: esc_html__( 'Search Cart ID', 'live-carts-for-woocommerce' ),
					'phplugins-live-carts-search'
				);
				$this->table->display(); ?>
            </form>
            <?php if ( ! $isListView ) : ?>
            <p class="instructions"><?php
				$archiveDays = (float) apply_filters( 'phplugins_live_carts_archive_time', LiveCarts::CART_ARCHIVE_DAYS * 86400 ) / 86400;
				printf(
					// translators: %s is the number of days (may be a decimal)
					esc_html__( 'Carts are removed from this page approximately %s days after they were last seen. Some cart data is retained in the database after this time.', 'live-carts-for-woocommerce' ),
					esc_html( rtrim( rtrim( number_format( $archiveDays, 1 ), '0' ), '.' ) )
				);
				echo ' ';
				if ( has_action( 'phplugins_live_carts_list_archive_hint' ) ) {
					do_action( 'phplugins_live_carts_list_archive_hint' );
				} else {
					esc_html_e( 'To change this, upgrade to Pro.', 'live-carts-for-woocommerce' );
				}
			?></p>
            <?php endif; ?>
        </div>
		<?php
	}

	public function settingsPage() {
		$showCartId = (bool) get_option( 'phplugins_carts_show_id' );
		$precedence = get_option( 'phplugins_carts_email_precedence', 'user' );

		$savedConversionStatuses = get_option( 'phplugins_carts_conversion_order_statuses', [] );
		if ( ! is_array( $savedConversionStatuses ) ) {
			$savedConversionStatuses = [];
		}
		$orderStatuses               = wc_get_order_statuses();
		$automaticConversionStatuses = LiveCarts::instance()->getAutomaticConversionOrderStatuses();
		$automaticConversionStatusLabels = [];
		foreach ( $orderStatuses as $statusKey => $statusLabel ) {
			if ( in_array( LiveCarts::normalizeOrderStatusSlug( $statusKey ), $automaticConversionStatuses, true ) ) {
				$automaticConversionStatusLabels[] = $statusLabel;
			}
		}
		$conversionStatusMode       = LiveCarts::instance()->getConversionOrderStatusConfiguration()['source'];
		$selectedConversionStatuses = ( 'custom' === $conversionStatusMode ) ? $savedConversionStatuses : $automaticConversionStatuses;
		$isAutomaticMode            = ( 'automatic' === $conversionStatusMode );
		?>
        <div id="phplugins-live-carts-settings" class="wrap">
            <h2></h2>
            <h1 class="phplugins-live-carts-settings-h1"><?php esc_html_e( 'Live Carts Settings', 'live-carts-for-woocommerce' ); ?></h1>

            <nav class="nav-tab-wrapper berrypress-nav-tab-wrapper" id="phplugins-lc-settings-tabs" aria-label="<?php esc_attr_e( 'Settings sections', 'live-carts-for-woocommerce' ); ?>">
                <a href="#" class="nav-tab" data-tab="tracking"><?php esc_html_e( 'Tracking', 'live-carts-for-woocommerce' ); ?></a>
                <a href="#" class="nav-tab" data-tab="lifecycle"><?php esc_html_e( 'Lifecycle', 'live-carts-for-woocommerce' ); ?></a>
                <a href="#" class="nav-tab" data-tab="display"><?php esc_html_e( 'Display', 'live-carts-for-woocommerce' ); ?></a>
                <a href="#" class="nav-tab" data-tab="email"><?php esc_html_e( 'Email', 'live-carts-for-woocommerce' ); ?></a>
                <a href="#" class="nav-tab" data-tab="integrations"><?php esc_html_e( 'Integrations', 'live-carts-for-woocommerce' ); ?></a>
                <a href="#" class="nav-tab" data-tab="api"><?php esc_html_e( 'API', 'live-carts-for-woocommerce' ); ?></a>
            </nav>

            <form action="" class="berrypress-nav-tab-content" method="post">
				<?php wp_nonce_field( 'phplugins-carts-settings-save', 'phplugins_carts_settings_save' ); ?>

                <!-- Tracking tab -->
                <div class="phplugins-lc-settings-tab" data-tab-panel="tracking">
                    <table class="form-table" role="presentation">
                        <tbody>
                            <tr>
                                <th scope="row"><?php esc_html_e( 'Cart data', 'live-carts-for-woocommerce' ); ?></th>
                                <td>
                                    <?php if ( has_action( 'phplugins_live_carts_settings_tracking_cart_data' ) ): do_action( 'phplugins_live_carts_settings_tracking_cart_data' ); else: ?>
                                    <div class="berrypress-field berrypress-mb-3 phplugins-lc-pro-field">
                                        <input type="checkbox" disabled>
                                        <label>
                                            <?php esc_html_e( 'Enable cart revisions', 'live-carts-for-woocommerce' ); ?>
                                            <span class="phplugins-lc-pro-badge"><?php esc_html_e( 'Pro', 'live-carts-for-woocommerce' ); ?></span>
                                            <span class="berrypress-doc-note" data-bp-tooltip="<?php esc_attr_e( 'Keep a history of changes made to each cart.', 'live-carts-for-woocommerce' ); ?>" tabindex="0"><i class="berrypress-icon-info"></i></span>
                                        </label>
                                    </div>
                                    <?php endif; ?>

                                    <div class="berrypress-field berrypress-mb-3">
                                        <input type="checkbox" name="phplugins_carts_collect_ip" id="phplugins_carts_collect_ip"
                                               value="1"<?php checked( ! get_option( 'phplugins_carts_no_ip' ) ); ?>>
                                        <label for="phplugins_carts_collect_ip">
                                            <?php esc_html_e( 'Collect site visitors\' IP addresses', 'live-carts-for-woocommerce' ); ?>
                                        </label>
                                    </div>

                                    <div class="berrypress-field berrypress-mb-3">
                                        <input type="checkbox" name="phplugins_carts_collect_url" id="phplugins_carts_collect_url"
                                               value="1"<?php checked( ! get_option( 'phplugins_carts_no_url' ) ); ?>>
                                        <label for="phplugins_carts_collect_url">
                                            <?php esc_html_e( 'Collect visited URLs', 'live-carts-for-woocommerce' ); ?>
                                        </label>
                                    </div>

                                    <div class="berrypress-field">
                                        <input type="checkbox" name="phplugins_carts_track_cart_emails" id="phplugins_carts_track_cart_emails"
                                               value="1"<?php checked( !get_option( 'phplugins_carts_no_track_cart_emails' ) ); ?>
                                               aria-controls="phplugins-lc-email-source-wrap">
                                        <label for="phplugins_carts_track_cart_emails">
                                            <?php esc_html_e( 'Track cart emails', 'live-carts-for-woocommerce' ); ?>
                                            <span class="berrypress-doc-note" data-bp-tooltip="<?php esc_attr_e( 'Track email addresses entered by shoppers at checkout. Enables cart recovery and customer identification for abandoned carts.', 'live-carts-for-woocommerce' ); ?>" tabindex="0"><i class="berrypress-icon-info"></i></span>
                                        </label>
                                    </div>

                                    <div id="phplugins-lc-email-source-wrap" class="phplugins-lc-email-source-wrap"<?php echo get_option( 'phplugins_carts_no_track_cart_emails' ) ? ' hidden' : ''; ?>>
                                        <div class="berrypress-field berrypress-align-items-center">
                                            <label for="phplugins_carts_email_precedence"><?php esc_html_e( 'Cart email source', 'live-carts-for-woocommerce' ); ?></label>
                                            <div>
                                                <select name="phplugins_carts_email_precedence" id="phplugins_carts_email_precedence">
                                                    <option value="user"<?php selected( $precedence, 'user' ); ?>><?php esc_html_e( 'User account email', 'live-carts-for-woocommerce' ); ?></option>
                                                    <option value="entered"<?php selected( $precedence, 'entered' ); ?>><?php esc_html_e( 'Email entered at checkout', 'live-carts-for-woocommerce' ); ?></option>
                                                </select>
                                                <span class="berrypress-doc-note" data-bp-tooltip="<?php esc_attr_e( 'When a logged-in user\'s account email and an email entered at checkout are both available, which one to use.', 'live-carts-for-woocommerce' ); ?>" tabindex="0"><i class="berrypress-icon-info"></i></span>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php esc_html_e( 'Cart details', 'live-carts-for-woocommerce' ); ?></th>
                                <td>
                                    <div class="berrypress-field">
                                        <input type="checkbox" name="phplugins_carts_show_recent_orders" id="phplugins_carts_show_recent_orders"
                                               value="1"<?php checked( ! get_option( 'phplugins_carts_hide_recent_orders' ) ); ?>>
                                        <label for="phplugins_carts_show_recent_orders">
                                            <?php esc_html_e( 'Show recent orders on the cart detail page', 'live-carts-for-woocommerce' ); ?>
                                        </label>
                                    </div>
                                    <?php do_action( 'phplugins_live_carts_settings_tracking_cart_details' ); ?>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php esc_html_e( 'Exclude from tracking', 'live-carts-for-woocommerce' ); ?></th>
                                <td>
                                    <div class="berrypress-field">
                                        <input type="checkbox" name="phplugins_carts_logged_in_only" id="phplugins_carts_logged_in_only"
                                               value="1"<?php checked( get_option( 'phplugins_carts_logged_in_only' ) ); ?>>
                                        <label for="phplugins_carts_logged_in_only">
                                            <?php esc_html_e( 'Guest visitors', 'live-carts-for-woocommerce' ); ?>
                                        </label>
                                    </div>

                                    <?php if ( has_action( 'phplugins_live_carts_settings_tracking_cart_exclusions' ) ): do_action( 'phplugins_live_carts_settings_tracking_cart_exclusions' ); else: ?>
                                    <div class="berrypress-field berrypress-mt-3 phplugins-lc-pro-field">
                                        <label>
                                            <?php esc_html_e( 'Users with role', 'live-carts-for-woocommerce' ); ?>:
                                            <span class="phplugins-lc-pro-badge"><?php esc_html_e( 'Pro', 'live-carts-for-woocommerce' ); ?></span>
                                        </label>
                                    </div>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        </tbody>
                    </table>

                    <?php do_action( 'phplugins_live_carts_settings_tracking' ); ?>

                </div><!-- /tracking -->

                <!-- Lifecycle tab -->
                <div class="phplugins-lc-settings-tab" data-tab-panel="lifecycle">

                    <table class="form-table" role="presentation">
                        <tbody>
                            <tr>
                                <th scope="row"><?php esc_html_e( 'Cart lifecycle', 'live-carts-for-woocommerce' ); ?></th>
                                <td>
                                    <?php if ( has_action( 'phplugins_live_carts_settings_lifecycle_cart_lifecycle' ) ): do_action( 'phplugins_live_carts_settings_lifecycle_cart_lifecycle' ); else: ?>
                                    <div class="berrypress-field berrypress-mb-3 phplugins-lc-pro-field berrypress-align-items-center">
                                        <label><?php esc_html_e( 'Mark carts as abandoned after', 'live-carts-for-woocommerce' ); ?></label>
                                        <div>
                                            <input type="number" value="<?php echo( (float) apply_filters('phplugins_live_carts_abandon_time', LiveCarts::CART_ABANDON_TIME) / 3600 ); ?>" disabled>
                                            <span><?php esc_html_e( 'hours', 'live-carts-for-woocommerce' ); ?></span>
                                            <span class="phplugins-lc-pro-badge"><?php esc_html_e( 'Pro', 'live-carts-for-woocommerce' ); ?></span>
                                        </div>
                                    </div>
                                    <div class="berrypress-field phplugins-lc-pro-field berrypress-align-items-center">
                                        <label><?php esc_html_e( 'Inactive cart is archived after', 'live-carts-for-woocommerce' ); ?></label>
                                        <div>
                                            <input type="number" value="<?php echo( (float) apply_filters('phplugins_live_carts_archive_time', LiveCarts::CART_ARCHIVE_DAYS * 86400) / 86400 ); ?>" disabled>
                                            <span><?php esc_html_e( 'days', 'live-carts-for-woocommerce' ); ?></span>
                                            <span class="phplugins-lc-pro-badge"><?php esc_html_e( 'Pro', 'live-carts-for-woocommerce' ); ?></span>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <?php esc_html_e( 'Conversion statuses', 'live-carts-for-woocommerce' ); ?>
                                    <span class="berrypress-doc-note" data-bp-tooltip="<?php esc_attr_e( 'Live Carts marks a cart as converted when its linked WooCommerce order reaches one of the configured order statuses. Change this setting if your carts are not marked as converted by default, or you are using custom order statuses.', 'live-carts-for-woocommerce' ); ?>" tabindex="0"><i class="berrypress-icon-info"></i></span>
                                </th>
                                <td>
                                    <div class="phplugins-lc-conversion-statuses">
                                        <p class="description">
                                            <?php
                                            printf(
                                            /* translators: %s: comma-separated list of automatically detected order statuses */
                                                esc_html__( 'Currently the cart is marked as converted when the order reaches statuses: %s.', 'live-carts-for-woocommerce' ),
                                                esc_html( implode( ', ', $automaticConversionStatusLabels ) )
                                            );
                                            ?>
                                        </p>
                                        <div class="berrypress-field phplugins-lc-conversion-status-mode">
                                            <input type="checkbox" name="phplugins_carts_conversion_status_mode" id="phplugins_carts_conversion_status_mode"
                                                   value="custom" aria-controls="phplugins-carts-manual-conversion-statuses"<?php checked( ! $isAutomaticMode ); ?>>
                                            <label for="phplugins_carts_conversion_status_mode"><?php esc_html_e( 'Choose order statuses manually', 'live-carts-for-woocommerce' ); ?></label>
                                        </div>

                                        <div id="phplugins-carts-manual-conversion-statuses"<?php echo $isAutomaticMode ? ' hidden' : ''; ?>>
                                            <ul id="phplugins-carts-conversion-statuses" class="phplugins-lc-group-options-grid" role="group"
                                                aria-label="<?php esc_attr_e( 'Order statuses that mark a cart as converted', 'live-carts-for-woocommerce' ); ?>">
                                                <?php foreach ( $orderStatuses as $statusKey => $statusLabel ) {
                                                    $statusSlug = LiveCarts::normalizeOrderStatusSlug( $statusKey );
                                                    ?>
                                                <li class="berrypress-field">
                                                    <input type="checkbox"
                                                           name="phplugins_carts_conversion_order_statuses[]"
                                                           id="phplugins_carts_conversion_order_status_<?php echo esc_attr( $statusSlug ); ?>"
                                                           value="<?php echo esc_attr( $statusSlug ); ?>"
                                                           data-automatic-selected="<?php echo in_array( $statusSlug, $automaticConversionStatuses, true ) ? '1' : '0'; ?>"
                                                           data-custom-selected="<?php echo in_array( $statusSlug, $savedConversionStatuses, true ) ? '1' : '0'; ?>"
                                                           <?php checked( in_array( $statusSlug, $selectedConversionStatuses, true ) ); ?>
                                                           <?php disabled( $isAutomaticMode ); ?>>
                                                    <label for="phplugins_carts_conversion_order_status_<?php echo esc_attr( $statusSlug ); ?>"><?php echo esc_html( $statusLabel ); ?></label>
                                                </li>
                                                <?php } ?>
                                            </ul>
                                            <p class="description phplugins-lc-conversion-status-warning">
                                                <?php esc_html_e( 'Selecting Pending payment, Failed, or Cancelled may mark carts as converted before payment is accepted.', 'live-carts-for-woocommerce' ); ?>
                                            </p>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>

					<?php do_action( 'phplugins_live_carts_settings_lifecycle' ); ?>
					<?php do_action( 'phplugins_live_carts_settings_general' ); // @deprecated — use phplugins_live_carts_settings_lifecycle or per-tab hooks ?>

                </div><!-- /lifecycle -->

                <!-- Display tab -->
                <div class="phplugins-lc-settings-tab" data-tab-panel="display">

                    <table class="form-table" role="presentation">
                        <tbody>
                            <tr>
                                <th scope="row">
                                    <?php esc_html_e( 'Cart ID', 'live-carts-for-woocommerce' ); ?>
                                    <span class="berrypress-doc-note" data-bp-tooltip="<?php esc_attr_e( 'Note: It may be necessary to refresh the page after adding the first item to cart to see the cart ID. The setting automatically appends the cart ID to the WooCommerce cart page and mini-cart.', 'live-carts-for-woocommerce' ); ?>" tabindex="0"><i class="berrypress-icon-info"></i></span>
                                    <a href="https://berrypress.com/docs/live-carts-for-woocommerce/settings/display-settings/"
                                       target="_blank"
                                       class="phplugins-lc-docs-link"
                                       data-bp-tooltip="<?php esc_attr_e( 'View documentation', 'live-carts-for-woocommerce' ); ?>"
                                       aria-label="<?php esc_attr_e( 'Cart ID documentation', 'live-carts-for-woocommerce' ); ?>"><i class="berrypress-icon-external-link" aria-hidden="true"></i></a>
                                </th>
                                <td>
                                    <div class="berrypress-field">
                                        <input type="checkbox" name="phplugins_carts_show_id" id="phplugins_carts_show_id"
                                               value="1"<?php checked( $showCartId ); ?>>
                                        <label for="phplugins_carts_show_id">
                                            <?php esc_html_e( 'Show cart ID on the frontend', 'live-carts-for-woocommerce' ); ?>
                                        </label>
                                    </div>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php esc_html_e( 'Cart ID Shortcode', 'live-carts-for-woocommerce' ); ?></th>
                                <td>
                                    <div class="berrypress-field phplugins-cart-shortcode-field" id="phplugins-cart-shortcode-field">
                                        <div class="phplugins-cart-shortcode">
                                            <p class="description">
                                                <?php esc_html_e( 'Use this shortcode to display the current cart ID anywhere on your site (e.g. on a Contact Us or Support page).', 'live-carts-for-woocommerce' ); ?>
                                            </p>
                                            <div class="berrypress-flex-base berrypress-flex-wrap">
                                                <code id="phplugins-cart-id-shortcode">[berrypress_live_cart_id]</code>
                                                <button type="button" class="berrypress-btn berrypress-btn-secondary" id="phplugins-copy-shortcode"
                                                        data-shortcode="[berrypress_live_cart_id]"
                                                        data-label-copied="<?php esc_attr_e( 'Copied!', 'live-carts-for-woocommerce' ); ?>">
                                                    <?php esc_html_e( 'Copy', 'live-carts-for-woocommerce' ); ?>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>

					<?php do_action( 'phplugins_live_carts_settings_display' ); ?>

                </div><!-- /display -->

                <!-- Email tab -->
                <div class="phplugins-lc-settings-tab" data-tab-panel="email">

                    <table class="form-table berrypress-mb-3" role="presentation">
                        <tbody>
                            <tr>
                                <th scope="row"><?php esc_html_e( 'Recovery email sender', 'live-carts-for-woocommerce' ); ?></th>
                                <td>
									<?php
									$senderName  = get_option( 'phplugins_carts_recovery_sender_name', '' );
									$senderEmail = get_option( 'phplugins_carts_recovery_sender_email', '' );
									?>
                                    <div class="berrypress-field berrypress-align-items-center">
                                        <label for="phplugins_carts_recovery_sender_name"><?php esc_html_e( 'Name', 'live-carts-for-woocommerce' ); ?></label>
                                        <input type="text"
                                               name="phplugins_carts_recovery_sender_name"
                                               id="phplugins_carts_recovery_sender_name"
                                               value="<?php echo esc_attr( $senderName ); ?>"
                                               placeholder="<?php echo esc_attr( class_exists('WC_Emails') ? \WC_Emails::instance()->get_from_name() : '' ); ?>"
                                               class="regular-text">
                                    </div>
                                    <div class="berrypress-field berrypress-align-items-center">
                                        <label for="phplugins_carts_recovery_sender_email"><?php esc_html_e( 'Email', 'live-carts-for-woocommerce' ); ?></label>
                                        <input type="email"
                                               name="phplugins_carts_recovery_sender_email"
                                               id="phplugins_carts_recovery_sender_email"
                                               value="<?php echo esc_attr( $senderEmail ); ?>"
                                               placeholder="<?php echo esc_attr( class_exists('WC_Emails') ? \WC_Emails::instance()->get_from_address() : '' ); ?>"
                                               class="regular-text">
                                    </div>
                                    <p class="description berrypress-mt-1">
										<?php esc_html_e( 'Leave empty to use the site name and admin email.', 'live-carts-for-woocommerce' ); ?>
                                    </p>
                                </td>
                            </tr>
                        </tbody>
                    </table>

                    <div class="berrypress-mb-3">
                        <p class="description">
                            <?php
                            printf(
                            /* translators: %s: link to Recovery Emails management page */
                                esc_html__( 'For guest visitors, enable "Track cart emails" on the Tracking tab. Recovery email sequences are managed separately. %s ', 'live-carts-for-woocommerce' ),
                                '<a href="' . esc_url( self::getUrl( [ 'recovery_emails' => 1 ] ) ) . '">' . esc_html__( 'Manage recovery emails →', 'live-carts-for-woocommerce' ) . '</a>'
                            );
                            ?>
                        </p>
                    </div>

                    <?php do_action( 'phplugins_live_carts_settings_email' ); ?>

                </div><!-- /email -->
				
                <!-- Integrations tab -->
                <div class="phplugins-lc-settings-tab phplugins-lc-settings-tab-integrations" data-tab-panel="integrations">

					<div class="phplugins-lc-integration-grid">
					<div class="phplugins-lc-integration-card">
						<img class="phplugins-lc-integration-logo"
							 src="<?php echo esc_url( plugin_dir_url( dirname( __DIR__ ) . '/live-carts-for-woocommerce.php' ) . 'assets/img/docsbot-logo.png' ); ?>"
							 alt="" width="56" height="56">
						<div class="phplugins-lc-integration-body">
							<h3 class="phplugins-lc-integration-title">
								<?php esc_html_e( 'DocsBot', 'live-carts-for-woocommerce' ); ?>
								<span class="phplugins-lc-tag"><?php esc_html_e( 'AI chatbot', 'live-carts-for-woocommerce' ); ?></span>
							</h3>
							<p class="description">
								<?php
									printf(
										// translators: 1,2: open/close link to docsbot.ai; 3,4: open/close link to the DocsBot plugin on wordpress.org
										esc_html__( 'Use %1$sDocsBot%2$s to boost your sales by getting potential customers the answers they need, instantly. Once the %3$sDocsBot plugin%4$s is installed, enable this integration to include the current cart ID in your DocsBot widget configuration so it can be logged in conversation history and used with the Live Carts skill. Only works if your bot is private.', 'live-carts-for-woocommerce' ),
										'<strong><a href="https://docsbot.ai?via=berrypress" target="_blank" rel="noopener sponsored">',
										'</a></strong>',
										'<a href="https://wordpress.org/plugins/docsbot/" target="_blank" rel="noopener">',
										'</a>'
									);
								?>
							</p>
							<div class="berrypress-field phplugins-lc-integration-toggle">
								<input type="checkbox" name="phplugins_carts_docsbot_on" id="phplugins_carts_docsbot_on"
									   value="1"<?php checked( get_option( 'phplugins_carts_docsbot_on' ) ); ?>>
								<label for="phplugins_carts_docsbot_on">
									<?php esc_html_e( 'Enable DocsBot integration', 'live-carts-for-woocommerce' ); ?>
								</label>
							</div>
						</div>
					</div>
					<?php // Future integrations: add more .phplugins-lc-integration-card blocks here. ?>
					</div><!-- /integration-grid -->

                    <?php do_action( 'phplugins_live_carts_settings_integrations' ); ?>
					
					<p class="description"><?php esc_html_e( 'This page may contain affiliate links, from which BerryPress may earn a commission.', 'live-carts-for-woocommerce' ); ?></p>

                </div><!-- /integrations -->

                <!-- API tab -->
                <div class="phplugins-lc-settings-tab phplugins-lc-settings-tab-api" data-tab-panel="api">

					<table class="form-table berrypress-mb-3" role="presentation">
						<tbody>
							<tr>
								<th scope="row"><?php esc_html_e( 'API', 'live-carts-for-woocommerce' ); ?></th>
								<td>
									<div class="berrypress-field">
										<input type="checkbox" name="phplugins_carts_rest_api" id="phplugins_carts_rest_api"
											   value="1"<?php checked( get_option( 'phplugins_carts_rest_api' ) ); ?>>
										<label for="phplugins_carts_rest_api">
											<?php esc_html_e( 'Enable REST API', 'live-carts-for-woocommerce' ); ?>
										</label>
									</div>
									<div class="berrypress-field">
										<input type="checkbox" name="phplugins_carts_webhooks_on" id="phplugins_carts_webhooks_on"
											   value="1"<?php checked( get_option( 'phplugins_carts_webhooks_on' ) ); ?>>
										<label for="phplugins_carts_webhooks_on">
											<?php esc_html_e( 'Enable webhooks', 'live-carts-for-woocommerce' ); ?>
										</label>
									</div>
								</td>
							</tr>
						</tbody>
					</table>

                    <?php do_action( 'phplugins_live_carts_settings_api' ); ?>

                </div><!-- /api -->


                

                <button class="berrypress-btn berrypress-btn-primary"><?php esc_html_e( 'Save Settings', 'live-carts-for-woocommerce' ); ?></button>
            </form>

			<?php do_action( 'phplugins_live_carts_settings_page' ); ?>
        </div>
		<?php
	}

	public function statusPage() {
		global $wpdb;
		$checks = [];

		$checks[] = [
			'label' => __( 'Plugin version', 'live-carts-for-woocommerce' ),
			'value' => LiveCarts::VERSION,
			'status' => 'ok',
		];
		
		$wpVer = get_bloginfo( 'version' );
		$checks[] = [
			'label' => __( 'WordPress version', 'live-carts-for-woocommerce' ),
			'value' => $wpVer,
			'status' => version_compare( $wpVer, '6.2', '>=' ) ? 'ok' : 'warning',
		];

		$checks[] = [
			'label' => __( 'PHP version', 'live-carts-for-woocommerce' ),
			'value' => PHP_VERSION,
			'status' => version_compare( PHP_VERSION, '8.0', '>=' ) ? 'ok' : 'warning',
		];

		$checks[] = [
			'label' => __( 'WooCommerce version', 'live-carts-for-woocommerce' ),
			'value' => defined( 'WC_VERSION' ) ? WC_VERSION : __( 'Not active', 'live-carts-for-woocommerce' ),
			'status' => defined( 'WC_VERSION' ) && version_compare( WC_VERSION, '7.0', '>=' ) ? 'ok' : 'error',
		];

		$conversionStatusConfig = LiveCarts::instance()->getConversionOrderStatusConfiguration();
		$checks[] = [
			'label' => __( 'Cart conversion order statuses', 'live-carts-for-woocommerce' ),
			'value' => empty( $conversionStatusConfig['statuses'] ) ? __( 'None', 'live-carts-for-woocommerce' ) : implode( ', ', $conversionStatusConfig['statuses'] ),
			'status' => empty( $conversionStatusConfig['statuses'] ) ? 'warning' : 'ok',
		];
		$checks[] = [
			'label' => __( 'Cart conversion status source', 'live-carts-for-woocommerce' ),
			'value' => 'custom' === $conversionStatusConfig['source'] ? __( 'Custom selection', 'live-carts-for-woocommerce' ) : __( 'WooCommerce automatic', 'live-carts-for-woocommerce' ),
			'status' => 'ok',
		];

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$allTables = $wpdb->get_col( 'SHOW TABLES' );
		$requiredTables = [ 'phplugins_carts', 'phplugins_cart_contents', 'phplugins_cart_contents_items' ];
		foreach ( $requiredTables as $table ) {
			$fullName = $wpdb->prefix . $table;
			$exists = in_array( $fullName, $allTables );
			$checks[] = [
				'label' => sprintf( /* translators: %s: database table name */ __( 'Table: %s', 'live-carts-for-woocommerce' ), $table ),
				'value' => $exists ? __( 'Exists', 'live-carts-for-woocommerce' ) : __( 'Missing', 'live-carts-for-woocommerce' ),
				'status' => $exists ? 'ok' : 'error',
			];
		}

		$migrationNeeded = LiveCarts::isMigrationNeeded();
		$checks[] = [
			'label' => __( 'Database migration (1.2.0)', 'live-carts-for-woocommerce' ),
			'value' => $migrationNeeded ? __( 'Incomplete', 'live-carts-for-woocommerce' ) : __( 'Complete', 'live-carts-for-woocommerce' ),
			'status' => $migrationNeeded ? 'error' : 'ok',
		];

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$totalCarts = $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'phplugins_carts' );
		$checks[] = [
			'label' => __( 'Total carts in database', 'live-carts-for-woocommerce' ),
			'value' => number_format_i18n( (int) $totalCarts ),
			'status' => 'ok',
		];

		$cronScheduled = wp_next_scheduled( 'phplugins_livecarts_hourly' );
		$checks[] = [
			'label' => __( 'Scheduled tasks (WP Cron)', 'live-carts-for-woocommerce' ),
			'value' => $cronScheduled
				? sprintf( /* translators: %s: next run time */ __( 'Scheduled (next: %s)', 'live-carts-for-woocommerce' ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $cronScheduled ) )
				: __( 'Not scheduled', 'live-carts-for-woocommerce' ),
			'status' => $cronScheduled >= time() ? 'ok' : 'warning',
		];

		$pluginDirWritable = wp_is_writable( plugin_dir_path( __DIR__ ) );
		$checks[] = [
			'label' => __( 'Plugin directory writable', 'live-carts-for-woocommerce' ),
			'value' => $pluginDirWritable ? __( 'Yes', 'live-carts-for-woocommerce' ) : __( 'No', 'live-carts-for-woocommerce' ),
			'status' => $pluginDirWritable ? 'ok' : 'warning',
		];

		$checks = apply_filters( 'phplugins_live_carts_status_checks', $checks );
		?>
		<div id="phplugins-live-carts-health-status" class="wrap">
			<h2></h2>
			<h1><?php esc_html_e( 'Status', 'live-carts-for-woocommerce' ); ?></h1>
            <div class="berrypress-mb-4">
                <h2 class="berrypress-fw-medium"><?php esc_html_e( 'Health Check', 'live-carts-for-woocommerce' ); ?></h2>
                <table class="berrypress-table phplugins-live-carts-table-status">
                    <tbody>
                    <?php
                    foreach ( $checks as $check ) {
                        ?>
                        <tr>
                            <th scope="row"><label><?php echo esc_html( $check['label'] ); ?></label></th>
                            <?php
                            $badgeColors = [ 'ok' => 'green', 'warning' => 'yellow', 'error' => 'red' ];
                            $badgeClass = 'phplugins-live-carts-status-' . ( $badgeColors[ $check['status'] ?? '' ] ?? 'green' );
                            ?>
                            <td class="phplugins-live-carts-status <?php echo esc_attr( $badgeClass ); ?>"><?php echo esc_html( $check['value'] ); ?></td>
                        </tr>
                    <?php } ?>
                    </tbody>
                </table>

                <?php
                $migrationSuccess = get_transient( 'phplugins_carts_migration_success' );
                if ( $migrationSuccess ) {
                    delete_transient( 'phplugins_carts_migration_success' );
                ?>
                <div class="berrypress-notice berrypress-notice-success">
                    <span class="berrypress-notice-icon"><i class="berrypress-icon-check"></i></span>
                    <div>
                        <p><?php esc_html_e( 'Database migration completed successfully.', 'live-carts-for-woocommerce' ); ?></p>
                    </div>
                </div>
                <?php } ?>

                <?php
                $migrationErrors = get_transient( 'phplugins_carts_migration_errors' );
                if ( $migrationErrors ) {
                    delete_transient( 'phplugins_carts_migration_errors' );
                ?>
                <div class="berrypress-notice berrypress-notice-error">
                    <span class="berrypress-notice-icon"><i class="berrypress-icon-warning"></i></span>
                    <div>
                        <strong><?php esc_html_e( 'Migration failed with the following errors:', 'live-carts-for-woocommerce' ); ?></strong>
                        <ul>
                            <?php foreach ( $migrationErrors as $error ) { ?>
                                <li><code><?php echo esc_html( $error ); ?></code></li>
                            <?php } ?>
                        </ul>
                    </div>
                </div>
                <?php } ?>

                <?php
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display, capability verified in onCurrentScreen
				$forceMigrationNotice = isset( $_GET['migrate'] ) && sanitize_text_field( wp_unslash( $_GET['migrate'] ) ) === '1_2_0';
				?>
                <?php if ( $migrationNeeded || $forceMigrationNotice ) { ?>
                <div class="berrypress-notice berrypress-notice-error">
                    <span class="berrypress-notice-icon"><i class="berrypress-icon-error_outline "></i></span>
                    <div>
                        <p>
                            <?php esc_html_e( 'The database migration has not completed. This may cause cart details to not display correctly.', 'live-carts-for-woocommerce' ); ?>
                            <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-ajax.php?action=phplugins_carts_rerun_migration' ), 'phplugins_carts_rerun_migration' ) ); ?>" ><?php esc_html_e( 'Re-run migration', 'live-carts-for-woocommerce' ); ?></a>
                        </p>

                    </div>
                </div>
                <?php } ?>
            </div>
            <span class="berrypress-seperator"></span>
            <div>
			<h2 class="berrypress-fw-medium berrypress-mt-4 berrypress-mb-4"><?php esc_html_e( 'Debug Log', 'live-carts-for-woocommerce' ); ?></h2>
			<form action="" method="post">
                <div class="berrypress-mb-3">
				<?php wp_nonce_field( 'phplugins-carts-settings-save', 'phplugins_carts_settings_save' ); ?>

				<div class="berrypress-field">
					<input type="checkbox" name="phplugins_carts_debug" id="phplugins_carts_debug"
						   value="1"<?php checked( get_option( 'phplugins_carts_debug' ) !== false ); ?>>
					<label for="phplugins_carts_debug">
						<?php esc_html_e( 'Enable debug mode', 'live-carts-for-woocommerce' ); ?>
					</label>
				</div>

                <div class="berrypress-field berrypress-mb-2">
                    <input type="checkbox" name="phplugins_carts_debug_clear" id="phplugins_carts_debug_clear" value="1">
                    <label for="phplugins_carts_debug_clear"><?php esc_html_e( 'Clear log on save', 'live-carts-for-woocommerce' ); ?></label>
                </div>


                <div class="berrypress-field berrypress-mb-2 berrypress-align-items-center" id="phplugins-carts-debug-level-field" style="<?php echo get_option( 'phplugins_carts_debug' ) === false ? 'display:none' : ''; ?>">
					<label for="phplugins_carts_debug_level"><?php esc_html_e( 'Log level', 'live-carts-for-woocommerce' ); ?></label>
					<select name="phplugins_carts_debug_level" id="phplugins_carts_debug_level">
						<option value="errors"<?php selected( get_option( 'phplugins_carts_debug_level', 'errors' ), 'errors' ); ?>><?php esc_html_e( 'Errors only', 'live-carts-for-woocommerce' ); ?></option>
						<option value="all"<?php selected( get_option( 'phplugins_carts_debug_level', 'errors' ), 'all' ); ?>><?php esc_html_e( 'All (verbose)', 'live-carts-for-woocommerce' ); ?></option>
					</select>
				</div>

				<?php
				$debugConversionStatusConfig = LiveCarts::instance()->getConversionOrderStatusConfiguration();
				$debugConversionStatusesLabel = empty( $debugConversionStatusConfig['statuses'] )
					? __( 'None', 'live-carts-for-woocommerce' )
					: implode( ', ', $debugConversionStatusConfig['statuses'] );
				$debugConversionSourceLabel = 'custom' === $debugConversionStatusConfig['source']
					? __( 'Custom selection', 'live-carts-for-woocommerce' )
					: __( 'WooCommerce automatic', 'live-carts-for-woocommerce' );
				?>
				<p class="description">
					<?php
					printf(
						/* translators: 1: comma-separated order statuses, 2: configuration source */
						esc_html__( 'Effective conversion statuses: %1$s (source: %2$s). Verbose debug entries include conversion_statuses and conversion_source.', 'live-carts-for-woocommerce' ),
						esc_html( $debugConversionStatusesLabel ),
						esc_html( $debugConversionSourceLabel )
					);
					?>
				</p>

				<?php
				$debugLogContents = LiveCarts::instance()->getDebugLogContents();
				if ( $debugLogContents ) {
					$debugLogPath = LiveCarts::instance()->getDebugLogPath();
					$debugLogSize = $debugLogPath && file_exists( $debugLogPath ) ? size_format( filesize( $debugLogPath ) ) : '';
				?>
				<div class="berrypress-field berrypress-mb-4">
					<label><?php esc_html_e( 'Log output', 'live-carts-for-woocommerce' ); ?></label>
					<div>
                        <textarea class="phplugins-lc-debug-log" readonly rows="14" ><?php echo esc_textarea( $debugLogContents ); ?></textarea>
                        <p>
                            <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-ajax.php?action=phplugins_carts_download_debug_log' ), 'phplugins_carts_download_debug_log' ) ); ?>" class="berrypress-link"><?php
                                esc_html_e( 'Download full log', 'live-carts-for-woocommerce' );
                                if ( $debugLogSize ) {
                                    echo ' (' . esc_html( $debugLogSize ) . ')';
                                }
                                ?></a>
                        </p>
                    </div>
				</div>
				<?php } ?>

                </div>

				<button class="berrypress-btn berrypress-btn-primary"><?php esc_html_e( 'Save', 'live-carts-for-woocommerce' ); ?></button>
			</form>
            </div>

        </div>
		<?php
	}

	public function cartPage() {
		global $wpdb;
		$statuses = LiveCarts::instance()->getCartStatuses();
		$isList = $this->isListView();
		$backUrl = $this->getListBackUrl();
		$backLabel = $isList
			? __( 'Back to shopper lists', 'live-carts-for-woocommerce' )
			: __( 'Back to carts list', 'live-carts-for-woocommerce' );

		if ( ! empty( $this->cartData ) ) {
			if ( ! empty( $this->cartData->user_id ) ) {
				$user = get_userdata( $this->cartData->user_id );
				if ( $user ) {
					$userDisplayName = trim( $user->first_name . ' ' . $user->last_name );
					if ( empty( $userDisplayName ) ) {
						$userDisplayName = $user->user_login;
					}
				}
			}
			// Check if migration has been completed
			if ( ! empty( $this->cartData->contents_id ) && get_option('phplugins_carts_migration_1_2_0_complete') ) {
				// Load cart items from the new table structure
				$contents = LiveCarts::getCartContentsArray($this->cartData->contents_id);
				$history = LiveCarts::getCartContentsHistory($this->cartData->cart_id);
			}
			if ( ! empty( $this->cartData->order_id ) ) {
				$order = wc_get_order( $this->cartData->order_id );
			}
			
			if ( ! $isList && $this->cartData->status !== 'converted' ) {
				$recoveryUrl = add_query_arg( 'recover_cart', empty($this->cartData->recovery_key) ? LiveCarts::generateCartRecoveryKey($this->cartData->cart_id) : $this->cartData->recovery_key, wc_get_cart_url() );
			}
		}
		?>
        <div id="phplugins-live-carts-details" class="wrap">
        <h2></h2>
		<?php $this->renderCartActionNotices(); ?>
        <div class="berrypress-page-content-header berrypress-lc-page-content-header">
            <div class="berrypress-lc-page-content-header-inner">
                <a href="<?php echo( esc_url( $backUrl ) ); ?>" class="berrypress-btn-go-back"
                   title="Go Back">
                    <i class="berrypress-icon-keyboard_arrow_left"></i>
                    <span class="berrypress-visually-hidden"><?php echo esc_html( $backLabel ); ?></span>
                </a>
                <?php if ( empty( $this->cartData ) ) { ?>
                <h1><?php echo esc_html( $isList ? __( 'List Not Found', 'live-carts-for-woocommerce' ) : __( 'Cart Not Found', 'live-carts-for-woocommerce' ) ); ?></h1>
            </div>
		<?php } else { ?>
            <h1>
				<?php
				if ( $isList ) {
					// translators: %s is the list ID
					echo( esc_html( sprintf( __( 'List #%s', 'live-carts-for-woocommerce' ), LiveCarts::formatCartId( $this->cartData->cart_id ) ) ) );
				} else {
					// translators: %s is the cart ID
					echo( esc_html( sprintf( __( 'Cart #%s', 'live-carts-for-woocommerce' ), LiveCarts::formatCartId( $this->cartData->cart_id ) ) ) );
				}
				?>
			</h1>
            </div>
			<?php $this->renderCartDetailHeaderActions(); ?>
        </div>
        <div class="phplugins-lc-detail-layout">
            <div id="phplugins-live-carts-details-card">
                <table class="form-table phplugins-lc-table-2col">
                    <tbody>
                    <tr>
                        <th scope="row">
                            <label><?php esc_html_e( 'Created at', 'live-carts-for-woocommerce' ); ?></label>
                        </th>
                        <td>
							<?php echo( esc_html( LiveCarts::instance()->formatGmtDatetime( $this->cartData->created ) ) ); ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label><?php esc_html_e( 'Last seen', 'live-carts-for-woocommerce' ); ?></label>
                        </th>
                        <td>
							<?php
							echo(
							$this->cartData->last_url
								? sprintf(
							// translators: first %s is a timestamp, second %s is a URL
								esc_html__( '%1$s at %2$s', 'live-carts-for-woocommerce' ),
								esc_html( LiveCarts::instance()->formatGmtDatetime( $this->cartData->last_seen ) ),
								'<a href="' . esc_url( $this->cartData->last_url ) . '" target="_blank" rel="noopener noreferrer">' . esc_url( $this->cartData->last_url ) . '</a>'
							)
								: esc_html( LiveCarts::instance()->formatGmtDatetime( $this->cartData->last_seen ) )
							);
							?>
                        </td>
                    </tr>
					<?php if ( $isList ) { ?>
                    <tr>
                        <th scope="row">
                            <label><?php esc_html_e( 'List Type', 'live-carts-for-woocommerce' ); ?></label>
                        </th>
                        <td>
							<?php echo esc_html( ShopperLists::getListTypeLabel( $this->cartData->type ?? '' ) ); ?>
                        </td>
                    </tr>
					<?php } else { ?>
                    <tr>
                        <th scope="row">
                            <label><?php esc_html_e( 'Status', 'live-carts-for-woocommerce' ); ?></label>
                        </th>
						<?php $statusKey = empty( $this->cartData->trashed ) ? $this->cartData->status : 'trashed'; ?>
                        <td class="phplugins-live-carts-status phplugins-live-carts-<?php echo( esc_attr( $statusKey ) ); ?>">
							<?php echo( esc_html( isset( $statuses[ $statusKey ] ) ? $statuses[ $statusKey ] : $statusKey ) ); ?>
                        </td>
                    </tr>
					<?php } ?>
					<?php if ( ! $isList && ! empty( $order ) ) { ?>
                        <tr>
                            <th scope="row">
                                <label><?php esc_html_e( 'Order', 'live-carts-for-woocommerce' ); ?></label>
                            </th>
                            <td>
                                <a href="<?php echo( esc_url( $order->get_edit_order_url() ) ); ?>"
                                   target="_blank"><?php echo( (int) $order->get_id() ); ?></a>
                            </td>
                        </tr>
					<?php } ?>
					<?php if ( isset($recoveryUrl) ) { ?>
                        <tr>
                            <th scope="row">
                                <label><?php esc_html_e( 'Recovery URL', 'live-carts-for-woocommerce' ); ?></label>
                            </th>
                            <td>
                                <a href="<?php echo( esc_url( $recoveryUrl ) ); ?>"
                                   target="_blank"><?php echo( esc_url( $recoveryUrl ) ); ?></a>
                            </td>
                        </tr>
					<?php } ?>
                    <tr>
                        <th scope="row">
                            <label><?php esc_html_e( 'User', 'live-carts-for-woocommerce' ); ?></label>
                        </th>
                        <td>
							<?php echo( empty( $user ) ? esc_html_e( 'Guest/Unknown', 'live-carts-for-woocommerce' ) : '<a href="' . esc_url( get_edit_user_link( $user->ID ) ) . '" target="_blank">' . esc_html( $userDisplayName ) . '</a>' ); ?>
                        </td>
                    </tr>
					<?php if ( ! empty( $this->cartData->email ) ) { ?>
                        <tr>
                            <th scope="row">
                                <label><?php esc_html_e( 'Email', 'live-carts-for-woocommerce' ); ?></label>
                            </th>
                            <td>
								<a href="<?php echo esc_url( 'mailto:' . $this->cartData->email ); ?>"><?php echo esc_html( $this->cartData->email ); ?></a>
                            </td>
                        </tr>
					<?php } ?>
					<?php if ( ! get_option( 'phplugins_carts_no_ip' ) && ! empty( $this->cartData->ip_address ) ) { ?>
                        <tr>
                            <th scope="row">
                                <label><?php esc_html_e( 'IP address', 'live-carts-for-woocommerce' ); ?></label>
                            </th>
                            <td>
								<?php echo( esc_html( $this->cartData->ip_address ) ); ?>
                            </td>
                        </tr>
					<?php } ?>
					<?php if ( ! $isList && $this->cartData->coupon ) { ?>
                        <tr>
                            <th scope="row">
                                <label><?php esc_html_e( 'Cart coupon(s)', 'live-carts-for-woocommerce' ); ?></label>
                            </th>
                            <td>
								<?php
LiveCarts::outputCouponCodeLinks($this->cartData->coupon);
								?>
                            </td>
                        </tr>
					<?php } ?>

                    </tbody>
                </table>

				<?php if ( isset( $contents ) ) { ?>

                <span class="berrypress-seperator"></span>
                <h2><?php echo esc_html( $isList ? __( 'List items', 'live-carts-for-woocommerce' ) : __( 'Cart items', 'live-carts-for-woocommerce' ) ); ?></h2>
			<?php
				$contentsOverride = apply_filters('phplugins_live_carts_admin_cart_contents', null, $this->cartData, $contents);
				if ($contentsOverride === null) {
			?>

                    <div class="phplugins-lc-table-cart berrypress-table-wrapper">
                        <div class="berrypress-table-scroll">
                            <table class="form-table berrypress-table">
                            <thead>
                            <tr>
                                <th><?php esc_html_e( 'Item Name', 'live-carts-for-woocommerce' ); ?></th>
                                <th><?php esc_html_e( 'Quantity', 'live-carts-for-woocommerce' ); ?></th>
                                <th><?php esc_html_e( 'Line Total', 'live-carts-for-woocommerce' ); ?></th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php
							$cartSubtotal = 0;
							$cartDiscount = 0;
                            foreach ( $contents as $item ) {
                                if ( empty( $item['product_id'] ) ) {
                                    unset( $product );
                                } else {
                                    $product = wc_get_product( empty( $item['variation_id'] ) ? $item['product_id'] : $item['variation_id'] );
                                }

								$cartSubtotal += ($item['line_subtotal'] ?? 0);
								$cartDiscount += (isset($item['line_total']) && isset($item['line_subtotal'])) ? $item['line_total'] - $item['line_subtotal'] :  0;
                                ?>
                                <tr>
                                    <td>
                                        <?php if ( empty( $product ) ) { ?>
                                            <?php esc_html_e( 'Unknown item', 'live-carts-for-woocommerce' ); ?>
                                        <?php } else { ?>
                                            <a class="phplugins-lc-product-info" href="<?php echo( esc_url( $product->get_permalink() ) ); ?>" target="_blank">
                                                <?php
                                                echo( get_the_post_thumbnail($product->get_id()).esc_html( $product->get_name() ) );
                                                ?>
                                            </a>
                                            <?php
                                            // Display product attributes/variations
                                            if ( $product && $product->is_type( 'variation' ) ) {
                                                $attributes = $product->get_variation_attributes();
                                                if ( ! empty( $attributes ) ) {
                                                    echo '<div class="phplugins-lc-product-attributes">';
                                                    foreach ( $attributes as $attr_name => $attr_value ) {
                                                        if ( $attr_value ) {
                                                            $taxonomy = str_replace( 'attribute_', '', $attr_name );
                                                            $term = get_term_by( 'slug', $attr_value, $taxonomy );
                                                            $label = wc_attribute_label( $taxonomy );
                                                            $value = $term ? $term->name : $attr_value;
                                                            echo '<span class="phplugins-lc-attribute">';
                                                            echo '<strong>' . esc_html( $label ) . ':</strong> ';
                                                            echo esc_html( $value );
                                                            echo '</span>';
                                                        }
                                                    }
                                                    echo '</div>';
                                                }
                                            }
                                            ?>
                                        <?php } ?>
                                    </td>
                                    <td><?php if ( isset( $item['quantity'] ) ) {
                                            echo( (float) $item['quantity'] );
                                        } ?></td>
                                    <td><?php if ( isset( $item['line_subtotal'] ) ) {
											// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- html output
                                            echo( wc_price( $item['line_subtotal'] ) );
                                        } ?></td>
                                </tr>

                            <?php } ?>

                            </tbody>
                            <tfoot>
                                <tr>
                                    <td>Subtotal:</td>
                                    <td></td>
                                    <td>
                                        <p class="phplugins-lc-total">
                                        <?php

										// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- html output
										echo( wc_price( $cartSubtotal ) );
										?>
                                        </p>
                                    </td>
                                </tr>
								<?php if ( ! $isList ) { ?>
                                <tr>
                                    <td>Discount:</td>
                                    <td></td>
                                    <td>
                                        <p class="phplugins-lc-total">
                                        <?php

										// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- html output
										echo( wc_price( $cartDiscount ) );
										?>
                                        </p>
                                    </td>
                                </tr>
								<?php } ?>
                                <tr>
                                    <td>Total:</td>
                                    <td></td>
                                    <td>
                                        <p class="phplugins-lc-total">
                                        <?php

										// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- html output
										echo( wc_price( $this->cartData->value ) );
										?>
                                        </p>
                                    </td>
                                </tr>
                            </tfoot>
                        </table>
                        </div>
                    </div>

			<?php } else

					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- html output from hook
					echo($contentsOverride); ?>

                <?php } ?>
                <?php do_action( 'phplugins_live_carts_admin_cart_details', $this->cartData->cart_id ); ?>

                <?php
                if ( ! $isList && !get_option( 'phplugins_carts_hide_recent_orders' ) ) {
                    $this->renderCustomerOrders( isset( $order ) ? $order : null );
                }
                ?>
            </div>

            <?php if ( isset( $history ) ) { ?>
                <aside class="phplugins-lc-sidebar">
                    <div class="phplugins-lc-revisions">
                        <h2><?php echo(esc_html(sprintf( /* translators: %d = number of revisions */ _n('History (%d revision)', 'History (%d revisions)', count($history), 'live-carts-for-woocommerce'), count($history)))); ?></h2>

                        <?php foreach ($history as $revision) { ?>
                            <div class="phplugins-lc-revision-item">
                                <h3><?php echo(esc_html(LiveCarts::instance()->formatGmtDatetime($revision[0]))); ?></h3>
                                <ol class="phplugins-lc-diffs">
                                    <?php foreach ($revision[1] as $diff) { ?>
                                        <li class="phplugins-lc-diff-<?php echo(esc_attr($diff[0])); ?>">
                                            <?php
                                            switch ($diff[0]) {
                                                case 'add':
                                                    echo(esc_html(sprintf(
                                                    /* translators: %1$s = product name, %2$d = quantity */
                                                            __('Added "%1$s" x %2$d', 'live-carts-for-woocommerce'),
                                                            $diff[1] ? $diff[1]->get_name() : __('(unknown product)', 'live-carts-for-woocommerce'),
                                                            $diff[2]
                                                    )));
                                                    break;
                                                case 'remove':
                                                    echo(esc_html(sprintf(
                                                    /* translators: %s = product name */
                                                            __('Removed "%s"', 'live-carts-for-woocommerce'),
                                                            $diff[1] ? $diff[1]->get_name() : __('(unknown product)', 'live-carts-for-woocommerce')
                                                    )));
                                                    break;
                                                case 'change-quantity':
                                                    echo(esc_html(sprintf(
                                                    /* translators: %1$s = product name, %2$d = old quantity, %3$d = new quantity */
                                                            __('Changed quantity of "%1$s" from %2$d to %3$d', 'live-carts-for-woocommerce'),
                                                            $diff[1] ? $diff[1]->get_name() : __('(unknown product)', 'live-carts-for-woocommerce'),
                                                            $diff[2],
                                                            $diff[3]
                                                    )));
                                                    break;
                                                case 'recovery-email':
                                                    echo '<i class="berrypress-icon-email" aria-hidden="true"></i>';
                                                    $recoveryEmailTitle = isset( $diff[1] ) ? (string) $diff[1] : '';
                                                    $recoveryEmailId    = isset( $diff[2] ) ? (int) $diff[2] : 0;
                                                    if ( $recoveryEmailTitle === '' ) {
														esc_html_e( 'Recovery email triggered', 'live-carts-for-woocommerce' );
													} else {
														printf(
															// translators: %s is email title, possibly linked
															esc_html__( 'Recovery email triggered: %s', 'live-carts-for-woocommerce' ),
															$recoveryEmailId
																? '<a href="' . esc_url( admin_url( 'admin.php?page=live-carts-for-woocommerce&recovery_emails=1&email_id=' . $recoveryEmailId ) ) . '">' . esc_html( $recoveryEmailTitle ) . '</a>'
																: esc_html( $recoveryEmailTitle )
														);
                                                    }
                                                    break;
                                            }
                                            do_action('phplugins_live_carts_revision_diff_meta', $diff);
                                            ?>
                                        </li>
                                    <?php } ?>
                                </ol>
                            </div>
                        <?php } ?>
                    </div>
                </aside>
            <?php } ?>
            </div>
		<?php } ?>
			<?php $this->renderCartDetailFooterNav( $backUrl, $backLabel ); ?>
        </div>
		<?php
	}

	/**
	 * @return array{prev:?int,next:?int,prevLabel:string,nextLabel:string,navLabel:string}
	 */
	private function getAdjacentCartNavigation() {
		$prevId = null;
		$nextId = null;

		if ( ! empty( $this->cartData ) ) {
			$adjacent = CartsListTable::getAdjacentCartIds(
				(int) $this->cartData->cart_id,
				$this->viewType,
				$this->cartData->last_seen
			);
			$prevId = $adjacent['prev'];
			$nextId = $adjacent['next'];
		}

		$isList = $this->isListView();

		return [
			'prev'      => $prevId,
			'next'      => $nextId,
			'prevLabel' => $isList
				? __( 'Previous list', 'live-carts-for-woocommerce' )
				: __( 'Previous cart', 'live-carts-for-woocommerce' ),
			'nextLabel' => $isList
				? __( 'Next list', 'live-carts-for-woocommerce' )
				: __( 'Next cart', 'live-carts-for-woocommerce' ),
			'navLabel'  => $isList
				? __( 'List navigation', 'live-carts-for-woocommerce' )
				: __( 'Cart navigation', 'live-carts-for-woocommerce' ),
		];
	}

	/**
     * Header actions:
     * remove cart (icon) and previous/next navigation.
	 */
	private function renderCartDetailHeaderActions() {
		?>
		<div class="phplugins-lc-detail-header-actions">
			<?php
			if ( ! empty( $this->cartData ) && ! empty( $this->cartData->trashed ) ) {
				$this->renderRestoreCartControl();
				$this->renderPermanentDeleteCartControl();
			} else {
				$this->renderTrashCartControl();
			}
			?>
			<?php $this->renderCartDetailHeaderNav(); ?>
		</div>
		<?php
	}

	/**
	 * Admin notices after trash, restore, or permanent delete actions.
	 */
	private function renderCartActionNotices() {
		$isList = $this->isListView();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only notice from redirect
		$trashed = isset( $_GET['trashed'] ) ? (int) $_GET['trashed'] : 0;
		if ( $trashed > 0 ) {
			$message = $isList
				? sprintf(
					/* translators: %s: number of lists */
					_n(
						'%s list moved to the Trash. If a user associated with one of these lists accesses the site again, the list will be automatically restored from the Trash.',
						'%s lists moved to the Trash. If a user associated with one of these lists accesses the site again, the list will be automatically restored from the Trash.',
						$trashed,
						'live-carts-for-woocommerce'
					),
					number_format_i18n( $trashed )
				)
				: sprintf(
					/* translators: %s: number of carts */
					_n(
						'%s cart moved to the Trash. If one of these carts are seen again, it will be automatically restored from the Trash.',
						'%s carts moved to the Trash. If one of these carts are seen again, it will be automatically restored from the Trash.',
						$trashed,
						'live-carts-for-woocommerce'
					),
					number_format_i18n( $trashed )
				);
			$this->renderActionNotice( $message );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only notice from redirect
		$restored = isset( $_GET['untrashed'] ) ? (int) $_GET['untrashed'] : 0;
		if ( $restored > 0 ) {
			$message = $isList
				? sprintf(
					/* translators: %s: number of lists */
					_n( '%s list restored from the Trash.', '%s lists restored from the Trash.', $restored, 'live-carts-for-woocommerce' ),
					number_format_i18n( $restored )
				)
				: sprintf(
					/* translators: %s: number of carts */
					_n( '%s cart restored from the Trash.', '%s carts restored from the Trash.', $restored, 'live-carts-for-woocommerce' ),
					number_format_i18n( $restored )
				);
			$this->renderActionNotice( $message );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only notice from redirect
		$deleted = isset( $_GET['deleted'] ) ? (int) $_GET['deleted'] : 0;
		if ( $deleted > 0 ) {
			$message = $isList
				? sprintf(
					/* translators: %s: number of lists */
					_n( '%s list permanently deleted.', '%s lists permanently deleted.', $deleted, 'live-carts-for-woocommerce' ),
					number_format_i18n( $deleted )
				)
				: sprintf(
					/* translators: %s: number of carts */
					_n( '%s cart permanently deleted.', '%s carts permanently deleted.', $deleted, 'live-carts-for-woocommerce' ),
					number_format_i18n( $deleted )
				);
			$this->renderActionNotice( $message );
		}
	}

	private function renderActionNotice( $message ) {
		?>
		<div class="berrypress-notice berrypress-notice-success">
			<span class="berrypress-notice-icon"><i class="berrypress-icon-check"></i></span>
			<div><p><?php echo esc_html( $message ); ?></p></div>
		</div>
		<?php
	}

	private function renderTrashCartControl() {
		if ( empty( $this->cartData ) ) {
			return;
		}

		$isList     = $this->isListView();
		$label      = $isList
			? __( 'Move list to Trash', 'live-carts-for-woocommerce' )
			: __( 'Move cart to Trash', 'live-carts-for-woocommerce' );
		$formAction = $this->getCartDetailUrl( (int) $this->cartData->cart_id );
		?>
		<form method="post"
			  action="<?php echo esc_url( $formAction ); ?>"
			  class="phplugins-lc-trash-cart">
			<?php wp_nonce_field( 'phplugins_carts_trash_cart' ); ?>
			<input type="hidden" name="phplugins_carts_trash_cart" value="1">
			<input type="hidden" name="phplugins_carts_trash_cart_id" value="<?php echo (int) $this->cartData->cart_id; ?>">
			<button type="submit" class="berrypress-btn berrypress-btn-icon"
					title="<?php echo esc_attr( $label ); ?>"
					aria-label="<?php echo esc_attr( $label ); ?>">
				<i class="berrypress-icon-delete" aria-hidden="true"></i>
			</button>
		</form>
		<?php
	}

	private function renderRestoreCartControl() {
		if ( empty( $this->cartData ) ) {
			return;
		}

		$isList     = $this->isListView();
		$label      = $isList
			? __( 'Restore list', 'live-carts-for-woocommerce' )
			: __( 'Restore cart', 'live-carts-for-woocommerce' );
		$formAction = $this->getCartDetailUrl( (int) $this->cartData->cart_id );
		?>
		<form method="post"
			  action="<?php echo esc_url( $formAction ); ?>"
			  class="phplugins-lc-restore-cart">
			<?php wp_nonce_field( 'phplugins_carts_restore_cart' ); ?>
			<input type="hidden" name="phplugins_carts_restore_cart" value="1">
			<input type="hidden" name="phplugins_carts_restore_cart_id" value="<?php echo (int) $this->cartData->cart_id; ?>">
			<button type="submit" class="berrypress-btn berrypress-btn-secondary phplugins-lc-restore-cart-btn"><?php echo esc_html( $label ); ?></button>
		</form>
		<?php
	}

	private function renderPermanentDeleteCartControl() {
		if ( empty( $this->cartData ) ) {
			return;
		}

		$isList     = $this->isListView();
		$label      = $isList
			? __( 'Delete list permanently', 'live-carts-for-woocommerce' )
			: __( 'Delete cart permanently', 'live-carts-for-woocommerce' );
		$confirm    = $isList
			? __( 'You are about to permanently delete this list. This action cannot be undone.', 'live-carts-for-woocommerce' )
			: __( 'You are about to permanently delete this cart. This action cannot be undone.', 'live-carts-for-woocommerce' );
		$formAction = $this->getCartDetailUrl( (int) $this->cartData->cart_id );
		?>
		<form method="post"
			  action="<?php echo esc_url( $formAction ); ?>"
			  class="phplugins-lc-delete-cart-permanently"
			  onsubmit="return confirm('<?php echo esc_js($confirm); ?>');">
			<?php wp_nonce_field( 'phplugins_carts_delete_cart_permanently' ); ?>
			<input type="hidden" name="phplugins_carts_delete_cart_permanently" value="1">
			<input type="hidden" name="phplugins_carts_delete_cart_id" value="<?php echo (int) $this->cartData->cart_id; ?>">
			<button type="submit" class="berrypress-btn berrypress-btn-icon"
					title="<?php echo esc_attr( $label ); ?>"
					aria-label="<?php echo esc_attr( $label ); ?>">
				<i class="berrypress-icon-delete" aria-hidden="true"></i>
			</button>
		</form>
		<?php
	}

	/**
	 * Icon-only previous/next controls for the cart detail page header.
	 */
	private function renderCartDetailHeaderNav() {
		$nav = $this->getAdjacentCartNavigation();
		?>
		<nav class="phplugins-lc-detail-header-nav" aria-label="<?php echo esc_attr( $nav['navLabel'] ); ?>">
			<a href="<?php echo $nav['prev'] ? esc_url( $this->getCartDetailUrl( $nav['prev'] ) ) : 'javascript:void(0)'; ?>"
			   class="berrypress-btn berrypress-btn-icon<?php if (!$nav['prev']) echo ' disabled'; ?>"
			   title="<?php echo esc_attr( $nav['prevLabel'] ); ?>"
			   aria-label="<?php echo esc_attr( $nav['prevLabel'] ); ?>"
			   <?php if ( ! $nav['prev'] ) { ?>aria-disabled="true" tabindex="-1"<?php } ?>>
					<i class="berrypress-icon-keyboard_arrow_left" aria-hidden="true"></i>
			</a>
			<a href="<?php echo $nav['next'] ? esc_url( $this->getCartDetailUrl( $nav['next'] ) ) : 'javascript:void(0)'; ?>"
			   class="berrypress-btn berrypress-btn-icon<?php if (!$nav['next']) echo ' disabled'; ?>"
			   title="<?php echo esc_attr( $nav['nextLabel'] ); ?>"
			   aria-label="<?php echo esc_attr( $nav['nextLabel'] ); ?>"
			   <?php if ( ! $nav['next'] ) { ?>aria-disabled="true" tabindex="-1"<?php } ?>>
					<i class="berrypress-icon-keyboard_arrow_right" aria-hidden="true"></i>
			</a>
		</nav>
		<?php
	}

	/**
	 * Footer bar: back link on the left, previous/next cart in the filtered list on the right.
	 *
	 * @param string $backUrl   URL for the back-to-list control.
	 * @param string $backLabel Accessible label for the back control.
	 */
	private function renderCartDetailFooterNav( $backUrl, $backLabel ) {
		$nav = $this->getAdjacentCartNavigation();
		?>
		<div class="phplugins-lc-detail-footer">
			<a href="<?php echo esc_url( $backUrl ); ?>" class="berrypress-btn berrypress-btn-secondary">
				<i class="berrypress-icon-keyboard_arrow_left"></i><?php echo esc_html( $backLabel ); ?>
			</a>
			<nav class="phplugins-lc-detail-footer-nav" aria-label="<?php echo esc_attr( $nav['navLabel'] ); ?>">
				<a href="<?php echo $nav['prev'] ? esc_url( $this->getCartDetailUrl( $nav['prev'] ) ) : 'javascript:void(0)'; ?>"
				   class="berrypress-btn berrypress-btn-secondary<?php if (!$nav['prev']) echo ' disabled'; ?>"
				   title="<?php echo esc_attr( $nav['prevLabel'] ); ?>"
				   <?php if ( ! $nav['prev'] ) { ?>aria-disabled="true" tabindex="-1"<?php } ?>>
					<i class="berrypress-icon-keyboard_arrow_left" aria-hidden="true"></i><?php echo esc_html( $nav['prevLabel'] ); ?>
				</a>
				<a href="<?php echo $nav['next'] ? esc_url(  $this->getCartDetailUrl( $nav['next'] ) ) : 'javascript:void(0)'; ?>"
				   class="berrypress-btn berrypress-btn-secondary<?php if (!$nav['next']) echo ' disabled'; ?>"
				   title="<?php echo esc_attr( $nav['nextLabel'] ); ?>"
				   <?php if ( ! $nav['next'] ) { ?>aria-disabled="true" tabindex="-1"<?php } ?>>
					<?php echo esc_html( $nav['nextLabel'] ); ?><i class="berrypress-icon-keyboard_arrow_right" aria-hidden="true"></i>
				</a>
			</nav>
		</div>
		<?php
	}

	/**
	 * Renders a listing the same customer's most recent WooCommerce orders.
	 * Displayed on the cart detail page (right-hand column on desktop).
	 *
	 * @param \WC_Order|false|null $currentOrder The order linked to this cart, if any.
	 */
	private function renderCustomerOrders( $currentOrder = null ) {
		global $wpdb;

		if ( empty( $this->cartData ) || ! function_exists( 'wc_get_orders' ) ) {
			return;
		}

		$customerUserId = ! empty( $this->cartData->user_id ) ? (int) $this->cartData->user_id : 0;
		$customerEmail  = ( $currentOrder instanceof \WC_Order ) ? sanitize_email( $currentOrder->get_billing_email() ) : '';

		// Without a way to identify the customer there is nothing to show.
		if ( ! $customerUserId && ! $customerEmail ) {
			return;
		}

		$args = [
			'limit'   => 5,
			'orderby' => 'date',
			'order'   => 'DESC',
			'return'  => 'objects',
		];
		if ( $customerUserId ) {
			$args['customer_id'] = $customerUserId;
			try {
				$customer = new \WC_Customer($customerUserId);
				$customerName = $customer->get_display_name();
			} catch (\Exception $ex) {
				$customerName = sprintf( /* translators: %d = customer ID */ __('customer #%d', 'live-carts-for-woocommerce'), $customerUserId );
			}
		} else {
			$args['customer'] = $customerEmail;
		}
		if ( $currentOrder instanceof \WC_Order ) {
			// phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_exclude
			$args['exclude'] = [ $currentOrder->get_id() ];
		}

		$orders = wc_get_orders( $args );
		if ( ! is_array( $orders ) ) {
			$orders = [];
		}
		$tsFormat = LiveCarts::instance()->getTimestampFormat();
		
		
		if ($customerUserId) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery -- prepared on next line
			$carts = $wpdb->get_results($wpdb->prepare(
				'SELECT cart_id, status, last_seen, value
					FROM '.$wpdb->prefix.'phplugins_carts
					WHERE type="cart" AND trashed=0 AND archived=0 AND status != "converted" AND user_id=%d AND cart_id != %d
					ORDER BY last_seen DESC
					LIMIT 5',
					$customerUserId,
						(int) $this->cartData->cart_id
				), ARRAY_A);
			
			$orders = array_merge($orders, $carts);
			usort(
				$orders,
				function($o1, $o2) {
					$o1 = is_array($o1) ? $o1['last_seen'] : $o1->get_date_created();
					$o2 = is_array($o2) ? $o2['last_seen'] : $o2->get_date_created();
					return strtotime($o2) - strtotime($o1);
				}
			);
			$orders = array_slice($orders, 0, 5);
			$cartStatuses = LiveCarts::instance()->getCartStatuses();
		}
		
		?>

            <span class="berrypress-seperator"></span>

			<h2><?php echo(esc_html( sprintf(
						// translators: %s = customer name or email
						__('Recent activity from %s', 'live-carts-for-woocommerce' ),
						$customerUserId ? $customerName : $customerEmail
					))); ?></h2>
			<div class="berrypress-table-wrapper berrypress-mb-4">
				<?php if ( empty( $orders ) ) { ?>
					<p class="phplugins-lc-orders-empty"><?php esc_html_e( 'No other activity found for this customer.', 'live-carts-for-woocommerce' ); ?></p>
				<?php } else { ?>
                <div class="berrypress-table-scroll">
					<table class="form-table berrypress-table phplugins-lc-orders-table">
						<thead>
						<tr>
							<th><?php esc_html_e( 'Order / Cart', 'live-carts-for-woocommerce' ); ?></th>
							<th><?php esc_html_e( 'Status', 'live-carts-for-woocommerce' ); ?></th>
							<th><?php esc_html_e( 'Date / Last Seen', 'live-carts-for-woocommerce' ); ?></th>
							<th><?php esc_html_e( 'Total', 'live-carts-for-woocommerce' ); ?></th>
						</tr>
						</thead>
						<tbody>
						<?php
							$statusClasses = [
								'completed'  => 'green',
								'converted'  => 'green',
                                'active'     => 'yellow',
								'on-hold'    => 'yellow',
								'pending'    => 'yellow',
								'processing' => 'yellow',
								'abandoned'  => 'red',
								'cancelled'  => 'red',
								'failed'     => 'red',
								'refunded'   => 'red',
							];
						foreach ( $orders as $order ) {
							$isCart = is_array($order);
							$date = $isCart ? $order['last_seen'] : $order->get_date_created();
								$statusKey = sanitize_key( $isCart ? $order['status'] : $order->get_status() );
								$statusClass = 'phplugins-live-carts-status-' . $statusClasses[$statusKey] ?? 'gray';
							?>
							<tr class="phplugins-lc-orders-table-row-<?php echo( $isCart ? 'cart' : 'order' ); ?>">
								<td>
									<a href="<?php echo esc_url( $isCart ? AdminPage::getUrl( ['cart_id' => $order['cart_id']] ) : $order->get_edit_order_url() ); ?>" target="_blank" rel="noopener noreferrer">
										<?php echo esc_html( $isCart ? LiveCarts::formatCartId($order['cart_id']) : '#'.$order->get_order_number() ); ?>
									</a>
								</td>
								<td>
									<span class="phplugins-lc-order-status<?php echo $statusClass ? ' ' . esc_attr( $statusClass ) : ''; ?>"><?php echo esc_html( $isCart ? ( $cartStatuses[$order['status']] ?? $order['status'] ) : wc_get_order_status_name( $order->get_status() ) ); ?></span>
								</td>
								<td><?php echo esc_html( $date ? wc_format_datetime( $date, $tsFormat ) : '' ); ?></td>
								<td><?php
									// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_formatted_order_total returns safe WooCommerce price HTML
									echo wp_kses_post( $isCart ? wc_price((float) $order['value']) : $order->get_formatted_order_total() ); ?></td>
							</tr>
							<?php
						}
						?>
						</tbody>
					</table>
                </div>
				<?php } ?>
			</div>
		<?php
	}

	function getNav() {

		$nav = [
			[
				'link'   => self::getUrl(),
				'icon'   => 'berrypress-icon-cart',
				'title'  => __( 'Live Carts', 'live-carts-for-woocommerce' ),
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- just checking which page we're on
				'active' => ! isset( $_GET['settings'] ) && ! isset( $_GET['addons'] ) && ! isset( $_GET['activation'] ) && ! isset( $_GET['status'] ) && ! isset( $_GET['recovery_emails'] ) && ! isset( $_GET['healthstatus'] ) && ! $this->isListView()
			],
			[
				'link'   => self::getUrl( [ 'type' => 'list' ] ),
				'icon'   => 'berrypress-icon-library_books',
				'title'  => __( 'Shopper Lists', 'live-carts-for-woocommerce' ),
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- just checking which page we're on
				'active' => ! isset( $_GET['settings'] ) && ! isset( $_GET['addons'] ) && ! isset( $_GET['activation'] ) && ! isset( $_GET['status'] ) && ! isset( $_GET['recovery_emails'] ) && ! isset( $_GET['healthstatus'] ) && $this->isListView(),
			],
			[
				'link'  => self::getUrl( [ 'recovery_emails' => 1 ] ),
				'title' => __('Recovery Emails', 'live-carts-for-woocommerce'),
				'label' => __('Beta', 'live-carts-for-woocommerce'),
				'icon'  => 'berrypress-icon-email',
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- just checking which page we're on
				'active' => !empty( $_GET['recovery_emails'] )
			],
			[
				'link'   => self::getUrl( [ 'settings' => 1 ] ),
				'icon'   => 'berrypress-icon-settings',
				'title'  => __( 'Settings', 'live-carts-for-woocommerce' ),

				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- just checking which page we're on
				'active' => isset( $_GET['settings'] ),
			],
			[
				'link'   => self::getUrl( [ 'healthstatus' => 1 ] ),
				'icon'   => 'berrypress-icon-help',
				'title'  => __( 'Status', 'live-carts-for-woocommerce' ),

				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- just checking which page we're on
				'active' => isset( $_GET['healthstatus'] ),
			],
			//			[
			//				'link'     => self::getUrl(['table_settings' => 1]),
			//				'icon'     => 'berrypress-icon-settings',
			//				'title'    => __('Table Settings', 'live-carts-for-woocommerce'),
			//				'link_id'  => 'berrypress-show-settings-link',
			//			],
			[
				'link'   => admin_url( 'admin.php?page=wc-admin&path=/analytics/phplugins-carts' ),
				'icon'   => 'berrypress-icon-bar-chart',
				'title'  => __( 'Analytics', 'live-carts-for-woocommerce' ),

				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- just checking which page we're on
				'active' => isset( $_GET['page'] ) && $_GET['page'] === 'wc-admin' && isset( $_GET['path'] ) && $_GET['path'] === '/analytics/phplugins-carts',
			],
		];
		
		if ($this->showExternalLinks) {
			$nav[] = [
				'link'   =>  self::getUrl( [ 'addons' => 1 ] ),
				'icon'   => 'berrypress-icon-addons',
				'title'  => __( 'Addons', 'live-carts-for-woocommerce' ),
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- just checking which page we're on
				'active' => isset( $_GET['addons'] )
			];
		}

		return $nav;
	}

	function getLogoUrl() {
		return plugin_dir_url( dirname( __DIR__ ) . '/live-carts-for-woocommerce.php' ) .
		       'assets/img/product-logo.svg';
	}

	function getProductUrl() {
        return 'https://wordpress.org/plugins/live-carts-for-woocommerce/';
	}

	function getHeaderText() {
		return __( 'Live Carts For WooCommerce', 'live-carts-for-woocommerce' );
	}

	function getAboveHeaderHtml() {

		$product_url = 'https://berrypress.com/live-carts/';

		// Check for Black Friday promotion
		$black_friday_notice = AdminPage::get_black_friday_notice( 'https://berrypress.com/product/woocommerce/live-carts/', 'Live Carts' );
		if ( !empty( $black_friday_notice ) ) {
			return $black_friday_notice;
		}

		// Default upgrade notice
		return sprintf(
			'<div class="berrypress-top-bar"><h2>%s <a class="berrypress-link" href="%s" target="_blank">%s<i class="berrypress-icon-filled berrypress-icon-keyboard_double_arrow_right"></i></a></h2></div>',
			esc_html__( 'Upgrade to Live Carts Pro to edit cart contents, create recovery URLs, and convert carts to orders with one click.', 'live-carts-for-woocommerce' ),
			esc_url( $product_url ),
			esc_html__( 'Upgrade', 'live-carts-for-woocommerce' )
		);
	}
}
