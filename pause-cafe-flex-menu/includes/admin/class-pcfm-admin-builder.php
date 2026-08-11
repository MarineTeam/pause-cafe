<?php
/**
 * The builder. One screen, three renderings.
 *
 * Which one you get depends on the selected schedule's mode: a month grid for
 * planned schedules, a single row for on-publish, and from/until pickers for
 * manual. The save handler branches the same way, so adding a mode later means
 * one more branch here rather than a new screen.
 */

defined( 'ABSPATH' ) || exit;

class PCFM_Admin_Builder {

	public static function init() {
		add_action( 'admin_post_pcfm_save_menu', array( __CLASS__, 'handle_save' ) );
		add_action( 'wp_ajax_pcfm_search_dishes', array( __CLASS__, 'ajax_search_dishes' ) );
	}

	/**
	 * Autocomplete over dishes that already exist, so a repeat brings its photo,
	 * description and price across instead of being rebuilt from scratch.
	 */
	public static function ajax_search_dishes() {
		check_ajax_referer( 'pcfm_search_dishes', 'nonce' );

		if ( ! current_user_can( PCFM_Admin::CAPABILITY ) ) {
			wp_send_json_error( array(), 403 );
		}

		$term = isset( $_GET['term'] ) ? sanitize_text_field( wp_unslash( $_GET['term'] ) ) : '';

		if ( strlen( $term ) < 2 ) {
			wp_send_json( array() );
		}

		$query = new WP_Query(
			array(
				'post_type'              => 'product',
				'post_status'            => array( 'publish', 'draft', 'private' ),
				'posts_per_page'         => 15,
				's'                      => $term,
				'orderby'                => 'title',
				'order'                  => 'ASC',
				'no_found_rows'          => true,
				'pcfm_bypass_visibility' => true,
			)
		);

		$seen    = array();
		$results = array();

		foreach ( $query->posts as $post_object ) {
			$key = strtolower( $post_object->post_title );

			// The catalog repeats dishes across weeks; offer each name once.
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}

			$seen[ $key ] = true;

			$results[] = array(
				'label' => $post_object->post_title,
				'value' => $post_object->post_title,
				'id'    => (int) $post_object->ID,
			);
		}

		wp_send_json( $results );
	}

	public static function render() {
		$schedule_id = PCFM_Admin::current_schedule_id();

		echo '<div class="wrap pcfm-wrap">';
		echo '<h1>' . esc_html__( 'Build menu', 'pause-cafe-flex-menu' ) . '</h1>';

		PCFM_Admin::print_notices();
		PCFM_Admin::warn_if_unconfigured();

		if ( ! $schedule_id || ! PCFM_Settings::locations() ) {
			echo '</div>';

			return;
		}

		$rules     = PCFM_Schedules::rules( $schedule_id );
		$locations = PCFM_Schedules::locations( $schedule_id );

		PCFM_Admin::render_schedule_picker( PCFM_Admin::PAGE_BUILDER, $schedule_id );

		if ( ! $locations ) {
			printf(
				'<div class="notice notice-warning"><p>%s</p></div></div>',
				esc_html__( 'This schedule has no pickup locations assigned.', 'pause-cafe-flex-menu' )
			);

			return;
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'pcfm_save_menu' );
		echo '<input type="hidden" name="action" value="pcfm_save_menu">';
		printf( '<input type="hidden" name="schedule" value="%d">', (int) $schedule_id );
		printf( '<input type="hidden" name="mode" value="%s">', esc_attr( $rules['mode'] ) );

		switch ( $rules['mode'] ) {
			case PCFM_Schedules::MODE_ON_PUBLISH:
				self::render_on_publish( $schedule_id, $rules, $locations );
				break;

			case PCFM_Schedules::MODE_MANUAL:
				self::render_manual( $schedule_id, $locations );
				break;

			default:
				self::render_planned( $schedule_id, $rules, $locations );
		}

		echo '</form></div>';
	}

	/* --------------------------------------------------------------------- *
	 * Planned: a month grid.
	 * --------------------------------------------------------------------- */

	private static function render_planned( $schedule_id, array $rules, array $locations ) {
		list( $year, $month ) = self::current_month();

		$cursor = DateTimeImmutable::createFromFormat(
			'Y-n-j H:i:s',
			$year . '-' . $month . '-1 00:00:00',
			PCFM_Window::timezone()
		);

		if ( ! $cursor ) {
			echo '<p>' . esc_html__( 'That month could not be read.', 'pause-cafe-flex-menu' ) . '</p>';

			return;
		}

		$dates    = self::service_dates_in_month( $year, $month, (int) $rules['service_weekday'] );
		$previous = $cursor->modify( '-1 month' );
		$next     = $cursor->modify( '+1 month' );

		printf(
			'<h2 class="pcfm-month-nav"><a class="button" href="%s">&laquo; %s</a> <span>%s</span> <a class="button" href="%s">%s &raquo;</a></h2>',
			esc_url( self::month_url( $schedule_id, (int) $previous->format( 'Y' ), (int) $previous->format( 'n' ) ) ),
			esc_html( $previous->format( 'M Y' ) ),
			esc_html( $cursor->format( 'F Y' ) ),
			esc_url( self::month_url( $schedule_id, (int) $next->format( 'Y' ), (int) $next->format( 'n' ) ) ),
			esc_html( $next->format( 'M Y' ) )
		);

		printf( '<input type="hidden" name="month" value="%s">', esc_attr( sprintf( '%04d-%02d', $year, $month ) ) );

		if ( ! $dates ) {
			echo '<p>' . esc_html__( 'There are no service days in this month.', 'pause-cafe-flex-menu' ) . '</p>';

			return;
		}

		echo '<table class="widefat striped pcfm-builder"><thead><tr>';
		echo '<th class="pcfm-builder__date">' . esc_html__( 'Service date', 'pause-cafe-flex-menu' ) . '</th>';

		foreach ( $locations as $location ) {
			printf( '<th>%s</th>', esc_html( $location['label'] ) );
		}

		echo '</tr></thead><tbody>';

		foreach ( $dates as $service_date ) {
			self::render_planned_row( $schedule_id, $service_date, $locations );
		}

		echo '</tbody></table>';

		printf(
			'<p class="description">%s</p>',
			esc_html__( 'Clearing a dish name moves that product to draft rather than deleting it, so past orders keep their history. Weeks already served are read-only. Leave portions blank for unlimited.', 'pause-cafe-flex-menu' )
		);

		submit_button( __( 'Save month', 'pause-cafe-flex-menu' ) );
	}

	private static function render_planned_row( $schedule_id, $service_date, array $locations ) {
		$blacked = PCFM_Blackouts::is_blackout( $service_date );
		$ids     = PCFM_Product::ids_for_service_date( $service_date, $schedule_id );
		$state   = $ids ? PCFM_Window::for_product( $ids[0] )->state() : PCFM_Window::NONE;
		$is_past = PCFM_Window::PAST === $state;

		printf( '<tr class="pcfm-row pcfm-row--%s">', esc_attr( $blacked ? 'blackout' : $state ) );

		printf(
			'<td class="pcfm-builder__date"><strong>%s</strong><br>%s</td>',
			esc_html( PCFM_Window::format_date( $service_date, 'j M' ) ),
			$blacked
				? '<span class="pcfm-state pcfm-state--blackout">' . esc_html( PCFM_Blackouts::label( $service_date ) ) . '</span>'
				: '<span class="pcfm-state pcfm-state--' . esc_attr( $state ) . '">' . esc_html( self::state_label( $state ) ) . '</span>'
		);

		foreach ( $locations as $location ) {
			$product_id = PCFM_Product::id_for_slot( $service_date, $schedule_id, $location['term_id'] );

			echo '<td>';

			if ( $blacked || $is_past ) {
				$title = $product_id ? get_the_title( $product_id ) : '';
				echo $title ? esc_html( $title ) : '<span aria-hidden="true">&mdash;</span>';
			} else {
				self::render_dish_inputs(
					sprintf( 'dish[%s][%d]', $service_date, $location['term_id'] ),
					sprintf( 'source[%s][%d]', $service_date, $location['term_id'] ),
					sprintf( 'portions[%s][%d]', $service_date, $location['term_id'] ),
					$product_id
				);
			}

			echo '</td>';
		}

		echo '</tr>';
	}

	/* --------------------------------------------------------------------- *
	 * On publish: a single row, published now.
	 * --------------------------------------------------------------------- */

	private static function render_on_publish( $schedule_id, array $rules, array $locations ) {
		$next_cutoff = PCFM_Window::next_weekday_at( PCFM_Window::now(), $rules['close_weekday'], $rules['close_time'] );
		$current     = PCFM_Product::current_service_date( $schedule_id );
		$open        = false;

		if ( $current ) {
			$ids  = PCFM_Product::ids_for_service_date( $current, $schedule_id );
			$open = $ids && PCFM_Window::for_product( $ids[0] )->is_orderable();
		}

		printf(
			'<div class="notice notice-%s inline pcfm-status"><p><strong>%s</strong> %s</p></div>',
			$open ? 'success' : 'warning',
			esc_html( $open ? __( 'Ordering is open.', 'pause-cafe-flex-menu' ) : __( 'Ordering is closed.', 'pause-cafe-flex-menu' ) ),
			esc_html(
				$open
					? __( 'Saving again updates the live menu.', 'pause-cafe-flex-menu' )
					: __( 'Publishing below reopens it straight away.', 'pause-cafe-flex-menu' )
			)
		);

		echo '<table class="widefat striped pcfm-publish"><thead><tr>';
		echo '<th class="pcfm-publish__location">' . esc_html__( 'Pickup location', 'pause-cafe-flex-menu' ) . '</th>';
		echo '<th>' . esc_html__( 'Dish', 'pause-cafe-flex-menu' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $locations as $location ) {
			$product_id = PCFM_Product::open_slot_id( $schedule_id, $location['term_id'] );

			printf( '<tr><td class="pcfm-publish__location"><strong>%s</strong></td><td>', esc_html( $location['label'] ) );

			self::render_dish_inputs(
				sprintf( 'dish[%d]', $location['term_id'] ),
				sprintf( 'source[%d]', $location['term_id'] ),
				sprintf( 'portions[%d]', $location['term_id'] ),
				$product_id
			);

			echo '</td></tr>';
		}

		echo '</tbody></table>';

		printf(
			'<p class="description">%s</p>',
			esc_html(
				sprintf(
					/* translators: %s: date and time ordering will close. */
					__( 'Publishing opens ordering immediately and closes it %s. Leaving a dish blank skips that location.', 'pause-cafe-flex-menu' ),
					( new PCFM_Window() )->format_moment( $next_cutoff )
				)
			)
		);

		submit_button( __( 'Publish menu and open ordering', 'pause-cafe-flex-menu' ) );
	}

	/* --------------------------------------------------------------------- *
	 * Manual: explicit from and until per dish.
	 * --------------------------------------------------------------------- */

	private static function render_manual( $schedule_id, array $locations ) {
		$current = PCFM_Product::current_service_date( $schedule_id );

		echo '<table class="widefat striped pcfm-manual"><thead><tr>';
		echo '<th class="pcfm-publish__location">' . esc_html__( 'Pickup location', 'pause-cafe-flex-menu' ) . '</th>';
		echo '<th>' . esc_html__( 'Dish', 'pause-cafe-flex-menu' ) . '</th>';
		echo '<th>' . esc_html__( 'Orderable from', 'pause-cafe-flex-menu' ) . '</th>';
		echo '<th>' . esc_html__( 'Until', 'pause-cafe-flex-menu' ) . '</th>';
		echo '<th>' . esc_html__( 'Served', 'pause-cafe-flex-menu' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $locations as $location ) {
			$product_id = $current
				? PCFM_Product::id_for_slot( $current, $schedule_id, $location['term_id'] )
				: 0;

			$from    = $product_id ? get_post_meta( $product_id, PCFM_Window::META_OPEN_FROM, true ) : '';
			$until   = $product_id ? get_post_meta( $product_id, PCFM_Window::META_CLOSE_AT, true ) : '';
			$service = $product_id ? get_post_meta( $product_id, PCFM_Window::META_SERVICE_DATE, true ) : '';

			printf( '<tr><td class="pcfm-publish__location"><strong>%s</strong></td><td>', esc_html( $location['label'] ) );

			self::render_dish_inputs(
				sprintf( 'dish[%d]', $location['term_id'] ),
				sprintf( 'source[%d]', $location['term_id'] ),
				sprintf( 'portions[%d]', $location['term_id'] ),
				$product_id
			);

			printf(
				'</td><td><input type="datetime-local" name="from[%1$d]" value="%2$s"></td>
				<td><input type="datetime-local" name="until[%1$d]" value="%3$s"></td>
				<td><input type="date" name="service[%1$d]" value="%4$s"></td></tr>',
				(int) $location['term_id'],
				esc_attr( self::to_input_datetime( $from ) ),
				esc_attr( self::to_input_datetime( $until ) ),
				esc_attr( $service )
			);
		}

		echo '</tbody></table>';

		printf(
			'<p class="description">%s</p>',
			esc_html__( 'Both from and until are required for a dish to be orderable. Leaving the served date blank derives it from the closing time.', 'pause-cafe-flex-menu' )
		);

		submit_button( __( 'Save menu', 'pause-cafe-flex-menu' ) );
	}

	/* --------------------------------------------------------------------- *
	 * Shared bits.
	 * --------------------------------------------------------------------- */

	private static function render_dish_inputs( $dish_name, $source_name, $portions_name, $product_id ) {
		$title    = $product_id ? get_the_title( $product_id ) : '';
		$capacity = $product_id ? PCFM_Product::capacity( $product_id ) : null;

		printf(
			'<input type="text" class="pcfm-dish-input" name="%s" value="%s" placeholder="%s" autocomplete="off">
			<input type="hidden" class="pcfm-dish-source" name="%s" value="">',
			esc_attr( $dish_name ),
			esc_attr( $title ),
			esc_attr__( 'Start typing…', 'pause-cafe-flex-menu' ),
			esc_attr( $source_name )
		);

		printf(
			'<label class="pcfm-portions">%s <input type="number" min="0" name="%s" value="%s" placeholder="%s"></label>',
			esc_html__( 'Portions', 'pause-cafe-flex-menu' ),
			esc_attr( $portions_name ),
			esc_attr( $capacity ? $capacity['limit'] : '' ),
			esc_attr__( '∞', 'pause-cafe-flex-menu' )
		);

		if ( $product_id ) {
			printf(
				'<a class="pcfm-edit-link" href="%s">%s</a>',
				esc_url( get_edit_post_link( $product_id ) ),
				esc_html__( 'Edit product', 'pause-cafe-flex-menu' )
			);

			if ( $capacity ) {
				printf(
					'<span class="pcfm-sold">%s</span>',
					esc_html(
						sprintf(
							/* translators: 1: sold, 2: limit. */
							__( '%1$d of %2$d sold', 'pause-cafe-flex-menu' ),
							$capacity['sold'],
							$capacity['limit']
						)
					)
				);
			}
		}
	}

	private static function state_label( $state ) {
		$labels = array(
			PCFM_Window::OPEN     => __( 'Ordering open', 'pause-cafe-flex-menu' ),
			PCFM_Window::UPCOMING => __( 'Upcoming', 'pause-cafe-flex-menu' ),
			PCFM_Window::CLOSED   => __( 'Closed', 'pause-cafe-flex-menu' ),
			PCFM_Window::PAST     => __( 'Served', 'pause-cafe-flex-menu' ),
			PCFM_Window::NONE     => __( 'Empty', 'pause-cafe-flex-menu' ),
		);

		return isset( $labels[ $state ] ) ? $labels[ $state ] : $state;
	}

	private static function to_input_datetime( $stored ) {
		$parsed = PCFM_Window::parse_datetime( $stored );

		return $parsed ? $parsed->format( 'Y-m-d\TH:i' ) : '';
	}

	private static function current_month() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation.
		$raw = isset( $_GET['month'] ) ? sanitize_text_field( wp_unslash( $_GET['month'] ) ) : '';

		if ( preg_match( '/^(\d{4})-(\d{2})$/', $raw, $matches ) ) {
			return array( (int) $matches[1], (int) $matches[2] );
		}

		$now = PCFM_Window::now();

		return array( (int) $now->format( 'Y' ), (int) $now->format( 'n' ) );
	}

	private static function month_url( $schedule_id, $year, $month ) {
		return add_query_arg(
			array(
				'page'     => PCFM_Admin::PAGE_BUILDER,
				'schedule' => (int) $schedule_id,
				'month'    => sprintf( '%04d-%02d', $year, $month ),
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * @return string[]
	 */
	public static function service_dates_in_month( $year, $month, $weekday ) {
		$dates  = array();
		$cursor = DateTimeImmutable::createFromFormat(
			'Y-n-j H:i:s',
			$year . '-' . $month . '-1 00:00:00',
			PCFM_Window::timezone()
		);

		if ( ! $cursor ) {
			return $dates;
		}

		$days = (int) $cursor->format( 't' );

		for ( $day = 1; $day <= $days; $day++ ) {
			$candidate = $cursor->setDate( (int) $year, (int) $month, $day );

			if ( (int) $candidate->format( 'w' ) === (int) $weekday ) {
				$dates[] = $candidate->format( 'Y-m-d' );
			}
		}

		return $dates;
	}

	/* --------------------------------------------------------------------- *
	 * Saving.
	 * --------------------------------------------------------------------- */

	public static function handle_save() {
		if ( ! current_user_can( PCFM_Admin::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to edit the menu.', 'pause-cafe-flex-menu' ) );
		}

		check_admin_referer( 'pcfm_save_menu' );

		$schedule_id = isset( $_POST['schedule'] ) ? absint( $_POST['schedule'] ) : 0;

		if ( ! PCFM_Schedules::exists( $schedule_id ) ) {
			wp_die( esc_html__( 'Unknown schedule.', 'pause-cafe-flex-menu' ) );
		}

		$rules  = PCFM_Schedules::rules( $schedule_id );
		$counts = array(
			'created' => 0,
			'updated' => 0,
			'drafted' => 0,
		);

		if ( PCFM_Schedules::MODE_PLANNED === $rules['mode'] ) {
			self::save_planned( $schedule_id, $counts );
		} else {
			self::save_single_row( $schedule_id, $rules, $counts );
		}

		do_action( 'pcfm_menu_saved' );
		PCFM_Product::resync_schedule( $schedule_id );

		PCFM_Admin::add_notice(
			sprintf(
				/* translators: 1: created, 2: updated, 3: drafted. */
				__( 'Menu saved. %1$d added, %2$d updated, %3$d moved to draft.', 'pause-cafe-flex-menu' ),
				$counts['created'],
				$counts['updated'],
				$counts['drafted']
			)
		);

		$redirect = array(
			'page'     => PCFM_Admin::PAGE_BUILDER,
			'schedule' => $schedule_id,
		);

		if ( isset( $_POST['month'] ) ) {
			$redirect['month'] = sanitize_text_field( wp_unslash( $_POST['month'] ) );
		}

		wp_safe_redirect( add_query_arg( $redirect, admin_url( 'admin.php' ) ) );
		exit;
	}

	private static function save_planned( $schedule_id, array &$counts ) {
		$dishes   = isset( $_POST['dish'] ) ? (array) wp_unslash( $_POST['dish'] ) : array();
		$sources  = isset( $_POST['source'] ) ? (array) wp_unslash( $_POST['source'] ) : array();
		$portions = isset( $_POST['portions'] ) ? (array) wp_unslash( $_POST['portions'] ) : array();

		foreach ( $dishes as $service_date => $slots ) {
			$service_date = sanitize_text_field( $service_date );

			if ( ! PCFM_Window::parse_date( $service_date ) || PCFM_Blackouts::is_blackout( $service_date ) ) {
				continue;
			}

			foreach ( (array) $slots as $term_id => $title ) {
				$term_id = (int) $term_id;

				if ( ! $term_id ) {
					continue;
				}

				$existing = PCFM_Product::id_for_slot( $service_date, $schedule_id, $term_id );

				// Never rewrite a week that has already been served.
				if ( $existing && PCFM_Window::PAST === PCFM_Window::for_product( $existing )->state() ) {
					continue;
				}

				self::apply_slot(
					$existing,
					$schedule_id,
					$term_id,
					sanitize_text_field( $title ),
					isset( $sources[ $service_date ][ $term_id ] ) ? absint( $sources[ $service_date ][ $term_id ] ) : 0,
					isset( $portions[ $service_date ][ $term_id ] ) ? $portions[ $service_date ][ $term_id ] : '',
					array( PCFM_Window::META_SERVICE_DATE => $service_date ),
					$counts,
					false
				);
			}
		}
	}

	private static function save_single_row( $schedule_id, array $rules, array &$counts ) {
		$dishes   = isset( $_POST['dish'] ) ? (array) wp_unslash( $_POST['dish'] ) : array();
		$sources  = isset( $_POST['source'] ) ? (array) wp_unslash( $_POST['source'] ) : array();
		$portions = isset( $_POST['portions'] ) ? (array) wp_unslash( $_POST['portions'] ) : array();
		$from     = isset( $_POST['from'] ) ? (array) wp_unslash( $_POST['from'] ) : array();
		$until    = isset( $_POST['until'] ) ? (array) wp_unslash( $_POST['until'] ) : array();
		$service  = isset( $_POST['service'] ) ? (array) wp_unslash( $_POST['service'] ) : array();

		$on_publish = PCFM_Schedules::MODE_ON_PUBLISH === $rules['mode'];

		/*
		 * Everything saved in one go shares a single opening moment, so all
		 * locations close together even if the save takes a few seconds.
		 */
		$now = PCFM_Window::now();

		foreach ( $dishes as $term_id => $title ) {
			$term_id = (int) $term_id;

			if ( ! $term_id ) {
				continue;
			}

			$existing = $on_publish
				? PCFM_Product::open_slot_id( $schedule_id, $term_id )
				: self::manual_slot_id( $schedule_id, $term_id );

			$meta = array();

			if ( $on_publish ) {
				$meta[ PCFM_Window::META_OPENED_AT ] = $now->format( 'Y-m-d H:i:s' );
			} else {
				$parsed_from  = PCFM_Window::parse_datetime( isset( $from[ $term_id ] ) ? $from[ $term_id ] : '' );
				$parsed_until = PCFM_Window::parse_datetime( isset( $until[ $term_id ] ) ? $until[ $term_id ] : '' );

				$meta[ PCFM_Window::META_OPEN_FROM ]    = $parsed_from ? $parsed_from->format( 'Y-m-d H:i:s' ) : '';
				$meta[ PCFM_Window::META_CLOSE_AT ]     = $parsed_until ? $parsed_until->format( 'Y-m-d H:i:s' ) : '';
				$meta[ PCFM_Window::META_SERVICE_DATE ] = isset( $service[ $term_id ] ) ? sanitize_text_field( $service[ $term_id ] ) : '';
			}

			self::apply_slot(
				$existing,
				$schedule_id,
				$term_id,
				sanitize_text_field( $title ),
				isset( $sources[ $term_id ] ) ? absint( $sources[ $term_id ] ) : 0,
				isset( $portions[ $term_id ] ) ? $portions[ $term_id ] : '',
				$meta,
				$counts,
				$on_publish
			);
		}
	}

	private static function manual_slot_id( $schedule_id, $term_id ) {
		$current = PCFM_Product::current_service_date( $schedule_id );

		return $current ? PCFM_Product::id_for_slot( $current, $schedule_id, $term_id ) : 0;
	}

	/**
	 * Creates, updates or drafts one slot, then writes its meta and capacity.
	 */
	private static function apply_slot( $existing, $schedule_id, $term_id, $title, $source_id, $portions, array $meta, array &$counts, $reopen ) {
		if ( '' === $title ) {
			if ( $existing && 'draft' !== get_post_status( $existing ) ) {
				wp_update_post(
					array(
						'ID'          => $existing,
						'post_status' => 'draft',
					)
				);

				++$counts['drafted'];
			}

			return;
		}

		if ( $existing ) {
			if ( get_the_title( $existing ) !== $title ) {
				wp_update_post(
					array(
						'ID'         => $existing,
						'post_title' => $title,
					)
				);
			}

			if ( 'publish' !== get_post_status( $existing ) ) {
				wp_update_post(
					array(
						'ID'          => $existing,
						'post_status' => 'publish',
					)
				);
			}

			$product_id = $existing;
			++$counts['updated'];
		} else {
			$product_id = self::create_dish( $schedule_id, $term_id, $title, $source_id );

			if ( ! $product_id ) {
				return;
			}

			++$counts['created'];
		}

		foreach ( $meta as $key => $value ) {
			if ( '' === $value ) {
				delete_post_meta( $product_id, $key );
			} else {
				update_post_meta( $product_id, $key, $value );
			}
		}

		if ( $reopen ) {
			PCFM_Product::open_now( $product_id, PCFM_Window::parse_datetime( $meta[ PCFM_Window::META_OPENED_AT ] ) );
		}

		if ( '' !== trim( (string) $portions ) ) {
			PCFM_Product::set_capacity( $product_id, absint( $portions ) );
		}

		PCFM_Window::flush();
		PCFM_Product::sync_resolved( $product_id );
	}

	private static function create_dish( $schedule_id, $term_id, $title, $source_id ) {
		$rules   = PCFM_Schedules::rules( $schedule_id );
		$product = new WC_Product_Simple();

		$product->set_name( $title );
		$product->set_catalog_visibility( 'visible' );
		$product->set_sold_individually( false );

		$source = $source_id ? wc_get_product( $source_id ) : null;

		if ( $source ) {
			$price = $source->get_regular_price();
			$product->set_description( $source->get_description() );
			$product->set_short_description( $source->get_short_description() );
			$product->set_image_id( $source->get_image_id() );
			$product->set_gallery_image_ids( $source->get_gallery_image_ids() );
		} else {
			$price = '';
		}

		if ( '' === $price ) {
			$price = '' !== $rules['default_price'] ? $rules['default_price'] : PCFM_Settings::get( 'default_price' );
		}

		$product->set_regular_price( $price );
		$product->set_category_ids( self::category_ids_for( $term_id, $source ) );

		// Created as a draft, then published, so the publish transition fires and
		// on-publish schedules get stamped by the normal path.
		$product->set_status( 'draft' );
		$product_id = $product->save();

		if ( ! $product_id ) {
			return 0;
		}

		PCFM_Schedules::assign( $product_id, $schedule_id );

		if ( absint( $rules['default_capacity'] ) > 0 ) {
			PCFM_Product::set_capacity( $product_id, absint( $rules['default_capacity'] ) );
		}

		wp_update_post(
			array(
				'ID'          => $product_id,
				'post_status' => 'publish',
			)
		);

		return (int) $product_id;
	}

	/**
	 * Keeps any grouping categories the source dish carried but swaps in the
	 * pickup category for this slot.
	 */
	private static function category_ids_for( $term_id, $source ) {
		$locations = PCFM_Settings::location_term_ids();
		$ids       = array();

		if ( $source ) {
			foreach ( $source->get_category_ids() as $category_id ) {
				if ( ! in_array( (int) $category_id, $locations, true ) ) {
					$ids[] = (int) $category_id;
				}
			}
		}

		$ids[] = (int) $term_id;

		return array_values( array_unique( $ids ) );
	}
}
