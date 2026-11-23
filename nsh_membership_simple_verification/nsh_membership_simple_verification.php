<?php
/**
 * Plugin Name: NSH Membership Simple Verification
 * Description: WordPress account membership verification, sign-up, trial/payment demo, and organizer code validation.
 * Version: 1.1.1
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: NSH
 * License: GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'NSHMSV_DB_VERSION', '2' );
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
		first_name varchar(100) NOT NULL DEFAULT '',
		last_name varchar(100) NOT NULL DEFAULT '',
		short_hike_experience text NULL,
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

function nshmsv_upsert_member( $email, $full_name, $status, $trials_used, $signup_data = array() ) {
	global $wpdb;

	$existing = nshmsv_get_member( $email );
	$now      = current_time( 'mysql' );
	$data     = array(
		'full_name'             => $full_name,
		'first_name'            => isset( $signup_data['first_name'] ) ? $signup_data['first_name'] : '',
		'last_name'             => isset( $signup_data['last_name'] ) ? $signup_data['last_name'] : '',
		'short_hike_experience' => isset( $signup_data['short_hike_experience'] ) ? $signup_data['short_hike_experience'] : '',
		'membership_status'     => $status,
		'trials_used'           => $trials_used,
		'updated_at'            => $now,
	);

	if ( $existing ) {
		$updated = $wpdb->update(
			nshmsv_members_table(),
			$data,
			array( 'email' => $email ),
			array( '%s', '%s', '%s', '%s', '%s', '%d', '%s' ),
			array( '%s' )
		);

		return false !== $updated;
	}

	$inserted = $wpdb->insert(
		nshmsv_members_table(),
		array_merge(
			array(
				'email'               => $email,
				'created_at'          => $now,
			),
			$data
		),
		array( '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
	);

	return 1 === $inserted;
}

function nshmsv_complete_payment( $email, $full_name, $event_ref, $choice, $allow_trial = false ) {
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

	if ( in_array( $choice, array( 'paid', 'credit_card', 'paypal', 'etransfer' ), true ) ) {
		$new_status = 'paid';
		$new_trials = (int) $member->trials_used;
	} elseif ( $allow_trial && 'trial' === $choice && (int) $member->trials_used < NSHMSV_TRIAL_LIMIT ) {
		$new_trials = (int) $member->trials_used + 1;
		$new_status = $new_trials >= NSHMSV_TRIAL_LIMIT ? 'unpaid' : 'trial';
	} elseif ( 'trial' === $choice && (int) $member->trials_used >= NSHMSV_TRIAL_LIMIT ) {
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

function nshmsv_new_flow( $email, $event_ref, $step, $full_name = '', $extra = array() ) {
	$token = bin2hex( random_bytes( 16 ) );
	$flow  = array_merge(
		array(
		'email'     => $email,
		'event_ref' => $event_ref,
		'step'      => $step,
		'full_name' => $full_name,
		),
		$extra
	);

	set_transient( 'nshmsv_flow_' . $token, $flow, 30 * MINUTE_IN_SECONDS );
	return $token;
}

function nshmsv_get_site_role( $user_id ) {
	$meta_keys = apply_filters( 'nshmsv_site_role_meta_keys', array( 'site_role', 'Site Role' ) );
	$user_meta = get_user_meta( $user_id );
	$lookup    = array();

	foreach ( $user_meta as $meta_key => $values ) {
		if ( 'siterole' === preg_replace( '/[^a-z0-9]/', '', strtolower( $meta_key ) ) ) {
			$lookup[ $meta_key ] = isset( $values[0] ) ? $values[0] : '';
		}
	}

	foreach ( $meta_keys as $meta_key ) {
		$meta_key = (string) $meta_key;
		$value    = array_key_exists( $meta_key, $lookup ) ? $lookup[ $meta_key ] : get_user_meta( $user_id, $meta_key, true );
		$value    = nshmsv_normalize_site_role( $value );
		if ( '' !== $value ) {
			return $value;
		}
	}

	foreach ( $lookup as $value ) {
		$value = nshmsv_normalize_site_role( $value );
		if ( '' !== $value ) {
			return $value;
		}
	}

	$user = get_userdata( $user_id );
	if ( $user && ! empty( $user->roles ) ) {
		foreach ( $user->roles as $role ) {
			$role = nshmsv_normalize_site_role( $role );
			if ( in_array( $role, array( 'active', 'unpaid', 'expired' ), true ) ) {
				return $role;
			}
		}
	}

	return '';
}

function nshmsv_normalize_site_role( $value ) {
	if ( is_array( $value ) ) {
		$unknown = '';
		foreach ( $value as $item ) {
			$normalized = nshmsv_normalize_site_role( $item );
			if ( in_array( $normalized, array( 'active', 'unpaid', 'expired' ), true ) ) {
				return $normalized;
			}
			if ( '' === $unknown && '' !== $normalized ) {
				$unknown = $normalized;
			}
		}
		return $unknown;
	}

	if ( ! is_string( $value ) && ! is_numeric( $value ) ) {
		return '';
	}

	$value = strtolower( trim( (string) $value ) );
	$value = preg_replace( '/[\s_]+/', '-', $value );
	$value = preg_replace( '/-(member|membership)$/', '', $value );

	return $value;
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
	$status_label = 'active' === $result['status'] ? 'Active member' : ( 'paid' === $result['status'] ? 'Paid member' : ( 'unpaid' === $result['status'] ? 'Trial limit reached; payment required for future events' : 'Trial member' ) );

	return '<div class="nshmsv-result" role="status"><h3>Verification complete</h3><p>Status: ' . esc_html( $status_label ) . '</p><p>Your verification code:</p><p><strong>' . esc_html( $result['code'] ) . '</strong></p><p>Enter this code in the event RSVP.</p></div>';
}

function nshmsv_verification_shortcode() {
	$error     = '';
	$result    = null;
	$event_ref = nshmsv_get_event_ref();
	$signup_url = nshmsv_page_url( 'nshmsv_signup_page_id' );
	if ( '' !== $event_ref ) {
		$signup_url = add_query_arg( 'event', $event_ref, $signup_url );
	}
	$action    = isset( $_POST['nshmsv_action'] ) && is_string( $_POST['nshmsv_action'] ) ? sanitize_key( wp_unslash( $_POST['nshmsv_action'] ) ) : '';

	if ( 'login' === $action && isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === $_SERVER['REQUEST_METHOD'] ) {
		if ( ! isset( $_POST['nshmsv_login_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nshmsv_login_nonce'] ) ), 'nshmsv_login' ) ) {
			$error = 'Your form session expired. Reload the page and try again.';
		} else {
			$login    = isset( $_POST['nshmsv_login'] ) ? sanitize_text_field( wp_unslash( $_POST['nshmsv_login'] ) ) : '';
			$password = isset( $_POST['nshmsv_password'] ) && is_string( $_POST['nshmsv_password'] ) ? wp_unslash( $_POST['nshmsv_password'] ) : '';
			$user     = is_email( $login ) ? get_user_by( 'email', $login ) : get_user_by( 'login', $login );
			if ( $user ) {
				$login = $user->user_login;
			}

			$authenticated = wp_signon(
				array(
					'user_login'    => $login,
					'user_password' => $password,
					'remember'      => true,
				),
				is_ssl()
			);

			if ( is_wp_error( $authenticated ) ) {
				$error = 'The username/email or password was not recognized. Please try again.';
			} else {
				wp_set_current_user( $authenticated->ID );
			}
		}
	}

	if ( is_user_logged_in() && '' === $error ) {
		$user      = wp_get_current_user();
		$site_role = nshmsv_get_site_role( $user->ID );
		if ( 'active' === $site_role ) {
			$existing_code = nshmsv_existing_event_code( $user->user_email, $event_ref );
			$code          = $existing_code ? $existing_code : nshmsv_create_code( $user->user_email, $event_ref );
			if ( is_wp_error( $code ) ) {
				$error = $code->get_error_message();
			} else {
				$result = array(
					'code'   => $code,
					'status' => 'active',
				);
			}
		} elseif ( in_array( $site_role, array( 'unpaid', 'expired' ), true ) ) {
			$existing_code = nshmsv_existing_event_code( $user->user_email, $event_ref );
			if ( $existing_code ) {
				$member = nshmsv_get_member( $user->user_email );
				$result = array(
					'code'   => $existing_code,
					'status' => $member ? $member->membership_status : 'unpaid',
				);
			} else {
				$full_name = trim( $user->first_name . ' ' . $user->last_name );
				if ( '' === $full_name ) {
					$full_name = $user->display_name;
				}
				if ( ! nshmsv_get_member( $user->user_email ) && ! nshmsv_upsert_member( $user->user_email, $full_name, 'unpaid', 0 ) ) {
					$error = 'Your membership status could not be checked. Please try again.';
				} else {
					$token = nshmsv_new_flow(
						$user->user_email,
						$event_ref,
						'payment',
						$full_name,
						array(
							'flow_type' => 'existing',
							'user_id'   => $user->ID,
						)
					);
					nshmsv_redirect_to( add_query_arg( 'nshmsv_flow', $token, nshmsv_page_url( 'nshmsv_payment_page_id' ) ) );
				}
			}
		} else {
			$error = 'Your Site Role does not allow verification. Please contact the Membership Secretary.';
		}
	}

	ob_start();
	?>
	<div class="nshmsv-portal">
		<h2>Membership Verification</h2>
		<?php if ( '' !== $error ) : ?><p role="alert"><?php echo esc_html( $error ); ?></p><?php endif; ?>
		<?php if ( $result ) : ?>
			<?php echo nshmsv_render_code_result( $result ); ?>
		<?php elseif ( ! is_user_logged_in() ) : ?>
			<h3>Log in to your membership account</h3>
			<form method="post">
				<input type="hidden" name="nshmsv_action" value="login">
				<?php wp_nonce_field( 'nshmsv_login', 'nshmsv_login_nonce' ); ?>
				<p><label for="nshmsv-login">Username or email</label><br><input id="nshmsv-login" name="nshmsv_login" type="text" autocomplete="username" required></p>
				<p><label for="nshmsv-password">Password</label><br><input id="nshmsv-password" name="nshmsv_password" type="password" autocomplete="current-password" required></p>
				<p><button type="submit">Log in and verify</button></p>
			</form>
			<p>New to NSH? <a href="<?php echo esc_url( $signup_url ); ?>">Sign up for membership</a>.</p>
		<?php endif; ?>
	</div>
	<?php
	return ob_get_clean();
}
add_shortcode( 'nshmsv_verification', 'nshmsv_verification_shortcode' );

function nshmsv_signup_shortcode() {
	$error = '';
	$first_name = '';
	$last_name  = '';
	$email      = '';
	$experience = '';
	$event_ref  = nshmsv_get_event_ref();
	$login_url  = nshmsv_page_url( 'nshmsv_verification_page_id' );
	if ( '' !== $event_ref ) {
		$login_url = add_query_arg( 'event', $event_ref, $login_url );
	}
	$action     = isset( $_POST['nshmsv_action'] ) && is_string( $_POST['nshmsv_action'] ) ? sanitize_key( wp_unslash( $_POST['nshmsv_action'] ) ) : '';

	if ( 'signup' === $action && isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === $_SERVER['REQUEST_METHOD'] ) {
		if ( ! isset( $_POST['nshmsv_signup_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nshmsv_signup_nonce'] ) ), 'nshmsv_signup' ) ) {
			$error = 'Your form session expired. Reload the page and try again.';
		} else {
			$email      = isset( $_POST['nshmsv_email'] ) ? strtolower( sanitize_email( wp_unslash( $_POST['nshmsv_email'] ) ) ) : '';
			$first_name = isset( $_POST['nshmsv_first_name'] ) ? sanitize_text_field( wp_unslash( $_POST['nshmsv_first_name'] ) ) : '';
			$last_name  = isset( $_POST['nshmsv_last_name'] ) ? sanitize_text_field( wp_unslash( $_POST['nshmsv_last_name'] ) ) : '';
			$experience = isset( $_POST['nshmsv_hike_experience'] ) ? sanitize_textarea_field( wp_unslash( $_POST['nshmsv_hike_experience'] ) ) : '';

			if ( ! is_email( $email ) || '' === $first_name || '' === $last_name || '' === $experience ) {
				$error = 'Enter a valid email address, first name, last name, and short hike experience.';
			} elseif ( get_user_by( 'email', $email ) ) {
				$error = 'An account already uses this email address. Please log in to continue.';
			} else {
				$full_name  = trim( $first_name . ' ' . $last_name );
				$signup_data = array(
					'first_name'            => $first_name,
					'last_name'             => $last_name,
					'short_hike_experience' => $experience,
				);
				$member      = nshmsv_get_member( $email );
				$trials_used = $member ? (int) $member->trials_used : 0;
				if ( ! nshmsv_upsert_member( $email, $full_name, 'trial', $trials_used, $signup_data ) ) {
					$error = 'Your sign-up could not be saved. Please try again.';
				} else {
					$token = nshmsv_new_flow(
						$email,
						$event_ref,
						'payment',
						$full_name,
						array_merge( $signup_data, array( 'flow_type' => 'signup' ) )
					);
					nshmsv_redirect_to( add_query_arg( 'nshmsv_flow', $token, nshmsv_page_url( 'nshmsv_payment_page_id' ) ) );
				}
			}
		}
	}

	ob_start();
	?>
	<div class="nshmsv-portal">
		<h2>Membership sign-up</h2>
		<?php if ( '' !== $error ) : ?><p role="alert"><?php echo esc_html( $error ); ?></p><?php endif; ?>
		<form method="post">
			<input type="hidden" name="nshmsv_action" value="signup">
			<?php wp_nonce_field( 'nshmsv_signup', 'nshmsv_signup_nonce' ); ?>
			<p><label for="nshmsv-signup-email">Email address</label><br><input id="nshmsv-signup-email" name="nshmsv_email" type="email" maxlength="190" value="<?php echo esc_attr( $email ); ?>" required></p>
			<p><label for="nshmsv-first-name">First name</label><br><input id="nshmsv-first-name" name="nshmsv_first_name" type="text" maxlength="100" value="<?php echo esc_attr( $first_name ); ?>" required></p>
			<p><label for="nshmsv-last-name">Last name</label><br><input id="nshmsv-last-name" name="nshmsv_last_name" type="text" maxlength="100" value="<?php echo esc_attr( $last_name ); ?>" required></p>
			<p><label for="nshmsv-hike-experience">Short hike experience</label><br><textarea id="nshmsv-hike-experience" name="nshmsv_hike_experience" rows="5" maxlength="2000" required><?php echo esc_textarea( $experience ); ?></textarea></p>
			<p><button type="submit">Continue to membership options</button></p>
		</form>
		<p>Already have an account? <a href="<?php echo esc_url( $login_url ); ?>">Log in</a>.</p>
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

	$is_signup = isset( $flow['flow_type'] ) && 'signup' === $flow['flow_type'];
	if ( ! $is_signup ) {
		if (
			! isset( $flow['flow_type'], $flow['user_id'] ) ||
			'existing' !== $flow['flow_type'] ||
			! is_user_logged_in() ||
			get_current_user_id() !== (int) $flow['user_id']
		) {
			return '<p>This payment link is not valid for your account. Return to the verification page and log in.</p>';
		}

		$site_role = nshmsv_get_site_role( get_current_user_id() );
		if ( 'active' === $site_role ) {
			$existing_code = nshmsv_existing_event_code( $flow['email'], $flow['event_ref'] );
			$code          = $existing_code ? $existing_code : nshmsv_create_code( $flow['email'], $flow['event_ref'] );
			if ( is_wp_error( $code ) ) {
				return '<p role="alert">' . esc_html( $code->get_error_message() ) . '</p>';
			}

			delete_transient( 'nshmsv_flow_' . $token );
			return '<div class="nshmsv-portal">' . nshmsv_render_code_result( array( 'code' => $code, 'status' => 'active' ) ) . '</div>';
		}
		if ( ! in_array( $site_role, array( 'unpaid', 'expired' ), true ) ) {
			return '<p>Your Site Role does not allow verification. Please contact the Membership Secretary.</p>';
		}
	} elseif ( get_user_by( 'email', $flow['email'] ) ) {
		return '<p>An account already uses this email address. Please <a href="' . esc_url( nshmsv_page_url( 'nshmsv_verification_page_id' ) ) . '">log in</a> to continue.</p>';
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
			$result = nshmsv_complete_payment( $flow['email'], $flow['full_name'], $flow['event_ref'], $choice, $is_signup );
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

	$can_trial = $is_signup && (int) $member->trials_used < NSHMSV_TRIAL_LIMIT;
	ob_start();
	?>
	<div class="nshmsv-portal">
		<h2>Membership payment options</h2>
		<p>Email: <?php echo esc_html( $flow['email'] ); ?></p>
		<?php if ( $is_signup ) : ?>
			<p>Choose PayPal, e-transfer, credit card, or an available trial membership. Payment selections are demonstrations only; no payment is processed or confirmed.</p>
		<?php else : ?>
			<p>Choose PayPal, e-transfer, or credit card. Payment selections are demonstrations only; no payment is processed or confirmed.</p>
		<?php endif; ?>
		<?php if ( '' !== $error ) : ?><p role="alert"><?php echo esc_html( $error ); ?></p><?php endif; ?>
		<form method="post">
			<input type="hidden" name="nshmsv_action" value="complete_payment">
			<input type="hidden" name="nshmsv_flow" value="<?php echo esc_attr( $token ); ?>">
			<?php wp_nonce_field( 'nshmsv_payment_' . $token, 'nshmsv_payment_nonce' ); ?>
			<p><label><input type="radio" name="nshmsv_choice" value="paypal" required> PayPal (demo)</label></p>
			<p><label><input type="radio" name="nshmsv_choice" value="etransfer" required> E-transfer (demo)</label></p>
			<p><label><input type="radio" name="nshmsv_choice" value="credit_card" required> Credit card (demo)</label></p>
			<?php if ( $can_trial ) : ?>
				<p><label><input type="radio" name="nshmsv_choice" value="trial" required> Trial membership (<?php echo esc_html( (string) ( NSHMSV_TRIAL_LIMIT - (int) $member->trials_used ) ); ?> remaining)</label></p>
			<?php elseif ( $is_signup ) : ?>
				<p>You have used all <?php echo esc_html( (string) NSHMSV_TRIAL_LIMIT ); ?> trial memberships. Choose a payment option to continue.</p>
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
