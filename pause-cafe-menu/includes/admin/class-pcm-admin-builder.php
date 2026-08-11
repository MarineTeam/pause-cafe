<?php
/**
 * The month builder.
 *
 * One grid: service dates down, pickup locations across. Type dish names, save,
 * and the products behind them are created or updated with the right category,
 * service date and SKU. This is the screen that replaces the weekly routine of
 * creating three products and re-shuffling categories by hand.
 */

defined( 'ABSPATH' ) || exit;

class PCM_Admin_Builder {

	public static function init() {
		add_action( 'admin_post_pcm_save_menu', array( __CLASS__, 'handle_save' ) );
		add_action( 'wp_ajax_pcm_search_dishes', array( __CLASS__, 'ajax_search_dishes' ) );
	}

	/**
	 * Autocomplete over dishes that already exist, so a repeat brings its photo,
	 * description and price across instead of being rebuilt from scratch.
	 */
	public static function ajax_search_dishes() {
		check_ajax_referer( 'pcm_search_dishes', 'nonce' );

		if ( ! current_user_can( PCM_Admin::CAPABILITY ) ) {
			wp_send_json_error( array(), 403 );
		}

		$term = isset( $_GET['term'] ) ? sanitize_text_field( wp_unslash( $_GET['term'] ) ) : '';

		if ( strlen( $term ) < 2 ) {
			wp_send_json( array() );
		}

		$query = new WP_Query(
			array(
				'post_type'             => 'product',
				'post_status'           => array( 'publish', 'draft', 'private' ),
				'posts_per_page'        => 15,
				's'                     => $term,
				'orderby'               => 'title',
				'order'                 => 'ASC',
				'no_found_rows'         => true,
				'pcm_bypass_visibility' => true,
			)
		);

		$seen    = array();
		$results = array();

		foreach ( $query->posts as $post_object ) {
			$title = $post_object->post_title;
			$key   = strtolower( $title );

			// The catalog has repeated dishes across weeks; offer each name once.
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}

			$seen[ $key ] = true;
			$product      = wc_get_product( $post_object->ID );

			$results[] = array(
				'label' => $title,
				'value' => $title,
				'id'    => (int) $post_object->ID,
				'price' => $product ? wc_format_localized_price( $product->get_regular_price() ) : '',
			);
		}

		wp_send_json( $results );
	}

	private static function current_month() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation.
		$raw = isset( $_GET['month'] ) ? sanitize_text_field( wp_unslash( $_GET['month'] ) ) : '';

		if ( preg_match( '/^(\d{4})-(\d{2})$/', $raw, $matches ) ) {
			return array( (int) $matches[1], (int) $matches[2] );
		}

		$now = PCM_Schedule::now();

		return array( (int) $now->format( 'Y' ), (int) $now->format( 'n' ) );
	}

	private static function month_url( $year, $month ) {
		return add_query_arg(
			array(
				'page'  => PCM_Admin::PAGE_BUILDER,
				'month' => sprintf( '%04d-%02d', $year, $month ),
			),
			admin_url( 'admin.php' )
		);
	}

	public static function render() {
		list( $year, $month ) = self::current_month();

		$dates     = PCM_Schedule::service_dates_in_month( $year, $month );
		$locations = PCM_Settings::locations();

		$cursor = DateTimeImmutable::createFromFormat(
			'Y-n-j H:i:s',
			$year . '-' . $month . '-1 00:00:00',
			PCM_Schedule::timezone()
		);

		if ( ! $cursor ) {
			echo '<div class="wrap"><h1>' . esc_html__( 'Build menu', 'pause-cafe-menu' ) . '</h1><p>' .
				esc_html__( 'That month could not be read. Try again from the current month.', 'pause-cafe-menu' ) .
				'</p></div>';

			return;
		}

		$previous = $cursor->modify( '-1 month' );
		$next     = $cursor->modify( '+1 month' );

		echo '<div class="wrap pcm-wrap">';
		echo '<h1>' . esc_html__( 'Build menu', 'pause-cafe-menu' ) . '</h1>';

		PCM_Admin::print_notices();
		PCM_Admin::warn_if_unconfigured();

		printf(
			'<h2 class="pcm-month-nav"><a class="button" href="%s">&laquo; %s</a> <span>%s</span> <a class="button" href="%s">%s &raquo;</a></h2>',
			esc_url( self::month_url( (int) $previous->format( 'Y' ), (int) $previous->format( 'n' ) ) ),
			esc_html( $previous->format( 'M Y' ) ),
			esc_html( $cursor->format( 'F Y' ) ),
			esc_url( self::month_url( (int) $next->format( 'Y' ), (int) $next->format( 'n' ) ) ),
			esc_html( $next->format( 'M Y' ) )
		);

		if ( ! $locations ) {
			echo '</div>';

			return;
		}

		if ( ! $dates ) {
			echo '<p>' . esc_html__( 'There are no service days in this month.', 'pause-cafe-menu' ) . '</p></div>';

			return;
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'pcm_save_menu' );
		echo '<input type="hidden" name="action" value="pcm_save_menu">';
		printf( '<input type="hidden" name="month" value="%s">', esc_attr( sprintf( '%04d-%02d', $year, $month ) ) );

		echo '<table class="widefat striped pcm-builder"><thead><tr>';
		echo '<th class="pcm-builder__date">' . esc_html__( 'Service date', 'pause-cafe-menu' ) . '</th>';

		foreach ( $locations as $location ) {
			printf( '<th>%s</th>', esc_html( $location['label'] ) );
		}

		echo '</tr></thead><tbody>';

		foreach ( $dates as $service_date ) {
			self::render_row( $service_date, $locations );
		}

		echo '</tbody></table>';

		printf(
			'<p class="description">%s</p>',
			esc_html__( 'Clearing a dish name moves that product to draft rather than deleting it, so past orders keep their history. Weeks that have already been served are read-only.', 'pause-cafe-menu' )
		);

		submit_button( __( 'Save month', 'pause-cafe-menu' ) );
		echo '</form></div>';
	}

	private static function render_row( $service_date, array $locations ) {
		$state    = PCM_Schedule::state_for( $service_date );
		$is_past  = PCM_Schedule::PAST === $state;
		$messages = array(
			PCM_Schedule::OPEN     => __( 'Ordering open', 'pause-cafe-menu' ),
			PCM_Schedule::UPCOMING => __( 'Upcoming', 'pause-cafe-menu' ),
			PCM_Schedule::CLOSED   => __( 'Closed', 'pause-cafe-menu' ),
			PCM_Schedule::PAST     => __( 'Served', 'pause-cafe-menu' ),
		);

		printf( '<tr class="pcm-row pcm-row--%s">', esc_attr( $state ) );

		printf(
			'<td class="pcm-builder__date"><strong>%s</strong><br><span class="pcm-state pcm-state--%s">%s</span><br><span class="description">%s</span></td>',
			esc_html( PCM_Schedule::format_date( $service_date, 'j M' ) ),
			esc_attr( $state ),
			esc_html( isset( $messages[ $state ] ) ? $messages[ $state ] : $state ),
			esc_html( PCM_Schedule::state_message( $service_date ) )
		);

		foreach ( $locations as $location ) {
			$product_id = PCM_Product::id_for_slot( $service_date, $location['term_id'] );
			$title      = $product_id ? get_the_title( $product_id ) : '';

			echo '<td>';

			if ( $is_past ) {
				echo $title ? esc_html( $title ) : '<span aria-hidden="true">&mdash;</span>';
			} else {
				printf(
					'<input type="text" class="pcm-dish-input" name="dish[%1$s][%2$d]" value="%3$s" placeholder="%4$s" autocomplete="off">
					<input type="hidden" class="pcm-dish-source" name="source[%1$s][%2$d]" value="">',
					esc_attr( $service_date ),
					(int) $location['term_id'],
					esc_attr( $title ),
					esc_attr__( 'Start typing…', 'pause-cafe-menu' )
				);

				if ( $product_id ) {
					printf(
						'<a class="pcm-edit-link" href="%s">%s</a>',
						esc_url( get_edit_post_link( $product_id ) ),
						esc_html__( 'Edit product', 'pause-cafe-menu' )
					);
				}
			}

			echo '</td>';
		}

		echo '</tr>';
	}

	public static function handle_save() {
		if ( ! current_user_can( PCM_Admin::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to edit the menu.', 'pause-cafe-menu' ) );
		}

		check_admin_referer( 'pcm_save_menu' );

		$dishes  = isset( $_POST['dish'] ) ? (array) wp_unslash( $_POST['dish'] ) : array();
		$sources = isset( $_POST['source'] ) ? (array) wp_unslash( $_POST['source'] ) : array();
		$month   = isset( $_POST['month'] ) ? sanitize_text_field( wp_unslash( $_POST['month'] ) ) : '';

		$created = 0;
		$updated = 0;
		$drafted = 0;

		foreach ( $dishes as $service_date => $slots ) {
			$service_date = sanitize_text_field( $service_date );

			if ( ! PCM_Schedule::date_obj( $service_date ) ) {
				continue;
			}

			// Never rewrite a week that has already been served.
			if ( PCM_Schedule::PAST === PCM_Schedule::state_for( $service_date ) ) {
				continue;
			}

			foreach ( (array) $slots as $term_id => $title ) {
				$term_id = (int) $term_id;
				$title   = sanitize_text_field( $title );

				if ( ! $term_id ) {
					continue;
				}

				$source_id = isset( $sources[ $service_date ][ $term_id ] )
					? absint( $sources[ $service_date ][ $term_id ] )
					: 0;

				$result = self::save_slot( $service_date, $term_id, $title, $source_id );

				if ( 'created' === $result ) {
					++$created;
				} elseif ( 'updated' === $result ) {
					++$updated;
				} elseif ( 'drafted' === $result ) {
					++$drafted;
				}
			}
		}

		do_action( 'pcm_menu_saved' );

		PCM_Admin::add_notice(
			sprintf(
				/* translators: 1: dishes added, 2: dishes updated, 3: dishes moved to draft. */
				__( 'Menu saved. %1$d added, %2$d updated, %3$d moved to draft.', 'pause-cafe-menu' ),
				$created,
				$updated,
				$drafted
			)
		);

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'  => PCM_Admin::PAGE_BUILDER,
					'month' => $month,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * @return string One of created, updated, drafted, or an empty string for no change.
	 */
	private static function save_slot( $service_date, $term_id, $title, $source_id ) {
		$existing_id = PCM_Product::id_for_slot( $service_date, $term_id );

		if ( '' === $title ) {
			if ( $existing_id && 'draft' !== get_post_status( $existing_id ) ) {
				wp_update_post(
					array(
						'ID'          => $existing_id,
						'post_status' => 'draft',
					)
				);

				return 'drafted';
			}

			return '';
		}

		if ( $existing_id ) {
			$changed = false;

			if ( get_the_title( $existing_id ) !== $title ) {
				wp_update_post(
					array(
						'ID'         => $existing_id,
						'post_title' => $title,
					)
				);

				$changed = true;
			}

			if ( 'publish' !== get_post_status( $existing_id ) ) {
				wp_update_post(
					array(
						'ID'          => $existing_id,
						'post_status' => 'publish',
					)
				);

				$changed = true;
			}

			return $changed ? 'updated' : '';
		}

		return self::create_dish( $service_date, $term_id, $title, $source_id ) ? 'created' : '';
	}

	private static function create_dish( $service_date, $term_id, $title, $source_id ) {
		$product = new WC_Product_Simple();
		$product->set_name( $title );
		$product->set_status( 'publish' );
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
			$price = PCM_Settings::get( 'default_price' );
		}

		$price = '' !== $price ? $price : PCM_Settings::get( 'default_price' );

		$product->set_regular_price( $price );
		$product->set_category_ids( self::category_ids_for( $term_id, $source ) );

		$sku = self::build_sku( $service_date, $term_id );

		if ( $sku ) {
			$product->set_sku( $sku );
		}

		$product_id = $product->save();

		if ( ! $product_id ) {
			return false;
		}

		PCM_Product::set_service_date( $product_id, $service_date );

		return true;
	}

	/**
	 * Keeps any grouping categories the source dish carried (Sunday, Sunday Lunch
	 * Menu and so on) but swaps in the pickup category for this slot.
	 */
	private static function category_ids_for( $term_id, $source ) {
		$managed = PCM_Settings::managed_term_ids();
		$ids     = array();

		if ( $source ) {
			foreach ( $source->get_category_ids() as $category_id ) {
				if ( ! in_array( (int) $category_id, $managed, true ) ) {
					$ids[] = (int) $category_id;
				}
			}
		}

		$ids[] = (int) $term_id;

		return array_values( array_unique( $ids ) );
	}

	/**
	 * SKUs read like 260816-MAR, so the weekly export says which week and which
	 * campus a line belongs to without any cross-referencing.
	 */
	private static function build_sku( $service_date, $term_id ) {
		$date = PCM_Schedule::date_obj( $service_date );

		if ( ! $date ) {
			return '';
		}

		$term   = get_term( $term_id, 'product_cat' );
		$label  = ( $term && ! is_wp_error( $term ) ) ? $term->name : 'X';
		$suffix = strtoupper( substr( preg_replace( '/[^a-z]/i', '', $label ), 0, 3 ) );
		$suffix = $suffix ? $suffix : 'X';

		$base = $date->format( 'ymd' ) . '-' . $suffix;
		$sku  = $base;
		$n    = 2;

		while ( wc_get_product_id_by_sku( $sku ) ) {
			$sku = $base . '-' . $n;
			++$n;

			if ( $n > 20 ) {
				return '';
			}
		}

		return $sku;
	}
}
