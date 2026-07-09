<?php

namespace Checkee;

defined( 'ABSPATH' ) || exit;

class Admin {

	// -------------------------------------------------------------------------
	// Menu & Assets
	// -------------------------------------------------------------------------

	public static function register_menu(): void {
		add_menu_page(
			'Checkee',
			'Checkee',
			'manage_options',
			'checkee',
			[ self::class, 'render_events' ],
			'data:image/svg+xml;base64,' . base64_encode('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path fill="black" d="M7.5 5.6L10 7 8.6 4.5 10 2 7.5 3.4 5 2l1.4 2.5L5 7zm12 9.8L17 14l1.4 2.5L17 19l2.5-1.4L22 19l-1.4-2.5L22 14zM22 2l-2.5 1.4L17 2l1.4 2.5L17 7l2.5-1.4L22 7l-1.4-2.5zm-7.63 5.29a1 1 0 0 0-1.41 0L1.29 18.96a1 1 0 0 0 0 1.41l2.34 2.34a1 1 0 0 0 1.41 0L16.7 11.05a1 1 0 0 0 0-1.41l-2.33-2.35z"/></svg>'),
			57
		);
		add_submenu_page( 'checkee', 'Events', 'Events', 'manage_options', 'checkee', [ self::class, 'render_events' ] );
		add_submenu_page( 'checkee', 'Settings', 'Settings', 'manage_options', 'checkee-settings', [ self::class, 'render_settings' ] );
	}

	public static function enqueue_assets( string $hook ): void {
		if ( ! in_array( $hook, [ 'toplevel_page_checkee', 'checkee_page_checkee-settings' ], true )
			&& ! str_contains( $hook, 'checkee' ) ) {
			return;
		}
		wp_enqueue_style( 'bootstrap-icons', 'https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css', [], '1.11.3' );
		wp_enqueue_style( 'checkee-admin', CHECKEE_URL . 'assets/admin.css', [ 'bootstrap-icons' ], CHECKEE_VERSION );
	}

	// -------------------------------------------------------------------------
	// Events list page
	// -------------------------------------------------------------------------

	public static function render_events(): void {
		$action = sanitize_key( $_GET['action'] ?? 'list' );

		match ( $action ) {
			'create'    => self::render_event_form(),
			'edit'      => self::render_event_form( (int) ( $_GET['id'] ?? 0 ) ),
			'attendees' => self::render_attendees( (int) ( $_GET['id'] ?? 0 ) ),
			default     => self::render_event_list(),
		};
	}

	private static function render_event_list(): void {
		$mappings = Mappings::get_all();
		?>
		<div class="ck-wrap">
			<?php self::render_notice(); ?>
			<div class="ck-page-header">
				<div class="ck-page-header__left">
					<h1><i class="bi bi-calendar2-check-fill"></i> Events</h1>
					<p>Each event links a Kadence form to Checkee's check-in system.</p>
				</div>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=checkee&action=create' ) ); ?>" class="ck-btn ck-btn-primary">
					<i class="bi bi-plus-lg"></i> New Event
				</a>
			</div>

			<?php if ( empty( $mappings ) ) : ?>
			<div class="ck-empty-state">
				<div class="ck-empty-state__icon"><i class="bi bi-calendar-x"></i></div>
				<h3>No events yet</h3>
				<p>Create your first event to start tracking registrations and check-ins.</p>
			</div>
			<?php else : ?>
			<div class="ck-card ck-card--flush">
				<table class="ck-table">
					<thead>
						<tr>
							<th>Event</th>
							<th>Form</th>
							<th class="ck-th-center">Attendees</th>
							<th class="ck-th-center">Status</th>
							<th class="ck-th-right">Actions</th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $mappings as $m ) : ?>
						<tr>
							<td data-label="Event">
								<a href="<?php echo esc_url( admin_url( 'admin.php?page=checkee&action=attendees&id=' . (int) $m['id'] ) ); ?>" class="ck-link-strong">
									<?php echo esc_html( $m['event_name'] ); ?>
								</a>
							</td>
							<td class="ck-text-muted" data-label="Form"><?php echo esc_html( $m['form_title'] ?: '—' ); ?></td>
							<td class="ck-th-center" data-label="Attendees">
								<span class="ck-count-badge"><?php echo (int) $m['attendee_count']; ?></span>
							</td>
							<td class="ck-th-center" data-label="Status">
								<?php
								$active = $m['status'] === 'active';
								echo '<span class="ck-badge ' . ( $active ? 'ck-badge--green' : 'ck-badge--gray' ) . '">'
									. ( $active ? 'Active' : 'Inactive' ) . '</span>';
								?>
							</td>
							<td class="ck-th-right" data-label="Actions">
								<div class="ck-action-group">
									<a href="<?php echo esc_url( admin_url( 'admin.php?page=checkee&action=attendees&id=' . (int) $m['id'] ) ); ?>" class="ck-icon-btn" title="View Attendees">
										<i class="bi bi-people"></i>
									</a>
									<a href="<?php echo esc_url( admin_url( 'admin.php?page=checkee&action=edit&id=' . (int) $m['id'] ) ); ?>" class="ck-icon-btn" title="Edit">
										<i class="bi bi-pencil-fill"></i>
									</a>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ck-inline-form" onsubmit="return confirm('Delete this event? Attendee records will be kept.');">
										<?php wp_nonce_field( 'checkee_delete_event_' . $m['id'], '_wpnonce' ); ?>
										<input type="hidden" name="action" value="checkee_delete_event">
										<input type="hidden" name="event_id" value="<?php echo (int) $m['id']; ?>">
										<button type="submit" class="ck-icon-btn ck-icon-btn--danger" title="Delete">
											<i class="bi bi-trash3-fill"></i>
										</button>
									</form>
								</div>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<?php endif; ?>
		</div>
		<?php
	}

	// -------------------------------------------------------------------------
	// Create / Edit event form
	// -------------------------------------------------------------------------

	private static function render_event_form( int $id = 0 ): void {
		$mapping = $id ? Mappings::find_by_id( $id ) : null;
		$is_edit = $mapping !== null;
		$title   = $is_edit ? 'Edit Event' : 'New Event';
		$forms   = Forms::get_kadence_forms();

		$v = [
			'event_name'          => $mapping['event_name']          ?? '',
			'form_id'             => $mapping['form_id']             ?? '',
			'email_field'         => $mapping['email_field']         ?? 'Email',
			'first_name_field'    => $mapping['first_name_field']    ?? 'First Name',
			'last_name_field'     => $mapping['last_name_field']     ?? 'Last Name',
			'ac_registration_tag' => $mapping['ac_registration_tag'] ?? '',
			'ac_checkin_tag'      => $mapping['ac_checkin_tag']      ?? '',
			'ac_checkout_tag'     => $mapping['ac_checkout_tag']     ?? '',
			'status'              => $mapping['status']              ?? 'active',
		];

		// Pre-load fields for current form (edit mode)
		$preloaded_fields = [];
		if ( $v['form_id'] && is_numeric( $v['form_id'] ) ) {
			$preloaded_fields = Forms::get_form_fields( (int) $v['form_id'] );
		}

		$fields_nonce = wp_create_nonce( 'checkee_get_form_fields' );
		?>
		<div class="ck-wrap">
			<div class="ck-page-header">
				<div class="ck-page-header__left">
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=checkee' ) ); ?>" class="ck-back-link">
						<i class="bi bi-arrow-left"></i> Events
					</a>
					<h1><?php echo esc_html( $title ); ?></h1>
				</div>
			</div>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( $is_edit ? 'checkee_update_event_' . $id : 'checkee_create_event', '_wpnonce' ); ?>
				<input type="hidden" name="action" value="<?php echo $is_edit ? 'checkee_update_event' : 'checkee_create_event'; ?>">
				<?php if ( $is_edit ) : ?>
				<input type="hidden" name="event_id" value="<?php echo (int) $id; ?>">
				<?php endif; ?>

				<div class="ck-form-grid">

					<!-- Main -->
					<div class="ck-form-main">
						<div class="ck-card">
							<h2 class="ck-card__title"><i class="bi bi-calendar-event"></i> Event Details</h2>
							<div class="ck-field">
								<label for="event_name">Event Name <span class="ck-required">*</span></label>
								<input type="text" id="event_name" name="event_name" required
									value="<?php echo esc_attr( $v['event_name'] ); ?>"
									placeholder="e.g. Grace and Faith 2026">
							</div>
							<div class="ck-field">
								<label for="form_id">Kadence Form <span class="ck-required">*</span></label>
								<?php if ( empty( $forms ) ) : ?>
									<p class="ck-field-note ck-field-note--warn"><i class="bi bi-exclamation-triangle"></i> No Kadence forms found.</p>
									<input type="text" id="form_id" name="form_id" value="<?php echo esc_attr( $v['form_id'] ); ?>" placeholder="Enter form ID manually">
								<?php else : ?>
									<select id="form_id" name="form_id" required>
										<option value="">— Select a form —</option>
										<?php foreach ( $forms as $form ) : ?>
										<option value="<?php echo esc_attr( $form['id'] ); ?>" <?php selected( $v['form_id'], $form['id'] ); ?>>
											<?php echo esc_html( $form['title'] ); ?>
										</option>
										<?php endforeach; ?>
									</select>
								<?php endif; ?>
								<p class="ck-field-note">Submissions from this form will create attendee records for this event.</p>
							</div>
						</div>

						<!-- Field mapping -->
						<div class="ck-card" id="ck-field-mapping-card">
							<h2 class="ck-card__title"><i class="bi bi-arrow-left-right"></i> Field Mapping</h2>
							<p class="ck-card__desc" id="ck-mapping-hint">
								<?php if ( empty( $preloaded_fields ) ) : ?>
									Select a form above to load its fields automatically.
								<?php else : ?>
									Map your form fields to Checkee's attendee data.
								<?php endif; ?>
							</p>
							<div class="ck-field-row">
								<div class="ck-field">
									<label for="first_name_field">First Name Field</label>
									<?php self::render_field_select( 'first_name_field', $v['first_name_field'], $preloaded_fields ); ?>
								</div>
								<div class="ck-field">
									<label for="last_name_field">Last Name Field</label>
									<?php self::render_field_select( 'last_name_field', $v['last_name_field'], $preloaded_fields ); ?>
								</div>
							</div>
							<div class="ck-field">
								<label for="email_field">Email Field</label>
								<?php self::render_field_select( 'email_field', $v['email_field'], $preloaded_fields ); ?>
							</div>
						</div>
					</div>

					<!-- Sidebar -->
					<div class="ck-form-side">
						<div class="ck-card">
							<h2 class="ck-card__title"><i class="bi bi-sliders"></i> Settings</h2>
							<div class="ck-field">
								<label for="status">Status</label>
								<select id="status" name="status">
									<option value="active"   <?php selected( $v['status'], 'active' ); ?>>Active</option>
									<option value="inactive" <?php selected( $v['status'], 'inactive' ); ?>>Inactive</option>
								</select>
							</div>
							<button type="submit" class="ck-btn ck-btn-primary ck-btn-full">
								<i class="bi bi-check-lg"></i> <?php echo $is_edit ? 'Save Changes' : 'Create Event'; ?>
							</button>
							<?php if ( $is_edit ) : ?>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=checkee' ) ); ?>" class="ck-btn ck-btn-ghost ck-btn-full" style="margin-top:8px;">Cancel</a>
							<?php endif; ?>
						</div>

						<div class="ck-card">
							<h2 class="ck-card__title"><i class="bi bi-tags-fill"></i> ActiveCampaign Tags</h2>
							<p class="ck-card__desc">Manage tags applied on registration, check-in, and removal. Leave blank to skip.</p>
							<div class="ck-field">
								<label for="ac_registration_tag">Registration Tag</label>
								<input type="text" id="ac_registration_tag" name="ac_registration_tag"
									value="<?php echo esc_attr( $v['ac_registration_tag'] ); ?>"
									placeholder="Registered - <?php echo esc_attr( $v['event_name'] ?: 'Event Name' ); ?>">
								<p class="ck-field-note">Removed from the contact when you delete their registration.</p>
							</div>
							<div class="ck-field">
								<label for="ac_checkin_tag">Check-In Tag</label>
								<input type="text" id="ac_checkin_tag" name="ac_checkin_tag"
									value="<?php echo esc_attr( $v['ac_checkin_tag'] ); ?>"
									placeholder="Checked In - <?php echo esc_attr( $v['event_name'] ?: 'Event Name' ); ?>">
							</div>
							<div class="ck-field">
								<label for="ac_checkout_tag">Check-Out Tag</label>
								<input type="text" id="ac_checkout_tag" name="ac_checkout_tag"
									value="<?php echo esc_attr( $v['ac_checkout_tag'] ); ?>"
									placeholder="Checked Out - <?php echo esc_attr( $v['event_name'] ?: 'Event Name' ); ?>">
							</div>
						</div>
					</div>

				</div><!-- .ck-form-grid -->
			</form>
		</div>

		<script>
		(function(){
			var formSel   = document.getElementById('form_id');
			var nonce     = '<?php echo esc_js( $fields_nonce ); ?>';
			var ajaxUrl   = '<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>';
			var fieldKeys = ['first_name_field','last_name_field','email_field'];
			var hint      = document.getElementById('ck-mapping-hint');

			function buildSelect(name, currentVal, fields) {
				var sel = document.createElement('select');
				sel.name = name;
				sel.id   = name;
				var opt0 = document.createElement('option');
				opt0.value = ''; opt0.text = '— Select field —';
				sel.appendChild(opt0);
				fields.forEach(function(f){
					var o = document.createElement('option');
					o.value = f; o.text = f;
					if (f.toLowerCase() === currentVal.toLowerCase()) o.selected = true;
					sel.appendChild(o);
				});
				// custom option
				var optC = document.createElement('option');
				optC.value = '__custom__'; optC.text = 'Other (type manually)…';
				if (fields.indexOf(currentVal) === -1 && currentVal !== '') optC.selected = true;
				sel.appendChild(optC);
				sel.addEventListener('change', function(){
					if (this.value === '__custom__') {
						var inp = document.createElement('input');
						inp.type = 'text'; inp.name = name; inp.id = name;
						inp.placeholder = 'Type field label…';
						inp.value = '';
						this.parentNode.replaceChild(inp, this);
						inp.focus();
					}
				});
				return sel;
			}

			function loadFields(formId) {
				if (!formId) return;
				hint && (hint.textContent = 'Loading fields…');
				fetch(ajaxUrl, {
					method: 'POST',
					headers: {'Content-Type':'application/x-www-form-urlencoded'},
					body: 'action=checkee_get_form_fields&form_id=' + encodeURIComponent(formId) + '&_wpnonce=' + encodeURIComponent(nonce)
				})
				.then(r => r.json())
				.then(data => {
					if (!data.success || !data.data.fields.length) {
						hint && (hint.textContent = 'No fields detected. Type labels manually.');
						return;
					}
					var fields = data.data.fields;
					hint && (hint.textContent = fields.length + ' fields loaded from form.');
					fieldKeys.forEach(function(key){
						var existing = document.getElementById(key);
						if (!existing) return;
						var currentVal = existing.value || existing.getAttribute('data-current') || '';
						var sel = buildSelect(key, currentVal, fields);
						existing.parentNode.replaceChild(sel, existing);
					});
				})
				.catch(() => {
					hint && (hint.textContent = 'Could not load fields. Type labels manually.');
				});
			}

			if (formSel) {
				formSel.addEventListener('change', function(){ loadFields(this.value); });
				// On edit page with a preloaded form, mark current values for JS
				fieldKeys.forEach(function(key){
					var el = document.getElementById(key);
					if (el) el.setAttribute('data-current', el.value);
				});
				<?php if ( $v['form_id'] && empty( $preloaded_fields ) ) : ?>
				// Form selected but no preloaded fields — load them now
				if (formSel.value) loadFields(formSel.value);
				<?php endif; ?>
			}
		})();
		</script>
		<?php
	}

	/** Render a field select or text input depending on available fields. */
	private static function render_field_select( string $name, string $current, array $fields ): void {
		if ( empty( $fields ) ) {
			echo '<input type="text" id="' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $current ) . '" data-current="' . esc_attr( $current ) . '" placeholder="' . esc_attr( ucwords( str_replace( '_field', '', $name ) ) ) . '">';
			return;
		}
		echo '<select id="' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '">';
		echo '<option value="">— Select field —</option>';
		foreach ( $fields as $field ) {
			$sel = selected( strtolower( $current ), strtolower( $field ), false );
			echo '<option value="' . esc_attr( $field ) . '" ' . $sel . '>' . esc_html( $field ) . '</option>';
		}
		echo '<option value="__custom__">Other (type manually)…</option>';
		echo '</select>';
	}

	// -------------------------------------------------------------------------
	// Attendees page
	// -------------------------------------------------------------------------

	private static function render_attendees( int $mapping_id ): void {
		$mapping = Mappings::find_by_id( $mapping_id );
		if ( ! $mapping ) {
			wp_die( esc_html__( 'Event not found.', 'checkee' ) );
		}

		$search   = sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) );
		$per_page = 50;
		$paged    = max( 1, (int) ( $_GET['paged'] ?? 1 ) );

		$stats       = Attendees::status_counts( $mapping_id );
		$total_match = '' === $search ? $stats['total'] : Attendees::count_for_mapping( $mapping_id, $search );
		$total_pages = max( 1, (int) ceil( $total_match / $per_page ) );
		$paged       = min( $paged, $total_pages );
		$offset      = ( $paged - 1 ) * $per_page;
		$attendees   = Attendees::get_for_mapping( $mapping_id, $per_page, $offset, $search );
		$checked     = $stats['checked_in'];

		$base_url        = admin_url( 'admin.php?page=checkee&action=attendees&id=' . $mapping_id );
		$ac_sync_ready   = ( new ActiveCampaign() )->is_configured() && ! empty( $mapping['ac_checkin_tag'] );
		?>
		<div class="ck-wrap">
			<?php self::render_notice(); ?>
			<div class="ck-page-header">
				<div class="ck-page-header__left">
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=checkee' ) ); ?>" class="ck-back-link">
						<i class="bi bi-arrow-left"></i> Events
					</a>
					<h1><?php echo esc_html( $mapping['event_name'] ); ?></h1>
				</div>
				<div class="ck-action-group">
					<button type="button" id="ck-walkin-toggle" class="ck-btn ck-btn-primary">
						<i class="bi bi-person-plus-fill"></i> Add Walk-in
					</button>
					<?php if ( $ac_sync_ready ) : ?>
					<button type="button" id="ck-sync-ac" class="ck-btn ck-btn-outline" data-mapping-id="<?php echo (int) $mapping_id; ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( 'checkee_sync_ac_attendance' ) ); ?>">
						<i class="bi bi-arrow-repeat"></i> Sync Attendees
					</button>
					<?php endif; ?>
					<?php if ( $stats['total'] > 0 ) : ?>
					<button type="button" id="ck-resend-qr" class="ck-btn ck-btn-outline" data-mapping-id="<?php echo (int) $mapping_id; ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( 'checkee_resend_qr_batch' ) ); ?>" data-total="<?php echo (int) $stats['total']; ?>">
						<i class="bi bi-envelope-fill"></i> Resend All QR Codes
					</button>
					<?php endif; ?>
					<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=checkee_export_attendees&mapping_id=' . $mapping_id ), 'checkee_export_attendees_' . $mapping_id ) ); ?>" class="ck-btn ck-btn-outline">
						<i class="bi bi-download"></i> Export CSV
					</a>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=checkee&action=edit&id=' . $mapping_id ) ); ?>" class="ck-btn ck-btn-outline">
						<i class="bi bi-pencil"></i> Edit Event
					</a>
				</div>
			</div>

			<div class="ck-card" id="ck-walkin-form-card" hidden>
				<h2 class="ck-card__title"><i class="bi bi-person-plus-fill"></i> Register + Check In</h2>
				<p class="ck-card__desc">For attendees who show up without registering online. Tags them with both this event's registration and check-in ActiveCampaign tags — creates the AC contact if one doesn't already exist.</p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'checkee_add_walkin_' . $mapping_id, '_wpnonce' ); ?>
					<input type="hidden" name="action" value="checkee_add_walkin">
					<input type="hidden" name="mapping_id" value="<?php echo (int) $mapping_id; ?>">
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
						<button type="submit" class="ck-btn ck-btn-primary">
							<i class="bi bi-check-lg"></i> Register &amp; Check In
						</button>
						<button type="button" id="ck-walkin-cancel" class="ck-btn ck-btn-ghost">Cancel</button>
					</div>
				</form>
			</div>

			<script>
			(function(){
				var toggle = document.getElementById('ck-walkin-toggle');
				var card   = document.getElementById('ck-walkin-form-card');
				var cancel = document.getElementById('ck-walkin-cancel');
				if (!toggle || !card) return;

				toggle.addEventListener('click', function(){
					card.hidden = !card.hidden;
					if (!card.hidden) {
						var first = document.getElementById('walkin_first_name');
						if (first) first.focus();
					}
				});
				if (cancel) {
					cancel.addEventListener('click', function(){
						card.hidden = true;
						card.querySelector('form').reset();
					});
				}
			})();
			</script>
			<?php if ( $ac_sync_ready || $stats['total'] > 0 ) : ?>
			<div id="ck-action-result" class="ck-list-meta" style="text-align:right;margin-top:-20px;"></div>
			<?php endif; ?>

			<?php if ( $ac_sync_ready ) : ?>
			<script>
			(function(){
				var btn    = document.getElementById('ck-sync-ac');
				var result = document.getElementById('ck-action-result');
				if (!btn) return;
				btn.addEventListener('click', function(){
					btn.disabled = true;
					var original = btn.innerHTML;
					btn.innerHTML = '<i class="bi bi-arrow-repeat ck-spin"></i> Syncing…';
					result.textContent = '';

					fetch(ajaxurl, {
						method: 'POST',
						headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
						body: 'action=checkee_sync_ac_attendance'
							+ '&mapping_id=' + encodeURIComponent(btn.getAttribute('data-mapping-id'))
							+ '&_wpnonce=' + encodeURIComponent(btn.getAttribute('data-nonce'))
					})
					.then(function(r){ return r.json(); })
					.then(function(data){
						btn.disabled = false;
						btn.innerHTML = original;
						if (data.success) {
							result.textContent = data.data.message;
							setTimeout(function(){ window.location.reload(); }, 900);
						} else {
							result.textContent = (data.data && data.data.message) ? data.data.message : 'Sync failed.';
						}
					})
					.catch(function(){
						btn.disabled = false;
						btn.innerHTML = original;
						result.textContent = 'Request failed. Check your connection and try again.';
					});
				});
			})();
			</script>
			<?php endif; ?>

			<?php if ( $stats['total'] > 0 ) : ?>
			<script>
			(function(){
				var btn    = document.getElementById('ck-resend-qr');
				var result = document.getElementById('ck-action-result');
				if (!btn) return;

				var BATCH_DELAY_MS = 400;

				function sendBatch(offset) {
					return fetch(ajaxurl, {
						method: 'POST',
						headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
						body: 'action=checkee_resend_qr_batch'
							+ '&mapping_id=' + encodeURIComponent(btn.getAttribute('data-mapping-id'))
							+ '&offset=' + offset
							+ '&_wpnonce=' + encodeURIComponent(btn.getAttribute('data-nonce'))
					}).then(function(r){ return r.json(); });
				}

				function runFrom(offset, sentSoFar) {
					sendBatch(offset).then(function(data){
						if (!data.success) {
							btn.disabled = false;
							btn.innerHTML = '<i class="bi bi-envelope-fill"></i> Resend All QR Codes';
							result.textContent = (data.data && data.data.message) ? data.data.message : 'Resend failed after ' + sentSoFar + ' sent.';
							return;
						}
						sentSoFar += data.data.sent;
						var total = data.data.total;
						result.textContent = 'Resent ' + sentSoFar + '/' + total + '…';

						if (data.data.done) {
							btn.disabled = false;
							btn.innerHTML = '<i class="bi bi-envelope-fill"></i> Resend All QR Codes';
							result.textContent = 'Done — resent QR code emails to ' + sentSoFar + ' of ' + total + ' attendees.';
						} else {
							setTimeout(function(){ runFrom(data.data.next_offset, sentSoFar); }, BATCH_DELAY_MS);
						}
					}).catch(function(){
						btn.disabled = false;
						btn.innerHTML = '<i class="bi bi-envelope-fill"></i> Resend All QR Codes';
						result.textContent = 'Request failed after ' + sentSoFar + ' sent. Check your connection and try again.';
					});
				}

				btn.addEventListener('click', function(){
					var total = btn.getAttribute('data-total');
					if (!confirm('Resend the QR code email to all ' + total + ' registered attendees?')) return;
					btn.disabled = true;
					btn.innerHTML = '<i class="bi bi-arrow-repeat ck-spin"></i> Resending…';
					result.textContent = 'Resent 0/' + total + '…';
					runFrom(0, 0);
				});
			})();
			</script>
			<?php endif; ?>

			<!-- Stats row (always true totals, independent of pagination/search) -->
			<div class="ck-stats-row">
				<div class="ck-stat">
					<div class="ck-stat__value"><?php echo (int) $stats['total']; ?></div>
					<div class="ck-stat__label">Registered</div>
				</div>
				<div class="ck-stat">
					<div class="ck-stat__value ck-stat__value--green"><?php echo (int) $checked; ?></div>
					<div class="ck-stat__label">Checked In</div>
				</div>
				<div class="ck-stat">
					<div class="ck-stat__value ck-stat__value--muted"><?php echo (int) ( $stats['total'] - $checked ); ?></div>
					<div class="ck-stat__label">Not Checked In</div>
				</div>
			</div>

			<?php if ( 0 === $stats['total'] ) : ?>
			<div class="ck-empty-state">
				<div class="ck-empty-state__icon"><i class="bi bi-people"></i></div>
				<h3>No attendees yet</h3>
				<p>Attendees will appear here when people submit the linked Kadence form.</p>
			</div>
			<?php else : ?>

			<!-- Search -->
			<form method="get" class="ck-search-bar">
				<input type="hidden" name="page" value="checkee">
				<input type="hidden" name="action" value="attendees">
				<input type="hidden" name="id" value="<?php echo (int) $mapping_id; ?>">
				<i class="bi bi-search"></i>
				<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="Search by name or email…" autocomplete="off">
				<?php if ( '' !== $search ) : ?>
				<a href="<?php echo esc_url( $base_url ); ?>" class="ck-btn ck-btn-sm ck-btn-ghost">Clear</a>
				<?php endif; ?>
				<button type="submit" class="ck-btn ck-btn-sm ck-btn-primary">Search</button>
				<?php if ( ! empty( $attendees ) ) : ?>
				<button type="button" id="ck-select-all-link" class="ck-btn ck-btn-sm ck-btn-ghost">Select all on page</button>
				<?php endif; ?>
			</form>

			<div class="ck-list-meta">
				<?php if ( $total_match > 0 ) :
					$range_start = $offset + 1;
					$range_end   = min( $offset + $per_page, $total_match );
					?>
					<span>
						Showing <?php echo (int) $range_start; ?>–<?php echo (int) $range_end; ?> of <?php echo (int) $total_match; ?> attendee<?php echo 1 === $total_match ? '' : 's'; ?>
						<?php if ( '' !== $search ) : ?>
							matching “<?php echo esc_html( $search ); ?>”
						<?php endif; ?>
					</span>
				<?php else : ?>
					<span>No attendees match “<?php echo esc_html( $search ); ?>”.</span>
				<?php endif; ?>
			</div>

			<?php if ( ! empty( $attendees ) ) : ?>

			<!-- Bulk actions -->
			<div class="ck-bulk-bar" id="ck-bulk-bar" hidden>
				<span class="ck-bulk-bar__count" id="ck-bulk-count">0 selected</span>
				<div class="ck-action-group">
					<button type="button" class="ck-btn ck-btn-sm ck-btn-primary" data-bulk="checkin"><i class="bi bi-box-arrow-in-right"></i> Check In</button>
					<button type="button" class="ck-btn ck-btn-sm ck-btn-outline" data-bulk="checkout"><i class="bi bi-box-arrow-right"></i> Check Out</button>
					<button type="button" class="ck-btn ck-btn-sm ck-btn-danger" data-bulk="delete"><i class="bi bi-trash3-fill"></i> Delete</button>
				</div>
			</div>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="ck-bulk-form">
				<?php wp_nonce_field( 'checkee_bulk_attendee_action', '_wpnonce' ); ?>
				<input type="hidden" name="action" value="checkee_bulk_attendee_action">
				<input type="hidden" name="mapping_id" value="<?php echo (int) $mapping_id; ?>">
				<input type="hidden" name="bulk_action" id="ck-bulk-action-input" value="">
				<input type="hidden" name="attendee_ids" id="ck-bulk-ids-input" value="">
			</form>

			<div class="ck-card ck-card--flush">
				<table class="ck-table" id="ck-attendees-table">
					<thead>
						<tr>
							<th class="ck-th-check"><input type="checkbox" id="ck-select-all"></th>
							<th>First Name</th>
							<th>Last Name</th>
							<th>Email</th>
							<th class="ck-th-center">Status</th>
							<th class="ck-th-center">Registered</th>
							<th class="ck-th-right">Actions</th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $attendees as $a ) :
						$status_map = [
							'checked_in'  => [ 'label' => 'Checked In',  'class' => 'ck-badge--green' ],
							'checked_out' => [ 'label' => 'Checked Out', 'class' => 'ck-badge--gray'  ],
							'registered'  => [ 'label' => 'Registered',  'class' => 'ck-badge--blue'  ],
						];
						$s = $status_map[ $a['status'] ] ?? $status_map['registered'];
					?>
					<tr>
						<td class="ck-th-check" data-label="Select"><input type="checkbox" class="ck-row-check" data-id="<?php echo (int) $a['id']; ?>"></td>
						<td data-label="First Name"><?php echo esc_html( $a['first_name'] ); ?></td>
						<td data-label="Last Name"><?php echo esc_html( $a['last_name'] ); ?></td>
						<td class="ck-text-muted" data-label="Email"><?php echo esc_html( $a['email'] ); ?></td>
						<td class="ck-th-center" data-label="Status">
							<span class="ck-badge <?php echo esc_attr( $s['class'] ); ?>"><?php echo esc_html( $s['label'] ); ?></span>
						</td>
						<td class="ck-th-center ck-text-muted" data-label="Registered"><?php echo esc_html( wp_date( 'M j, Y', strtotime( $a['created_at'] ) ) ); ?></td>
						<td class="ck-th-right" data-label="Actions">
							<div class="ck-action-group">
								<?php if ( $a['status'] !== 'checked_in' ) : ?>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ck-inline-form">
									<?php wp_nonce_field( 'checkee_manual_checkin_' . $a['id'], '_wpnonce' ); ?>
									<input type="hidden" name="action"         value="checkee_admin_checkin">
									<input type="hidden" name="attendee_id"    value="<?php echo (int) $a['id']; ?>">
									<input type="hidden" name="checkin_action" value="in">
									<input type="hidden" name="mapping_id"     value="<?php echo (int) $mapping_id; ?>">
									<button type="submit" class="ck-btn ck-btn-sm ck-btn-primary">Check In</button>
								</form>
								<?php endif; ?>
								<?php if ( $a['status'] === 'checked_in' ) : ?>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ck-inline-form">
									<?php wp_nonce_field( 'checkee_manual_checkin_' . $a['id'], '_wpnonce' ); ?>
									<input type="hidden" name="action"         value="checkee_admin_checkin">
									<input type="hidden" name="attendee_id"    value="<?php echo (int) $a['id']; ?>">
									<input type="hidden" name="checkin_action" value="out">
									<input type="hidden" name="mapping_id"     value="<?php echo (int) $mapping_id; ?>">
									<button type="submit" class="ck-btn ck-btn-sm ck-btn-outline">Check Out</button>
								</form>
								<?php endif; ?>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ck-inline-form">
									<?php wp_nonce_field( 'checkee_delete_attendee_' . $a['id'], '_wpnonce' ); ?>
									<input type="hidden" name="action"      value="checkee_delete_attendee">
									<input type="hidden" name="attendee_id" value="<?php echo (int) $a['id']; ?>">
									<input type="hidden" name="mapping_id"  value="<?php echo (int) $mapping_id; ?>">
									<button type="submit" class="ck-icon-btn ck-icon-btn--danger" title="Remove Registration">
										<i class="bi bi-trash3-fill"></i>
									</button>
								</form>
							</div>
						</td>
					</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>

			<?php if ( $total_pages > 1 ) :
				$page_base    = $base_url . ( '' !== $search ? '&s=' . rawurlencode( $search ) : '' );
				$prev_disabled = $paged <= 1;
				$next_disabled = $paged >= $total_pages;
				?>
			<div class="ck-pagination">
				<?php if ( $prev_disabled ) : ?>
					<span class="ck-btn ck-btn-sm ck-btn-outline ck-btn--disabled">&laquo; Prev</span>
				<?php else : ?>
					<a href="<?php echo esc_url( $page_base . '&paged=' . ( $paged - 1 ) ); ?>" class="ck-btn ck-btn-sm ck-btn-outline">&laquo; Prev</a>
				<?php endif; ?>
				<span class="ck-pagination__pages">Page <?php echo (int) $paged; ?> of <?php echo (int) $total_pages; ?></span>
				<?php if ( $next_disabled ) : ?>
					<span class="ck-btn ck-btn-sm ck-btn-outline ck-btn--disabled">Next &raquo;</span>
				<?php else : ?>
					<a href="<?php echo esc_url( $page_base . '&paged=' . ( $paged + 1 ) ); ?>" class="ck-btn ck-btn-sm ck-btn-outline">Next &raquo;</a>
				<?php endif; ?>
			</div>
			<?php endif; ?>

			<script>
			(function(){
				var selectAll  = document.getElementById('ck-select-all');
				var bulkBar    = document.getElementById('ck-bulk-bar');
				var bulkCount  = document.getElementById('ck-bulk-count');
				var bulkForm   = document.getElementById('ck-bulk-form');
				var actionIn   = document.getElementById('ck-bulk-action-input');
				var idsIn      = document.getElementById('ck-bulk-ids-input');

				function rowChecks() {
					return Array.prototype.slice.call(document.querySelectorAll('#ck-attendees-table .ck-row-check'));
				}

				function updateBulkBar() {
					var checked = rowChecks().filter(function(c){ return c.checked; });
					if (checked.length) {
						bulkBar.hidden = false;
						bulkCount.textContent = checked.length + ' selected';
					} else {
						bulkBar.hidden = true;
					}
					if (selectAll) {
						var all = rowChecks();
						selectAll.checked = all.length > 0 && checked.length === all.length;
					}
				}

				rowChecks().forEach(function(cb){
					cb.addEventListener('change', updateBulkBar);
				});

				if (selectAll) {
					selectAll.addEventListener('change', function(){
						rowChecks().forEach(function(cb){ cb.checked = selectAll.checked; });
						updateBulkBar();
					});
				}

				var selectAllLink = document.getElementById('ck-select-all-link');
				if (selectAllLink && selectAll) {
					selectAllLink.addEventListener('click', function(){
						selectAll.checked = !selectAll.checked;
						selectAll.dispatchEvent(new Event('change'));
					});
				}

				if (bulkBar) {
					bulkBar.querySelectorAll('[data-bulk]').forEach(function(btn){
						btn.addEventListener('click', function(){
							var action = this.getAttribute('data-bulk');
							var ids = rowChecks().filter(function(c){ return c.checked; }).map(function(c){ return c.getAttribute('data-id'); });
							if (!ids.length) return;
							if (action === 'delete' && !confirm('Remove ' + ids.length + ' registration(s)? This cannot be undone.')) return;
							actionIn.value = action;
							idsIn.value = ids.join(',');
							bulkForm.submit();
						});
					});
				}
			})();
			</script>
			<?php endif; // ! empty $attendees ?>
			<?php endif; // 0 === $stats['total'] ?>

		</div>
		<?php
	}

	// -------------------------------------------------------------------------
	// Settings page
	// -------------------------------------------------------------------------

	public static function render_settings(): void {
		$tab = sanitize_key( $_GET['tab'] ?? 'email' );
		?>
		<div class="ck-wrap">
			<?php self::render_notice(); ?>
			<div class="ck-page-header">
				<div class="ck-page-header__left">
					<h1><i class="bi bi-sliders"></i> Settings</h1>
				</div>
			</div>

			<div class="ck-tabs">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=checkee-settings&tab=email' ) ); ?>"
				   class="ck-tab <?php echo $tab === 'email' ? 'ck-tab--active' : ''; ?>">
					<i class="bi bi-envelope-fill"></i> Email
				</a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=checkee-settings&tab=integrations' ) ); ?>"
				   class="ck-tab <?php echo $tab === 'integrations' ? 'ck-tab--active' : ''; ?>">
					<i class="bi bi-plug-fill"></i> Integrations
				</a>
			</div>

			<?php if ( $tab === 'email' ) : ?>
				<?php self::render_email_settings(); ?>
			<?php else : ?>
				<?php self::render_integration_settings(); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	private static function render_email_settings(): void {
		$from_name  = Email::get_from_name();
		$from_email = Email::get_from_email();
		$subject    = Email::get_subject();
		$template   = Email::get_template();

		$placeholders = [
			'{{first_name}}'  => 'Attendee first name',
			'{{last_name}}'   => 'Attendee last name',
			'{{full_name}}'   => 'Attendee full name',
			'{{email}}'       => 'Attendee email',
			'{{event_name}}'  => 'Event name',
			'{{qr_code}}'     => 'QR code image (renders inline)',
			'{{checkin_url}}' => 'Check-in page URL',
			'{{site_name}}'   => 'Your site name',
			'{{site_url}}'    => 'Your site URL',
		];
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'checkee_save_email', '_wpnonce' ); ?>
			<input type="hidden" name="action" value="checkee_save_email">

			<div class="ck-settings-grid">
				<div class="ck-settings-main">
					<div class="ck-card">
						<h2 class="ck-card__title"><i class="bi bi-send-fill"></i> Sender</h2>
						<div class="ck-field-row">
							<div class="ck-field">
								<label for="from_name">From Name</label>
								<input type="text" id="from_name" name="from_name" value="<?php echo esc_attr( $from_name ); ?>" placeholder="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>">
							</div>
							<div class="ck-field">
								<label for="from_email">From Email</label>
								<input type="email" id="from_email" name="from_email" value="<?php echo esc_attr( $from_email ); ?>" placeholder="noreply@yoursite.com">
							</div>
						</div>
						<div class="ck-field">
							<label for="email_subject">Subject Line</label>
							<input type="text" id="email_subject" name="email_subject" value="<?php echo esc_attr( $subject ); ?>">
							<p class="ck-field-note">Supports: <code>{{event_name}}</code> <code>{{first_name}}</code></p>
						</div>
					</div>

					<div class="ck-card">
						<h2 class="ck-card__title"><i class="bi bi-code-slash"></i> Email Template</h2>
						<p class="ck-card__desc">Full HTML email body. Use the placeholders on the right to insert dynamic values.</p>
						<div class="ck-field">
							<textarea id="email_template" name="email_template" rows="20" class="ck-code-textarea"><?php echo esc_textarea( $template ); ?></textarea>
						</div>
					</div>
				</div>

				<div class="ck-settings-side">
					<div class="ck-card">
						<h2 class="ck-card__title"><i class="bi bi-braces"></i> Placeholders</h2>
						<p class="ck-card__desc">Click to copy to clipboard.</p>
						<div class="ck-placeholder-list">
							<?php foreach ( $placeholders as $tag => $desc ) : ?>
							<div class="ck-placeholder-item" onclick="navigator.clipboard.writeText('<?php echo esc_js( $tag ); ?>')" title="Click to copy">
								<code><?php echo esc_html( $tag ); ?></code>
								<span><?php echo esc_html( $desc ); ?></span>
							</div>
							<?php endforeach; ?>
						</div>
					</div>
					<div class="ck-card">
						<button type="submit" class="ck-btn ck-btn-primary ck-btn-full">
							<i class="bi bi-check-lg"></i> Save Email Settings
						</button>
					</div>
				</div>
			</div>
		</form>
		<?php
	}

	private static function render_integration_settings(): void {
		$ac_url = get_option( 'checkee_ac_url', '' );
		$ac_key = get_option( 'checkee_ac_key', '' );
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'checkee_save_settings', '_wpnonce' ); ?>
			<input type="hidden" name="action" value="checkee_save_settings">

			<div class="ck-settings-grid">
				<div class="ck-settings-main">
					<div class="ck-card">
						<h2 class="ck-card__title"><i class="bi bi-lightning-charge-fill"></i> ActiveCampaign</h2>
						<p class="ck-card__desc">Connect your ActiveCampaign account. Checkee tags contacts on registration, check-in, and check-out. Walk-ins create a new AC contact if one doesn't already exist; everyone else must already exist in AC to be tagged.</p>

						<div class="ck-field">
							<label for="ac_url">Account URL</label>
							<input type="url" id="ac_url" name="checkee_ac_url" value="<?php echo esc_attr( $ac_url ); ?>" placeholder="https://yourname.api-us1.com">
							<p class="ck-field-note">Find this in ActiveCampaign → Settings → Developer.</p>
						</div>
						<div class="ck-field">
							<label for="ac_key">API Key</label>
							<input type="password" id="ac_key" name="checkee_ac_key" value="<?php echo esc_attr( $ac_key ); ?>" autocomplete="new-password">
							<p class="ck-field-note">Find this in ActiveCampaign → Settings → Developer.</p>
						</div>

						<div class="ck-inline-actions">
							<button type="submit" class="ck-btn ck-btn-primary">
								<i class="bi bi-check-lg"></i> Save Credentials
							</button>
							<?php if ( $ac_url && $ac_key ) : ?>
							<button type="button" id="ck-test-ac" class="ck-btn ck-btn-outline">
								<i class="bi bi-wifi"></i> Test Connection
							</button>
							<span id="ck-test-result"></span>
							<?php endif; ?>
						</div>
					</div>
				</div>

				<div class="ck-settings-side">
					<div class="ck-card ck-card--info">
						<h3><i class="bi bi-info-circle-fill"></i> How it works</h3>
						<ul class="ck-info-list">
							<li>On <strong>registration</strong>: adds the registration tag (finds the contact by email — doesn't create one)</li>
							<li>On <strong>walk-in</strong>: creates the AC contact if it doesn't exist yet, then tags it</li>
							<li>On <strong>check-in</strong>: adds the configured tag</li>
							<li>On <strong>check-out</strong>: removes check-in tag, adds check-out tag</li>
							<li>Tag names are configured per-event</li>
						</ul>
					</div>
				</div>
			</div>
		</form>

		<?php if ( $ac_url && $ac_key ) : ?>
		<script>
		document.getElementById('ck-test-ac').addEventListener('click', function(){
			var btn    = this;
			var result = document.getElementById('ck-test-result');
			btn.disabled = true;
			btn.innerHTML = '<i class="bi bi-arrow-repeat ck-spin"></i> Testing…';
			result.innerHTML = '';

			fetch(ajaxurl, {
				method: 'POST',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body: 'action=checkee_test_ac&_wpnonce=<?php echo esc_js( wp_create_nonce( 'checkee_test_ac' ) ); ?>'
			})
			.then( r => r.json() )
			.then( data => {
				btn.disabled = false;
				btn.innerHTML = '<i class="bi bi-wifi"></i> Test Connection';
				var ok  = data.success === true;
				var msg = (data.data && data.data.message) ? data.data.message : (ok ? 'Connected!' : 'Failed');
				result.innerHTML = '<span class="ck-test-result ' + (ok ? 'ck-test-result--ok' : 'ck-test-result--fail') + '">'
					+ '<i class="bi bi-' + (ok ? 'check-circle-fill' : 'x-circle-fill') + '"></i> ' + msg + '</span>';
			})
			.catch( () => {
				btn.disabled = false;
				btn.innerHTML = '<i class="bi bi-wifi"></i> Test Connection';
				result.innerHTML = '<span class="ck-test-result ck-test-result--fail"><i class="bi bi-x-circle-fill"></i> Request failed. Check browser console.</span>';
			});
		});
		</script>
		<?php endif; ?>
		<?php
	}

	// -------------------------------------------------------------------------
	// Admin-post handlers
	// -------------------------------------------------------------------------

	public static function handle_create_event(): void {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );
		if ( ! wp_verify_nonce( sanitize_key( $_POST['_wpnonce'] ?? '' ), 'checkee_create_event' ) ) wp_die( 'Security check failed' );

		$form_id = sanitize_text_field( $_POST['form_id'] ?? '' );
		// Fetch the form title from Kadence CPT if available
		$form_title = '';
		if ( is_numeric( $form_id ) ) {
			$post = get_post( (int) $form_id );
			$form_title = $post ? $post->post_title : '';
		}

		Mappings::create( [
			'event_name'          => $_POST['event_name'] ?? '',
			'form_id'             => $form_id,
			'form_title'          => $form_title,
			'email_field'         => $_POST['email_field'] ?? 'Email',
			'first_name_field'    => $_POST['first_name_field'] ?? 'First Name',
			'last_name_field'     => $_POST['last_name_field'] ?? 'Last Name',
			'ac_registration_tag' => $_POST['ac_registration_tag'] ?? '',
			'ac_checkin_tag'      => $_POST['ac_checkin_tag'] ?? '',
			'ac_checkout_tag'     => $_POST['ac_checkout_tag'] ?? '',
			'status'              => 'active',
		] );

		wp_safe_redirect( admin_url( 'admin.php?page=checkee&ck_msg=created' ) );
		exit;
	}

	public static function handle_update_event(): void {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );
		$id = (int) ( $_POST['event_id'] ?? 0 );
		if ( ! wp_verify_nonce( sanitize_key( $_POST['_wpnonce'] ?? '' ), 'checkee_update_event_' . $id ) ) wp_die( 'Security check failed' );

		$form_id    = sanitize_text_field( $_POST['form_id'] ?? '' );
		$form_title = '';
		if ( is_numeric( $form_id ) ) {
			$post = get_post( (int) $form_id );
			$form_title = $post ? $post->post_title : '';
		}

		Mappings::update( $id, [
			'event_name'          => $_POST['event_name'] ?? '',
			'form_id'             => $form_id,
			'form_title'          => $form_title,
			'email_field'         => $_POST['email_field'] ?? 'Email',
			'first_name_field'    => $_POST['first_name_field'] ?? 'First Name',
			'last_name_field'     => $_POST['last_name_field'] ?? 'Last Name',
			'ac_registration_tag' => $_POST['ac_registration_tag'] ?? '',
			'ac_checkin_tag'      => $_POST['ac_checkin_tag'] ?? '',
			'ac_checkout_tag'     => $_POST['ac_checkout_tag'] ?? '',
			'status'              => $_POST['status'] ?? 'active',
		] );

		wp_safe_redirect( admin_url( 'admin.php?page=checkee&ck_msg=saved' ) );
		exit;
	}

	public static function handle_delete_event(): void {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );
		$id = (int) ( $_POST['event_id'] ?? 0 );
		if ( ! wp_verify_nonce( sanitize_key( $_POST['_wpnonce'] ?? '' ), 'checkee_delete_event_' . $id ) ) wp_die( 'Security check failed' );

		Mappings::delete( $id );
		wp_safe_redirect( admin_url( 'admin.php?page=checkee&ck_msg=deleted' ) );
		exit;
	}

	public static function handle_checkin_post(): void {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );
		$attendee_id = (int) ( $_POST['attendee_id'] ?? 0 );
		if ( ! wp_verify_nonce( sanitize_key( $_POST['_wpnonce'] ?? '' ), 'checkee_manual_checkin_' . $attendee_id ) ) wp_die( 'Security check failed' );

		$action     = sanitize_key( $_POST['checkin_action'] ?? 'in' );
		$mapping_id = (int) ( $_POST['mapping_id'] ?? 0 );
		$attendee   = Attendees::find_by_id( $attendee_id );

		if ( $attendee ) {
			Checkin::process( $attendee['qr_token'], $action );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=checkee&action=attendees&id=' . $mapping_id . '&ck_msg=saved' ) );
		exit;
	}

	public static function handle_delete_attendee(): void {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );
		$attendee_id = (int) ( $_POST['attendee_id'] ?? 0 );
		if ( ! wp_verify_nonce( sanitize_key( $_POST['_wpnonce'] ?? '' ), 'checkee_delete_attendee_' . $attendee_id ) ) wp_die( 'Security check failed' );

		$mapping_id = (int) ( $_POST['mapping_id'] ?? 0 );
		$attendee   = Attendees::find_by_id( $attendee_id );

		if ( $attendee ) {
			// Remove registration tag from AC if configured
			$mapping = Mappings::find_by_id( (int) ( $attendee['event_mapping_id'] ?? 0 ) );
			if ( $mapping && ! empty( $mapping['ac_registration_tag'] ) ) {
				try {
					$ac = new ActiveCampaign();
					if ( $ac->is_configured() ) {
						$contact_id = $ac->find_contact( $attendee['email'] );
						if ( $contact_id ) {
							$ac->remove_tag( $contact_id, $mapping['ac_registration_tag'] );
						}
					}
				} catch ( \Throwable $e ) {
					// AC failure should not block deletion
				}
			}

			Attendees::delete_by_id( $attendee_id );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=checkee&action=attendees&id=' . $mapping_id . '&ck_msg=attendee_removed' ) );
		exit;
	}

	public static function handle_bulk_attendee_action(): void {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );
		if ( ! wp_verify_nonce( sanitize_key( $_POST['_wpnonce'] ?? '' ), 'checkee_bulk_attendee_action' ) ) wp_die( 'Security check failed' );

		$mapping_id  = (int) ( $_POST['mapping_id'] ?? 0 );
		$bulk_action = sanitize_key( $_POST['bulk_action'] ?? '' );
		$ids         = array_filter( array_map( 'intval', explode( ',', (string) ( $_POST['attendee_ids'] ?? '' ) ) ) );

		foreach ( $ids as $attendee_id ) {
			$attendee = Attendees::find_by_id( $attendee_id );
			if ( ! $attendee ) {
				continue;
			}

			if ( 'checkin' === $bulk_action ) {
				Checkin::process( $attendee['qr_token'], 'in' );
			} elseif ( 'checkout' === $bulk_action ) {
				Checkin::process( $attendee['qr_token'], 'out' );
			} elseif ( 'delete' === $bulk_action ) {
				$mapping = Mappings::find_by_id( (int) ( $attendee['event_mapping_id'] ?? 0 ) );
				if ( $mapping && ! empty( $mapping['ac_registration_tag'] ) ) {
					try {
						$ac = new ActiveCampaign();
						if ( $ac->is_configured() ) {
							$contact_id = $ac->find_contact( $attendee['email'] );
							if ( $contact_id ) {
								$ac->remove_tag( $contact_id, $mapping['ac_registration_tag'] );
							}
						}
					} catch ( \Throwable $e ) {
						// AC failure should not block deletion
					}
				}
				Attendees::delete_by_id( $attendee_id );
			}
		}

		wp_safe_redirect( admin_url( 'admin.php?page=checkee&action=attendees&id=' . $mapping_id . '&ck_msg=bulk_done' ) );
		exit;
	}

	public static function handle_export_csv(): void {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );
		$mapping_id = (int) ( $_GET['mapping_id'] ?? 0 );
		if ( ! wp_verify_nonce( sanitize_key( $_GET['_wpnonce'] ?? '' ), 'checkee_export_attendees_' . $mapping_id ) ) wp_die( 'Security check failed' );

		$mapping   = Mappings::find_by_id( $mapping_id );
		$attendees = Attendees::get_all_for_mapping( $mapping_id );
		$filename  = 'checkee-' . sanitize_title( $mapping['event_name'] ?? 'attendees' ) . '-' . gmdate( 'Y-m-d' ) . '.csv';

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, [ 'First Name', 'Last Name', 'Email', 'Status', 'Registered At' ], ',', '"', '\\' );
		foreach ( $attendees as $a ) {
			fputcsv( $out, [
				$a['first_name'],
				$a['last_name'],
				$a['email'],
				$a['status'],
				$a['created_at'],
			], ',', '"', '\\' );
		}
		fclose( $out );
		exit;
	}

	public static function handle_add_walkin(): void {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );
		$mapping_id = (int) ( $_POST['mapping_id'] ?? 0 );
		if ( ! wp_verify_nonce( sanitize_key( $_POST['_wpnonce'] ?? '' ), 'checkee_add_walkin_' . $mapping_id ) ) wp_die( 'Security check failed' );

		$redirect_base = admin_url( 'admin.php?page=checkee&action=attendees&id=' . $mapping_id );

		$mapping = Mappings::find_by_id( $mapping_id );
		if ( ! $mapping ) {
			wp_die( 'Event not found.' );
		}

		$email      = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
		$first_name = sanitize_text_field( wp_unslash( $_POST['first_name'] ?? '' ) );
		$last_name  = sanitize_text_field( wp_unslash( $_POST['last_name'] ?? '' ) );

		if ( ! $email || ! is_email( $email ) ) {
			wp_safe_redirect( $redirect_base . '&ck_msg=walkin_invalid' );
			exit;
		}

		$attendee = Attendees::find_by_email_event( $email, $mapping_id );

		if ( ! $attendee ) {
			$id = Attendees::create( [
				'event_mapping_id' => $mapping_id,
				'event_name'       => $mapping['event_name'],
				'first_name'       => $first_name,
				'last_name'        => $last_name,
				'email'            => $email,
			] );
			if ( ! $id ) {
				wp_safe_redirect( $redirect_base . '&ck_msg=walkin_error' );
				exit;
			}
			$attendee = Attendees::find_by_id( $id );
			$ck_msg   = 'walkin_added';
		} else {
			$ck_msg = 'checked_in' === $attendee['status'] ? 'walkin_already' : 'walkin_checked_in';
		}

		// Ensure the AC contact exists (and is tagged registered) before Checkin::process applies the
		// check-in tag — walk-ins never touched Kadence, so there's no guarantee AC already knows them.
		self::sync_walkin_to_ac( $attendee, $mapping );

		if ( 'checked_in' !== $attendee['status'] ) {
			Checkin::process( $attendee['qr_token'], 'in' );
		}

		wp_safe_redirect( $redirect_base . '&ck_msg=' . $ck_msg );
		exit;
	}

	/** Finds-or-creates the AC contact for a walk-in and applies the event's registration tag. */
	private static function sync_walkin_to_ac( array $attendee, array $mapping ): void {
		try {
			$ac = new ActiveCampaign();
			if ( ! $ac->is_configured() ) {
				return;
			}
			if ( empty( $mapping['ac_registration_tag'] ) && empty( $mapping['ac_checkin_tag'] ) ) {
				return;
			}
			$contact_id = $ac->find_or_create_contact( $attendee['email'], $attendee['first_name'], $attendee['last_name'] );
			if ( ! $contact_id ) {
				return;
			}
			if ( ! empty( $mapping['ac_registration_tag'] ) ) {
				$ac->add_tag( $contact_id, $mapping['ac_registration_tag'] );
			}
		} catch ( \Throwable $e ) {
			// AC failure should not block the walk-in from being registered/checked in.
		}
	}

	public static function handle_save_settings(): void {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );
		if ( ! wp_verify_nonce( sanitize_key( $_POST['_wpnonce'] ?? '' ), 'checkee_save_settings' ) ) wp_die( 'Security check failed' );

		update_option( 'checkee_ac_url', sanitize_text_field( $_POST['checkee_ac_url'] ?? '' ) );
		update_option( 'checkee_ac_key', sanitize_text_field( $_POST['checkee_ac_key'] ?? '' ) );

		wp_safe_redirect( admin_url( 'admin.php?page=checkee-settings&tab=integrations&ck_msg=saved' ) );
		exit;
	}

	public static function handle_save_email(): void {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );
		if ( ! wp_verify_nonce( sanitize_key( $_POST['_wpnonce'] ?? '' ), 'checkee_save_email' ) ) wp_die( 'Security check failed' );

		Email::save_subject( sanitize_text_field( $_POST['email_subject'] ?? '' ) );
		Email::save_template( $_POST['email_template'] ?? '' );
		update_option( 'checkee_email_from_name',  sanitize_text_field( $_POST['from_name']  ?? '' ) );
		update_option( 'checkee_email_from_email', sanitize_email( $_POST['from_email'] ?? '' ) );

		wp_safe_redirect( admin_url( 'admin.php?page=checkee-settings&tab=email&ck_msg=saved' ) );
		exit;
	}

	public static function ajax_test_ac(): void {
		try {
			if ( ! wp_verify_nonce( sanitize_key( $_POST['_wpnonce'] ?? '' ), 'checkee_test_ac' ) ) {
				wp_send_json_error( [ 'message' => 'Security check failed. Try refreshing the page.' ] );
				return;
			}
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( [ 'message' => 'Unauthorized.' ] );
				return;
			}
			$ac     = new ActiveCampaign();
			$result = $ac->test_connection();
			if ( $result['connected'] ) {
				wp_send_json_success( [ 'message' => $result['message'] ] );
			} else {
				wp_send_json_error( [ 'message' => $result['message'] ] );
			}
		} catch ( \Throwable $e ) {
			wp_send_json_error( [ 'message' => 'PHP error: ' . $e->getMessage() ] );
		}
	}

	public static function ajax_resend_qr_batch(): void {
		try {
			if ( ! wp_verify_nonce( sanitize_key( $_POST['_wpnonce'] ?? '' ), 'checkee_resend_qr_batch' ) ) {
				wp_send_json_error( [ 'message' => 'Security check failed. Refresh the page and try again.' ] );
				return;
			}
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( [ 'message' => 'Unauthorized.' ] );
				return;
			}

			$mapping_id = (int) ( $_POST['mapping_id'] ?? 0 );
			$offset     = max( 0, (int) ( $_POST['offset'] ?? 0 ) );
			$batch_size = 10;

			$mapping = Mappings::find_by_id( $mapping_id );
			if ( ! $mapping ) {
				wp_send_json_error( [ 'message' => 'Event not found.' ] );
				return;
			}

			$total     = Attendees::status_counts( $mapping_id )['total'];
			$attendees = Attendees::get_for_mapping( $mapping_id, $batch_size, $offset );

			$sent = 0;
			foreach ( $attendees as $a ) {
				if ( Email::send_confirmation( $a, $mapping ) ) {
					$sent++;
				}
			}

			$next_offset = $offset + count( $attendees );

			wp_send_json_success( [
				'sent'        => $sent,
				'next_offset' => $next_offset,
				'total'       => $total,
				'done'        => $next_offset >= $total || 0 === count( $attendees ),
			] );
		} catch ( \Throwable $e ) {
			wp_send_json_error( [ 'message' => 'Error: ' . $e->getMessage() ] );
		}
	}

	public static function ajax_sync_ac_attendance(): void {
		try {
			if ( ! wp_verify_nonce( sanitize_key( $_POST['_wpnonce'] ?? '' ), 'checkee_sync_ac_attendance' ) ) {
				wp_send_json_error( [ 'message' => 'Security check failed. Refresh the page and try again.' ] );
				return;
			}
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( [ 'message' => 'Unauthorized.' ] );
				return;
			}

			$mapping_id = (int) ( $_POST['mapping_id'] ?? 0 );
			$mapping    = Mappings::find_by_id( $mapping_id );
			if ( ! $mapping ) {
				wp_send_json_error( [ 'message' => 'Event not found.' ] );
				return;
			}
			if ( empty( $mapping['ac_checkin_tag'] ) ) {
				wp_send_json_error( [ 'message' => 'This event has no Check-In Tag configured. Set one under Edit Event first.' ] );
				return;
			}

			$ac = new ActiveCampaign();
			if ( ! $ac->is_configured() ) {
				wp_send_json_error( [ 'message' => 'ActiveCampaign is not connected. Configure it under Settings → Integrations.' ] );
				return;
			}

			$emails = $ac->get_emails_by_tag( $mapping['ac_checkin_tag'] );
			if ( null === $emails ) {
				wp_send_json_error( [ 'message' => 'Could not reach ActiveCampaign. Try again in a moment.' ] );
				return;
			}

			$result = Attendees::sync_checkin_status( $mapping_id, $emails );

			wp_send_json_success( [
				'message' => sprintf(
					'Synced: %d checked in, %d un-checked-in, %d already matched.',
					$result['promoted'],
					$result['demoted'],
					$result['unchanged']
				),
			] );
		} catch ( \Throwable $e ) {
			wp_send_json_error( [ 'message' => 'Error: ' . $e->getMessage() ] );
		}
	}

	public static function ajax_scan_checkin(): void {
		try {
			if ( ! wp_verify_nonce( sanitize_key( $_POST['_wpnonce'] ?? '' ), 'checkee_scan_checkin' ) ) {
				wp_send_json_error( [ 'message' => 'Security check failed. Refresh the page and try again.' ] );
				return;
			}
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( [ 'message' => 'Unauthorized.' ] );
				return;
			}
			$token = sanitize_text_field( $_POST['token'] ?? '' );
			if ( ! $token ) {
				wp_send_json_error( [ 'message' => 'No token in QR code.' ] );
				return;
			}
			$result = Checkin::process( $token, 'in' );
			if ( $result['success'] ) {
				wp_send_json_success( $result );
			} else {
				wp_send_json_error( $result );
			}
		} catch ( \Throwable $e ) {
			wp_send_json_error( [ 'message' => 'Error: ' . $e->getMessage() ] );
		}
	}

	public static function ajax_get_form_fields(): void {
		try {
			if ( ! wp_verify_nonce( sanitize_key( $_POST['_wpnonce'] ?? '' ), 'checkee_get_form_fields' ) ) {
				wp_send_json_error( [ 'message' => 'Security check failed.' ] );
				return;
			}
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( [ 'message' => 'Unauthorized.' ] );
				return;
			}
			$form_id = (int) ( $_POST['form_id'] ?? 0 );
			$fields  = $form_id ? Forms::get_form_fields( $form_id ) : [];
			wp_send_json_success( [ 'fields' => $fields ] );
		} catch ( \Throwable $e ) {
			wp_send_json_error( [ 'message' => 'PHP error: ' . $e->getMessage() ] );
		}
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	private static function render_notice(): void {
		$msg = sanitize_key( $_GET['ck_msg'] ?? '' );
		$map = [
			'saved'            => [ 'success', 'Changes saved.' ],
			'created'          => [ 'success', 'Event created.' ],
			'deleted'          => [ 'info',    'Event deleted.' ],
			'attendee_removed' => [ 'success', 'Registration removed.' ],
			'bulk_done'        => [ 'success', 'Bulk action completed.' ],
			'walkin_added'     => [ 'success', 'Walk-in registered and checked in.' ],
			'walkin_checked_in' => [ 'success', 'Existing registration found — checked in.' ],
			'walkin_already'   => [ 'info',    'Already registered and checked in.' ],
			'walkin_invalid'   => [ 'error',   'Enter a valid email address.' ],
			'walkin_error'     => [ 'error',   'Could not add walk-in. Try again.' ],
		];
		if ( ! isset( $map[ $msg ] ) ) return;
		[ $type, $text ] = $map[ $msg ];
		$icon = 'error' === $type ? 'bi-x-circle-fill' : ( 'info' === $type ? 'bi-info-circle-fill' : 'bi-check-circle-fill' );
		echo '<div class="ck-notice ck-notice--' . esc_attr( $type ) . '"><i class="bi ' . esc_attr( $icon ) . '"></i> ' . esc_html( $text ) . '</div>';
	}
}
