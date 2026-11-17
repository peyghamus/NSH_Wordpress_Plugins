<?php
/**
 * Plugin Name: NSH Membership Simple Verification
 * Description: Simple email-first membership verification, trial/payment demo, and organizer code validation.
 * Version: 1.0.3
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: NSH
 * License: GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'NSHMSV_DB_VERSION', '1' );
define( 'NSHMSV_TRIAL_LIMIT', 3 );

function nshmsv_members_table() {
	global $wpdb;

	return $wpdb->prefix . 'nshmsv_members';
}

function nshmsv_codes_table() {
	global $wpdb;

	return $wpdb->prefix . 'nshmsv_codes';
}

function nshmsv_install_tables() {
	global $wpdb;

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$charset_collate = $wpdb->get_charset_collate();
	$members_table   = nshmsv_members_table();
	$codes_table     = nshmsv_codes_table();

	$members_sql = "CREATE TABLE $members_table (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		email varchar(190) NOT NULL,
		full_name varchar(190) NOT NULL DEFAULT '',
		membership_status varchar(20) NOT NULL DEFAULT 'trial',
		trials_used smallint(5) unsigned NOT NULL DEFAULT 0,
		created_at datetime NOT NULL,
		updated_at datetime NOT NULL,
		PRIMARY KEY  (id),
		UNIQUE KEY email (email)
	) $charset_collate;";

	$codes_sql = "CREATE TABLE $codes_table (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		verification_code char(10) NOT NULL,
		email varchar(190) NOT NULL,
		event_ref varchar(190) NOT NULL DEFAULT '',
		created_at datetime NOT NULL,
		PRIMARY KEY  (id),
		UNIQUE KEY verification_code (verification_code),
		KEY email (email),
		KEY event_ref (event_ref)
	) $charset_collate;";

	dbDelta( $members_sql );
	dbDelta( $codes_sql );
	update_option( 'nshmsv_db_version', NSHMSV_DB_VERSION );
}

function nshmsv_ensure_page( $option_name, $title, $slug, $shortcode ) {
	$page_id = absint( get_option( $option_name ) );
	if ( $page_id && 'publish' === get_post_status( $page_id ) ) {
		return $page_id;
	}

	$existing = get_page_by_path( $slug, OBJECT, 'page' );
	if (
		$existing &&
		'publish' === $existing->post_status &&
		has_shortcode( $existing->post_content, $shortcode )
	) {
		$page_id = $existing->ID;
	} else {
		$page_id = wp_insert_post(
			array(
				'post_title'   => $title,
				'post_name'    => $slug,
				'post_status'  => 'publish',
				'post_type'    => 'page',
				'post_content' => '[' . $shortcode . ']',
			),
			true
		);
	}

	if ( is_wp_error( $page_id ) ) {
		return $page_id;
	}

	update_option( $option_name, $page_id );
	return $page_id;
}

function nshmsv_ensure_pages() {
	$pages = array(
		array( 'nshmsv_verification_page_id', 'Membership Verification', 'membership-verification', 'nshmsv_verification' ),
		array( 'nshmsv_signup_page_id', 'Membership Sign Up', 'membership-sign-up', 'nshmsv_signup' ),
		array( 'nshmsv_payment_page_id', 'Membership Payment Demo', 'membership-payment-demo', 'nshmsv_payment' ),
		array( 'nshmsv_organizer_page_id', 'Organizer Code Validation', 'organizer-code-validation', 'nshmsv_organizer' ),
	);

	foreach ( $pages as $page ) {
		$result = nshmsv_ensure_page( $page[0], $page[1], $page[2], $page[3] );
		if ( is_wp_error( $result ) ) {
			error_log( 'NSH Membership Simple Verification: ' . $result->get_error_message() );
		}
	}
}

function nshmsv_activate() {
	nshmsv_install_tables();
	nshmsv_ensure_pages();
}
register_activation_hook( __FILE__, 'nshmsv_activate' );

function nshmsv_maybe_upgrade() {
	if ( NSHMSV_DB_VERSION !== get_option( 'nshmsv_db_version' ) ) {
		nshmsv_install_tables();
	}

	nshmsv_ensure_pages();
}
add_action( 'plugins_loaded', 'nshmsv_maybe_upgrade' );

function nshmsv_page_url( $option_name ) {
	$page_id = absint( get_option( $option_name ) );
	$url     = $page_id ? get_permalink( $page_id ) : false;

	return $url ? $url : home_url( '/' );
}

function nshmsv_get_member( $email, $for_update = false ) {
	global $wpdb;

	$sql = $wpdb->prepare(
		'SELECT * FROM ' . nshmsv_members_table() . ' WHERE email = %s LIMIT 1' . ( $for_update ? ' FOR UPDATE' : '' ),
		$email
	);

	return $wpdb->get_row( $sql );
}

function nshmsv_get_event_ref() {
	if ( ! isset( $_GET['event'] ) || ! is_string( $_GET['event'] ) ) {
		return '';
	}

	return substr( sanitize_text_field( wp_unslash( $_GET['event'] ) ), 0, 190 );
}

function nshmsv_existing_event_code( $email, $event_ref ) {
	global $wpdb;

	if ( '' === $event_ref ) {
		return null;
	}

	return $wpdb->get_var(
		$wpdb->prepare(
			'SELECT verification_code FROM ' . nshmsv_codes_table() . ' WHERE email = %s AND event_ref = %s ORDER BY id ASC LIMIT 1',
			$email,
			$event_ref
		)
	);
}

function nshmsv_create_code( $email, $event_ref ) {
	global $wpdb;

	$alphabet = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
	for ( $attempt = 0; $attempt < 5; $attempt++ ) {
		$code = '';
		for ( $i = 0; $i < 10; $i++ ) {
			$code .= $alphabet[ random_int( 0, strlen( $alphabet ) - 1 ) ];
		}

		$inserted = $wpdb->insert(
			nshmsv_codes_table(),
			array(
				'verification_code' => $code,
				'email'             => $email,
				'event_ref'         => $event_ref,
				'created_at'        => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%s' )
		);

		if ( 1 === $inserted ) {
			return $code;
		}

		$existing_code = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT verification_code FROM ' . nshmsv_codes_table() . ' WHERE verification_code = %s',
				$code
			)
		);
		if ( ! $existing_code ) {
			return new WP_Error( 'nshmsv_code_save_failed', 'The verification code could not be saved. Please try again.' );
		}
	}

	return new WP_Error( 'nshmsv_code_failed', 'A unique verification code could not be generated. Please try again.' );
}

function nshmsv_upsert_member( $email, $full_name, $status, $trials_used ) {
	global $wpdb;

	$existing = nshmsv_get_member( $email );
	$now      = current_time( 'mysql' );

	if ( $existing ) {
		$updated = $wpdb->update(
			nshmsv_members_table(),
			array(
				'full_name'        => $full_name,
				'membership_status' => $status,
				'trials_used'      => $trials_used,
				'updated_at'       => $now,
			),
			array( 'email' => $email ),
			array( '%s', '%s', '%d', '%s' ),
			array( '%s' )
		);

		return false !== $updated;
	}

	$inserted = $wpdb->insert(
		nshmsv_members_table(),
		array(
			'email'             => $email,
			'full_name'         => $full_name,
			'membership_status' => $status,
			'trials_used'       => $trials_used,
			'created_at'        => $now,
			'updated_at'        => $now,
		),
		array( '%s', '%s', '%s', '%d', '%s', '%s' )
	);

	return 1 === $inserted;
}

function nshmsv_complete_payment( $email, $full_name, $event_ref, $choice ) {
	global $wpdb;

	if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
		return new WP_Error( 'nshmsv_transaction_failed', 'The registration could not be started. Please try again.' );
	}

	$member = nshmsv_get_member( $email, true );
	if ( ! $member ) {
		$wpdb->query( 'ROLLBACK' );
		return new WP_Error( 'nshmsv_member_missing', 'Your sign-up could not be found. Restart verification and try again.' );
	}

	$existing_code = nshmsv_existing_event_code( $email, $event_ref );
	if ( $existing_code ) {
		if ( false === $wpdb->query( 'COMMIT' ) ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'nshmsv_transaction_failed', 'The registration could not be completed. Please try again.' );
		}

		return array(
			'code'   => $existing_code,
			'status' => $member->membership_status,
		);
	}

	if ( 'paid' === $member->membership_status || in_array( $choice, array( 'paid', 'credit_card', 'paypal' ), true ) ) {
		$new_status = 'paid';
		$new_trials = (int) $member->trials_used;
	} elseif ( 'trial' === $choice && (int) $member->trials_used < NSHMSV_TRIAL_LIMIT ) {
		$new_trials = (int) $member->trials_used + 1;
		$new_status = $new_trials >= NSHMSV_TRIAL_LIMIT ? 'unpaid' : 'trial';
	} elseif ( 'trial' === $choice ) {
		$wpdb->query( 'ROLLBACK' );
		return new WP_Error( 'nshmsv_trial_limit', 'You have used all three trial events. Choose a payment option to continue.' );
	} else {
		$wpdb->query( 'ROLLBACK' );
		return new WP_Error( 'nshmsv_payment_choice', 'Choose a payment option or an available trial to continue.' );
	}

	$code = nshmsv_create_code( $email, $event_ref );
	if ( is_wp_error( $code ) ) {
		$wpdb->query( 'ROLLBACK' );
		return $code;
	}

	$updated = $wpdb->update(
		nshmsv_members_table(),
		array(
			'full_name'         => $full_name,
			'membership_status' => $new_status,
			'trials_used'       => $new_trials,
			'updated_at'        => current_time( 'mysql' ),
		),
		array( 'email' => $email ),
		array( '%s', '%s', '%d', '%s' ),
		array( '%s' )
	);

	if ( false === $updated ) {
		$wpdb->query( 'ROLLBACK' );
		return new WP_Error( 'nshmsv_member_update_failed', 'Your membership status could not be saved. Please try again.' );
	}

	if ( false === $wpdb->query( 'COMMIT' ) ) {
		$wpdb->query( 'ROLLBACK' );
		return new WP_Error( 'nshmsv_transaction_failed', 'The registration could not be completed. Please try again.' );
	}

	return array(
		'code'   => $code,
		'status' => 'trial' === $choice && $new_status === 'unpaid' ? 'trial' : $new_status,
	);
}

function nshmsv_flow_key() {
	return isset( $_REQUEST['nshmsv_flow'] ) && is_string( $_REQUEST['nshmsv_flow'] )
		? sanitize_text_field( wp_unslash( $_REQUEST['nshmsv_flow'] ) )
		: '';
}

function nshmsv_get_flow( $token, $expected_step = '' ) {
	if ( ! preg_match( '/^[a-f0-9]{32}$/', $token ) ) {
		return false;
	}

	$flow = get_transient( 'nshmsv_flow_' . $token );
	if ( ! is_array( $flow ) || ( '' !== $expected_step && $expected_step !== $flow['step'] ) ) {
		return false;
	}

	return $flow;
}

function nshmsv_new_flow( $email, $event_ref, $step, $full_name = '' ) {
	$token = bin2hex( random_bytes( 16 ) );
	$flow  = array(
		'email'     => $email,
		'event_ref' => $event_ref,
		'step'      => $step,
		'full_name' => $full_name,
	);

	set_transient( 'nshmsv_flow_' . $token, $flow, 30 * MINUTE_IN_SECONDS );
	return $token;
}

function nshmsv_redirect_to( $url ) {
	$target_host = wp_parse_url( $url, PHP_URL_HOST );
	$trusted_hosts = array_filter(
		array(
			wp_parse_url( home_url( '/' ), PHP_URL_HOST ),
			wp_parse_url( site_url( '/' ), PHP_URL_HOST ),
			isset( $_SERVER['HTTP_HOST'] ) ? wp_parse_url( '//' . sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ), PHP_URL_HOST ) : '',
		)
	);

	if ( ! $target_host || ! in_array( strtolower( $target_host ), array_map( 'strtolower', $trusted_hosts ), true ) ) {
		wp_die( esc_html__( 'The next step could not be opened because its URL is not on this WordPress site. Please contact the site administrator.', 'nsh-membership-simple-verification' ) );
	}

	$allow_target_host = static function ( $hosts ) use ( $target_host ) {
		$hosts[] = $target_host;
		return array_unique( $hosts );
	};
	add_filter( 'allowed_redirect_hosts', $allow_target_host );
	$redirected = wp_safe_redirect( $url );
	remove_filter( 'allowed_redirect_hosts', $allow_target_host );

	if ( ! $redirected ) {
		wp_die( esc_html__( 'The next step could not be opened. Please check the WordPress site URL settings or contact the site administrator.', 'nsh-membership-simple-verification' ) );
	}

	exit;
}

function nshmsv_render_code_result( $result ) {
	$status_label = 'paid' === $result['status'] ? 'Paid member' : ( 'unpaid' === $result['status'] ? 'Trial limit reached; payment required for future events' : 'Trial member' );

	return '<div class="nshmsv-result" role="status"><h3>Verification complete</h3><p>Status: ' . esc_html( $status_label ) . '</p><p>Your verification code:</p><p><strong>' . esc_html( $result['code'] ) . '</strong></p><p>Enter this code in the event RSVP.</p><p>Payment options are demo only; no payment was processed.</p></div>';
}

function nshmsv_verification_shortcode() {
	global $wpdb;

	$error  = '';
	$result = null;
	$email  = '';

	if ( isset( $_SERVER['REQUEST_METHOD'], $_POST['nshmsv_action'] ) && 'POST' === $_SERVER['REQUEST_METHOD'] && 'verify_email' === sanitize_key( wp_unslash( $_POST['nshmsv_action'] ) ) ) {
		if ( ! isset( $_POST['nshmsv_verify_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nshmsv_verify_nonce'] ) ), 'nshmsv_verify_email' ) ) {
			$error = 'Your form session expired. Reload the page and try again.';
		} else {
			$email     = isset( $_POST['nshmsv_email'] ) ? strtolower( sanitize_email( wp_unslash( $_POST['nshmsv_email'] ) ) ) : '';
			$event_ref = isset( $_POST['nshmsv_event_ref'] ) ? substr( sanitize_text_field( wp_unslash( $_POST['nshmsv_event_ref'] ) ), 0, 190 ) : '';

			if ( ! is_email( $email ) ) {
				$error = 'Enter a valid email address.';
			} else {
				$existing_code = nshmsv_existing_event_code( $email, $event_ref );
				$member        = nshmsv_get_member( $email );

				if ( $existing_code ) {
					$result = array(
						'code'   => $existing_code,
						'status' => $member ? $member->membership_status : 'trial',
					);
				} elseif ( $member && 'paid' === $member->membership_status ) {
					$code = nshmsv_create_code( $email, $event_ref );
					if ( is_wp_error( $code ) ) {
						$error = $code->get_error_message();
					} else {
						$result = array(
							'code'   => $code,
							'status' => 'paid',
						);
					}
				} elseif ( $member ) {
					if ( (int) $member->trials_used >= NSHMSV_TRIAL_LIMIT ) {
						$member->membership_status = 'unpaid';
						$wpdb_result = $wpdb->update(
							nshmsv_members_table(),
							array(
								'membership_status' => 'unpaid',
								'updated_at'        => current_time( 'mysql' ),
							),
							array( 'email' => $email ),
							array( '%s', '%s' ),
							array( '%s' )
						);
						if ( false === $wpdb_result ) {
							$error = 'Your membership status could not be checked. Please try again.';
						}
					}

					if ( '' === $error ) {
						$token = nshmsv_new_flow( $email, $event_ref, 'payment', $member->full_name );
						nshmsv_redirect_to( add_query_arg( 'nshmsv_flow', $token, nshmsv_page_url( 'nshmsv_payment_page_id' ) ) );
					}
				} else {
					$token = nshmsv_new_flow( $email, $event_ref, 'signup' );
					nshmsv_redirect_to( add_query_arg( 'nshmsv_flow', $token, nshmsv_page_url( 'nshmsv_signup_page_id' ) ) );
				}
			}
		}
	}

	$event_ref = nshmsv_get_event_ref();
	ob_start();
	?>
	<div class="nshmsv-portal">
		<h2>Membership Verification</h2>
		<?php if ( '' !== $error ) : ?>
			<p role="alert"><?php echo esc_html( $error ); ?></p>
		<?php endif; ?>
		<?php if ( $result ) : ?>
			<?php echo nshmsv_render_code_result( $result ); ?>
		<?php else : ?>
			<form method="post">
				<input type="hidden" name="nshmsv_action" value="verify_email">
				<input type="hidden" name="nshmsv_event_ref" value="<?php echo esc_attr( $event_ref ); ?>">
				<?php wp_nonce_field( 'nshmsv_verify_email', 'nshmsv_verify_nonce' ); ?>
				<p><label for="nshmsv-email">Email address</label><br><input id="nshmsv-email" name="nshmsv_email" type="email" maxlength="190" required></p>
				<p><button type="submit">Continue</button></p>
			</form>
		<?php endif; ?>
	</div>
	<?php
	return ob_get_clean();
}
add_shortcode( 'nshmsv_verification', 'nshmsv_verification_shortcode' );

function nshmsv_signup_shortcode() {
	$error = '';
	$token = nshmsv_flow_key();
	$flow  = nshmsv_get_flow( $token, 'signup' );

	if ( ! $flow ) {
		return '<p>This sign-up link has expired. Return to the verification page and try again.</p>';
	}

	if ( isset( $_SERVER['REQUEST_METHOD'], $_POST['nshmsv_action'] ) && 'POST' === $_SERVER['REQUEST_METHOD'] && 'signup' === sanitize_key( wp_unslash( $_POST['nshmsv_action'] ) ) ) {
		if ( ! isset( $_POST['nshmsv_signup_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nshmsv_signup_nonce'] ) ), 'nshmsv_signup_' . $token ) ) {
			$error = 'Your form session expired. Return to the verification page and try again.';
		} else {
			$full_name = isset( $_POST['nshmsv_name'] ) ? sanitize_text_field( wp_unslash( $_POST['nshmsv_name'] ) ) : '';
			if ( '' === $full_name ) {
				$error = 'Enter your name.';
			} else {
				$member = nshmsv_get_member( $flow['email'] );
				if ( ! $member && ! nshmsv_upsert_member( $flow['email'], $full_name, 'trial', 0 ) ) {
					$error = 'Your sign-up could not be saved. Please try again.';
				} else {
					$flow['full_name'] = $member && '' !== $member->full_name ? $member->full_name : $full_name;
					$flow['step']      = 'payment';
					set_transient( 'nshmsv_flow_' . $token, $flow, 30 * MINUTE_IN_SECONDS );
					nshmsv_redirect_to( add_query_arg( 'nshmsv_flow', $token, nshmsv_page_url( 'nshmsv_payment_page_id' ) ) );
				}
			}
		}
	}

	ob_start();
	?>
	<div class="nshmsv-portal">
		<h2>Create your membership record</h2>
		<p>Email: <?php echo esc_html( $flow['email'] ); ?></p>
		<?php if ( '' !== $error ) : ?><p role="alert"><?php echo esc_html( $error ); ?></p><?php endif; ?>
		<form method="post">
			<input type="hidden" name="nshmsv_action" value="signup">
			<input type="hidden" name="nshmsv_flow" value="<?php echo esc_attr( $token ); ?>">
			<?php wp_nonce_field( 'nshmsv_signup_' . $token, 'nshmsv_signup_nonce' ); ?>
			<p><label for="nshmsv-name">Full name</label><br><input id="nshmsv-name" name="nshmsv_name" type="text" maxlength="190" required></p>
			<p><button type="submit">Continue to payment options</button></p>
		</form>
	</div>
	<?php
	return ob_get_clean();
}
add_shortcode( 'nshmsv_signup', 'nshmsv_signup_shortcode' );

function nshmsv_payment_shortcode() {
	$error  = '';
	$result = null;
	$token  = nshmsv_flow_key();
	$flow   = nshmsv_get_flow( $token, 'payment' );

	if ( ! $flow ) {
		return '<p>This payment link has expired. Return to the verification page and try again.</p>';
	}

	$member = nshmsv_get_member( $flow['email'] );
	if ( ! $member ) {
		return '<p>Your membership record could not be found. Return to the verification page and try again.</p>';
	}

	if ( isset( $_SERVER['REQUEST_METHOD'], $_POST['nshmsv_action'] ) && 'POST' === $_SERVER['REQUEST_METHOD'] && 'complete_payment' === sanitize_key( wp_unslash( $_POST['nshmsv_action'] ) ) ) {
		if ( ! isset( $_POST['nshmsv_payment_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nshmsv_payment_nonce'] ) ), 'nshmsv_payment_' . $token ) ) {
			$error = 'Your form session expired. Reload this page and try again.';
		} else {
			$choice = isset( $_POST['nshmsv_choice'] ) ? sanitize_key( wp_unslash( $_POST['nshmsv_choice'] ) ) : '';
			$result = nshmsv_complete_payment( $flow['email'], $flow['full_name'], $flow['event_ref'], $choice );
			if ( is_wp_error( $result ) ) {
				$error  = $result->get_error_message();
				$result = null;
			} else {
				delete_transient( 'nshmsv_flow_' . $token );
			}
		}
	}

	if ( $result ) {
		return '<div class="nshmsv-portal">' . nshmsv_render_code_result( $result ) . '</div>';
	}

	$can_trial = 'paid' !== $member->membership_status && (int) $member->trials_used < NSHMSV_TRIAL_LIMIT;
	ob_start();
	?>
	<div class="nshmsv-portal">
		<h2>Payment options</h2>
		<p>Email: <?php echo esc_html( $flow['email'] ); ?></p>
		<?php if ( 'paid' === $member->membership_status ) : ?>
			<p>Your paid membership is recognized. Continue to receive your event verification code.</p>
		<?php else : ?>
			<p>Choose a demo payment option or use an available trial. No real payment is processed.</p>
		<?php endif; ?>
		<?php if ( '' !== $error ) : ?><p role="alert"><?php echo esc_html( $error ); ?></p><?php endif; ?>
		<form method="post">
			<input type="hidden" name="nshmsv_action" value="complete_payment">
			<input type="hidden" name="nshmsv_flow" value="<?php echo esc_attr( $token ); ?>">
			<?php wp_nonce_field( 'nshmsv_payment_' . $token, 'nshmsv_payment_nonce' ); ?>
			<?php if ( 'paid' !== $member->membership_status ) : ?>
				<p><label><input type="radio" name="nshmsv_choice" value="credit_card" required> Pay with credit card (demo)</label></p>
				<p><label><input type="radio" name="nshmsv_choice" value="paypal" required> Pay with PayPal (demo)</label></p>
				<?php if ( $can_trial ) : ?>
					<p><label><input type="radio" name="nshmsv_choice" value="trial" required> Use one trial event (<?php echo esc_html( (string) ( NSHMSV_TRIAL_LIMIT - (int) $member->trials_used ) ); ?> remaining)</label></p>
				<?php else : ?>
					<p>You have used all <?php echo esc_html( (string) NSHMSV_TRIAL_LIMIT ); ?> trial events. Payment is required to continue.</p>
				<?php endif; ?>
			<?php else : ?>
				<input type="hidden" name="nshmsv_choice" value="paid">
			<?php endif; ?>
			<p><button type="submit">Continue and get verification code</button></p>
		</form>
	</div>
	<?php
	return ob_get_clean();
}
add_shortcode( 'nshmsv_payment', 'nshmsv_payment_shortcode' );

function nshmsv_organizer_shortcode() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return '<p>You must be signed in as a site administrator to validate verification codes.</p>';
	}

	$message = '';
	if ( isset( $_SERVER['REQUEST_METHOD'], $_POST['nshmsv_action'] ) && 'POST' === $_SERVER['REQUEST_METHOD'] && 'validate_code' === sanitize_key( wp_unslash( $_POST['nshmsv_action'] ) ) ) {
		if ( ! isset( $_POST['nshmsv_organizer_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nshmsv_organizer_nonce'] ) ), 'nshmsv_validate_code' ) ) {
			$message = 'Your form session expired. Reload the page and try again.';
		} else {
			global $wpdb;
			$code = isset( $_POST['nshmsv_code'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_POST['nshmsv_code'] ) ) ) : '';
			if ( ! preg_match( '/^[2-9A-HJ-NP-Z]{10}$/', $code ) ) {
				$message = 'Enter a valid 10-character code.';
			} else {
				$registration = $wpdb->get_row(
					$wpdb->prepare(
						'SELECT verification_code, email, event_ref, created_at FROM ' . nshmsv_codes_table() . ' WHERE verification_code = %s LIMIT 1',
						$code
					)
				);
				$message = $registration ? 'Valid verification code.' : 'Invalid verification code.';
			}
		}
	}

	ob_start();
	?>
	<div class="nshmsv-organizer">
		<h2>Validate a verification code</h2>
		<?php if ( '' !== $message ) : ?><p role="status"><?php echo esc_html( $message ); ?></p><?php endif; ?>
		<form method="post">
			<input type="hidden" name="nshmsv_action" value="validate_code">
			<?php wp_nonce_field( 'nshmsv_validate_code', 'nshmsv_organizer_nonce' ); ?>
			<p><label for="nshmsv-code">Verification code</label><br><input id="nshmsv-code" name="nshmsv_code" type="text" maxlength="10" pattern="[2-9A-HJ-NP-Z]{10}" required></p>
			<p><button type="submit">Validate code</button></p>
		</form>
	</div>
	<?php
	return ob_get_clean();
}
add_shortcode( 'nshmsv_organizer', 'nshmsv_organizer_shortcode' );
