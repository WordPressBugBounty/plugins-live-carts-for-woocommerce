<?php
/**
 * Review request (NPS-style admin notice).
 *
 * Shown only on the admin screen after usage milestones
 * (14 days, ~3 months, ~10 months). Fully dismissible, with snooze options.
 *
 *
 */

namespace BerryPress\LiveCarts;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ReviewRequest
 */
class ReviewRequest {
	/**
	 * Usage milestones in seconds after install (14 days, 90 days, 300 days).
	 *
	 * @var int[]
	 */
	const MILESTONES = array(
		1209600,
		7776000,
		25920000,
	);

	protected $config;

	/**
	 * Constructor.
	 */
	public function __construct($config) {
		$requiredConfigKeys = [
			'product_name',
			'plugin_id',
			'review_url',
			'support_url',
			'screen_id',
			'screen_admin_url',
			'screen_capability'
		];
		if (array_diff($requiredConfigKeys, array_keys($config)) || !$config['screen_capability']) {
			throw new \Exception();
		}
		$this->config = apply_filters($config['plugin_id'].'_review_request_config', $config);
		
		$this->maybe_record_install_time();

		$this->handle_actions();
		
		add_action( 'admin_notices', array( $this, 'render_notice' ) );
	}

	/**
	 * Record first-seen timestamp for existing installs and new activations.
	 */
	public function maybe_record_install_time() {
		if ( ! get_option( $this->config['plugin_id'].'_installed_at' ) ) {
			update_option( $this->config['plugin_id'].'_installed_at', time(), false );
		}
	}

	/**
	 * Whether the current request is on the admin screen.
	 *
	 * @return bool
	 */
	private function is_live_carts_screen() {
		if ( ! function_exists( 'get_current_screen' ) ) {
			return false;
		}

		$screen = get_current_screen();
		return $screen && $this->config['screen_id'] === $screen->id;
	}

	/**
	 * Get per-user notice state.
	 *
	 * @param int $user_id User ID.
	 * @return array{dismissed:bool,snooze_until:int,prompts_done:int,pending_rating:int}
	 */
	private function get_state( $user_id ) {
		$state = get_user_meta( $user_id, $this->config['plugin_id'].'_review_request', true );

		if ( ! is_array( $state ) ) {
			$state = array();
		}

		return array(
			'dismissed'      => ! empty( $state['dismissed'] ),
			'snooze_until'   => isset( $state['snooze_until'] ) ? (int) $state['snooze_until'] : 0,
			'prompts_done'   => isset( $state['prompts_done'] ) ? (int) $state['prompts_done'] : 0,
			'pending_rating' => isset( $state['pending_rating'] ) ? (int) $state['pending_rating'] : 0,
		);
	}

	/**
	 * Persist per-user notice state.
	 *
	 * @param int   $user_id User ID.
	 * @param array $state   State.
	 */
	private function save_state( $user_id, array $state ) {
		update_user_meta( $user_id, $this->config['plugin_id'].'_review_request', $state );
	}

	/**
	 * Whether the review notice should be shown for the current user.
	 *
	 * @return bool
	 */
	private function should_show() {
		// capability — matches admin page access.
		if ( ! current_user_can( $this->config['screen_capability'] ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown
			return false;
		}

		if ( ! $this->is_live_carts_screen() ) {
			return false;
		}

		$installed_at = (int) get_option( $this->config['plugin_id'].'_installed_at', 0 );
		if ( $installed_at <= 0 ) {
			return false;
		}

		$state = $this->get_state( get_current_user_id() );

		if ( $state['dismissed'] ) {
			return false;
		}

		if ( $state['prompts_done'] >= count( self::MILESTONES ) ) {
			return false;
		}

		if ( $state['snooze_until'] > time() ) {
			return false;
		}

		$milestone = self::MILESTONES[ $state['prompts_done'] ];
		if ( time() < ( $installed_at + $milestone ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Build an action URL for the notice.
	 *
	 * @param string               $action Action name.
	 * @param array<string, mixed> $extra  Extra query args.
	 * @return string
	 */
	private function action_url( $action, array $extra = array() ) {
		$args = array_merge(
			array(
				$this->config['plugin_id'].'_review' => sanitize_key($action),
				'_wpnonce'         => wp_create_nonce( 'bp_lc_pro_review_' . $action ),
			),
			$extra
		);

		return add_query_arg( $args, admin_url( 'admin.php?page=live-carts-for-woocommerce' ) );
	}

	/**
	 * Redirect back after handling an action.
	 */
	private function redirect_back() {
		wp_safe_redirect( admin_url( $this->config['screen_admin_url'] ) );
		exit;
	}

	/**
	 * Handle dismiss / snooze / rate / complete actions.
	 */
	public function handle_actions() {
		try {
			if ( empty( $_GET[ $this->config['plugin_id'].'_review' ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				return;
			}

			// capability — matches admin page access
			if ( ! current_user_can( $this->config['screen_capability'] ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown
				return;
			}

			$action = sanitize_key( wp_unslash( $_GET[ $this->config['plugin_id'].'_review' ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$nonce  = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

			if ( ! wp_verify_nonce( $nonce, 'bp_lc_pro_review_' . $action ) ) {
				return;
			}

			$user_id = get_current_user_id();
			$state   = $this->get_state( $user_id );

			switch ( $action ) {
				case 'dismiss':
					$state['dismissed']      = true;
					$state['pending_rating'] = 0;
					$state['snooze_until']   = 0;
					$this->save_state( $user_id, $state );
					$this->redirect_back();
					break;

				case 'snooze':
					$days = isset( $_GET['days'] ) ? absint( $_GET['days'] ) : 7; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
					if ( ! in_array( $days, array( 7, 30 ), true ) ) {
						$days = 7;
					}
					$state['snooze_until']   = time() + ( $days * DAY_IN_SECONDS );
					$state['pending_rating'] = 0;
					$this->save_state( $user_id, $state );
					$this->redirect_back();
					break;

				case 'rate':
					$rating = isset( $_GET['rating'] ) ? absint( $_GET['rating'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
					if ( $rating < 1 || $rating > 5 ) {
						$this->redirect_back();
						break;
					}
					$state['pending_rating'] = $rating;
					$state['snooze_until']   = 0;
					$this->save_state( $user_id, $state );
					$this->redirect_back();
					break;

				case 'complete':
					// Advance to the next milestone prompt (or finish the sequence).
					$state['prompts_done']   = min( count( self::MILESTONES ), $state['prompts_done'] + 1 );
					$state['pending_rating'] = 0;
					$state['snooze_until']   = 0;
					$this->save_state( $user_id, $state );
					$this->redirect_back();
					break;
			}
		} catch ( \Exception $ex ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			// Best-effort: never break wp-admin.
		} catch ( \Error $err ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			// Best-effort: never break wp-admin.
		}
	}

	/**
	 * Render the review request notice.
	 */
	public function render_notice() {
		try {
			if ( ! $this->should_show() ) {
				return;
			}

			$state         = $this->get_state( get_current_user_id() );
			$rating        = $state['pending_rating'];
			$dismiss_url   = $this->action_url( 'dismiss' );
			$snooze_7_url  = $this->action_url( 'snooze', array( 'days' => 7 ) );
			$snooze_30_url = $this->action_url( 'snooze', array( 'days' => 30 ) );
			$complete_url  = $this->action_url( 'complete' );
			?>
			<div class="notice berrypress-notice berrypress-notice-info berrypress-review-notice bp-lc-pro-review-notice is-dismissible" role="status">
				<div class="berrypress-review-notice__body">
					<?php if ( $rating >= 4 ) : ?>
						<p>
							<strong><?php esc_html_e( 'Thanks for the feedback!', 'live-carts-for-woocommerce' ); ?></strong>
							<?php
								echo esc_html( sprintf(
									/* translators: %s = product name */
									__( 'Would you mind sharing your experience by leaving a review? It helps other potential users decide if %s is right for them.', 'live-carts-for-woocommerce' ),
									$this->config['product_name_short'] ?? $this->config['product_name']
								) );
							?>
						</p>
						<p class="berrypress-review-notice__actions">
							<a
								class="berrypress-btn berrypress-btn-primary bp-lc-pro-review-complete"
								href="<?php echo esc_url( $this->config['review_url'] ); ?>"
								target="_blank"
								rel="noopener noreferrer"
								data-complete-url="<?php echo esc_url( $complete_url ); ?>"
							><?php esc_html_e( 'Leave a review', 'live-carts-for-woocommerce' ); ?></a>
							<a href="<?php echo esc_url( $complete_url ); ?>"><?php esc_html_e( 'I already left a review', 'live-carts-for-woocommerce' ); ?></a>
							<span class="berrypress-notice-link-separator" aria-hidden="true">|</span>
							<a href="<?php echo esc_url( $dismiss_url ); ?>"><?php esc_html_e( 'Don\'t ask again', 'live-carts-for-woocommerce' ); ?></a>
						</p>
					<?php elseif ( $rating >= 1 && $rating <= 3 ) : ?>
						<p>
							<strong><?php esc_html_e( 'Thanks for letting us know.', 'live-carts-for-woocommerce' ); ?></strong>
							<?php esc_html_e( 'We\'re here to help - please reach out and we\'ll do our best to improve your experience.', 'live-carts-for-woocommerce' ); ?>
						</p>
						<p class="berrypress-review-notice__actions">
							<a
								class="bp-lc-pro-review-complete"
								href="<?php echo esc_url( $this->config['support_url'] ); ?>"
								target="_blank"
								rel="noopener noreferrer"
								data-complete-url="<?php echo esc_url( $complete_url ); ?>"
							><?php esc_html_e( 'Contact support', 'live-carts-for-woocommerce' ); ?></a>
							<span class="berrypress-notice-link-separator" aria-hidden="true">|</span>
							<a href="<?php echo esc_url( $complete_url ); ?>"><?php esc_html_e( 'Maybe later', 'live-carts-for-woocommerce' ); ?></a>
							<span class="berrypress-notice-link-separator" aria-hidden="true">|</span>
							<a href="<?php echo esc_url( $dismiss_url ); ?>"><?php esc_html_e( 'Don\'t ask again', 'live-carts-for-woocommerce' ); ?></a>
						</p>
					<?php else : ?>
						<p>
							<strong>
								<?php
								echo esc_html( sprintf(
									/* translators: %s = product name */
									__( 'How would you rate %s?', 'live-carts-for-woocommerce' ),
									$this->config['product_name']
								) );
							?>
							<?php esc_html_e( 'Your feedback helps us improve - and helps other store owners find the right tools.', 'live-carts-for-woocommerce' ); ?>
							<span class="berrypress-review-notice__stars" role="group" aria-label="<?php esc_attr_e( 'Rating from 1 to 5 stars', 'live-carts-for-woocommerce' ); ?>">
								<?php for ( $i = 1; $i <= 5; $i++ ) : ?>
									<a
										class="berrypress-review-star"
										href="<?php echo esc_url( $this->action_url( 'rate', array( 'rating' => $i ) ) ); ?>"
										title="<?php echo esc_attr( sprintf( /* translators: %d: star rating 1–5 */ __( '%d out of 5', 'live-carts-for-woocommerce' ), $i ) ); ?>"
										aria-label="<?php echo esc_attr( sprintf( /* translators: %d: star rating 1–5 */ __( 'Rate %d out of 5 stars', 'live-carts-for-woocommerce' ), $i ) ); ?>"
									>★</a>
								<?php endfor; ?>
							</span>
						</p>
						<p class="berrypress-review-notice__actions berrypress-review-notice__actions--secondary">
							<a href="<?php echo esc_url( $snooze_7_url ); ?>"><?php esc_html_e( 'Remind me in 1 week', 'live-carts-for-woocommerce' ); ?></a>
							<span class="berrypress-notice-link-separator" aria-hidden="true">|</span>
							<a href="<?php echo esc_url( $snooze_30_url ); ?>"><?php esc_html_e( 'Remind me in 1 month', 'live-carts-for-woocommerce' ); ?></a>
							<span class="berrypress-notice-link-separator" aria-hidden="true">|</span>
							<a href="<?php echo esc_url( $dismiss_url ); ?>"><?php esc_html_e( 'Don\'t ask again', 'live-carts-for-woocommerce' ); ?></a>
						</p>
					<?php endif; ?>
				</div>
				<a class="notice-dismiss" href="<?php echo esc_url( $dismiss_url ); ?>">
					<span class="screen-reader-text"><?php esc_html_e( 'Dismiss this notice', 'live-carts-for-woocommerce' ); ?></span>
				</a>
			</div>
			<?php
		} catch ( \Exception $ex ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			// Best-effort: never break wp-admin.
		} catch ( \Error $err ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			// Best-effort: never break wp-admin.
		}
	}
}
