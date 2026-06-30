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
		$mappings     = Mappings::get_all();
		$is_connected = \Checkee\API::is_connected();
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
							<?php if ( $is_connected ) : ?>
							<th class="ck-th-center">Checkee</th>
							<?php endif; ?>
							<th class="ck-th-right">Actions</th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $mappings as $m ) : ?>
						<tr id="ck-row-<?php echo (int) $m['id']; ?>">
							<td>
								<a href="<?php echo esc_url( admin_url( 'admin.php?page=checkee&action=attendees&id=' . (int) $m['id'] ) ); ?>" class="ck-link-strong">
									<?php echo esc_html( $m['event_name'] ); ?>
								</a>
							</td>
							<td class="ck-text-muted"><?php echo esc_html( $m['form_title'] ?: '—' ); ?></td>
							<td class="ck-th-center">
								<span class="ck-count-badge"><?php echo (int) $m['attendee_count']; ?></span>
							</td>
							<td class="ck-th-center">
								<?php
								$active = $m['status'] === 'active';
								echo '<span class="ck-badge ' . ( $active ? 'ck-badge--green' : 'ck-badge--gray' ) . '">'
									. ( $active ? 'Active' : 'Inactive' ) . '</span>';
								?>
							</td>
							<?php if ( $is_connected ) : ?>
							<td class="ck-th-center" id="ck-sync-cell-<?php echo (int) $m['id']; ?>">
								<?php if ( ! empty( $m['checkee_event_id'] ) ) : ?>
									<span class="ck-badge ck-badge--green" title="Linked to Checkee event #<?php echo (int) $m['checkee_event_id']; ?>">
										<i class="bi bi-cloud-check-fill"></i> #<?php echo (int) $m['checkee_event_id']; ?>
									</span>
								<?php else : ?>
									<button type="button"
									        class="ck-btn ck-btn-sm ck-btn-outline js-push-event"
									        data-mapping-id="<?php echo (int) $m['id']; ?>"
									        data-nonce="<?php echo esc_attr( wp_create_nonce( 'checkee_push_event' ) ); ?>"
									        title="Create this event in Checkee and link it">
										<i class="bi bi-cloud-upload"></i> Push
									</button>
								<?php endif; ?>
							</td>
							<?php endif; ?>
							<td class="ck-th-right">
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

		<?php if ( $is_connected ) : ?>
		<script>
		(function(){
			document.querySelectorAll('.js-push-event').forEach(function(btn){
				btn.addEventListener('click', function(){
					var mappingId = this.dataset.mappingId;
					var nonce     = this.dataset.nonce;
					var cell      = document.getElementById('ck-sync-cell-' + mappingId);
					btn.disabled = true;
					btn.innerHTML = '<i class="bi bi-hourglass-split"></i> Pushing…';

					fetch(ajaxurl, {
						method: 'POST',
						headers: {'Content-Type': 'application/x-www-form-urlencoded'},
						body: new URLSearchParams({
							action:     'checkee_push_event',
							mapping_id: mappingId,
							_wpnonce:   nonce
						})
					})
					.then(r => r.json())
					.then(function(data){
						if (data.success) {
							cell.innerHTML = '<span class="ck-badge ck-badge--green"><i class="bi bi-cloud-check-fill"></i> #' + data.data.checkee_event_id + '</span>';
						} else {
							btn.disabled = false;
							btn.innerHTML = '<i class="bi bi-cloud-upload"></i> Push';
							alert('Push failed: ' + (data.data && data.data.message ? data.data.message : 'Unknown error'));
						}
					})
					.catch(function(){
						btn.disabled = false;
						btn.innerHTML = '<i class="bi bi-cloud-upload"></i> Push';
						alert('Request failed. Check your connection.');
					});
				});
			});
		})();
		</script>
		<?php endif; ?>
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
			'checkee_event_id'    => $mapping['checkee_event_id']    ?? '',
			'status'              => $mapping['status']              ?? 'active',
		];

		// In connected mode, fetch Checkee events for the dropdown.
		$checkee_events = \Checkee\API::is_connected() ? \Checkee\API::get_events() : [];

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
							<?php if ( ! empty( $checkee_events ) ) : ?>
							<div class="ck-field">
								<label for="checkee_event_id">Checkee Event <small style="font-weight:normal;color:#6b7280;">(connected)</small></label>
								<select id="checkee_event_id" name="checkee_event_id">
									<option value="">— Standalone (local only) —</option>
									<?php foreach ( $checkee_events as $ce ) : ?>
									<option value="<?php echo (int) ( $ce['id'] ?? 0 ); ?>"
									        <?php selected( (string) $v['checkee_event_id'], (string) ( $ce['id'] ?? '' ) ); ?>>
										<?php echo esc_html( $ce['name'] ?? ( 'Event #' . ( $ce['id'] ?? '?' ) ) ); ?>
										<?php if ( ! empty( $ce['event_date'] ) ) echo ' — ' . esc_html( $ce['event_date'] ); ?>
									</option>
									<?php endforeach; ?>
								</select>
								<p class="ck-field-note">Sync registrations to this Checkee event. Leave blank for standalone mode.</p>
							</div>
							<?php elseif ( \Checkee\API::is_connected() ) : ?>
							<div class="ck-field">
								<label for="checkee_event_id">Checkee Event ID <small style="font-weight:normal;color:#6b7280;">(connected)</small></label>
								<input type="number" id="checkee_event_id" name="checkee_event_id"
								       value="<?php echo esc_attr( $v['checkee_event_id'] ); ?>"
								       placeholder="e.g. 12">
								<p class="ck-field-note">Enter the Event ID from your Checkee dashboard. Leave blank for standalone mode.</p>
							</div>
							<?php endif; ?>
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

		$attendees = Attendees::get_for_mapping( $mapping_id );
		$total     = count( $attendees );
		$checked   = count( array_filter( $attendees, fn( $a ) => $a['status'] === 'checked_in' ) );
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
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=checkee&action=edit&id=' . $mapping_id ) ); ?>" class="ck-btn ck-btn-outline">
					<i class="bi bi-pencil"></i> Edit Event
				</a>
			</div>

			<!-- Stats row -->
			<div class="ck-stats-row">
				<div class="ck-stat">
					<div class="ck-stat__value"><?php echo $total; ?></div>
					<div class="ck-stat__label">Registered</div>
				</div>
				<div class="ck-stat">
					<div class="ck-stat__value ck-stat__value--green"><?php echo $checked; ?></div>
					<div class="ck-stat__label">Checked In</div>
				</div>
				<div class="ck-stat">
					<div class="ck-stat__value ck-stat__value--muted"><?php echo $total - $checked; ?></div>
					<div class="ck-stat__label">Not Checked In</div>
				</div>
			</div>

			<?php if ( empty( $attendees ) ) : ?>
			<div class="ck-empty-state">
				<div class="ck-empty-state__icon"><i class="bi bi-people"></i></div>
				<h3>No attendees yet</h3>
				<p>Attendees will appear here when people submit the linked Kadence form.</p>
			</div>
			<?php else : ?>

			<!-- Search -->
			<div class="ck-search-bar">
				<i class="bi bi-search"></i>
				<input type="search" id="ck-search" placeholder="Search by name or email…" autocomplete="off">
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
					<tbody>
					<?php foreach ( $attendees as $a ) :
						$status_map = [
							'checked_in'  => [ 'label' => 'Checked In',  'class' => 'ck-badge--green' ],
							'checked_out' => [ 'label' => 'Checked Out', 'class' => 'ck-badge--gray'  ],
							'registered'  => [ 'label' => 'Registered',  'class' => 'ck-badge--blue'  ],
						];
						$s = $status_map[ $a['status'] ] ?? $status_map['registered'];
					?>
					<tr data-search="<?php echo esc_attr( strtolower( $a['first_name'] . ' ' . $a['last_name'] . ' ' . $a['email'] ) ); ?>">
						<td><?php echo esc_html( $a['first_name'] ); ?></td>
						<td><?php echo esc_html( $a['last_name'] ); ?></td>
						<td class="ck-text-muted"><?php echo esc_html( $a['email'] ); ?></td>
						<td class="ck-th-center">
							<span class="ck-badge <?php echo esc_attr( $s['class'] ); ?>"><?php echo esc_html( $s['label'] ); ?></span>
						</td>
						<td class="ck-th-center ck-text-muted"><?php echo esc_html( wp_date( 'M j, Y', strtotime( $a['created_at'] ) ) ); ?></td>
						<td class="ck-th-right">
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

			<script>
			(function(){
				var input = document.getElementById('ck-search');
				if (!input) return;
				input.addEventListener('input', function(){
					var q = this.value.toLowerCase().trim();
					document.querySelectorAll('#ck-attendees-table tbody tr').forEach(function(row){
						row.style.display = (!q || row.dataset.search.includes(q)) ? '' : 'none';
					});
				});
			})();
			</script>
			<?php endif; ?>

		</div>
		<?php
	}

	// -------------------------------------------------------------------------
	// Settings page
	// -------------------------------------------------------------------------

	public static function render_settings(): void {
		$tab = sanitize_key( $_GET['tab'] ?? 'connection' );
		?>
		<div class="ck-wrap">
			<?php self::render_notice(); ?>
			<div class="ck-page-header">
				<div class="ck-page-header__left">
					<h1><i class="bi bi-sliders"></i> Settings</h1>
				</div>
			</div>

			<div class="ck-tabs">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=checkee-settings&tab=connection' ) ); ?>"
				   class="ck-tab <?php echo $tab === 'connection' ? 'ck-tab--active' : ''; ?>">
					<i class="bi bi-cloud-fill"></i> Checkee Connection
				</a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=checkee-settings&tab=email' ) ); ?>"
				   class="ck-tab <?php echo $tab === 'email' ? 'ck-tab--active' : ''; ?>">
					<i class="bi bi-envelope-fill"></i> Email
				</a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=checkee-settings&tab=integrations' ) ); ?>"
				   class="ck-tab <?php echo $tab === 'integrations' ? 'ck-tab--active' : ''; ?>">
					<i class="bi bi-plug-fill"></i> Integrations
				</a>
			</div>

			<?php if ( $tab === 'connection' ) : ?>
				<?php self::render_connection_settings(); ?>
			<?php elseif ( $tab === 'email' ) : ?>
				<?php self::render_email_settings(); ?>
			<?php else : ?>
				<?php self::render_integration_settings(); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	private static function render_connection_settings(): void {
		$is_connected = \Checkee\API::is_connected();
		$token        = \Checkee\API::get_token();
		$base_url     = \Checkee\API::get_base_url();
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'checkee_save_connection', '_wpnonce' ); ?>
			<input type="hidden" name="action" value="checkee_save_connection">

			<div class="ck-settings-grid">
				<div class="ck-settings-main">

					<?php if ( $is_connected ) : ?>
					<div class="ck-notice ck-notice--success" style="display:flex;align-items:center;gap:8px;padding:12px 16px;border-radius:8px;background:#ecfdf5;border:1px solid #6ee7b7;color:#065f46;font-size:13px;margin-bottom:20px;">
						<i class="bi bi-check-circle-fill"></i>
						<span>Connected to Checkee. Events created at <strong>checkee.up.railway.app</strong> will appear in the event dropdown when you link a form.</span>
					</div>
					<?php else : ?>
					<div class="ck-notice ck-notice--info" style="display:flex;align-items:center;gap:8px;padding:12px 16px;border-radius:8px;background:#eff6ff;border:1px solid #93c5fd;color:#1e3a5f;font-size:13px;margin-bottom:20px;">
						<i class="bi bi-info-circle-fill"></i>
						<span>Not connected. Enter your Checkee API token to sync registrations to <strong>checkee.up.railway.app</strong>. Without a token, the plugin works in standalone mode.</span>
					</div>
					<?php endif; ?>

					<div class="ck-card">
						<h2 class="ck-card__title"><i class="bi bi-key-fill"></i> API Token</h2>
						<p class="ck-card__desc">Generate a token in your Checkee account under <strong>Settings → API Tokens</strong>, then paste it here.</p>
						<div class="ck-field">
							<label for="checkee_api_token">Token</label>
							<input type="password"
							       id="checkee_api_token"
							       name="checkee_api_token"
							       value="<?php echo esc_attr( $token ); ?>"
							       placeholder="ck_live_xxxxxxxxxxxxxxxx"
							       autocomplete="new-password">
							<p class="ck-field-note">Stored securely. Never shared with the browser.</p>
						</div>
					</div>

					<div class="ck-card">
						<h2 class="ck-card__title"><i class="bi bi-globe"></i> API Base URL</h2>
						<p class="ck-card__desc">Leave as default unless you are running a self-hosted Checkee instance or a local dev environment.</p>
						<div class="ck-field">
							<label for="checkee_api_url">Base URL</label>
							<input type="url"
							       id="checkee_api_url"
							       name="checkee_api_url"
							       value="<?php echo esc_attr( $base_url ); ?>"
							       placeholder="https://checkee.up.railway.app">
						</div>
					</div>

				</div>

				<div class="ck-settings-side">
					<div class="ck-card">
						<button type="submit" class="ck-btn ck-btn-primary ck-btn-full">
							<i class="bi bi-check-lg"></i> Save Connection
						</button>
						<?php if ( $is_connected ) : ?>
						<button type="button" id="js-test-api" class="ck-btn ck-btn-secondary ck-btn-full" style="margin-top:8px;">
							<i class="bi bi-wifi"></i> Test Connection
						</button>
						<p id="js-test-api-result" style="display:none;font-size:12px;margin-top:8px;padding:8px 12px;border-radius:6px;"></p>
						<script>
						document.getElementById('js-test-api').addEventListener('click', function() {
							var btn = this;
							var result = document.getElementById('js-test-api-result');
							btn.disabled = true;
							btn.innerHTML = '<i class="bi bi-hourglass-split"></i> Testing…';
							fetch(ajaxurl, {
								method: 'POST',
								headers: {'Content-Type': 'application/x-www-form-urlencoded'},
								body: new URLSearchParams({
									action: 'checkee_test_api',
									_wpnonce: '<?php echo esc_js( wp_create_nonce( 'checkee_test_api' ) ); ?>'
								})
							})
							.then(r => r.json())
							.then(data => {
								result.style.display = 'block';
								if (data.success) {
									result.style.background = '#ecfdf5';
									result.style.color = '#065f46';
									result.style.border = '1px solid #6ee7b7';
								} else {
									result.style.background = '#fef2f2';
									result.style.color = '#991b1b';
									result.style.border = '1px solid #fca5a5';
								}
								result.textContent = data.data.message;
								btn.disabled = false;
								btn.innerHTML = '<i class="bi bi-wifi"></i> Test Connection';
							});
						});
						</script>
						<?php endif; ?>
					</div>

					<div class="ck-card">
						<h2 class="ck-card__title"><i class="bi bi-question-circle"></i> How it works</h2>
						<ol style="font-size:13px;color:#555;line-height:1.7;padding-left:16px;margin:0;">
							<li>Create an event at <a href="https://checkee.up.railway.app" target="_blank" rel="noopener">checkee.up.railway.app</a></li>
							<li>Copy the Event ID from the event page</li>
							<li>In the event form below, paste the ID into the <em>Checkee Event ID</em> field</li>
							<li>Form registrations flow to Checkee — check-in and dashboard work across all your WordPress sites</li>
						</ol>
					</div>
				</div>
			</div>
		</form>
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
						<p class="ck-card__desc">Connect your ActiveCampaign account. Checkee will add and remove tags from existing contacts on check-in and check-out. It never creates new contacts.</p>

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
							<li>Checkee <strong>never creates</strong> AC contacts</li>
							<li>It finds existing contacts by email address</li>
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
			'checkee_event_id'    => $_POST['checkee_event_id'] ?? '',
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
			'checkee_event_id'    => $_POST['checkee_event_id'] ?? '',
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

	public static function handle_save_connection(): void {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );
		if ( ! wp_verify_nonce( sanitize_key( $_POST['_wpnonce'] ?? '' ), 'checkee_save_connection' ) ) wp_die( 'Security check failed' );

		\Checkee\API::save(
			sanitize_text_field( $_POST['checkee_api_token'] ?? '' ),
			esc_url_raw( $_POST['checkee_api_url'] ?? '' )
		);

		wp_safe_redirect( admin_url( 'admin.php?page=checkee-settings&tab=connection&ck_msg=saved' ) );
		exit;
	}

	public static function ajax_test_api(): void {
		try {
			if ( ! wp_verify_nonce( sanitize_key( $_POST['_wpnonce'] ?? '' ), 'checkee_test_api' ) ) {
				wp_send_json_error( [ 'message' => 'Security check failed. Try refreshing the page.' ] );
				return;
			}
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( [ 'message' => 'Unauthorized.' ] );
				return;
			}
			$result = \Checkee\API::test_connection();
			if ( $result['connected'] ) {
				wp_send_json_success( [ 'message' => $result['message'] ] );
			} else {
				wp_send_json_error( [ 'message' => $result['message'] ] );
			}
		} catch ( \Throwable $e ) {
			wp_send_json_error( [ 'message' => 'Error: ' . $e->getMessage() ] );
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

	public static function ajax_push_event(): void {
		try {
			if ( ! wp_verify_nonce( sanitize_key( $_POST['_wpnonce'] ?? '' ), 'checkee_push_event' ) ) {
				wp_send_json_error( [ 'message' => 'Security check failed. Refresh and try again.' ] );
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

			$result = \Checkee\API::push_event( $mapping['event_name'] );
			if ( ! $result || empty( $result['id'] ) ) {
				wp_send_json_error( [ 'message' => 'Failed to create event in Checkee. Check your API token and try again.' ] );
				return;
			}

			Mappings::update( $mapping_id, [ 'checkee_event_id' => (int) $result['id'] ] );

			wp_send_json_success( [
				'message'          => 'Event synced to Checkee (ID ' . (int) $result['id'] . ').',
				'checkee_event_id' => (int) $result['id'],
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
		];
		if ( ! isset( $map[ $msg ] ) ) return;
		[ $type, $text ] = $map[ $msg ];
		echo '<div class="ck-notice ck-notice--' . esc_attr( $type ) . '"><i class="bi bi-check-circle-fill"></i> ' . esc_html( $text ) . '</div>';
	}
}
