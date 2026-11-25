<?php

namespace BerryPress\LiveCarts;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use BerryPress\LiveCarts\LiveCarts;

/**
 * Class RecoveryEmailsAdminPage
 * 
 * Handles the admin UI for recovery emails within the Live Carts admin page.
 */
class RecoveryEmailsAdminPage {

	public static function processActions() {
		// Handle form submission
		if ( isset( $_POST['save_recovery_email'] ) && isset( $_GET['email_id'] ) ) {
			$is_new = $_GET['email_id'] === 'new';
			$post_id = $is_new ? 0 : absint($_GET['email_id']);
			
			// Verify nonce
			check_admin_referer( 'save-recovery-email-' . $post_id );
			
			// Verify user can edit/create
			if ( $is_new ) {
				if ( ! current_user_can( LiveCarts::ADMIN_CAPABILITY ) ) {
					wp_die( esc_html__( 'You do not have permission to create emails.', 'live-carts-for-woocommerce' ) );
				}
			} else {
				if ( ! current_user_can( 'edit_post', $post_id ) ) {
					wp_die( esc_html__( 'You do not have permission to edit this email.', 'live-carts-for-woocommerce' ) );
				}
				
				if ( get_post_type( $post_id ) !== RecoveryEmails::POST_TYPE ) {
					wp_die( esc_html__( 'Invalid email ID.', 'live-carts-for-woocommerce' ) );
				}
			}

			// Sanitize and validate inputs
			$email_subject = isset( $_POST['email_subject'] ) ? sanitize_text_field( wp_unslash( $_POST['email_subject'] ) ) : '';
			$email_body = isset( $_POST['email_body'] ) ? wp_kses_post( wp_unslash( $_POST['email_body'] ) ) : '';
			$email_status = isset( $_POST['email_status'] ) && $_POST['email_status'] === 'publish' ? 'publish' : 'draft';
			
			// Validate required fields
			if ( empty( $email_subject ) ) {
				wp_die( esc_html__( 'Email subject is required.', 'live-carts-for-woocommerce' ) );
			}

			$post_data = array(
				'post_type'    => RecoveryEmails::POST_TYPE,
				'post_title'   => $email_subject,
				'post_content' => $email_body,
				'post_status'  => $email_status,
			);

			if ( ! $is_new ) {
				$post_data['ID'] = $post_id;
				$saved_id = wp_update_post( $post_data );
			} else {
				$saved_id = wp_insert_post( $post_data );
			}

			if ( $saved_id && ! is_wp_error( $saved_id ) ) {
				// Save meta fields with validation
				if ( isset( $_POST['time_value'] ) ) {
					$time_value = absint( $_POST['time_value'] );
					if ( $time_value > 0 ) {
						update_post_meta( $saved_id, RecoveryEmails::META_TIME_VALUE, $time_value );
					}
				}
				
				if ( isset( $_POST['time_unit'] ) ) {
					$time_unit = sanitize_text_field( wp_unslash( $_POST['time_unit'] ) );
					if ( in_array( $time_unit, array( 'minutes', 'hours', 'days' ), true ) ) {
						update_post_meta( $saved_id, RecoveryEmails::META_TIME_UNIT, $time_unit );
					}
				}
				
				do_action('phplugins_live_carts_recovery_email_settings_save', $saved_id);

				wp_safe_redirect( admin_url( 'admin.php?page=live-carts-for-woocommerce&recovery_emails=1&email_id=' . $saved_id . '&saved=1' ) );
				exit;
			} else {
				wp_die( esc_html__( 'Error saving recovery email. Please try again.', 'live-carts-for-woocommerce' ) );
			}
		}
		
		// Handle delete action
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce checked below when action is taken
		if ( isset( $_GET['action'] ) && $_GET['action'] === 'delete' && isset( $_GET['email_id'] ) ) {
			// Verify nonce
			$email_id = absint( $_GET['email_id'] );
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			check_admin_referer( 'delete-recovery-email-' . $email_id );
			
			// Check post type
			if ( get_post_type( $email_id ) !== RecoveryEmails::POST_TYPE ) {
				wp_die( esc_html__( 'Invalid email ID.', 'live-carts-for-woocommerce' ) );
			}
			
			// Verify user can delete
			if ( ! current_user_can( 'delete_post', $email_id ) ) {
				wp_die( esc_html__( 'You do not have permission to delete this email.', 'live-carts-for-woocommerce' ) );
			}
			
			wp_delete_post( $email_id, true );
			
			wp_safe_redirect( admin_url( 'admin.php?page=live-carts-for-woocommerce&recovery_emails=1' ) );
			exit;
		}
	}
	
	/**
	 * Renders the recovery emails page.
	 */
	public function render() {
		// Check user capabilities
		if ( ! current_user_can( LiveCarts::ADMIN_CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'live-carts-for-woocommerce' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- just checking which page we're on
		if ( isset( $_GET['email_id'] ) && $_GET['email_id'] !== 'new' ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- used for rendering edit page
			$this->renderEditPage( absint( $_GET['email_id'] ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- just checking which page we're on
		} elseif ( isset( $_GET['email_id'] ) && $_GET['email_id'] === 'new' ) {
			$this->renderEditPage( 0 );
		} else {
			$this->renderListPage();
		}
	}

	/**
	 * Renders the list page of recovery emails.
	 */
	private function renderListPage() {
		// Get all recovery emails
		$emails = get_posts( array(
			'post_type'      => RecoveryEmails::POST_TYPE,
			'post_status'    => array( 'publish', 'draft' ),
			'posts_per_page' => -1,
			'orderby'        => 'menu_order title',
			'order'          => 'ASC',
		) );

		?>
		<div class="wrap berrypress-page">
            <h2></h2>
			<div class="berrypress-page-content-header">
				<h1>
                    <?php esc_html_e( 'Cart Recovery Emails', 'live-carts-for-woocommerce' ); ?>
                    <span class="berrypress-page-content-header-badge"><?php esc_html_e( 'Beta', 'live-carts-for-woocommerce' ); ?></span>
                </h1>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=live-carts-for-woocommerce&recovery_emails=1&email_id=new' ) ); ?>" class="berrypress-btn berrypress-btn-primary">
					<i class="berrypress-icon-add"></i>
					<?php esc_html_e( 'Add New Email', 'live-carts-for-woocommerce' ); ?>
				</a>
			</div>

			<?php if ( empty( $emails ) ) : ?>
				<div class="berrypress-card berrypress-text-center phplugins-lc-recovery-emails-empty">
					<i class="berrypress-icon-filled berrypress-icon-email" style="font-size: 48px;"></i>
					<h2><?php esc_html_e( 'No recovery emails yet', 'live-carts-for-woocommerce' ); ?></h2>
					<p class="berrypress-text-secondary"><?php esc_html_e( 'Create your first recovery email to start recovering abandoned carts automatically.', 'live-carts-for-woocommerce' ); ?></p>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=live-carts-for-woocommerce&recovery_emails=1&email_id=new' ) ); ?>" class="berrypress-btn berrypress-btn-primary">
						<i class="berrypress-icon-add"></i>
						<?php esc_html_e( 'Create First Recovery Email', 'live-carts-for-woocommerce' ); ?>
					</a>
				</div>
			<?php else : ?>
				<div class="berrypress-table-wrapper">
				<table class="berrypress-table phplugins-lc-recovery-emails-table">
					<thead>
						<tr>
							<th class="column-subject"><?php esc_html_e( 'Email Subject', 'live-carts-for-woocommerce' ); ?></th>
							<th class="column-send-after"><?php esc_html_e( 'Send After', 'live-carts-for-woocommerce' ); ?></th>
							<th class="column-status"><?php esc_html_e( 'Status', 'live-carts-for-woocommerce' ); ?></th>
							<th class="column-actions"><?php esc_html_e( 'Actions', 'live-carts-for-woocommerce' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $emails as $email ) :
							$edit_url = admin_url( 'admin.php?page=live-carts-for-woocommerce&recovery_emails=1&email_id=' . $email->ID );
							$delete_url = wp_nonce_url(
								admin_url( 'admin.php?page=live-carts-for-woocommerce&recovery_emails=1&action=delete&email_id=' . $email->ID ),
								'delete-recovery-email-' . $email->ID
							);
						?>
							<tr>
								<td class="column-subject">
									<strong>
										<a href="<?php echo esc_url( $edit_url ); ?>" class="row-title">
											<?php echo esc_html( $email->post_title ?: __( '(No subject)', 'live-carts-for-woocommerce' ) ); ?>
										</a>
									</strong>
								</td>
								<td class="column-send-after"><?php echo esc_html( RecoveryEmails::getTimeIntervalString( $email->ID ) ); ?></td>
								<td class="column-status">
									<?php if ( $email->post_status === 'publish' ) : ?>
										<span class="phplugins-live-carts-status phplugins-live-carts-status-green"><?php esc_html_e( 'Active', 'live-carts-for-woocommerce' ); ?></span>
									<?php else : ?>
										<span class="phplugins-live-carts-status phplugins-lc-order-status"><?php esc_html_e( 'Draft', 'live-carts-for-woocommerce' ); ?></span>
									<?php endif; ?>
								</td>
								<td class="column-actions">
									<a href="<?php echo esc_url( $edit_url ); ?>" class="berrypress-btn berrypress-btn-secondary">
										<?php esc_html_e( 'Edit', 'live-carts-for-woocommerce' ); ?>
									</a>
									<a href="<?php echo esc_url( $delete_url ); ?>" class="berrypress-btn berrypress-btn-secondary" onclick="return confirm('<?php esc_attr_e( 'Are you sure you want to delete this recovery email?', 'live-carts-for-woocommerce' ); ?>');">
										<?php esc_html_e( 'Delete', 'live-carts-for-woocommerce' ); ?>
									</a>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				</div>

				<div class="berrypress-mt-4">
					<h3>
						<?php esc_html_e( 'Recommended Email Sequence', 'live-carts-for-woocommerce' ); ?>
					</h3>
					<ul>
						<li><?php esc_html_e( 'Email 1: Send after 1 hour with 0% discount (gentle reminder)', 'live-carts-for-woocommerce' ); ?></li>
						<li><?php esc_html_e( 'Email 2: Send after 24 hours with 5-10% discount (incentive)', 'live-carts-for-woocommerce' ); ?></li>
						<li><?php esc_html_e( 'Email 3: Send after 3 days with 10-15% discount (last chance)', 'live-carts-for-woocommerce' ); ?></li>
					</ul>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Renders the edit/create page for a recovery email.
	 *
	 * @param int $post_id Post ID (0 for new).
	 */
	private function renderEditPage( $post_id = 0 ) {
		$is_new = $post_id === 0;
		$post = $is_new ? null : get_post( $post_id );

		// Get current values or set defaults for new emails
		if ( $is_new ) {
			$subject = '[first_name], you left items in your cart!';
			$body = '<p>Hi [first_name],</p>

<p>We noticed you left some items in your shopping cart. Good news - we saved them for you!</p>

[cart_contents]

<p>Complete your purchase now and use coupon code <strong>[coupon]</strong> to save 10% on your order!</p>

<p><a href="[cart_url]">Click here to complete your purchase</a></p>

<p><small>This exclusive offer expires in 7 days.</small></p>

<p>Best regards,<br>
' . esc_html( wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES ) ) . '</p>';
		} else {
			if ( !$post || $post->post_type !== RecoveryEmails::POST_TYPE) {
				wp_die( esc_html__( 'Invalid email ID.', 'live-carts-for-woocommerce' ) );
			}
			$subject = $post->post_title;
			$body = $post->post_content;
		}
		
		$status = $is_new ? 'draft' : $post->post_status;
		$time_value = $is_new ? 24 : get_post_meta( $post_id, RecoveryEmails::META_TIME_VALUE, true );
		$time_unit = $is_new ? 'hours' : get_post_meta( $post_id, RecoveryEmails::META_TIME_UNIT, true );

		?>
		<div class="wrap berrypress-page">
			<h2></h2>
			<?php
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- just checking if message should be shown
			if ( isset( $_GET['saved'] ) ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( 'Recovery email saved successfully!', 'live-carts-for-woocommerce' ); ?></p>
				</div>
			<?php endif; ?>

			<div class="berrypress-page-content-header berrypress-lc-page-content-header">
				<div class="berrypress-lc-page-content-header-inner">
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=live-carts-for-woocommerce&recovery_emails=1' ) ); ?>" class="berrypress-btn-go-back"><i class="berrypress-icon-keyboard_arrow_left"></i></a>
					<h1><?php echo $is_new ? esc_html__( 'Add New Recovery Email', 'live-carts-for-woocommerce' ) : esc_html__( 'Edit Recovery Email', 'live-carts-for-woocommerce' ); ?></h1>
				</div>
				<button type="submit" form="lc-recovery-email-form" class="berrypress-btn berrypress-btn-primary">
					<?php esc_html_e( 'Save', 'live-carts-for-woocommerce' ); ?>
				</button>
			</div>

			<form id="lc-recovery-email-form" method="post" class="phplugins-lc-recovery-email-form">
				<?php wp_nonce_field( 'save-recovery-email-' . $post_id ); ?>
				<input type="hidden" name="save_recovery_email" value="1">

				<div class="phplugins-lc-editor-grid">
					<!-- Main Content -->
					<div class="phplugins-lc-editor-main">
						<div class="berrypress-field berrypress-field-align-center berrypress-mb-3">
							<label for="email_subject"><?php esc_html_e( 'Subject', 'live-carts-for-woocommerce' ); ?></label>
							<input
								type="text"
								id="email_subject"
								name="email_subject"
								value="<?php echo esc_attr( $subject ); ?>"
								class="phplugins-lc-subject-input"
								placeholder="<?php esc_attr_e( 'e.g., [first_name], you forgot something in your cart!', 'live-carts-for-woocommerce' ); ?>"
								required
							>
						</div>

						<?php
						$recoveryEmailShortcodes = apply_filters( 'phplugins_live_carts_recovery_email_shortcodes', [
							'first_name'    => __( 'Customer\'s first name (if known)', 'live-carts-for-woocommerce' ),
							'cart_contents' => __( 'Formatted list of cart items', 'live-carts-for-woocommerce' ),
							'cart_url'      => __( 'Cart recovery URL', 'live-carts-for-woocommerce' ),
							'coupon'        => __( 'Generated coupon code (if discount is set) [PRO]', 'live-carts-for-woocommerce' )
						] );
						wp_localize_script( 'phplugins-live-carts-admin', 'phplugins_live_carts_RecoveryEmailShortcodes', $recoveryEmailShortcodes );
						add_filter( 'mce_external_plugins', [ $this, 'mceExternalPlugins' ], 10, 2 );
						add_filter( 'mce_buttons', [ $this, 'mceToolbarButtons' ], 10, 2 );
						wp_editor( $body, 'email_body', array(
							'textarea_rows' => 20,
							'media_buttons' => false,
							'teeny'         => false,
							'quicktags'     => true,
						) );
						?>
						<p class="phplugins-lc-docs-link berrypress-mt-2">
							<?php esc_html_e( 'Use shortcodes from the editor toolbar to personalize.', 'live-carts-for-woocommerce' ); ?>
							<a href="https://berrypress.com/docs/live-carts-for-woocommerce/recovery-emails/creating-a-recovery-email/" target="_blank" rel="noopener"><?php esc_html_e( 'Documentation →', 'live-carts-for-woocommerce' ); ?></a>
						</p>

					</div>

					<!-- Sidebar -->
					<div class="phplugins-lc-editor-sidebar">
						<div class="berrypress-card">
							<h3 class="berrypress-fs-14"><?php esc_html_e( 'Status', 'live-carts-for-woocommerce' ); ?></h3>
							<select id="email_status" name="email_status" class="widefat berrypress-mb-2">
								<option value="draft" <?php selected( $status, 'draft' ); ?>><?php esc_html_e( 'Draft', 'live-carts-for-woocommerce' ); ?></option>
								<option value="publish" <?php selected( $status, 'publish' ); ?>><?php esc_html_e( 'Active', 'live-carts-for-woocommerce' ); ?></option>
							</select>
							<h3 class="berrypress-fs-14 berrypress-mt-3"><?php esc_html_e( 'Send after', 'live-carts-for-woocommerce' ); ?></h3>
							<div class="phplugins-lc-time-input">
								<input
									type="number"
									name="time_value"
									value="<?php echo esc_attr( $time_value ); ?>"
									min="1"
									step="1"
									class="phplugins-lc-time-value"
									required
								>
								<select name="time_unit" class="phplugins-lc-time-unit">
									<option value="minutes" <?php selected( $time_unit, 'minutes' ); ?>><?php esc_html_e( 'Minutes', 'live-carts-for-woocommerce' ); ?></option>
									<option value="hours" <?php selected( $time_unit, 'hours' ); ?>><?php esc_html_e( 'Hours', 'live-carts-for-woocommerce' ); ?></option>
									<option value="days" <?php selected( $time_unit, 'days' ); ?>><?php esc_html_e( 'Days', 'live-carts-for-woocommerce' ); ?></option>
								</select>
							</div>
							<p class="berrypress-fs-13 berrypress-text-secondary"><?php esc_html_e( 'after cart was last seen', 'live-carts-for-woocommerce' ); ?></p>
							<p class="description">
								<?php esc_html__( 'Exact email send time may vary depending on when hourly scheduled tasks (cron) are triggered in WordPress.', 'live-carts-for-woocommerce' ); ?>
							</p>
						</div>

						<?php do_action('phplugins_live_carts_recovery_email_settings', $post); ?>
					</div>
				</div>
			</form>
		</div>
		<?php
	}

	// lc-hook-safety: skip — only returns modified array, no DB / IO / 3rd-party calls
	public function mceExternalPlugins( $plugins, $editor_id ) {
		if ( $editor_id === 'email_body' ) {
			$plugins['phplugins_live_carts_shortcodes'] = LiveCarts::$plugin_url . 'assets/js/lc-recovery-email-editor' . ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min' ) . '.js';
		}
		return $plugins;
	}

	// lc-hook-safety: skip — only returns modified array, no DB / IO / 3rd-party calls
	public function mceToolbarButtons( $buttons, $editor_id ) {
		if ( $editor_id === 'email_body' ) {
			array_unshift( $buttons, 'phplugins_live_carts_shortcodes', 'separator' );
		}
		return $buttons;
	}
}
