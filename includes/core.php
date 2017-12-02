<?php

/**
 * The core of the wallets plugin.
 *
 * Provides an API for other plugins and themes to perform wallet actions, bound to the current logged in user.
 */

// don't load directly
defined( 'ABSPATH' ) || die( '-1' );

include_once( 'shortcodes.php' );
include_once( 'admin-menu-adapter-list.php' );
include_once( 'admin-menu.php' );
include_once( 'admin-notices.php' );
include_once( 'json-api.php' );


if ( ! class_exists( 'Dashed_Slug_Wallets' ) ) {

	/**
	 * The core of this plugin.
	 *
	 * Provides an API for other plugins and themes to perform wallet actions, bound to the current logged in user.
	 */
	final class Dashed_Slug_Wallets {

		/** error code for exception thrown while getting user info */
		const ERR_GET_USERS_INFO = -101;

		/** error code for exception thrown while getting coins info */
		const ERR_GET_COINS_INFO = -102;

		/** error code for exception thrown while getting transactions */
		const ERR_GET_TRANSACTIONS = -103;

		/** error code for exception thrown while performing withdrawals */
		const ERR_DO_WITHDRAW = -104;

		/** error code for exception thrown while transferring funds between users */
		const ERR_DO_MOVE = -105;

		/** error code for exception thrown due to user not being logged in */
		const ERR_NOT_LOGGED_IN = -106;

		/** @internal */
		private static $_instance;

		/** @internal */
		private $account = "0";

		/** @internal */
		private $_notices;

		/** @internal */
		private static $table_name_txs = '';

		/** @internal */
		private static $table_name_adds = '';

		/** @internal */
		private function __construct() {
			Dashed_Slug_Wallets_Shortcodes::get_instance();
			$this->_notices = Dashed_Slug_Wallets_Admin_Notices::get_instance();

			if ( is_admin() ) {
				Dashed_Slug_Wallets_Admin_Menu::get_instance();
			} else {
				Dashed_Slug_Wallets_JSON_API::get_instance();
			}

			// wp actions
			add_action( 'admin_init', array( &$this, 'action_admin_init' ) );
			add_action( 'wp_loaded', array( &$this, 'action_wp_loaded' ) );
			add_action( 'wp_enqueue_scripts', array( &$this, 'action_wp_enqueue_scripts' ) );
			add_action( 'shutdown', 'Dashed_Slug_Wallets::flush_rules' );

			// actions defined by this plugin
			add_action( 'wallets_transaction',	array( &$this, 'action_wallets_transaction' ) );
			add_action( 'wallets_address',	array( &$this, 'action_wallets_address' ) );


			add_action( 'wallets_withdraw',		array( &$this, 'action_withdraw' ) );
			add_action( 'wallets_move_send',		array( &$this, 'action_move_send' ) );
			add_action( 'wallets_move_receive',	array( &$this, 'action_move_receive' ) );
			add_action( 'wallets_deposit',		array( &$this, 'action_deposit' ) );

			// wp_cron mechanism for double-checking for deposits
			add_filter( 'cron_schedules', array( &$this, 'filter_cron_schedules' ) );
			add_action( 'wallets_periodic_checks', array( &$this, 'action_wallets_periodic_checks') );
			if ( false === wp_next_scheduled( 'wallets_periodic_checks' ) ) {
				$cron_interval = get_option( 'wallets_cron_interval', 'wallets_five_minutes' );
				wp_schedule_event( time(), $cron_interval, 'wallets_periodic_checks' );
			}

			global $wpdb;
			self::$table_name_txs = "{$wpdb->prefix}wallets_txs";
			self::$table_name_adds = "{$wpdb->prefix}wallets_adds";
		}

		/**
		 * Returns the singleton core of this plugin that provides the application-facing API.
		 *
		 *  @api
		 *  @since 1.0.0
		 *  @return object The singleton instance of this class.
		 *
		 */
		 public static function get_instance() {
			if ( ! ( self::$_instance instanceof self ) ) {
				self::$_instance = new self();
			};
			return self::$_instance;
		}

		/**
		 * Notifications entrypoint. Bound to wallets_notify action, triggered from JSON API.
		 * Routes `-blocknotify` and `-walletnotify` notifications from the daemon.
		 *
		 * @internal
		 * @param string $type Can be 'wallet' or 'block'
		 * @param string $arg A txid for wallet type or a blockhash for block type
		 */
		public function notify( $notification ) {
			do_action(
				"wallets_notify_{$notification->type}_{$notification->symbol}",
				$notification->message
			);
		}

		/** @internal */
		public function action_wp_enqueue_scripts() {
			wp_enqueue_script(
				'knockout',
				'https://cdnjs.cloudflare.com/ajax/libs/knockout/3.4.0/knockout-min.js',
				array(),
				'3.4.0',
				true );

			wp_enqueue_script(
				'momentjs',
				plugins_url( 'moment.min.js', "wallets/assets/scripts/moment.min.js" ),
				array(),
				'2.17.1',
				true );

			if ( file_exists( DSWALLETS_PATH . '/assets/scripts/wallets-ko.min.js' ) ) {
				$ko_script = 'wallets-ko.min.js';
			} else {
				$ko_script = 'wallets-ko.js';
			}

			wp_enqueue_script(
				'wallets_ko',
				plugins_url( $ko_script, "wallets/assets/scripts/$ko_script" ),
				array( 'knockout', 'momentjs' ),
				false,
				true );

			if ( file_exists( DSWALLETS_PATH . '/assets/styles/wallets.min.css' ) ) {
				$front_styles = 'wallets.min.css';
			} else {
				$front_styles = 'wallets.css';
			}

			wp_enqueue_style(
				'wallets_styles',
				plugins_url( $front_styles, "wallets/assets/styles/$front_styles" ),
				array(),
				'2.0.1'
			);
		}

		/** @internal */
		public function action_admin_init() {
			global $wpdb;

			$table_name_txs = self::$table_name_txs;
			if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table_name_txs}'" ) != $table_name_txs ) {
				$this->_notices->error( sprintf(
						__( "%s could NOT create a transactions table (\"%s\") in the database. The plugin may not function properly.", 'wallets'),
						'Bitcoin and Altcoin Wallets',
						$table_name_txs
				) );
			}
		}

		/** @internal */
		public function action_wp_loaded() {
			$user = wp_get_current_user();
			$this->account = "$user->ID";
		}

		/** @internal */
		public static function action_activate() {

			// create or update db tables
			global $wpdb;
			$charset_collate = $wpdb->get_charset_collate();
			$table_name_txs = self::$table_name_txs;
			$table_name_adds = self::$table_name_adds;

			$installed_db_revision = intval( get_option( 'wallets_db_revision' ) );
			$current_db_revision = 2;

			if ( $installed_db_revision < $current_db_revision ) {

				require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );

				$sql = "CREATE TABLE {$table_name_txs} (
				id int(10) unsigned NOT NULL AUTO_INCREMENT,
				category enum('deposit','move','withdraw') NOT NULL COMMENT 'type of transaction',
				account bigint(20) unsigned NOT NULL COMMENT '{$wpdb->prefix}_users.ID',
				other_account bigint(20) unsigned DEFAULT NULL COMMENT '{$wpdb->prefix}_users.ID when category==move',
				address varchar(255) CHARACTER SET latin1 COLLATE latin1_bin NOT NULL DEFAULT '' COMMENT 'blockchain address when category==deposit or category==withdraw',
				txid varchar(255) CHARACTER SET latin1 COLLATE latin1_bin NOT NULL DEFAULT '' COMMENT 'blockchain transaction id',
				symbol varchar(5) NOT NULL COMMENT 'coin symbol (e.g. BTC for Bitcoin)',
				amount decimal(20,10) signed NOT NULL COMMENT 'amount plus any fees deducted from account',
				fee decimal(20,10) signed NOT NULL DEFAULT 0 COMMENT 'fees deducted from account',
				comment TEXT DEFAULT NULL COMMENT 'transaction comment',
				created_time datetime NOT NULL COMMENT 'when transaction was entered into the system in GMT',
				updated_time datetime NOT NULL COMMENT 'when transaction was last updated in GMT (e.g. for update to confirmations count)',
				confirmations mediumint unsigned DEFAULT 0 COMMENT 'amount of confirmations received from blockchain, or null for category==move',
				PRIMARY KEY  (id),
				INDEX account_idx (account),
				INDEX txid_idx (txid),
				UNIQUE KEY `uq_tx_idx` (`address`, `txid`)
				) $charset_collate;";

				dbDelta( $sql );

				$sql = "CREATE TABLE {$table_name_adds} (
				id int(10) unsigned NOT NULL AUTO_INCREMENT,
				account bigint(20) unsigned NOT NULL COMMENT '{$wpdb->prefix}_users.ID',
				symbol varchar(5) NOT NULL COMMENT 'coin symbol (e.g. BTC for Bitcoin)',
				address varchar(255) NOT NULL,
				created_time datetime NOT NULL COMMENT 'when address was requested in GMT',
				PRIMARY KEY  (id),
				INDEX retrieve_idx (account,symbol),
				INDEX lookup_idx (address),
				UNIQUE KEY `uq_ad_idx` (`address`, `symbol`)
				) $charset_collate;";

				dbDelta( $sql );

				if (	( $wpdb->get_var( "SHOW TABLES LIKE '{$table_name_txs}'" )	== $table_name_txs ) &&
						( $wpdb->get_var( "SHOW TABLES LIKE '{$table_name_adds}'" )	== $table_name_adds ) ) {

					update_option( 'wallets_db_revision', $current_db_revision );
				}
			}

			// access control
			$role = get_role( 'administrator' );
			$role->add_cap( 'manage_wallets' );

			// flush json api rules
			self::flush_rules();
		}

		/** @internal */
		public static function action_deactivate() {
			// will not remove DB tables for safety

			// access control
			$role = get_role( 'administrator' );
			$role->remove_cap( 'manage_wallets' );

			// flush json api rules
			self::flush_rules();
		}

		/** @internal */
		public static function flush_rules() {
			$is_apache = strpos( $_SERVER['SERVER_SOFTWARE'], 'pache' ) !== false;
			flush_rewrite_rules( $is_apache );
		}

		/**
		 * Returns the coin adapter for the symbol specified, or an associative array of all the adapters
		 * if the symbol is omitted.
		 *
		 * The adapters provide the low-level API for talking to the various wallets.
		 *
		 * @since 1.0.0
		 * @param string $symbol (Usually) three-letter symbol of the wallet's coin.
		 * @throws Exception If the symbol passed does not correspond to a coin adapter
		 * @return stdClass|array The adapter or array of adapters requested.
		 */
		public function get_coin_adapters( $symbol = null ) {
			static $adapters = null;
			if ( is_null( $adapters ) ) {
				$adapters = apply_filters( 'wallets_coin_adapters', array() );
			}
			if ( is_null( $symbol ) ) {
				return $adapters;
			}
			if ( ! is_string( $symbol ) ) {
				throw new Exception( __( 'The symbol for the requested coin adapter was not a string.', 'wallets' ), self::ERR_GET_COINS_INFO );
			}
			$symbol = strtoupper( $symbol );
			if ( ! isset ( $adapters[ $symbol ] ) || ! is_object( $adapters[ $symbol ] ) ) {
				throw new Exception( sprintf( __( 'The coin adapter for the symbol %s is not available.', 'wallets' ), $symbol ), self::ERR_GET_COINS_INFO );
			}
			return $adapters[ $symbol ];
		}

		/**
		 * Account ID corresponding to an address.
		 *
		 * Returns the WordPress user ID for the account that has the specified address in the specified coin's wallet.
		 *
		 * @since 1.0.0
		 * @param string $symbol (Usually) three-letter symbol of the wallet's coin.
		 * @param string $address The address
		 * @throws Exception If the address is not associated with an account.
		 * @return integer The WordPress user ID for the account found.
		 */
		public function get_account_id_for_address( $symbol, $address ) {
			global $wpdb;

			$table_name_adds = self::$table_name_adds;
			$account = $wpdb->get_var( $wpdb->prepare(
				"
				SELECT
					account
				FROM
					$table_name_adds a
				WHERE
					symbol = %s AND
					address = %s
				ORDER BY
					created_time DESC
				LIMIT 1
				",
				$symbol,
				$address
			) );

			if ( is_null( $account ) ) {
				throw new Exception( sprintf( __( 'Could not get account for %s address %s', 'wallets' ), $symbol, $address ), self::ERR_GET_COINS_INFO );
			}

			return intval( $account );
		}


		/**
		 * Get user's wallet balance.
		 *
		 * Get the current logged in user's total wallet balance for a specific coin. If a minimum number of confirmations
		 * is specified, only deposits with than number of confirmations or higher are counted. All withdrawals and
		 * moves are counted at all times.
		 *
		 * @api
		 * @since 1.0.0
		 * @param string $symbol (Usually) three-letter symbol of the wallet's coin.
		 * @param integer $minconf (optional) Minimum number of confirmations. If left out, the default adapter setting is used.
		 * @return float The balance.
		 */
		public function get_balance( $symbol, $minconf = null ) {
			$adapter = $this->get_coin_adapters( $symbol );

			if ( ! is_int( $minconf ) ) {
				$minconf = $adapter->get_minconf();
			}

			global $wpdb;
			$table_name_txs = self::$table_name_txs;
			$balance = $wpdb->get_var( $wpdb->prepare(
				"
					SELECT
						sum(amount)
					FROM
						$table_name_txs
					WHERE
						symbol = %s AND
						account = %s AND (
							confirmations >= %d OR
							category != 'deposit'
						)
				",
				$adapter->get_symbol(),
				intval( $this->account ),
				intval( $minconf )
			) );
			return floatval( $balance );
		}

		/**
		 * Get transactions of current logged in user.
		 *
		 * Returns the deposits, withdrawals and intra-user transfers initiated by the current logged in user
		 * for the specified coin.
		 *
		 * @api
		 * @since 1.0.0
		 * @param string $symbol Character symbol of the wallet's coin.
		 * @param integer $count Maximal number of transactions to return.
		 * @param integer $from Start retrieving transactions from this offset.
		 * @param integer $minconf (optional) Minimum number of confirmations for deposits and withdrawals. If left out, the default adapter setting is used.
		 * @return array The transactions.
		 */
		 public function get_transactions( $symbol, $count = 10, $from = 0, $minconf = null ) {
			$adapter = $this->get_coin_adapters( $symbol );

			if ( ! is_int( $minconf ) ) {
				$minconf = $adapter->get_minconf();
			}

			global $wpdb;
			$table_name_txs = self::$table_name_txs;
			$txs = $wpdb->get_results( $wpdb->prepare(
				"
					SELECT
						txs.*,
						u.user_login other_account_name
					FROM
						$table_name_txs txs
					LEFT JOIN
						{$wpdb->users} u
					ON ( u.ID = txs.other_account )
					WHERE
						txs.account = %d AND
						txs.symbol = %s AND
						( txs.confirmations >= %d OR txs.category = 'move' )
					ORDER BY
						created_time
					LIMIT
						$from, $count
				",
				intval( $this->account ),
				$symbol,
				intval( $minconf )
			) );

			foreach ( $txs as &$tx ) {
				unset( $tx->id );
			}

			return $txs;
		}


		/**
		 * Withdraw from current logged in user's account.
		 *
		 * @api
		 * @since 1.0.0
		 * @param string $symbol Character symbol of the wallet's coin.
		 * @param string $address Address to withdraw to.
		 * @param float $amount Amount to withdraw to.
		 * @param string $comment Optional comment to attach to the transaction.
		 * @param string $comment_to Optional comment to attach to the destination address.
		 */
		 public function do_withdraw( $symbol, $address, $amount, $comment = '', $comment_to = '' ) {
			$adapter = $this->get_coin_adapters( $symbol );

			global $wpdb;

			$table_name_txs = self::$table_name_txs;
			$table_name_adds = self::$table_name_adds;
			$table_name_options = $wpdb->options;

			$wpdb->query( "LOCK TABLES $table_name_txs WRITE, $table_name_options WRITE, $table_name_adds READ" );

			try {
				$balance = $this->get_balance( $symbol );
				$fee = $adapter->get_withdraw_fee();
				$amount_plus_fee = $amount + $fee;

				if ( $amount < 0) {
					throw new Exception( __( 'Cannot withdraw negative amount', 'wallets' ), self::ERR_DO_WITHDRAW );
				}
				if ( $balance < $amount_plus_fee) {
					throw new Exception( sprintf( __( 'Insufficient funds: %f + %f fees < %f', 'wallets' ), $amount, $fee, $balance ), self::ERR_DO_WITHDRAW );
				}

				$txid = $adapter->do_withdraw( $address, $amount, $comment, $comment_to );

				if ( ! is_string( $txid ) ) {
					throw new Exception( __( 'Adapter did not return TXID for withdrawal', 'wallets' ), self::ERR_DO_WITHDRAW );
				}

				$current_time_gmt = current_time( 'mysql', true );

				$txrow = new stdClass();
				$txrow->category = 'withdraw';
				$txrow->account = intval( $this->account );
				$txrow->address = $address;
				$txrow->txid = $txid;
				$txrow->symbol = $symbol;
				$txrow->amount = -floatval( $amount_plus_fee );
				$txrow->fee = $fee;
				$txrow->created_time = time();
				$txrow->updated_time = $txrow->created_time;
				$txrow->comment = $comment;

				do_action( 'wallets_transaction', $txrow );

			} catch ( Exception $e ) {
				$wpdb->query( 'UNLOCK TABLES' );
				throw $e;
			}
			$wpdb->query( 'UNLOCK TABLES' );

			do_action( 'wallets_withdraw', (array)$txrow );
		}

		/**
		 * Move funds from the current logged in user's balance to the specified user.
		 *
		 * @api
		 * @since 1.0.0
		 * @param string $symbol Character symbol of the wallet's coin.
		 * @param integer $toaccount The WordPress user_ID of the recipient.
		 * @param float $amount Amount to withdraw to.
		 * @param string $comment Optional comment to attach to the transaction.
		 */
		public function do_move( $symbol, $toaccount, $amount, $comment) {
			$adapter = $this->get_coin_adapters( $symbol );

			global $wpdb;

			$table_name_txs = self::$table_name_txs;
			$table_name_adds = self::$table_name_adds;
			$table_name_options = $wpdb->options;

			$wpdb->query( "LOCK TABLES $table_name_txs WRITE, $table_name_options WRITE, $table_name_adds READ" );

			try {
				$balance = $this->get_balance( $symbol );
				$fee = $adapter->get_move_fee();
				$amount_plus_fee = $amount + $fee;

				if ( $amount < 0 ) {
					throw new Exception( __( 'Cannot move negative amount', 'wallets' ), self::ERR_DO_MOVE );
				}
				if ( $balance < $amount_plus_fee ) {
					throw new Exception( sprintf( __( 'Insufficient funds: %f + %f fees < %f', 'wallets' ), $amount, $fee, $balance ), self::ERR_DO_WITHDRAW );
				}

				$current_time_gmt = current_time( 'mysql', true );
				$txid = uniqid( 'move-', true );

				$row1 = array(
					'category' => 'move',
					'account' => intval( $this->account ),
					'other_account' => intval( $toaccount ),
					'txid' => "$txid-send",
					'symbol' => $symbol,
					'amount' => -$amount_plus_fee,
					'fee' => $fee,
					'created_time' => $current_time_gmt,
					'updated_time' => $current_time_gmt,
					'comment' => $comment
				);

				$affected = $wpdb->insert(
					self::$table_name_txs,
					$row1,
					array( '%s', '%d', '%d', '%s', '%s', '%20.10f', '%20.10f', '%s', '%s', '%s' )
				);

				if ( false === $affected ) {
					error_log( __FUNCTION__ . " Transaction was not recorded! Details: " . print_r( $row1, true ) );
				}

				$row2 = array(
					'category' => 'move',
					'account' => intval( $toaccount ),
					'other_account' => intval( $this->account ),
					'txid' => "$txid-receive",
					'symbol' => $symbol,
					'amount' => $amount,
					'fee' => 0,
					'created_time' => $current_time_gmt,
					'updated_time' => $current_time_gmt,
					'comment' => $comment
				);

				$wpdb->insert(
					self::$table_name_txs,
					$row2,
					array( '%s', '%d', '%d', '%s', '%s', '%20.10f', '%20.10f', '%s', '%s', '%s' )
				);

				if ( false === $affected ) {
					error_log( __FUNCTION__ . " Transaction was not recorded! Details: " . print_r( $row2, true ) );
				}

			} catch ( Exception $e ) {
				$wpdb->query( 'UNLOCK TABLES' );
				throw $e;
			}
			$wpdb->query( 'UNLOCK TABLES' );

			do_action( 'wallets_move_send', $row1 );
			do_action( 'wallets_move_receive', $row2 );
		}

		/**
		 * Get a deposit address for the current logged in user's account.
		 *
		 * @api
		 * @since 1.0.0
		 * @param string $symbol Character symbol of the wallet's coin.
		 * @return string A deposit address associated with the current logged in user.
		 */
		 public function get_new_address( $symbol ) {

			$adapter = $this->get_coin_adapters( $symbol );

			global $wpdb;
			$table_name_adds = self::$table_name_adds;
			$address = $wpdb->get_var( $wpdb->prepare(
				"
					SELECT
						address
					FROM
						$table_name_adds a
					WHERE
						account = %d AND
						symbol = %s
					ORDER BY
						created_time DESC
					LIMIT 1
				",
				intval( $this->account ),
				$symbol
			) );

			if ( ! is_string( $address ) ) {
				$address = $adapter->get_new_address();
				$current_time_gmt = current_time( 'mysql', true );

				$address_row = new stdClass();
				$address_row->account = $this->account;
				$address_row->symbol = $symbol;
				$address_row->address = $address;
				$address_row->created_time = $current_time_gmt;

				// trigger action that inserts user-address mapping to db
				do_action( 'wallets_address', $address_row );
			}

			return $address;

		} // function get_new_address()


		//////// notification API

		/**
		 * Handler attached to the action wallets_transaction.
		 *
		 * Called by the coin adapter when a transaction is first seen or is updated.
		 * Adds new deposits and updates confirmation counts for existing deposits and withdrawals.
		 *
		 * @internal
		 * @param stdClass $tx The transaction details.
		 */
		public function action_wallets_transaction( $tx ) {
			$adapter = $this->get_coin_adapters( $tx->symbol );

			if ( false !== $adapter ) {
				$created_time = date( DATE_ISO8601, $tx->created_time );
				$current_time_gmt = current_time( 'mysql', true );
				$table_name_txs = self::$table_name_txs;

				global $wpdb;

				if ( isset( $tx->category ) ) {

					if ( 'deposit' == $tx->category ) {
						try {
							$account_id = $this->get_account_id_for_address( $tx->symbol, $tx->address );
						} catch ( Exception $e ) {
							// we don't know about this address - ignore it
							return;
						}

						$affected = $wpdb->query( $wpdb->prepare(
							"
								INSERT INTO $table_name_txs(
									category,
									account,
									address,
									txid,
									symbol,
									amount,
									created_time,
									updated_time,
									confirmations)
								VALUES(%s,%d,%s,%s,%s,%20.10f,%s,%s,%d)
								ON DUPLICATE KEY UPDATE updated_time = %s , confirmations = %d
							",
							$tx->category,
							$account_id,
							$tx->address,
							$tx->txid,
							$tx->symbol,
							$tx->amount,
							$created_time,
							$current_time_gmt,
							$tx->confirmations,

							$current_time_gmt,
							$tx->confirmations
						) );

						$row = array(
							'account'		=>	$account_id,
							'address'		=>	$tx->address,
							'txid'			=>	$tx->txid,
							'symbol'		=>	$tx->symbol,
							'amount'		=>	$tx->amount,
							'created_time'	=>	$created_time,
							'confirmations'	=>	$tx->confirmations
						);

						if ( false === $affected ) {
							error_log( __FUNCTION__ . " Transaction was not recorded! Details: " . print_r( $row, true ) );
						}

						if ( 1 === $affected ) {
							// row was inserted, not updated
							do_action( 'wallets_deposit', $row );
						}

					} elseif ( 'withdraw' == $tx->category ) {

						$affected = 0;

						// try to record as new withdrawal if this is not an old transaction
						// old transactions that are rediscovered via cron do not have an account id

						if ( isset( $tx->account ) )  {

							$affected = $wpdb->insert(
								self::$table_name_txs,
								array(
									'category' => 'withdraw',
									'account' => $tx->account,
									'address' => $tx->address,
									'txid' => $tx->txid,
									'symbol' => $tx->symbol,
									'amount' => $tx->amount,
									'fee' => $tx->fee,
									'comment' => $tx->comment,
									'created_time' =>	$created_time,
									'confirmations'	=> 0
								),
								array( '%s', '%d', '%s', '%s', '%s', '%20.10f', '%20.10f', '%s', '%s', '%d' )
							);
						}

						if ( 1 != $affected ) {

							// this is a withdrawal update. set confirmations.

							$wpdb->update(
								self::$table_name_txs,
								array(
									'updated_time'	=> $current_time_gmt,
									'confirmations'	=> $tx->confirmations,
								),
								array(
									'address'		=> $tx->address,
									'txid'			=> $tx->txid,
								),
								array( '%s', '%d' ),
								array( '%s', '%s' )
							);

							if ( false === $affected ) {
								error_log( __FUNCTION__ . " Transaction was not recorded! Details: " . print_r( $row, true ) );
							}
						}

					} // end if category == withdraw
				} // end if isset category
			} // end if false !== $adapter
		} // end function action_wallets_transaction()

		/**
		 * Handler attached to the action wallets_address.
		 *
		 * Called by core or the coin adapter when a new user-address mapping is seen..
		 * Adds the link between an address and a user.
		 * Core should always record new addresses. Adapters that choose to notify about
		 * user-address mappings do so as a failsafe mechanism only. Addresses that have
		 * already been assigned are not reaassigned because the address column is UNIQUE
		 * on the DB.
		 *
		 * @internal
		 * @param stdClass $tx The address mapping.
		 */
		public function action_wallets_address( $address ) {
			global $wpdb;
			$table_name_adds = self::$table_name_adds;

			$wpdb->insert(
				$table_name_adds,
				(array) $address,
				array( '%d', '%s', '%s', '%s' )
			);
		}

		/** @internal */
		public function action_withdraw( $row ) {
			$user = get_userdata( $row['account'] );
			$row['account'] = $user->user_login;

			$this->notify_user_by_email(
				$user->user_email,
				__( 'You have performed a withdrawal.', 'wallets' ),
				$row
			);
		}

		/** @internal */
		public function action_move_send( $row ) {
			$sender = get_userdata( $row['account'] );
			$recipient = get_userdata( $row['other_account'] );

			$row['account'] = $sender->user_login;
			$row['other_account'] = $recipient->user_login;

			$this->notify_user_by_email(
				$sender->user_email,
				__( 'You have sent funds to another user.', 'wallets' ),
				$row
			);
		}

		/** @internal */
		public function action_move_receive( $row ) {
			$recipient = get_userdata( $row['account'] );
			$sender = get_userdata( $row['other_account'] );

			$row['account'] = $recipient->user_login;
			$row['other_account'] = $sender->user_login;
			unset( $row['fee'] );

			$this->notify_user_by_email(
				$recipient->user_email,
				__( 'You have received funds from another user.', 'wallets' ),
				$row
			);
		}

		/** @internal */
		public function action_deposit( $row ) {
			$user = get_userdata( $row['account'] );
			$row['account'] = $user->user_login;

			$this->notify_user_by_email(
				$user->user_email,
				__( 'You have performed a deposit.', 'wallets' ),
				$row
			);
		}

		/** @internal */
		private function notify_user_by_email( $email, $subject, &$row ) {
			unset( $row['category'] );
			unset( $row['updated_time'] );

			$full_message = "$subject\n" . __( 'Transaction details follow', 'wallets' ) . ":\n\n";
			foreach ( $row as $field => $val ) {
				$full_message .= "$field : $val\n";
			}

			try {
				wp_mail(
					$email,
					$subject,
					$full_message
				);
			} catch ( Exception $e ) {
				$this->_notices->error(
					__( "The following errors occured while sending notification email to $email: ", 'wallets' ) .
					$e->getMessage()
				);
			}
		}

		/**
		 * Register some wp_cron intervals on which we can bind
		 * action_ensure_transaction_notify .
		 *
		 * @internal
		 */
		public function filter_cron_schedules( $schedules ) {
			$schedules['wallets_five_minutes'] = array(
				'interval' => 5 * MINUTE_IN_SECONDS,
				'display'  => esc_html__( 'Every five minutes', 'wallets' ),
			);

			$schedules['wallets_ten_minutes'] = array(
				'interval' => 10 * MINUTE_IN_SECONDS,
				'display'  => esc_html__( 'Every ten minutes', 'wallets' ),
			);

			$schedules['wallets_twenty_minutes'] = array(
				'interval' => 20 * MINUTE_IN_SECONDS,
				'display'  => esc_html__( 'Every twenty minutes', 'wallets' ),
			);

			$schedules['wallets_thirty_minutes'] = array(
				'interval' => 30 * MINUTE_IN_SECONDS,
				'display'  => esc_html__( 'Every half an hour', 'wallets' ),
			);

			$schedules['wallets_one_hour'] = array(
				'interval' => 1 * HOUR_IN_SECONDS,
				'display'  => esc_html__( 'Every one hour', 'wallets' ),
			);

			$schedules['wallets_four_hours'] = array(
				'interval' => 4 * HOUR_IN_SECONDS,
				'display'  => esc_html__( 'Every four hours', 'wallets' ),
			);

			return $schedules;
		}

		/**
		 * Trigger the cron function of each adapter.
		 *
		 * Deposit addresses and deposits can be overlooked if the RPC callback notification
		 * mechanism is not correctly setup, or if something else goes wrong.
		 * Adapters can offer a cron() function that does periodic checks for these things.
		 * Adapters can discover overlooked addresses and transactions and trigger the actions
		 * wallets_transaction and wallets_address
		 *
		 * For some adapters this will be a failsafe-check and for others it will be the main mechanism
		 * of polling for deposits. Adapters can also opt to not offer a cron() method.
		 * @internal
		 * @since 2.0.0
		 *
		 */
		public function action_wallets_periodic_checks( ) {
			foreach ( $this->get_coin_adapters() as $adapter ) {
				if ( method_exists( $adapter, 'cron' ) ) {
					try {
						$adapter->cron();
					} catch ( Exception $e ) {
						error_log(
							sprintf( 'Function %s failed to run cron() on adapter %s and coin %s due to: %s',
								__FUNCTION__,
								$adapter->get_adapter_name(),
								$adapter->get_name(),
								$e->getMessage()
							)
						);
					}
				}
			}
		}
	}
}

// Instantiate
Dashed_Slug_Wallets::get_instance();