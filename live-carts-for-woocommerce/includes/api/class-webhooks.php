<?php
namespace BerryPress\LiveCarts\Api;

use BerryPress\LiveCarts\LiveCarts;
use BerryPress\LiveCarts\ShopperLists;

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-helpers.php';

/**
 * Webhook subscriptions: lets the store admin register one or more URLs,
 * each subscribed to a subset of Live Carts events, and dispatches a
 * signed JSON payload to every matching, active webhook when an event
 * fires.
 *
 * Storage: single option (`phplugins_carts_webhooks`) holding an array of
 * `[ 'id', 'url', 'events' => [...], 'secret', 'active' ]`. This is plenty
 * for the handful of webhooks a store typically configures; there is no
 * REST management endpoint, only the Settings screen (Webhooks section).
 */
class Webhooks {

	const OPTION_KEY = 'phplugins_carts_webhooks';

	/**
	 * All events a webhook can subscribe to, with their admin-facing label.
	 */
	public static function getAvailableEvents() {
		return apply_filters(
			'phplugins_live_carts_webhook_events',
			[
				'cart.created'       => __( 'Cart created', 'live-carts-for-woocommerce' ),
				'cart.seen'          => __( 'Cart seen', 'live-carts-for-woocommerce' ),
				'cart.items.added'   => __( 'Cart item(s) added', 'live-carts-for-woocommerce' ),
				'cart.items.removed' => __( 'Cart item(s) removed', 'live-carts-for-woocommerce' ),
				'cart.items.updated' => __( 'Cart item(s) quantity changed', 'live-carts-for-woocommerce' ),
				'cart.converted'     => __( 'Cart converted to order', 'live-carts-for-woocommerce' ),
				'cart.abandoned'     => __( 'Cart abandoned', 'live-carts-for-woocommerce' ),
				'list.created'       => __( 'Shopper list created', 'live-carts-for-woocommerce' ),
				'list.seen'          => __( 'Shopper list seen', 'live-carts-for-woocommerce' ),
				'list.items.added'   => __( 'Shopper list item(s) added', 'live-carts-for-woocommerce' ),
				'list.items.removed' => __( 'Shopper list item(s) removed', 'live-carts-for-woocommerce' ),
				'list.items.updated' => __( 'Shopper list item(s) quantity changed', 'live-carts-for-woocommerce' ),
			]
		);
	}

	private static $instance;

	public static function instance() {
		if ( ! isset( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function __construct() {
		add_action( 'phplugins_live_carts_cart_created', [ $this, 'onCartCreated' ] );
		add_action( 'phplugins_live_carts_cart_seen', [ $this, 'onCartSeen' ] );
		add_action( 'phplugins_live_carts_cart_converted', [ $this, 'onCartConverted' ] );
		add_action( 'phplugins_live_carts_cart_abandoned', [ $this, 'onCartAbandoned' ] );
		add_action( 'phplugins_live_carts_list_created', [ $this, 'onListCreated' ] );
		add_action( 'phplugins_live_carts_list_seen', [ $this, 'onListSeen' ] );

		add_action( 'phplugins_live_carts_items_added', [ $this, 'onItemsAdded' ], 10, 2 );
		add_action( 'phplugins_live_carts_items_removed', [ $this, 'onItemsRemoved' ], 10, 2 );
		add_action( 'phplugins_live_carts_items_updated', [ $this, 'onItemsUpdated' ], 10, 2 );

		add_action( 'phplugins_live_carts_settings_api', [ $this, 'renderSettingsSection' ] );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- phplugins_live_carts_save_settings_settings checks the nonce
		if ( isset( $_POST['phplugins_carts_webhook_add'] ) ) {
			add_action( 'phplugins_live_carts_save_settings_settings', [ $this, 'handleAddWebhook' ] );
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- phplugins_live_carts_save_settings_settings checks the nonce
		if ( isset( $_POST['phplugins_carts_webhook_secret'] ) ) {
			add_action( 'phplugins_live_carts_save_settings_settings', [ $this, 'handleWebhookRegenSecret' ] );
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- phplugins_live_carts_save_settings_settings checks the nonce
		if ( isset( $_POST['phplugins_carts_webhook_delete'] ) ) {
			add_action( 'phplugins_live_carts_save_settings_settings', [ $this, 'handleDeleteWebhook' ] );
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- phplugins_live_carts_save_settings_settings checks the nonce
		if ( isset( $_POST['phplugins_carts_webhook_toggle'] ) ) {
			add_action( 'phplugins_live_carts_save_settings_settings', [ $this, 'handleToggleWebhook' ] );
		}
	}

	// -----------------------------------------------------------------
	// Storage
	// -----------------------------------------------------------------

	public static function getWebhooks() {
		$webhooks = get_option( self::OPTION_KEY, [] );
		return is_array( $webhooks ) ? $webhooks : [];
	}

	private static function saveWebhooks( $webhooks ) {
		update_option( self::OPTION_KEY, array_values( $webhooks ), false );
	}

	/**
	 * Secrets are generated when a webhook is created / regenerated (not lazily when the
	 * settings screen is next rendered) so that no delivery is ever sent unsigned. The new
	 * value is stashed briefly so the redirect target can show it once.
	 */
	private static function createSecret( $webhookId ) {
		$secret = wp_generate_password( 40, false );
		set_transient( 'phplugins_carts_webhook_new_secret_' . get_current_user_id() . '_' . $webhookId, $secret, 5 * MINUTE_IN_SECONDS );
		return $secret;
	}

	private static function redirectToSettings( $error = '' ) {
		$url = wp_get_referer() ?: admin_url( 'admin.php?page=live-carts-for-woocommerce&settings=1' );
		$url = $error ? add_query_arg( 'phplugins_carts_webhook_error', $error, $url ) : remove_query_arg( 'phplugins_carts_webhook_error', $url );
		wp_safe_redirect( $url );
		exit;
	}

	// -----------------------------------------------------------------
	// Event -> webhook payload plumbing
	// -----------------------------------------------------------------

	/**
	 * Loads a cart/list row (cheap — one query by primary key) and says whether
	 * it's a cart or a list. Returns null if the row no longer exists.
	 *
	 * @return array{0:string,1:object}|null [ 'cart'|'list', $row ]
	 */
	private function loadRow( $cartId ) {
		$row = Helpers::getRow( $cartId );
		if ( is_wp_error( $row ) ) {
			return null;
		}
		return [ ShopperLists::isListType( $row->type ?? '' ) ? 'list' : 'cart', $row ];
	}

	/**
	 * Whether any active webhook is subscribed to $event. Checked before doing
	 * the expensive part of building a payload (formatRow() with contents/revisions
	 * walks the cart's full history), since most of these events fire on ordinary
	 * customer-facing requests (cart seen, items changed, ...) where, on a store
	 * with no matching webhook configured, that work would otherwise be wasted
	 * on every single request.
	 */
	private function hasActiveSubscriber( $event ) {
		foreach ( self::getWebhooks() as $webhook ) {
			if ( ! empty( $webhook['active'] ) && ! empty( $webhook['url'] ) && ! empty( $webhook['events'] ) && in_array( $event, $webhook['events'], true ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Formats a row exactly as GET /carts/{id} or GET /shopper-lists/{id} would —
	 * the same helper (Helpers::formatRow()) backs both, so a webhook's
	 * `cart`/`list` object is always identical to what you'd get by fetching that
	 * same resource over the REST API.
	 */
	private function formatResource( $row ) {
		return Helpers::formatRow( $row, null, true );
	}

	public function onCartCreated( $cartId ) {
		$this->dispatchResourceEvent( $cartId, 'cart.created' );
	}

	public function onCartSeen( $cartId ) {
		$this->dispatchResourceEvent( $cartId, 'cart.seen' );
	}

	public function onCartConverted( $cartId ) {
		$this->dispatchResourceEvent( $cartId, 'cart.converted' );
	}

	public function onCartAbandoned( $cartId ) {
		$this->dispatchResourceEvent( $cartId, 'cart.abandoned' );
	}

	public function onListCreated( $cartId ) {
		$this->dispatchResourceEvent( $cartId, 'list.created' );
	}

	public function onListSeen( $cartId ) {
		$this->dispatchResourceEvent( $cartId, 'list.seen' );
	}

	/**
	 * Fires a plain (non-item) event: cart/list.created, .seen, .converted, .abandoned.
	 * $event is passed through as-is (each of these hooks only ever fires for a cart
	 * or only ever for a list, never both, so the caller already picked the right one).
	 */
	private function dispatchResourceEvent( $cartId, $event ) {
		try {
			if ( ! $this->hasActiveSubscriber( $event ) ) {
				return;
			}

			$loaded = $this->loadRow( $cartId );
			if ( ! $loaded ) {
				return;
			}
			list( $key, $row ) = $loaded;

			$this->dispatch( $event, [ $key => $this->formatResource( $row ) ] );
		} catch ( \Exception $ex ) {
			LiveCarts::instance()->debugLog( $ex->getMessage(), __FILE__, __LINE__ );
		} catch ( \Error $err ) {
			LiveCarts::instance()->debugLog( $err->getMessage(), __FILE__, __LINE__ );
		}
	}

	public function onItemsAdded( $cartId, $items ) {
		$this->dispatchItemEvent( $cartId, $items, 'added' );
	}

	public function onItemsRemoved( $cartId, $items ) {
		$this->dispatchItemEvent( $cartId, $items, 'removed' );
	}

	public function onItemsUpdated( $cartId, $changes ) {
		$this->dispatchItemEvent( $cartId, $changes, 'updated' );
	}

	/**
	 * Fires one event per changed item (not one batched event for the whole
	 * operation), each with an `item` field built the same way as a revision's
	 * `changes[].item` (Helpers::formatItem()). For 'updated', $items
	 * is a list of `['item' => ..., 'item_before' => ...]` pairs instead of plain
	 * item arrays (see LiveCarts::emitItemChangeEvents()).
	 */
	private function dispatchItemEvent( $cartId, $items, $action ) {
		try {
			if ( empty( $items ) ) {
				return;
			}

			$loaded = $this->loadRow( $cartId );
			if ( ! $loaded ) {
				return;
			}
			list( $key, $row ) = $loaded;
			$event = $key . '.items.' . $action;

			if ( ! $this->hasActiveSubscriber( $event ) ) {
				return;
			}

			$object = $this->formatResource( $row );
			$isList = 'list' === $key;

			foreach ( $items as $entry ) {
				$payload = [ $key => $object ];

				if ( 'updated' === $action ) {
					$payload['item']        = Helpers::formatItem( $entry['item']['key'] ?? '', $entry['item'] ?? [], $isList );
					$payload['item_before'] = Helpers::formatItem( $entry['item_before']['key'] ?? '', $entry['item_before'] ?? [], $isList );
				} else {
					$payload['item'] = Helpers::formatItem( $entry['key'] ?? '', $entry, $isList );
				}

				$this->dispatch( $event, $payload );
			}
		} catch ( \Exception $ex ) {
			LiveCarts::instance()->debugLog( $ex->getMessage(), __FILE__, __LINE__ );
		} catch ( \Error $err ) {
			LiveCarts::instance()->debugLog( $err->getMessage(), __FILE__, __LINE__ );
		}
	}

	/**
	 * Sends the event to every active webhook subscribed to it. Fires a
	 * non-blocking HTTP request so this never slows down the customer-facing
	 * request that triggered the event.
	 *
	 * @param string $event
	 * @param array  $payload Extra top-level fields for the JSON body (`cart`/`list`,
	 *                        and for item events, `item`/`item_before`) -- merged in
	 *                        alongside `event` and `created`.
	 */
	public function dispatch( $event, array $payload ) {
		$webhooks = self::getWebhooks();
		if ( empty( $webhooks ) ) {
			return;
		}

		$body = wp_json_encode(
			array_merge(
				[
					'event'   => $event,
					'created' => gmdate( 'c' ),
				],
				$payload
			)
		);

		foreach ( $webhooks as $webhook ) {
			if ( empty( $webhook['active'] ) || empty( $webhook['url'] ) || empty( $webhook['events'] ) || ! in_array( $event, $webhook['events'], true ) ) {
				continue;
			}
			
			$headers = [
				'Content-Type'             => 'application/json',
				'X-Live-Carts-Event'       => $event,
			];
			
			if (!empty($webhook['secret'])) {
				$headers['X-Live-Carts-Signature'] = hash_hmac( 'sha256', $body, $webhook['secret'] );
			}

			wp_safe_remote_post(
				$webhook['url'],
				[
					'body'      => $body,
					'headers'   => $headers,
					'timeout'   => 5,
					'blocking'  => false,
					'sslverify' => true,
				]
			);
		}
	}

	// -----------------------------------------------------------------
	// Admin UI (Settings -> Webhooks)
	// -----------------------------------------------------------------

	public function renderSettingsSection() {
		if (!get_option('phplugins_carts_webhooks_on')) {
			return;
		}
		$webhooks = self::getWebhooks();
		$events   = self::getAvailableEvents();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only flag set by our own redirect
		$error = isset( $_GET['phplugins_carts_webhook_error'] ) ? sanitize_key( wp_unslash( $_GET['phplugins_carts_webhook_error'] ) ) : '';
		?>
		<table class="form-table berrypress-mb-3" role="presentation">
			<tbody>
				<tr>
					<th scope="row"><?php esc_html_e( 'Webhooks', 'live-carts-for-woocommerce' ); ?></th>
					<td>
		<?php if ( 'url' === $error || 'events' === $error ) { ?>
			<div class="berrypress-notice berrypress-notice-error">
				<span class="berrypress-notice-icon"><i class="berrypress-icon-warning"></i></span>
				<p><?php echo(esc_html( $error == 'url' ? __('The webhook was not added: please enter a valid URL. Localhost and private-network addresses are rejected.', 'live-carts-for-woocommerce' ) : __( 'The webhook was not added: select at least one event.', 'live-carts-for-woocommerce' ) )); ?></p>
			</div>
		<?php } ?>
		<p class="description">
			<?php esc_html_e( 'Register one or more URLs to receive a signed JSON POST request whenever a subscribed event happens.', 'live-carts-for-woocommerce' ); ?>
		</p>

		<?php if ( $webhooks ) : ?>
		<div class="berrypress-table-wrapper berrypress-mb-3">
		<div class="berrypress-table-scroll">
		<table class="berrypress-table phplugins-lc-webhooks-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'URL', 'live-carts-for-woocommerce' ); ?></th>
					<th><?php esc_html_e( 'Events', 'live-carts-for-woocommerce' ); ?></th>
					<th><?php esc_html_e( 'Status', 'live-carts-for-woocommerce' ); ?></th>
					<th aria-label="<?php esc_attr_e( 'Actions', 'live-carts-for-woocommerce' ); ?>"></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $webhooks as &$webhook ) : ?>
				<?php
				// Legacy webhooks saved before secrets were generated on creation.
				if ( empty( $webhook['secret'] ) ) {
					$webhook['secret'] = self::createSecret( $webhook['id'] );
					$webhooksChanged   = true;
				}
				$newSecret = get_transient( 'phplugins_carts_webhook_new_secret_' . get_current_user_id() . '_' . $webhook['id'] );
				if ( $newSecret ) {
					delete_transient( 'phplugins_carts_webhook_new_secret_' . get_current_user_id() . '_' . $webhook['id'] );
				}
				?>
				<tr>
					<td>
						<span class="phplugins-lc-webhook-url"><?php echo esc_url( $webhook['url'] ); ?></span>
						<?php if ( $newSecret ) : ?>
							<p class="description phplugins-lc-webhook-secret">
								<?php esc_html_e( 'Secret (shown once):', 'live-carts-for-woocommerce' ); ?>
								<code class="phplugins-lc-webhook-secret-value"><?php echo esc_html( $newSecret ); ?></code>
							</p>
						<?php endif; ?>
					</td>
					<td>
						<div class="phplugins-lc-tags">
							<?php foreach ( $webhook['events'] ?? [] as $event ) : ?>
								<span class="phplugins-lc-tag"><?php echo esc_html( $events[ $event ] ?? $event ); ?></span>
							<?php endforeach; ?>
						</div>
					</td>
					<td>
						<span class="phplugins-live-carts-status <?php echo empty( $webhook['active'] ) ? 'phplugins-lc-order-status' : 'phplugins-live-carts-status-green'; ?>">
							<?php empty( $webhook['active'] ) ? esc_html_e( 'Disabled', 'live-carts-for-woocommerce' ) : esc_html_e( 'Active', 'live-carts-for-woocommerce' ); ?>
						</span>
					</td>
					<td>
						<?php wp_nonce_field( 'phplugins_carts_webhook_toggle_' . $webhook['id'] ); ?>
						<button type="submit" class="berrypress-btn berrypress-btn-secondary" name="phplugins_carts_webhook_toggle" value="<?php echo esc_attr( $webhook['id'] ); ?>">
							<?php echo ! empty( $webhook['active'] ) ? esc_html__( 'Disable', 'live-carts-for-woocommerce' ) : esc_html__( 'Enable', 'live-carts-for-woocommerce' ); ?>
						</button>
						
						<div class="berrypress-dropdown">
							<button type="button" class="berrypress-btn berrypress-btn-icon berrypress-dropdown-trigger" aria-haspopup="true" aria-expanded="false" aria-label="<?php esc_attr_e( 'More options', 'live-carts-for-woocommerce' ); ?>">
								<span class="phplugins-lc-kebab-icon" aria-hidden="true"></span>
							</button>
							<div class="berrypress-dropdown-menu berrypress-hidden" role="menu" aria-label="<?php esc_attr_e( 'More options', 'live-carts-for-woocommerce' ); ?>">
								<?php wp_nonce_field( 'phplugins_carts_webhook_secret_' . $webhook['id'] ); ?>
								<button type="submit" role="menuitem" class="berrypress-dropdown-item" name="phplugins_carts_webhook_secret" value="<?php echo esc_attr( $webhook['id'] ); ?>">
									<?php esc_html_e( 'Regenerate Secret', 'live-carts-for-woocommerce' ); ?>
								</button>
								<?php wp_nonce_field( 'phplugins_carts_webhook_delete_' . $webhook['id'] ); ?>
								<button type="submit" role="menuitem" class="berrypress-dropdown-item" name="phplugins_carts_webhook_delete" value="<?php echo esc_attr( $webhook['id'] ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Delete this webhook?', 'live-carts-for-woocommerce' ) ); ?>');">
									<?php esc_html_e( 'Delete', 'live-carts-for-woocommerce' ); ?>
								</button>
							</div>
						</div>
					</td>
				</tr>
			<?php endforeach; unset( $webhook ); if (!empty($webhooksChanged)) { self::saveWebhooks( $webhooks ); } ?>
			</tbody>
		</table>
		</div>
		</div>
		<?php endif; ?>

		<details class="phplugins-lc-webhook-add berrypress-mb-3" <?php echo $error ? ' open' : ''; ?>>
		<summary class="berrypress-btn berrypress-btn-secondary"><?php esc_html_e( 'Add Webhook', 'live-carts-for-woocommerce' ); ?></summary>
		<div method="post" class="phplugins-lc-webhook-add-form berrypress-mt-3">
			<?php wp_nonce_field( 'phplugins_carts_webhook_add' ); ?>
			<div class="berrypress-field berrypress-field-label-fixed berrypress-align-items-center berrypress-mb-3">
				<label for="phplugins_carts_webhook_url"><?php esc_html_e( 'Webhook URL', 'live-carts-for-woocommerce' ); ?></label>
				<input type="url" name="phplugins_carts_webhook_url" id="phplugins_carts_webhook_url" placeholder="https://example.com/webhooks/live-carts">
			</div>
			<div class="berrypress-field berrypress-field-label-fixed berrypress-field-checkbox-group berrypress-mb-3">
				<div class="berrypress-field-label"><?php esc_html_e( 'Events', 'live-carts-for-woocommerce' ); ?></div>
				<ul role="group">
					<?php foreach ( $events as $eventKey => $eventLabel ) : ?>
						<li class="berrypress-field">
							<input type="checkbox" name="phplugins_carts_webhook_events[]" id="phplugins_carts_webhook_event_<?php echo esc_attr( $eventKey ); ?>" value="<?php echo esc_attr( $eventKey ); ?>">
							<label for="phplugins_carts_webhook_event_<?php echo esc_attr( $eventKey ); ?>"><?php echo esc_html( $eventLabel ); ?> <code><?php echo esc_html( $eventKey ); ?></code></label>
						</li>
					<?php endforeach; ?>
				</ul>
			</div>
			<button type="submit" name="phplugins_carts_webhook_add" value="1" class="berrypress-btn berrypress-btn-secondary berrypress-mb-2">
				<?php esc_html_e( 'Save Webhook', 'live-carts-for-woocommerce' ); ?>
			</button>
			<button type="button" class="berrypress-btn berrypress-btn-secondary" data-lc-close-details>
				<?php esc_html_e( 'Cancel', 'live-carts-for-woocommerce' ); ?>
			</button>
		</div>
		</details>
					</td>
				</tr>
			</tbody>
		</table>
		<?php
	}

	public function handleAddWebhook() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- phplugins_live_carts_save_settings_settings checks the nonce
		if ( ! current_user_can( LiveCarts::ADMIN_CAPABILITY ) ) {
			return;
		}

		$url = isset( $_POST['phplugins_carts_webhook_url'] ) ? wp_http_validate_url( esc_url_raw( wp_unslash( $_POST['phplugins_carts_webhook_url'] ) ) ) : '';
		if ( ! $url ) {
			self::redirectToSettings( 'url' );
		}

		$availableEvents = array_keys( self::getAvailableEvents() );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- sanitized via array_intersect against allowlist below
		$events = isset( $_POST['phplugins_carts_webhook_events'] ) ? array_map( 'sanitize_text_field', (array) $_POST['phplugins_carts_webhook_events'] ) : [];
		$events = array_values( array_intersect( $events, $availableEvents ) );

		if ( empty( $events ) ) {
			self::redirectToSettings( 'events' );
		}

		$webhooks = self::getWebhooks();

		do {
			$newId = 'wh_' . wp_generate_password( 12, false );
		} while ( in_array( $newId, array_column( $webhooks, 'id' ), true ) );

		$webhooks[] = [
			'id'     => $newId,
			'url'    => $url,
			'events' => $events,
			'secret' => self::createSecret( $newId ),
			'active' => true,
		];
		self::saveWebhooks( $webhooks );

		self::redirectToSettings();
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	public function handleWebhookRegenSecret() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- phplugins_live_carts_save_settings_settings checks the nonce
		if ( ! current_user_can( LiveCarts::ADMIN_CAPABILITY ) ) {
			return;
		}
		$id = sanitize_text_field( wp_unslash( $_POST['phplugins_carts_webhook_secret'] ?? '' ) );

		$webhooks = self::getWebhooks();
		foreach ( $webhooks as &$webhook ) {
			if ( ( $webhook['id'] ?? '' ) === $id ) {
				$webhook['secret'] = self::createSecret( $id );
				break;
			}
		}
		unset( $webhook );
		self::saveWebhooks( $webhooks );

		self::redirectToSettings();
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	public function handleDeleteWebhook() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- phplugins_live_carts_save_settings_settings checks the nonce
		if ( ! current_user_can( LiveCarts::ADMIN_CAPABILITY ) ) {
			return;
		}
		$id = sanitize_text_field( wp_unslash( $_POST['phplugins_carts_webhook_delete'] ?? '' ) );

		$webhooks = array_values( array_filter( self::getWebhooks(), function ( $webhook ) use ( $id ) {
			return ( $webhook['id'] ?? '' ) !== $id;
		} ) );
		self::saveWebhooks( $webhooks );

		self::redirectToSettings();
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	public function handleToggleWebhook() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- phplugins_live_carts_save_settings_settings checks the nonce
		if ( ! current_user_can( LiveCarts::ADMIN_CAPABILITY ) ) {
			return;
		}
		$id = sanitize_text_field( wp_unslash( $_POST['phplugins_carts_webhook_toggle'] ?? '' ) );

		$webhooks = self::getWebhooks();
		foreach ( $webhooks as &$webhook ) {
			if ( ( $webhook['id'] ?? '' ) === $id ) {
				$webhook['active'] = empty( $webhook['active'] );
				break;
			}
		}
		unset( $webhook );
		self::saveWebhooks( $webhooks );

		self::redirectToSettings();
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}
}
