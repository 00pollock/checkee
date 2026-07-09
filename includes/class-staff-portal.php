<?php

namespace Checkee;

defined( 'ABSPATH' ) || exit;

/**
 * Public, no-WordPress-login check-in page for a single event: /checkee/{slug}.
 * Gated by a per-event PIN, not a WP account — nothing here can edit or delete an
 * event, sync AC, resend emails, or touch any other event's data.
 */
class StaffPortal {

	private const COOKIE_TTL   = 12 * HOUR_IN_SECONDS;
	private const MAX_ATTEMPTS = 5;
	private const LOCKOUT_TTL  = 15 * MINUTE_IN_SECONDS;

	public static function register_rewrite_rule(): void {
		add_rewrite_rule( '^checkee/([^/]+)/?$', 'index.php?checkee_staff_slug=$matches[1]', 'top' );
		add_filter( 'query_vars', function ( $vars ) {
			$vars[] = 'checkee_staff_slug';
			return $vars;
		} );
	}

	public static function handle_request(): void {
		$slug = sanitize_text_field( get_query_var( 'checkee_staff_slug' ) );
		if ( ! $slug ) {
			return;
		}

		$mapping = Mappings::find_by_staff_slug( $slug );
		if ( ! $mapping ) {
			self::render_error_page( 'This check-in link is invalid or has been replaced.' );
			exit;
		}

		if ( isset( $_POST['checkee_staff_pin'] ) ) {
			self::handle_pin_submit( $mapping );
			exit;
		}

		if ( ! self::has_valid_session( $mapping ) ) {
			self::render_pin_gate( $mapping );
			exit;
		}

		self::render_portal( $mapping );
		exit;
	}

	private static function handle_pin_submit( array $mapping ): void {
		if ( ! wp_verify_nonce( sanitize_key( $_POST['_wpnonce'] ?? '' ), 'checkee_staff_pin_' . $mapping['staff_slug'] ) ) {
			self::render_pin_gate( $mapping, 'Security check failed. Please try again.' );
			return;
		}
		if ( self::is_locked_out( $mapping['staff_slug'] ) ) {
			self::render_pin_gate( $mapping, 'Too many incorrect attempts. Try again in a few minutes.' );
			return;
		}

		$submitted = sanitize_text_field( wp_unslash( $_POST['checkee_staff_pin'] ) );
		if ( '' !== $mapping['staff_pin'] && hash_equals( (string) $mapping['staff_pin'], $submitted ) ) {
			self::clear_attempts( $mapping['staff_slug'] );
			self::set_session_cookie( $mapping );
			self::render_portal( $mapping );
			return;
		}

		self::record_attempt( $mapping['staff_slug'] );
		self::render_pin_gate( $mapping, 'Incorrect PIN.' );
	}

	// -------------------------------------------------------------------------
	// Session — HMAC-signed cookie, no server-side session storage needed.
	// Regenerating the PIN (Edit Event) invalidates every existing cookie for
	// this event automatically, since the signature is derived from the PIN.
	// -------------------------------------------------------------------------

	private static function cookie_name( array $mapping ): string {
		return 'checkee_staff_' . substr( md5( $mapping['staff_slug'] ), 0, 12 );
	}

	private static function session_signature( array $mapping, int $expires ): string {
		return hash_hmac( 'sha256', $mapping['id'] . '|' . $mapping['staff_pin'] . '|' . $expires, wp_salt( 'auth' ) );
	}

	private static function set_session_cookie( array $mapping ): void {
		$expires = time() + self::COOKIE_TTL;
		$value   = $expires . '.' . self::session_signature( $mapping, $expires );
		setcookie( self::cookie_name( $mapping ), $value, $expires, '/', '', is_ssl(), true );
	}

	private static function has_valid_session( array $mapping ): bool {
		$cookie = sanitize_text_field( wp_unslash( $_COOKIE[ self::cookie_name( $mapping ) ] ?? '' ) );
		if ( '' === $cookie || ! str_contains( $cookie, '.' ) ) {
			return false;
		}
		[ $expires, $sig ] = explode( '.', $cookie, 2 );
		$expires = (int) $expires;
		if ( $expires < time() ) {
			return false;
		}
		return hash_equals( self::session_signature( $mapping, $expires ), $sig );
	}

	// -------------------------------------------------------------------------
	// Lockout — per slug + IP, so a wrong PIN can't be brute-forced.
	// -------------------------------------------------------------------------

	private static function attempts_key( string $slug ): string {
		$ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '' ) );
		return 'checkee_staff_fails_' . md5( $slug . '|' . $ip );
	}

	private static function is_locked_out( string $slug ): bool {
		return (int) get_transient( self::attempts_key( $slug ) ) >= self::MAX_ATTEMPTS;
	}

	private static function record_attempt( string $slug ): void {
		$key   = self::attempts_key( $slug );
		$count = (int) get_transient( $key );
		set_transient( $key, $count + 1, self::LOCKOUT_TTL );
	}

	private static function clear_attempts( string $slug ): void {
		delete_transient( self::attempts_key( $slug ) );
	}

	/** Mapping + valid session, scoped to the slug in the request. Used by every public AJAX handler. */
	private static function authorize_request(): ?array {
		$slug = sanitize_text_field( wp_unslash( $_POST['slug'] ?? '' ) );
		if ( '' === $slug ) {
			return null;
		}
		$mapping = Mappings::find_by_staff_slug( $slug );
		if ( ! $mapping ) {
			return null;
		}
		if ( ! wp_verify_nonce( sanitize_key( $_POST['_wpnonce'] ?? '' ), 'checkee_staff_action_' . $slug ) ) {
			return null;
		}
		if ( ! self::has_valid_session( $mapping ) ) {
			return null;
		}
		return $mapping;
	}

	// -------------------------------------------------------------------------
	// Public AJAX — nopriv, authorized by slug + PIN session, never by WP login.
	// -------------------------------------------------------------------------

	public static function ajax_search(): void {
		try {
			$mapping = self::authorize_request();
			if ( ! $mapping ) {
				wp_send_json_error( [ 'message' => 'Session expired. Refresh the page and re-enter the PIN.' ] );
				return;
			}
			$mapping_id = (int) $mapping['id'];
			$search     = sanitize_text_field( wp_unslash( $_POST['s'] ?? '' ) );
			$status     = sanitize_key( $_POST['status'] ?? '' );
			$limit      = 50;

			$total_match = Attendees::count_for_mapping( $mapping_id, $search, $status );
			$attendees   = Attendees::get_for_mapping( $mapping_id, $limit, 0, $search, $status );

			$rows = '';
			foreach ( $attendees as $a ) {
				$rows .= Admin::render_attendee_row( $a, $mapping_id, false );
			}

			wp_send_json_success( [
				'rows'        => $rows,
				'total_match' => $total_match,
				'shown'       => count( $attendees ),
			] );
		} catch ( \Throwable $e ) {
			wp_send_json_error( [ 'message' => 'Error: ' . $e->getMessage() ] );
		}
	}

	public static function ajax_manual_checkin(): void {
		try {
			$mapping = self::authorize_request();
			if ( ! $mapping ) {
				wp_send_json_error( [ 'message' => 'Session expired. Refresh the page and re-enter the PIN.' ] );
				return;
			}
			$mapping_id  = (int) $mapping['id'];
			$attendee_id = (int) ( $_POST['attendee_id'] ?? 0 );
			$action      = sanitize_key( $_POST['checkin_action'] ?? 'in' );
			$attendee    = Attendees::find_by_id( $attendee_id );

			if ( ! $attendee || (int) $attendee['event_mapping_id'] !== $mapping_id ) {
				wp_send_json_error( [ 'message' => 'Attendee not found.' ] );
				return;
			}

			Checkin::process( $attendee['qr_token'], 'in' === $action ? 'in' : 'out' );
			$attendee = Attendees::find_by_id( $attendee_id );

			wp_send_json_success( [
				'row'   => Admin::render_attendee_row( $attendee, $mapping_id, false ),
				'stats' => Attendees::status_counts( $mapping_id ),
			] );
		} catch ( \Throwable $e ) {
			wp_send_json_error( [ 'message' => 'Error: ' . $e->getMessage() ] );
		}
	}

	public static function ajax_add_walkin(): void {
		try {
			$mapping = self::authorize_request();
			if ( ! $mapping ) {
				wp_send_json_error( [ 'message' => 'Session expired. Refresh the page and re-enter the PIN.' ] );
				return;
			}
			$mapping_id = (int) $mapping['id'];

			$email      = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
			$first_name = sanitize_text_field( wp_unslash( $_POST['first_name'] ?? '' ) );
			$last_name  = sanitize_text_field( wp_unslash( $_POST['last_name'] ?? '' ) );

			if ( ! $email || ! is_email( $email ) ) {
				wp_send_json_error( [ 'message' => 'Enter a valid email address.' ] );
				return;
			}

			$attendee            = Attendees::find_by_email_event( $email, $mapping_id );
			$already_checked_in  = $attendee && 'checked_in' === $attendee['status'];

			if ( ! $attendee ) {
				$id = Attendees::create( [
					'event_mapping_id' => $mapping_id,
					'event_name'       => $mapping['event_name'],
					'first_name'       => $first_name,
					'last_name'        => $last_name,
					'email'            => $email,
				] );
				if ( ! $id ) {
					wp_send_json_error( [ 'message' => 'Could not add walk-in. Try again.' ] );
					return;
				}
				$attendee = Attendees::find_by_id( $id );
			}

			Admin::sync_walkin_to_ac( $attendee, $mapping );

			if ( ! $already_checked_in ) {
				Checkin::process( $attendee['qr_token'], 'in' );
				$attendee = Attendees::find_by_id( $attendee['id'] );
			}

			wp_send_json_success( [
				'attendee_id' => (int) $attendee['id'],
				'row'         => Admin::render_attendee_row( $attendee, $mapping_id, false ),
				'stats'       => Attendees::status_counts( $mapping_id ),
				'already'     => $already_checked_in,
			] );
		} catch ( \Throwable $e ) {
			wp_send_json_error( [ 'message' => 'Error: ' . $e->getMessage() ] );
		}
	}

	// -------------------------------------------------------------------------
	// Rendering — raw HTML documents, no WP theme/admin chrome at all.
	// -------------------------------------------------------------------------

	private static function page_head( string $title ): void {
		$css_url = esc_url( CHECKEE_URL . 'assets/admin.css' );
		echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">';
		echo '<meta name="viewport" content="width=device-width,initial-scale=1">';
		echo '<title>' . esc_html( $title ) . '</title>';
		echo '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">';
		echo '<link rel="stylesheet" href="' . $css_url . '">';
		echo '<style>
			body{margin:0;background:#f0f0f1;}
			.ck-wrap{margin:0 auto;padding-top:32px;}
			body.checkee-staff-portal .ck-page-header{
				position:sticky;top:0;z-index:20;background:#f0f0f1;padding:16px 0;margin:0 0 20px;
			}
			body.checkee-staff-portal .ck-status-filter-wrap{position:relative;flex-shrink:0;}
			body.checkee-staff-portal .ck-status-filter-menu{
				position:absolute;top:calc(100% + 6px);right:0;z-index:30;
				background:#fff;border:1.5px solid #e5e7eb;border-radius:8px;
				box-shadow:0 4px 16px rgba(0,0,0,.12);min-width:170px;padding:6px;
			}
			body.checkee-staff-portal .ck-status-filter-menu[hidden]{display:none;}
			body.checkee-staff-portal .ck-status-filter-option{
				display:block;width:100%;text-align:left;background:none;border:none;
				padding:10px 12px;font-size:14px;border-radius:6px;cursor:pointer;color:#111827;
			}
			body.checkee-staff-portal .ck-status-filter-option:hover,
			body.checkee-staff-portal .ck-status-filter-option.is-active{background:#f3f4f6;}
			@media (max-width:900px){
				body.checkee-staff-portal .ck-wrap{padding-top:6px;}
				body.checkee-staff-portal .ck-stats-row{margin-bottom:10px;}
				body.checkee-staff-portal .ck-page-header{
					flex-direction:row;align-items:center;justify-content:space-between;flex-wrap:nowrap;
					padding:8px 0;
				}
				body.checkee-staff-portal .ck-search-bar{flex-wrap:nowrap;}
				body.checkee-staff-portal .ck-search-bar input[type="text"]{min-width:0;flex:1 1 auto;}
				body.checkee-staff-portal .ck-search-bar > i.bi-search{display:none;}

				/* Attendee cards: name on one line (no label), email + status sharing a row,
				   check-in/out as a full-width button. Registered date is hidden entirely. */
				body.checkee-staff-portal .ck-table tbody tr{
					display:grid;
					grid-template-columns:max-content 1fr max-content;
					grid-template-areas:"first last last" "email email status" "actions actions actions";
					column-gap:6px;row-gap:8px;align-items:center;padding:14px 16px;
				}
				body.checkee-staff-portal .ck-table td{display:block;padding:0;border:none;}
				body.checkee-staff-portal .ck-table td::before{content:none;}
				body.checkee-staff-portal .ck-table td[data-label="First Name"]{
					grid-area:first;font-size:16px;font-weight:700;color:var(--ck-primary);white-space:nowrap;
				}
				body.checkee-staff-portal .ck-table td[data-label="Last Name"]{
					grid-area:last;justify-self:start;font-size:16px;font-weight:700;color:var(--ck-primary);white-space:nowrap;
				}
				body.checkee-staff-portal .ck-table td[data-label="Email"]{
					grid-area:email;min-width:0;font-size:13px;color:var(--ck-gray);
					overflow:hidden;text-overflow:ellipsis;white-space:nowrap;
				}
				body.checkee-staff-portal .ck-table td[data-label="Status"]{grid-area:status;justify-self:end;}
				body.checkee-staff-portal .ck-table td[data-label="Registered"]{display:none;}
				body.checkee-staff-portal .ck-table td[data-label="Actions"]{grid-area:actions;margin-top:10px;}
				body.checkee-staff-portal .ck-table td[data-label="Actions"] .ck-action-group{display:flex;}
				body.checkee-staff-portal .ck-table td[data-label="Actions"] .ck-checkin-btn{
					flex:1 1 auto;width:100%;padding:8px;font-size:14px;justify-content:center;
				}
			}
		</style>';
		echo '</head><body class="checkee-staff-portal">';
	}

	private static function page_foot(): void {
		echo '</body></html>';
	}

	private static function render_error_page( string $message ): void {
		self::page_head( 'Checkee' );
		echo '<div class="ck-wrap" style="max-width:480px;"><div class="ck-card" style="text-align:center;">';
		echo '<p>' . esc_html( $message ) . '</p></div></div>';
		self::page_foot();
	}

	private static function render_pin_gate( array $mapping, string $error = '' ): void {
		self::page_head( $mapping['event_name'] . ' — Check-in' );
		?>
		<div class="ck-wrap" style="max-width:400px;">
			<div class="ck-card">
				<h2 class="ck-card__title"><?php echo esc_html( $mapping['event_name'] ); ?></h2>
				<p class="ck-card__desc">Enter the PIN you were given to access check-in for this event.</p>
				<?php if ( $error ) : ?>
				<div class="ck-notice ck-notice--error"><i class="bi bi-x-circle-fill"></i> <?php echo esc_html( $error ); ?></div>
				<?php endif; ?>
				<form method="post">
					<?php wp_nonce_field( 'checkee_staff_pin_' . $mapping['staff_slug'], '_wpnonce' ); ?>
					<div class="ck-field">
						<label for="checkee_staff_pin">PIN</label>
						<input type="text" inputmode="numeric" pattern="[0-9]*" autocomplete="off" id="checkee_staff_pin" name="checkee_staff_pin" autofocus required>
					</div>
					<button type="submit" class="ck-btn ck-btn-primary ck-btn-full">Continue</button>
				</form>
			</div>
		</div>
		<?php
		self::page_foot();
	}

	private static function render_portal( array $mapping ): void {
		$mapping_id = (int) $mapping['id'];
		$per_page   = 50;
		$paged      = max( 1, (int) ( $_GET['paged'] ?? 1 ) );

		$stats       = Attendees::status_counts( $mapping_id );
		$total_pages = max( 1, (int) ceil( $stats['total'] / $per_page ) );
		$paged       = min( $paged, $total_pages );
		$offset      = ( $paged - 1 ) * $per_page;
		$attendees   = Attendees::get_for_mapping( $mapping_id, $per_page, $offset );
		$checked     = $stats['checked_in'];
		$page_base   = home_url( 'checkee/' . $mapping['staff_slug'] );

		self::page_head( $mapping['event_name'] . ' — Check-in' );
		?>
		<div class="ck-wrap">
			<div class="ck-page-header">
				<div class="ck-page-header__left">
					<h1><?php echo esc_html( $mapping['event_name'] ); ?></h1>
				</div>
				<div class="ck-action-group">
					<button type="button" id="ck-walkin-toggle" class="ck-btn ck-btn-primary">Add walk-in</button>
				</div>
			</div>

			<div class="ck-card" id="ck-walkin-form-card" hidden>
				<h2 class="ck-card__title">Register + Check In</h2>
				<form id="ck-walkin-form">
					<div class="ck-field-row">
						<div class="ck-field">
							<label for="walkin_first_name">First Name</label>
							<input type="text" id="walkin_first_name" name="first_name" required>
						</div>
						<div class="ck-field">
							<label for="walkin_last_name">Last Name</label>
							<input type="text" id="walkin_last_name" name="last_name" required>
						</div>
					</div>
					<div class="ck-field">
						<label for="walkin_email">Email</label>
						<input type="email" id="walkin_email" name="email" required>
					</div>
					<div class="ck-inline-actions">
						<button type="submit" class="ck-btn ck-btn-primary">Register &amp; check in</button>
						<button type="button" id="ck-walkin-cancel" class="ck-btn ck-btn-ghost">Cancel</button>
					</div>
					<div id="ck-walkin-result" class="ck-list-meta"></div>
				</form>
			</div>

			<div class="ck-stats-row">
				<div class="ck-stat">
					<div class="ck-stat__value" id="ck-stat-total"><?php echo (int) $stats['total']; ?></div>
					<div class="ck-stat__label">Registered</div>
				</div>
				<div class="ck-stat">
					<div class="ck-stat__value ck-stat__value--green" id="ck-stat-checked-in"><?php echo (int) $checked; ?></div>
					<div class="ck-stat__label">Checked In</div>
				</div>
				<div class="ck-stat">
					<div class="ck-stat__value ck-stat__value--muted" id="ck-stat-not-checked-in"><?php echo (int) ( $stats['total'] - $checked ); ?></div>
					<div class="ck-stat__label">Not Checked In</div>
				</div>
			</div>

			<?php if ( 0 === $stats['total'] ) : ?>
			<div class="ck-empty-state">
				<div class="ck-empty-state__icon"><i class="bi bi-people"></i></div>
				<h3>No attendees yet</h3>
			</div>
			<?php else : ?>

			<div class="ck-search-bar">
				<i class="bi bi-search"></i>
				<input type="text" id="ck-search" placeholder="Search by name or email…" autocomplete="off">
				<button type="button" id="ck-search-clear" class="ck-btn ck-btn-sm ck-btn-ghost" hidden>Clear</button>
				<div class="ck-status-filter-wrap">
					<button type="button" id="ck-status-filter-btn" class="ck-btn ck-btn-sm ck-btn-outline">
						<span id="ck-status-filter-label">All Statuses</span> <i class="bi bi-chevron-down"></i>
					</button>
					<div class="ck-status-filter-menu" id="ck-status-filter-menu" hidden>
						<button type="button" class="ck-status-filter-option is-active" data-value="">All Statuses</button>
						<button type="button" class="ck-status-filter-option" data-value="registered">Registered</button>
						<button type="button" class="ck-status-filter-option" data-value="checked_in">Checked In</button>
						<button type="button" class="ck-status-filter-option" data-value="checked_out">Checked Out</button>
					</div>
				</div>
			</div>

			<div class="ck-list-meta" id="ck-list-meta">
				<?php
				$range_start = $offset + 1;
				$range_end   = min( $offset + $per_page, $stats['total'] );
				?>
				<span>Showing <?php echo (int) $range_start; ?>–<?php echo (int) $range_end; ?> of <?php echo (int) $stats['total']; ?> attendee<?php echo 1 === $stats['total'] ? '' : 's'; ?></span>
			</div>

			<div class="ck-card ck-card--flush">
				<table class="ck-table" id="ck-attendees-table">
					<thead>
						<tr>
							<th>First Name</th>
							<th>Last Name</th>
							<th>Email</th>
							<th class="ck-th-center">Status</th>
							<th class="ck-th-center">Registered</th>
							<th class="ck-th-right">Actions</th>
						</tr>
					</thead>
					<tbody id="ck-attendees-tbody">
					<?php foreach ( $attendees as $a ) {
						echo Admin::render_attendee_row( $a, $mapping_id, false );
					} ?>
					</tbody>
				</table>
			</div>

			<?php if ( $total_pages > 1 ) :
				$prev_disabled = $paged <= 1;
				$next_disabled = $paged >= $total_pages;
				?>
			<div class="ck-pagination" id="ck-pagination">
				<?php if ( $prev_disabled ) : ?>
					<span class="ck-btn ck-btn-sm ck-btn-outline ck-btn--disabled">&laquo; Prev</span>
				<?php else : ?>
					<a href="<?php echo esc_url( $page_base . '?paged=' . ( $paged - 1 ) ); ?>" class="ck-btn ck-btn-sm ck-btn-outline">&laquo; Prev</a>
				<?php endif; ?>
				<span class="ck-pagination__pages">Page <?php echo (int) $paged; ?> of <?php echo (int) $total_pages; ?></span>
				<?php if ( $next_disabled ) : ?>
					<span class="ck-btn ck-btn-sm ck-btn-outline ck-btn--disabled">Next &raquo;</span>
				<?php else : ?>
					<a href="<?php echo esc_url( $page_base . '?paged=' . ( $paged + 1 ) ); ?>" class="ck-btn ck-btn-sm ck-btn-outline">Next &raquo;</a>
				<?php endif; ?>
			</div>
			<?php endif; ?>

			<?php endif; // 0 === total ?>
		</div>

		<script>
		(function(){
			var ajaxUrl = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
			var slug    = <?php echo wp_json_encode( $mapping['staff_slug'] ); ?>;
			var nonce   = <?php echo wp_json_encode( wp_create_nonce( 'checkee_staff_action_' . $mapping['staff_slug'] ) ); ?>;

			var tbody      = document.getElementById('ck-attendees-tbody');
			var input      = document.getElementById('ck-search');
			var clear      = document.getElementById('ck-search-clear');
			var statusBtn  = document.getElementById('ck-status-filter-btn');
			var statusMenu = document.getElementById('ck-status-filter-menu');
			var statusLabel = document.getElementById('ck-status-filter-label');
			var meta   = document.getElementById('ck-list-meta');
			var pager  = document.getElementById('ck-pagination');
			var currentStatus = '';
			var STATUS_LABELS = { '': 'All Statuses', registered: 'Registered', checked_in: 'Checked In', checked_out: 'Checked Out' };

			if (statusBtn && statusMenu) {
				statusBtn.addEventListener('click', function(e){
					e.stopPropagation();
					statusMenu.hidden = !statusMenu.hidden;
				});
				statusMenu.querySelectorAll('.ck-status-filter-option').forEach(function(opt){
					opt.addEventListener('click', function(){
						currentStatus = opt.getAttribute('data-value');
						statusLabel.textContent = STATUS_LABELS[currentStatus];
						statusMenu.querySelectorAll('.ck-status-filter-option').forEach(function(o){
							o.classList.toggle('is-active', o === opt);
						});
						statusMenu.hidden = true;
						runFilter();
					});
				});
				document.addEventListener('click', function(e){
					if (!statusMenu.hidden && e.target !== statusBtn && !statusMenu.contains(e.target) && !statusBtn.contains(e.target)) {
						statusMenu.hidden = true;
					}
				});
			}

			function updateStatCards(stats) {
				var totalEl = document.getElementById('ck-stat-total');
				var inEl    = document.getElementById('ck-stat-checked-in');
				var notEl   = document.getElementById('ck-stat-not-checked-in');
				if (totalEl) totalEl.textContent = stats.total;
				if (inEl)    inEl.textContent     = stats.checked_in;
				if (notEl)   notEl.textContent    = stats.total - stats.checked_in;
			}

			function replaceRow(container, id, rowHtml) {
				var tr = container.querySelector('tr[data-attendee-id="' + id + '"]');
				var wrap = document.createElement('tbody');
				wrap.innerHTML = rowHtml;
				if (tr) {
					tr.replaceWith(wrap.firstElementChild);
				} else if (container === tbody) {
					tbody.insertBefore(wrap.firstElementChild, tbody.firstChild);
				}
			}

			if (tbody) {
				tbody.addEventListener('click', function(e){
					var btn = e.target.closest('.ck-checkin-btn');
					if (!btn) return;
					var id        = btn.getAttribute('data-id');
					var actionVal = btn.getAttribute('data-action');
					btn.disabled  = true;

					fetch(ajaxUrl, {
						method: 'POST',
						headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
						body: 'action=checkee_staff_manual_checkin'
							+ '&slug=' + encodeURIComponent(slug)
							+ '&attendee_id=' + encodeURIComponent(id)
							+ '&checkin_action=' + encodeURIComponent(actionVal)
							+ '&_wpnonce=' + encodeURIComponent(nonce)
					})
					.then(function(r){ return r.json(); })
					.then(function(data){
						if (!data.success) {
							btn.disabled = false;
							alert((data.data && data.data.message) ? data.data.message : 'Action failed.');
							return;
						}
						replaceRow(tbody, id, data.data.row);
						updateStatCards(data.data.stats);
					})
					.catch(function(){
						btn.disabled = false;
						alert('Request failed. Check your connection and try again.');
					});
				});
			}

			var debounceTimer  = null;
			var currentRequest = 0;

			function isFiltering() {
				return (input && input.value.trim() !== '') || currentStatus !== '';
			}

			function describeFilter(term, statusVal) {
				var parts = [];
				if (term) parts.push('matching “' + term + '”');
				if (statusVal) parts.push('with status “' + STATUS_LABELS[statusVal] + '”');
				return parts.length ? ' ' + parts.join(' ') : '';
			}

			function runFilter() {
				if (!input || !tbody) return;
				var term = input.value.trim();
				clear.hidden = ! isFiltering();

				if (! isFiltering()) {
					window.location.href = <?php echo wp_json_encode( $page_base ); ?>;
					return;
				}

				var requestId = ++currentRequest;
				fetch(ajaxUrl, {
					method: 'POST',
					headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
					body: 'action=checkee_staff_search'
						+ '&slug=' + encodeURIComponent(slug)
						+ '&s=' + encodeURIComponent(term)
						+ '&status=' + encodeURIComponent(currentStatus)
						+ '&_wpnonce=' + encodeURIComponent(nonce)
				})
				.then(function(r){ return r.json(); })
				.then(function(data){
					if (requestId !== currentRequest || !data.success) return;
					tbody.innerHTML = data.data.rows;
					if (pager) { pager.remove(); pager = null; }
					var count = data.data.total_match;
					var desc  = describeFilter(term, currentStatus);
					if (count > 0) {
						var shownNote = data.data.total_match > data.data.shown ? ' (showing first ' + data.data.shown + ')' : '';
						meta.innerHTML = '<span>' + count + ' attendee' + (count === 1 ? '' : 's') + desc + shownNote + '</span>';
					} else {
						meta.innerHTML = '<span>No attendees found' + desc + '.</span>';
					}
				});
			}

			if (input && tbody) {
				input.addEventListener('input', function(){
					clearTimeout(debounceTimer);
					debounceTimer = setTimeout(runFilter, 250);
				});
				clear.addEventListener('click', function(){
					input.value = '';
					currentStatus = '';
					statusLabel.textContent = STATUS_LABELS[''];
					statusMenu.querySelectorAll('.ck-status-filter-option').forEach(function(o){
						o.classList.toggle('is-active', o.getAttribute('data-value') === '');
					});
					window.location.href = <?php echo wp_json_encode( $page_base ); ?>;
				});
			}

			var walkinToggle = document.getElementById('ck-walkin-toggle');
			var walkinCard   = document.getElementById('ck-walkin-form-card');
			var walkinForm   = document.getElementById('ck-walkin-form');
			var walkinCancel = document.getElementById('ck-walkin-cancel');
			var walkinResult = document.getElementById('ck-walkin-result');

			if (walkinToggle && walkinCard) {
				walkinToggle.addEventListener('click', function(){
					walkinCard.hidden = !walkinCard.hidden;
					if (!walkinCard.hidden) {
						var first = document.getElementById('walkin_first_name');
						if (first) first.focus();
					}
				});
			}
			if (walkinCancel) {
				walkinCancel.addEventListener('click', function(){
					walkinCard.hidden = true;
					walkinForm.reset();
					walkinResult.textContent = '';
				});
			}
			if (walkinForm) {
				walkinForm.addEventListener('submit', function(e){
					e.preventDefault();
					var submitBtn = walkinForm.querySelector('button[type="submit"]');
					submitBtn.disabled = true;
					walkinResult.textContent = '';

					fetch(ajaxUrl, {
						method: 'POST',
						headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
						body: 'action=checkee_staff_add_walkin'
							+ '&slug=' + encodeURIComponent(slug)
							+ '&first_name=' + encodeURIComponent(document.getElementById('walkin_first_name').value)
							+ '&last_name=' + encodeURIComponent(document.getElementById('walkin_last_name').value)
							+ '&email=' + encodeURIComponent(document.getElementById('walkin_email').value)
							+ '&_wpnonce=' + encodeURIComponent(nonce)
					})
					.then(function(r){ return r.json(); })
					.then(function(data){
						submitBtn.disabled = false;
						if (!data.success) {
							walkinResult.textContent = (data.data && data.data.message) ? data.data.message : 'Could not add walk-in.';
							return;
						}
						if (tbody) replaceRow(tbody, data.data.attendee_id, data.data.row);
						updateStatCards(data.data.stats);
						walkinForm.reset();
						walkinCard.hidden = true;
						walkinResult.textContent = '';
					})
					.catch(function(){
						submitBtn.disabled = false;
						walkinResult.textContent = 'Request failed. Check your connection and try again.';
					});
				});
			}
		})();
		</script>
		<?php
		self::page_foot();
	}
}
