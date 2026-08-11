<?php
/**
 * The publish screen.
 *
 * One row of dish names, one button. Saving publishes the dishes and opens
 * ordering there and then, running to the next cutoff. There is no calendar and
 * nothing to fill in ahead of time -- publishing is the schedule.
 */

defined( 'ABSPATH' ) || exit;

class PCLM_Admin_Publish {

	public static function init() {
		add_action( 'admin_post_pclm_publish_menu', array( __CLASS__, 'handle_publish' ) );
		add_action( 'wp_ajax_pclm_search_dishes', array( __CLASS__, 'ajax_search_dishes' ) );
	}

	/**
	 * Autocomplete over dishes that already exist, so a repeat brings its photo,
	 * description and price across instead of being rebuilt from scratch.
	 */
	public static function ajax_search_dishes() {
		check_ajax_referer( 'pclm_search_dishes', 'nonce' );

		if ( ! current_user_can( PCLM_Admin::CAPABILITY ) ) {
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
				'pclm_bypass_visibility' => true,
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
		$locations = PCLM_Settings::locations();
		$cycle     = PCLM_Schedule::current_cycle();
		$state     = $cycle ? PCLM_Schedule::cycle_state( $cycle ) : PCLM_Schedule::PAST;

		echo '<div class="wrap pclm-wrap">';
		echo '<h1>' . esc_html__( 'Publish menu', 'pause-cafe-live-menu' ) . '</h1>';

		PCLM_Admin::print_notices();
		PCLM_Admin::warn_if_unconfigured();

		self::render_status( $cycle, $state );

		if ( ! $locations ) {
			echo '</div>';

			return;
		}

		$next_cutoff = PCLM_Schedule::cutoff_after( PCLM_Schedule::now() );

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'pclm_publish_menu' );
		echo '<input type="hidden" name="action" value="pclm_publish_menu">';

		echo '<table class="widefat striped pclm-publish"><thead><tr>';
		echo '<th class="pclm-publish__location">' . esc_html__( 'Pickup location', 'pause-cafe-live-menu' ) . '</th>';
		echo '<th>' . esc_html__( 'Dish', 'pause-cafe-live-menu' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $locations as $location ) {
			$product_id = PCLM_Product::open_slot_id( $location['term_id'] );
			$title      = $product_id ? get_the_title( $product_id ) : '';

			echo '<tr><td class="pclm-publish__location"><strong>' . esc_html( $location['label'] ) . '</strong></td><td>';

			printf(
				'<input type="text" class="pclm-dish-input" name="dish[%1$d]" value="%2$s" placeholder="%3$s" autocomplete="off">
				<input type="hidden" class="pclm-dish-source" name="source[%1$d]" value="">',
				(int) $location['term_id'],
				esc_attr( $title ),
				esc_attr__( 'Start typing…', 'pause-cafe-live-menu' )
			);

			if ( $product_id ) {
				printf(
					'<a class="pclm-edit-link" href="%s">%s</a>',
					esc_url( get_edit_post_link( $product_id ) ),
					esc_html__( 'Edit product', 'pause-cafe-live-menu' )
				);
			}

			echo '</td></tr>';
		}

		echo '</tbody></table>';

		printf(
			'<p class="description">%s</p>',
			esc_html(
				sprintf(
					/* translators: %s: date and time ordering will close. */
					__( 'Publishing opens ordering immediately and closes it %s. Leaving a dish blank skips that location. Previous weeks drop off on their own.', 'pause-cafe-live-menu' ),
					PCLM_Schedule::format_moment( $next_cutoff )
				)
			)
		);

		submit_button( __( 'Publish menu and open ordering', 'pause-cafe-live-menu' ) );
		echo '</form></div>';
	}

	private static function render_status( $cycle, $state ) {
		if ( $cycle && PCLM_Schedule::OPEN === $state ) {
			printf(
				'<div class="notice notice-success inline pclm-status"><p><strong>%s</strong> %s</p></div>',
				esc_html__( 'Ordering is open.', 'pause-cafe-live-menu' ),
				esc_html(
					sprintf(
						/* translators: 1: cutoff moment, 2: service date. */
						__( 'It closes %1$s, for food served %2$s.', 'pause-cafe-live-menu' ),
						PCLM_Schedule::format_moment( PCLM_Schedule::cutoff_for_cycle( $cycle ) ),
						PCLM_Schedule::format_date( PCLM_Schedule::service_date_for_cycle( $cycle ), 'l j F' )
					)
				)
			);

			return;
		}

		printf(
			'<div class="notice notice-warning inline pclm-status"><p><strong>%s</strong> %s</p></div>',
			esc_html__( 'Ordering is closed.', 'pause-cafe-live-menu' ),
			esc_html__( 'Publishing the menu below reopens it straight away.', 'pause-cafe-live-menu' )
		);
	}

	public static function handle_publish() {
		if ( ! current_user_can( PCLM_Admin::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to publish the menu.', 'pause-cafe-live-menu' ) );
		}

		check_admin_referer( 'pclm_publish_menu' );

		$dishes  = isset( $_POST['dish'] ) ? (array) wp_unslash( $_POST['dish'] ) : array();
		$sources = isset( $_POST['source'] ) ? (array) wp_unslash( $_POST['source'] ) : array();

		$now = PCLM_Schedule::now();

		/*
		 * Everything published in one go shares a single opening moment, so all
		 * three campuses close together even if saving takes a few seconds.
		 */
		$cycle     = PCLM_Schedule::cycle_for( $now );
		$published = 0;
		$updated   = 0;

		foreach ( $dishes as $term_id => $title ) {
			$term_id = (int) $term_id;
			$title   = sanitize_text_field( $title );

			if ( ! $term_id || '' === $title ) {
				continue;
			}

			$source_id = isset( $sources[ $term_id ] ) ? absint( $sources[ $term_id ] ) : 0;
			$existing  = self::existing_in_cycle( $cycle, $term_id );

			if ( $existing ) {
				if ( get_the_title( $existing ) !== $title ) {
					wp_update_post(
						array(
							'ID'         => $existing,
							'post_title' => $title,
						)
					);
				}

				PCLM_Product::open_now( $existing, $now );
				++$updated;

				continue;
			}

			if ( self::create_dish( $term_id, $title, $source_id, $now ) ) {
				++$published;
			}
		}

		do_action( 'pclm_menu_published' );

		if ( $published || $updated ) {
			PCLM_Admin::add_notice(
				sprintf(
					/* translators: 1: dishes published, 2: dishes updated, 3: cutoff moment. */
					__( 'Menu live. %1$d published, %2$d updated. Ordering closes %3$s.', 'pause-cafe-live-menu' ),
					$published,
					$updated,
					PCLM_Schedule::format_moment( PCLM_Schedule::cutoff_for_cycle( $cycle ) )
				)
			);
		} else {
			PCLM_Admin::add_notice( __( 'Nothing to publish — every dish was left blank.', 'pause-cafe-live-menu' ), 'error' );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=' . PCLM_Admin::PAGE_PUBLISH ) );
		exit;
	}

	/**
	 * A dish already published into this cycle at this location, or 0. Saving
	 * twice in one window edits the live menu instead of duplicating it.
	 */
	private static function existing_in_cycle( $cycle, $term_id ) {
		$ids = PCLM_Product::ids_for_cycle( $cycle, $term_id );

		return $ids ? (int) $ids[0] : 0;
	}

	private static function create_dish( $term_id, $title, $source_id, DateTimeImmutable $now ) {
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
			$price = PCLM_Settings::get( 'default_price' );
		}

		$product->set_regular_price( '' !== $price ? $price : PCLM_Settings::get( 'default_price' ) );
		$product->set_category_ids( self::category_ids_for( $term_id, $source ) );

		$sku = self::build_sku( $now, $term_id );

		if ( $sku ) {
			$product->set_sku( $sku );
		}

		/*
		 * Saved as a draft first, then published, so the publish transition fires
		 * and stamps the opening time. open_now() is still called afterwards to
		 * pin every dish in this batch to the same moment.
		 */
		$product->set_status( 'draft' );
		$product_id = $product->save();

		if ( ! $product_id ) {
			return false;
		}

		wp_update_post(
			array(
				'ID'          => $product_id,
				'post_status' => 'publish',
			)
		);

		PCLM_Product::open_now( $product_id, $now );

		return true;
	}

	/**
	 * Keeps any grouping categories the source dish carried but swaps in the
	 * pickup category for this location.
	 */
	private static function category_ids_for( $term_id, $source ) {
		$managed = PCLM_Settings::managed_term_ids();
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
	 * SKUs read like 260816-MAR, keyed on the service date, so an export says
	 * which week and which campus a line belongs to without cross-referencing.
	 */
	private static function build_sku( DateTimeImmutable $now, $term_id ) {
		$cycle   = PCLM_Schedule::cycle_for( $now );
		$service = PCLM_Schedule::service_date_for_cycle( $cycle );
		$date    = PCLM_Schedule::parse( $service . ' 00:00:00' );

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
