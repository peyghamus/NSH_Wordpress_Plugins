<?php
/**
 * Plugin Name: NSH Membership Verification Demo
 * Description: Demo event registration, simulated payment selection, and organizer verification tools.
 * Version: 0.6.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: NSH
 * License: GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'NSHMV_DB_VERSION', '3' );

function nshmv_table_name() {
	global $wpdb;

	return $wpdb->prefix . 'nshmv_registrations';
}

function nshmv_events_table_name() {
	global $wpdb;

	return $wpdb->prefix . 'nshmv_events';
}

function nshmv_install() {
	global $wpdb;

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$charset_collate = $wpdb->get_charset_collate();
	$events_table    = nshmv_events_table_name();
	$registrations   = nshmv_table_name();

	$events_sql = "CREATE TABLE $events_table (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		event_key char(32) NOT NULL,
		event_name varchar(190) NOT NULL,
		verification_page_url varchar(500) NOT NULL,
		created_at datetime NOT NULL,
		PRIMARY KEY  (id),
		UNIQUE KEY event_key (event_key)
	) $charset_collate;";

	$registrations_sql = "CREATE TABLE $registrations (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		verification_code char(10) NOT NULL,
		participant_name varchar(190) NOT NULL,
		email varchar(190) NOT NULL,
		event_name varchar(190) NOT NULL,
		event_key char(32) NOT NULL DEFAULT '',
		payment_method varchar(20) NOT NULL DEFAULT '',
		status varchar(20) NOT NULL DEFAULT 'trial_member',
		created_at datetime NOT NULL,
		PRIMARY KEY  (id),
		UNIQUE KEY verification_code (verification_code),
		KEY event_key (event_key)
	) $charset_collate;";

	dbDelta( $events_sql );
	dbDelta( $registrations_sql );
	$wpdb->query(
		'UPDATE ' . $registrations . " SET status = 'paid_member' WHERE payment_method IN ('credit_card', 'paypal', 'paid_member')"
	);
	update_option( 'nshmv_db_version', NSHMV_DB_VERSION );
}
register_activation_hook( __FILE__, 'nshmv_install' );

function nshmv_maybe_upgrade() {
	if ( NSHMV_DB_VERSION !== get_option( 'nshmv_db_version' ) ) {
		nshmv_install();
	}
}
add_action( 'plugins_loaded', 'nshmv_maybe_upgrade' );

function nshmv_keep_event_query_url( $redirect_url ) {
	$event_key = isset( $_GET['event'] ) && is_string( $_GET['event'] )
		? sanitize_text_field( wp_unslash( $_GET['event'] ) )
		: '';

	if (
		isset( $_GET['nshmv_verify'] ) &&
		'1' === sanitize_text_field( wp_unslash( $_GET['nshmv_verify'] ) ) &&
		preg_match( '/^[a-f0-9]{32}$/i', $event_key )
	) {
		return false;
	}

	if (
		isset( $_GET['page_id'] ) &&
		is_scalar( $_GET['page_id'] ) &&
		absint( wp_unslash( $_GET['page_id'] ) ) > 0 &&
		preg_match( '/^[a-f0-9]{32}$/i', $event_key )
	) {
		return false;
	}

	return $redirect_url;
}
add_filter( 'redirect_canonical', 'nshmv_keep_event_query_url' );

function nshmv_event_verification_url( $event_key ) {
	return add_query_arg(
		'event',
		$event_key,
		add_query_arg( 'nshmv_verify', '1', home_url( '/' ) )
	);
}

function nshmv_render_verification_route() {
	if (
		! isset( $_GET['nshmv_verify'] ) ||
		! is_string( $_GET['nshmv_verify'] ) ||
		'1' !== sanitize_text_field( wp_unslash( $_GET['nshmv_verify'] ) )
	) {
		return;
	}

	$event_key = isset( $_GET['event'] ) && is_string( $_GET['event'] )
		? sanitize_text_field( wp_unslash( $_GET['event'] ) )
		: '';
	if ( ! nshmv_get_event( $event_key ) ) {
		status_header( 404 );
	}

	nocache_headers();
	get_header();
	echo '<main class="nshmv-verification-page">';
	echo do_shortcode( '[nsh_registration]' );
	echo '</main>';
	get_footer();
	exit;
}
add_action( 'template_redirect', 'nshmv_render_verification_route' );

function nshmv_get_event( $event_key ) {
	global $wpdb;

	if ( ! preg_match( '/^[a-f0-9]{32}$/i', $event_key ) ) {
		return null;
	}

	return $wpdb->get_row(
		$wpdb->prepare(
			'SELECT * FROM ' . nshmv_events_table_name() . ' WHERE event_key = %s',
			$event_key
		)
	);
}

function nshmv_create_event( $event_name ) {
	global $wpdb;

	$events_table = nshmv_events_table_name();
	for ( $attempt = 0; $attempt < 5; $attempt++ ) {
		$event_key = str_replace( '-', '', wp_generate_uuid4() );
		$event_url = nshmv_event_verification_url( $event_key );
		$inserted  = $wpdb->insert(
			$events_table,
			array(
				'event_key'            => $event_key,
				'event_name'           => $event_name,
				'verification_page_url' => $event_url,
				'created_at'           => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%s' )
		);

		if ( 1 === $inserted ) {
			return $event_key;
		}

		$existing_key = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT event_key FROM ' . $events_table . ' WHERE event_key = %s',
				$event_key
			)
		);
		if ( ! $existing_key ) {
			return new WP_Error( 'nshmv_event_save_failed', 'The event could not be saved. Please try again.' );
		}
	}

	return new WP_Error( 'nshmv_event_key_failed', 'A unique event link could not be generated. Please try again.' );
}

function nshmv_create_registration( $participant_name, $email, $event, $payment_method ) {
	global $wpdb;

	$table_name = nshmv_table_name();
	$alphabet   = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';

	for ( $attempt = 0; $attempt < 5; $attempt++ ) {
		$code = '';
		for ( $i = 0; $i < 10; $i++ ) {
			$code .= $alphabet[ random_int( 0, strlen( $alphabet ) - 1 ) ];
		}

		$inserted = $wpdb->insert(
			$table_name,
			array(
				'verification_code' => $code,
				'participant_name'  => $participant_name,
				'email'             => $email,
				'event_name'        => $event->event_name,
				'event_key'         => $event->event_key,
				'payment_method'     => $payment_method,
				'status'             => 'trial' === $payment_method ? 'trial_member' : 'paid_member',
				'created_at'        => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( 1 === $inserted ) {
			return $code;
		}

		$existing_code = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT verification_code FROM $table_name WHERE verification_code = %s",
				$code
			)
		);
		if ( ! $existing_code ) {
			return new WP_Error( 'nshmv_save_failed', 'The registration could not be saved. Please try again.' );
		}
	}

	return new WP_Error( 'nshmv_code_failed', 'A unique code could not be generated. Please try again.' );
}

function nshmv_get_registration_for_event( $email, $event_key ) {
	global $wpdb;

	return $wpdb->get_row(
		$wpdb->prepare(
			'SELECT verification_code, status, payment_method FROM ' . nshmv_table_name() . ' WHERE email = %s AND event_key = %s ORDER BY id ASC LIMIT 1',
			$email,
			$event_key
		)
	);
}

function nshmv_is_paid_member( $email ) {
	global $wpdb;

	return (bool) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT id FROM " . nshmv_table_name() . " WHERE email = %s AND status = 'paid_member' LIMIT 1",
			$email
		)
	);
}

function nshmv_count_member_events( $email ) {
	global $wpdb;

	return (int) $wpdb->get_var(
		$wpdb->prepare(
			'SELECT COUNT(DISTINCT event_key) FROM ' . nshmv_table_name() . ' WHERE email = %s',
			$email
		)
	);
}

function nshmv_valid_registration_details( $name, $email ) {
	return '' !== $name && is_email( $email );
}

function nshmv_registration_shortcode() {
	$code       = '';
	$error      = '';
	$event_key  = isset( $_POST['nshmv_event_key'] )
		? sanitize_text_field( wp_unslash( $_POST['nshmv_event_key'] ) )
		: ( isset( $_GET['event'] ) ? sanitize_text_field( wp_unslash( $_GET['event'] ) ) : '' );
	$event      = nshmv_get_event( $event_key );
	$step       = '';
	$name       = '';
	$email      = '';
	$payment    = '';
	$status     = '';

	if ( isset( $_SERVER['REQUEST_METHOD'], $_POST['nshmv_action'] ) && 'POST' === $_SERVER['REQUEST_METHOD'] ) {
		$action = sanitize_key( wp_unslash( $_POST['nshmv_action'] ) );

		if ( 'start_registration' === $action ) {
			if ( ! isset( $_POST['nshmv_registration_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nshmv_registration_nonce'] ) ), 'nshmv_register' ) ) {
				$error = 'Your form session expired. Please reload the event link and try again.';
			} elseif ( ! $event ) {
				$error = 'This event link is missing or invalid. Please use the event link provided by the organizer.';
			} else {
				$name  = isset( $_POST['nshmv_name'] ) ? sanitize_text_field( wp_unslash( $_POST['nshmv_name'] ) ) : '';
				$email = isset( $_POST['nshmv_email'] ) ? strtolower( sanitize_email( wp_unslash( $_POST['nshmv_email'] ) ) ) : '';
				if ( ! nshmv_valid_registration_details( $name, $email ) ) {
					$error = 'Enter your name and a valid email address.';
				} else {
					$existing_registration = nshmv_get_registration_for_event( $email, $event->event_key );
					if ( $existing_registration ) {
						$code    = $existing_registration->verification_code;
						$status  = $existing_registration->status;
						$payment = $existing_registration->payment_method;
					} elseif ( nshmv_is_paid_member( $email ) ) {
						$result = nshmv_create_registration( $name, $email, $event, 'paid_member' );
						if ( is_wp_error( $result ) ) {
							$error = $result->get_error_message();
						} else {
							$code    = $result;
							$status  = 'paid_member';
							$payment = 'paid_member';
						}
					} else {
						$step = 'payment';
					}
				}
			}
		} elseif ( 'complete_payment' === $action ) {
			if ( ! isset( $_POST['nshmv_payment_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nshmv_payment_nonce'] ) ), 'nshmv_payment' ) ) {
				$error = 'Your form session expired. Please reload the event link and try again.';
			} elseif ( ! $event ) {
				$error = 'This event link is missing or invalid. Please use the event link provided by the organizer.';
			} else {
				$name    = isset( $_POST['nshmv_name'] ) ? sanitize_text_field( wp_unslash( $_POST['nshmv_name'] ) ) : '';
				$email   = isset( $_POST['nshmv_email'] ) ? strtolower( sanitize_email( wp_unslash( $_POST['nshmv_email'] ) ) ) : '';
				$payment = isset( $_POST['nshmv_payment_method'] ) ? sanitize_key( wp_unslash( $_POST['nshmv_payment_method'] ) ) : '';

				if ( ! nshmv_valid_registration_details( $name, $email ) ) {
					$error = 'Enter your name and a valid email address.';
				} else {
					$existing_registration = nshmv_get_registration_for_event( $email, $event->event_key );
					if ( $existing_registration ) {
						$code    = $existing_registration->verification_code;
						$status  = $existing_registration->status;
						$payment = $existing_registration->payment_method;
					} elseif ( nshmv_is_paid_member( $email ) ) {
						$result = nshmv_create_registration( $name, $email, $event, 'paid_member' );
						if ( is_wp_error( $result ) ) {
							$error = $result->get_error_message();
						} else {
							$code    = $result;
							$status  = 'paid_member';
							$payment = 'paid_member';
						}
					} elseif ( ! in_array( $payment, array( 'credit_card', 'paypal', 'trial' ), true ) ) {
						$error = 'Choose Credit Card, PayPal, or Trial to continue with the demo.';
					} elseif ( 'trial' === $payment && nshmv_count_member_events( $email ) >= 3 ) {
						$error = 'The trial is limited to three events per email address. Choose a demo payment option to continue.';
					} else {
						$result = nshmv_create_registration( $name, $email, $event, $payment );
						if ( is_wp_error( $result ) ) {
							$error = $result->get_error_message();
						} else {
							$code   = $result;
							$status = 'trial' === $payment ? 'trial_member' : 'paid_member';
						}
					}
				}
			}
		}
	}

	ob_start();
	?>
	<div class="nshmv-portal">
		<h2>NSH Event Verification Demo</h2>
		<?php if ( '' !== $error ) : ?>
			<p class="nshmv-notice nshmv-error" role="alert"><?php echo esc_html( $error ); ?></p>
		<?php endif; ?>
		<?php if ( '' !== $code ) : ?>
			<div class="nshmv-notice nshmv-success" role="status">
				<h3>Registration complete for <?php echo esc_html( $event->event_name ); ?></h3>
				<p><strong>Status:</strong> <?php echo esc_html( 'paid_member' === $status ? 'Paid member' : 'Trial member' ); ?></p>
				<p><strong>Your demo verification code:</strong></p>
				<p class="nshmv-code"><?php echo esc_html( $code ); ?></p>
				<?php if ( 'trial_member' === $status ) : ?>
					<p>This trial registration counts as one event toward the three-event limit.</p>
				<?php elseif ( in_array( $payment, array( 'credit_card', 'paypal' ), true ) ) : ?>
					<p>Selected demo payment option: <?php echo esc_html( 'credit_card' === $payment ? 'Credit Card' : 'PayPal' ); ?>.</p>
				<?php endif; ?>
				<p>Enter this code in the RSVP question for this event.</p>
				<p>This is a demo only; no payment was processed.</p>
			</div>
		<?php elseif ( ! $event ) : ?>
			<p>Open the event-specific verification link supplied by the organizer to register.</p>
		<?php elseif ( 'payment' === $step ) : ?>
			<h3>Demo payment for <?php echo esc_html( $event->event_name ); ?></h3>
			<p>This demonstration does not collect card or PayPal credentials and will not charge you. Existing paid members who use the same email receive a code without this step.</p>
			<form method="post">
				<input type="hidden" name="nshmv_action" value="complete_payment">
				<input type="hidden" name="nshmv_event_key" value="<?php echo esc_attr( $event->event_key ); ?>">
				<input type="hidden" name="nshmv_name" value="<?php echo esc_attr( $name ); ?>">
				<input type="hidden" name="nshmv_email" value="<?php echo esc_attr( $email ); ?>">
				<?php wp_nonce_field( 'nshmv_payment', 'nshmv_payment_nonce' ); ?>
				<p>
					<label><input type="radio" name="nshmv_payment_method" value="credit_card" required> Pay with credit card (demo)</label>
				</p>
				<p>
					<label><input type="radio" name="nshmv_payment_method" value="paypal" required> Pay with PayPal (demo)</label>
				</p>
				<?php if ( nshmv_count_member_events( $email ) < 3 ) : ?>
					<p>
						<label><input type="radio" name="nshmv_payment_method" value="trial" required> Register as a trial member (no payment; up to 3 events)</label>
					</p>
				<?php else : ?>
					<p>You have reached the three-event trial limit for this email address. Choose Credit Card or PayPal in this demo to continue.</p>
				<?php endif; ?>
				<p><button type="submit">Continue and get verification code</button></p>
			</form>
		<?php else : ?>
			<h3>Register for <?php echo esc_html( $event->event_name ); ?></h3>
			<form method="post">
				<input type="hidden" name="nshmv_action" value="start_registration">
				<input type="hidden" name="nshmv_event_key" value="<?php echo esc_attr( $event->event_key ); ?>">
				<?php wp_nonce_field( 'nshmv_register', 'nshmv_registration_nonce' ); ?>
				<p>
					<label for="nshmv-name">Full name</label><br>
					<input id="nshmv-name" name="nshmv_name" type="text" maxlength="190" required>
				</p>
				<p>
					<label for="nshmv-email">Email address</label><br>
					<input id="nshmv-email" name="nshmv_email" type="email" maxlength="190" required>
				</p>
				<p><button type="submit">Continue to demo payment options</button></p>
			</form>
		<?php endif; ?>
	</div>
	<?php
	return ob_get_clean();
}
add_shortcode( 'nsh_registration', 'nshmv_registration_shortcode' );

function nshmv_organizer_shortcode( $attributes ) {
	if ( ! current_user_can( 'manage_options' ) ) {
		return '<p>You must be signed in as a site administrator to use the organizer portal.</p>';
	}

	$message     = '';
	$created_url = '';

	if ( isset( $_SERVER['REQUEST_METHOD'], $_POST['nshmv_action'] ) && 'POST' === $_SERVER['REQUEST_METHOD'] && 'create_event' === sanitize_key( wp_unslash( $_POST['nshmv_action'] ) ) ) {
		if ( ! isset( $_POST['nshmv_event_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nshmv_event_nonce'] ) ), 'nshmv_create_event' ) ) {
			$message = 'Your form session expired. Please reload the page and try again.';
		} else {
			$event_name = isset( $_POST['nshmv_event_name'] ) ? sanitize_text_field( wp_unslash( $_POST['nshmv_event_name'] ) ) : '';
			if ( '' === $event_name ) {
				$message = 'Enter an event name.';
			} else {
				$result = nshmv_create_event( $event_name );
				if ( is_wp_error( $result ) ) {
					$message = $result->get_error_message();
				} else {
					$created_url = nshmv_event_verification_url( $result );
					$message     = 'Event created. Share its verification link with participants.';
				}
			}
		}
	}

	global $wpdb;
	$events             = $wpdb->get_results( 'SELECT * FROM ' . nshmv_events_table_name() . ' ORDER BY id DESC' );
	$selected_event_key = isset( $_GET['nshmv_event_view'] ) ? sanitize_text_field( wp_unslash( $_GET['nshmv_event_view'] ) ) : '';
	$selected_event     = '' !== $selected_event_key ? nshmv_get_event( $selected_event_key ) : null;
	$organizer_page_url = get_permalink();
	if ( ! $organizer_page_url ) {
		$organizer_page_url = home_url( '/' );
	}

	ob_start();
	?>
	<div class="nshmv-portal">
		<h2>NSH Organizer Dashboard</h2>
		<h3>Events</h3>
		<?php if ( empty( $events ) ) : ?>
			<p>No events have been created yet.</p>
		<?php else : ?>
			<ul class="nshmv-event-list">
				<?php foreach ( $events as $event ) : ?>
					<li>
						<strong><?php echo esc_html( $event->event_name ); ?></strong>
						&mdash; <a href="<?php echo esc_url( add_query_arg( 'nshmv_event_view', $event->event_key, $organizer_page_url ) ); ?>">View verified participants and codes</a>
						&mdash; <a href="<?php echo esc_url( nshmv_event_verification_url( $event->event_key ) ); ?>">Participant verification link</a>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
		<?php if ( '' !== $selected_event_key ) : ?>
			<?php if ( ! $selected_event ) : ?>
				<p class="nshmv-notice nshmv-error" role="alert">That event could not be found.</p>
			<?php else : ?>
				<?php
				$registrations = $wpdb->get_results(
					$wpdb->prepare(
						'SELECT participant_name, email, verification_code, status, payment_method, created_at FROM ' . nshmv_table_name() . ' WHERE event_key = %s ORDER BY id DESC',
						$selected_event->event_key
					)
				);
				?>
				<h3>Verified participants: <?php echo esc_html( $selected_event->event_name ); ?></h3>
				<?php if ( empty( $registrations ) ) : ?>
					<p>No participants have completed the demo flow for this event.</p>
				<?php else : ?>
					<table class="widefat striped">
						<thead><tr><th>Participant</th><th>Email</th><th>Status</th><th>Verification code</th><th>Payment option</th><th>Verified at</th></tr></thead>
						<tbody>
							<?php foreach ( $registrations as $registration ) : ?>
								<tr>
									<td><?php echo esc_html( $registration->participant_name ); ?></td>
									<td><?php echo esc_html( $registration->email ); ?></td>
									<td><?php echo esc_html( 'paid_member' === $registration->status ? 'Paid member' : 'Trial member' ); ?></td>
									<td><code><?php echo esc_html( $registration->verification_code ); ?></code></td>
									<td><?php echo esc_html( 'credit_card' === $registration->payment_method ? 'Credit Card (demo)' : ( 'paypal' === $registration->payment_method ? 'PayPal (demo)' : ( 'paid_member' === $registration->payment_method ? 'Existing paid member' : ( 'trial' === $registration->payment_method ? 'No payment (trial)' : 'Not recorded' ) ) ) ); ?></td>
									<td><?php echo esc_html( $registration->created_at ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			<?php endif; ?>
		<?php endif; ?>

		<?php if ( '' !== $message ) : ?>
			<p class="nshmv-notice <?php echo '' !== $created_url ? 'nshmv-success' : 'nshmv-info'; ?>" role="status"><?php echo esc_html( $message ); ?></p>
			<?php if ( '' !== $created_url ) : ?>
				<p><label>Event verification link<br><input type="url" readonly value="<?php echo esc_url( $created_url ); ?>" size="70" onclick="this.select()"></label></p>
			<?php endif; ?>
		<?php endif; ?>
		<h3>Create an event</h3>
		<form method="post">
			<input type="hidden" name="nshmv_action" value="create_event">
			<?php wp_nonce_field( 'nshmv_create_event', 'nshmv_event_nonce' ); ?>
			<p>
				<label for="nshmv-event-name">Event name</label><br>
				<input id="nshmv-event-name" name="nshmv_event_name" type="text" maxlength="190" required>
			</p>
			<p><button type="submit">Create event</button></p>
		</form>
	</div>
	<?php
	return ob_get_clean();
}
add_shortcode( 'nsh_organizer_portal', 'nshmv_organizer_shortcode' );
