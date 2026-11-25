<?php
namespace BerryPress\LiveCarts;

defined('ABSPATH') || exit;

class Setup {
	
	public static $dbErrors = [];
	
	static function createDatabaseTables($table=null) {
		global $wpdb;
		$charset_collate = $wpdb->get_charset_collate();
		$sql = [
			'phplugins_carts' => 'CREATE TABLE '.$wpdb->prefix.'phplugins_carts (
			  cart_id bigint(20) NOT NULL AUTO_INCREMENT PRIMARY KEY,
			  order_id bigint(20) NOT NULL,
			  user_id bigint(20) NOT NULL,
			  created datetime NOT NULL,
			  last_seen datetime NOT NULL,
			  status varchar(24) NOT NULL,
			  type varchar(128) NOT NULL DEFAULT \'cart\',
			  ip_address varchar(39) NOT NULL,
			  value double(12,2) NOT NULL,
			  archived tinyint(1) NOT NULL
			)',
			'phplugins_cart_contents' => 'CREATE TABLE '.$wpdb->prefix.'phplugins_cart_contents (
			  cart_id bigint(20) NOT NULL,
			  ts datetime NOT NULL,
			  contents varchar(2048) NOT NULL,
			  FOREIGN KEY (cart_id) REFERENCES '.$wpdb->prefix.'phplugins_carts (cart_id)
			)',
			'phplugins_cart_contents_items' => 'CREATE TABLE IF NOT EXISTS '.$wpdb->prefix.'phplugins_cart_contents_items (
			  item_id bigint(20) NOT NULL AUTO_INCREMENT PRIMARY KEY,
			  contents_id bigint(20) NOT NULL,
			  product_id bigint(20) NOT NULL,
			  variation_id bigint(20) NOT NULL,
			  quantity double NOT NULL,
			  line_subtotal double NOT NULL,
			  line_subtotal_tax double NOT NULL,
			  line_total double NOT NULL,
			  line_tax double NOT NULL,
			  meta text NOT NULL,
			  FOREIGN KEY (contents_id) REFERENCES '.$wpdb->prefix.'phplugins_cart_contents (contents_id) ON DELETE CASCADE
			)',
			'phplugins_recovery_emails_sent' => 'CREATE TABLE IF NOT EXISTS '.$wpdb->prefix.'phplugins_recovery_emails_sent (
				sent_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				cart_id bigint(20) unsigned NOT NULL,
				email_id bigint(20) unsigned NOT NULL,
				sent_at datetime NOT NULL,
				PRIMARY KEY  (sent_id),
				KEY email_id (email_id),
				UNIQUE KEY cart_email (cart_id, email_id)
			) '.$charset_collate
		];
		
		if ($table === null) {
			unset($sql['phplugins_cart_contents_items']);
			foreach ($sql as $tableSql) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- static sql
				$wpdb->query($tableSql);
				if (!empty($wpdb->last_error)) {
					self::$dbErrors[] = $wpdb->last_error;
				}
			}
		} else if (isset($sql[$table])) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- static sql
			$wpdb->query($sql[$table]);
			if (!empty($wpdb->last_error)) {
				self::$dbErrors[] = $wpdb->last_error;
			}
		} else {
			throw new Exception();
		}
		
	}
	
	static function upgradeDatabaseTables($fromVersion) {
		global $wpdb;
		$sql = [
			'1.0.12' => [
				'ALTER TABLE '.$wpdb->prefix.'phplugins_cart_contents CHANGE contents contents text NOT NULL',
				'ALTER TABLE '.$wpdb->prefix.'phplugins_cart_contents ADD FOREIGN KEY key_cart_id (cart_id) REFERENCES '.$wpdb->prefix.'phplugins_carts (cart_id)'
			],
			'1.0.6' => [
				'ALTER TABLE '.$wpdb->prefix.'phplugins_carts ADD coupon varchar(128) NOT NULL AFTER value, ADD last_url varchar(4096) NOT NULL AFTER ip_address'
			],
			'1.3.0' => [
				function() use ( $wpdb ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$hasTypeColumn = $wpdb->get_var(
						$wpdb->prepare(
							'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = %s',
							DB_NAME,
							$wpdb->prefix . 'phplugins_carts',
							'type'
						)
					);
					if ( ! $hasTypeColumn ) {
						// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
						$wpdb->query(
							// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- version based database schema changes on custom table
							'ALTER TABLE ' . $wpdb->prefix . 'phplugins_carts ADD type varchar(128) NOT NULL DEFAULT \'cart\' AFTER status'
						);
						if ( ! empty( $wpdb->last_error ) ) {
							self::$dbErrors[] = $wpdb->last_error;
						}
					}

					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$wpdb->query(
						'UPDATE ' . $wpdb->prefix . 'phplugins_carts SET type=\'cart\' WHERE type=\'\' OR type IS NULL'
					);
					if ( ! empty( $wpdb->last_error ) ) {
						self::$dbErrors[] = $wpdb->last_error;
					}
				},
			],
			'1.2.0' => [
				// Migration to separate cart items table
				function() use ($wpdb) {
					// Check if migration was already completed
					if (!LiveCarts::isMigrationNeeded()) {
						// Migration already done but option not set
						if (!get_option('phplugins_carts_migration_1_2_0_complete')) {
							update_option('phplugins_carts_migration_1_2_0_complete', time(), false);
						}
						return; // Migration already completed
					}
					
					// Step 1: Add contents_id column to phplugins_cart_contents if it doesn't exist
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$hasContentsId = $wpdb->get_var(
						$wpdb->prepare(
							'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = %s',
							DB_NAME, $wpdb->prefix . 'phplugins_cart_contents', 'contents_id'
						)
					);
					if (!$hasContentsId) {
						// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
						$wpdb->query(
							// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- version based database schema changes on custom table
							'ALTER TABLE '.$wpdb->prefix.'phplugins_cart_contents 
							ADD COLUMN contents_id bigint(20) NOT NULL AUTO_INCREMENT PRIMARY KEY FIRST'
						);
						if (!empty($wpdb->last_error)) {
							self::$dbErrors[] = $wpdb->last_error;
						}
					}
					
					// Step 2: Create new table for cart content items
					self::createDatabaseTables('phplugins_cart_contents_items');
					
					// Steps 3 & 4: Migrate data and drop old column (only if contents column still exists)
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$hasContentsColumn = $wpdb->get_var(
						$wpdb->prepare(
							'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = %s',
							DB_NAME, $wpdb->prefix . 'phplugins_cart_contents', 'contents'
						)
					);
					
					if ($hasContentsColumn) {
					// Step 3: Migrate existing data

					for ($offset = 0; ; $offset += 250) {
						
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$existingContents = $wpdb->get_results(
						$wpdb->prepare('SELECT contents_id, cart_id, contents FROM '.$wpdb->prefix.'phplugins_cart_contents ORDER BY contents_id ASC LIMIT %d,250', $offset),
						ARRAY_A
					);
					if (!empty($wpdb->last_error)) {
						self::$dbErrors[] = $wpdb->last_error;
					}

					if (!$existingContents) {
						break;
					}
					
					foreach ($existingContents as $row) {
						$contents = json_decode($row['contents'], true);
						if (empty($contents) || !is_array($contents)) {
							continue;
						}
						
						// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
						$wpdb->query( $wpdb->prepare('DELETE FROM '.$wpdb->prefix.'phplugins_cart_contents_items WHERE contents_id=%d', $row['contents_id']) );
						
						if (!empty($wpdb->last_error)) {
							self::$dbErrors[] = $wpdb->last_error;
						}
						
						foreach ($contents as $cartItem) {
							$meta = $cartItem;
							// Remove fields that have dedicated columns
							unset($meta['product_id'], $meta['variation_id'], $meta['quantity'], 
								  $meta['line_subtotal'], $meta['line_subtotal_tax'], 
								  $meta['line_total'], $meta['line_tax']);
							
							// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
							if ($wpdb->insert(
								$wpdb->prefix.'phplugins_cart_contents_items',
								[
									'contents_id' => $row['contents_id'],
									'product_id' => isset($cartItem['product_id']) ? (int) $cartItem['product_id'] : 0,
									'variation_id' => isset($cartItem['variation_id']) ? (int) $cartItem['variation_id'] : 0,
									'quantity' => isset($cartItem['quantity']) ? (float) $cartItem['quantity'] : 0,
									'line_subtotal' => isset($cartItem['line_subtotal']) ? (float) $cartItem['line_subtotal'] : 0,
									'line_subtotal_tax' => isset($cartItem['line_subtotal_tax']) ? (float) $cartItem['line_subtotal_tax'] : 0,
									'line_total' => isset($cartItem['line_total']) ? (float) $cartItem['line_total'] : 0,
									'line_tax' => isset($cartItem['line_tax']) ? (float) $cartItem['line_tax'] : 0,
									'meta' => wp_json_encode($meta)
								],
								['%d', '%d', '%d', '%f', '%f', '%f', '%f', '%f', '%s']
							) !== 1) {
								if (!empty($wpdb->last_error)) {
									self::$dbErrors[] = $wpdb->last_error;
								}
								throw new Exception();
							}
						}
					}

					$wpdb->flush();

					}
					
					// Step 4: Remove contents column from phplugins_cart_contents
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$wpdb->query(
						// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- version based database schema changes on custom table
						'ALTER TABLE '.$wpdb->prefix.'phplugins_cart_contents DROP COLUMN contents'
					);
					
					if (!empty($wpdb->last_error)) {
						self::$dbErrors[] = $wpdb->last_error;
					}
					} // end if ($hasContentsColumn)
					
					if (empty(self::$dbErrors)) {
						// Mark migration as complete
						update_option('phplugins_carts_migration_1_2_0_complete', time(), false);
					}
				}
			],
			'1.3.1' => [
				'ALTER TABLE '.$wpdb->prefix.'phplugins_carts ADD trashed tinyint(1) NOT NULL DEFAULT 0 AFTER status'
			],
			'2.0.0' => [
				function() {
					global $wpdb;
					self::createDatabaseTables('phplugins_recovery_emails_sent');
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					if (!$wpdb->get_var('SHOW COLUMNS FROM '.$wpdb->prefix.'phplugins_carts LIKE "recovery_key"')) {
						// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- version based database schema changes on custom table
						$wpdb->query('ALTER TABLE '.$wpdb->prefix.'phplugins_carts ADD COLUMN recovery_key VARCHAR(32) DEFAULT NULL UNIQUE');
					}
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					if (!$wpdb->get_var('SHOW COLUMNS FROM '.$wpdb->prefix.'phplugins_carts LIKE "email"')) {
						// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- version based database schema changes on custom table
						$wpdb->query('ALTER TABLE '.$wpdb->prefix.'phplugins_carts ADD email varchar(254) DEFAULT NULL AFTER ip_address');
					}
				}
			]
		];
		
		foreach ($sql as $sqlFromVersion => $versionSql) {
			if (version_compare($fromVersion, $sqlFromVersion, '>=')) {
				continue;
			}
			foreach ($versionSql as $sqlStatement) {
				if (is_callable($sqlStatement)) {
					// Execute migration function
					$sqlStatement();
				} else {
					// Execute SQL string - migration statements are static and safe
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- version-based schema changes, static SQL
					@$wpdb->query($sqlStatement);
					
					if (!empty($wpdb->last_error)) {
						self::$dbErrors[] = $wpdb->last_error;
					}
				}
			}
		}
	}

}
