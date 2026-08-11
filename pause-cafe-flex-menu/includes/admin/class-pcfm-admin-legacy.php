<?php
/**
 * One-time cleanup for menu items created before this plugin existed.
 *
 * Those products are still published and still purchasable, so an old bookmark
 * lands on a buyable dish from a week that has long passed. Because payment
 * comes out of a wallet balance, such an order can complete and then never
 * appear on any cook list.
 *
 * Nothing is selected by default and nothing is deleted -- items are moved to
 * draft, which keeps every past order's history intact.
 */

defined( 'ABSPATH' ) || exit;

class PCFM_Admin_Legacy {

	public static function init() {
		add_action( 'admin_post_pcfm_archive_legacy', array( __CLASS__, 'handle_archive' ) );
	}

	public static function render() {
		$ids = PCFM_Product::unscheduled_legacy_ids();

		echo '<div class="wrap pcfm-wrap">';
		echo '<h1>' . esc_html__( 'Legacy items', 'pause-cafe-flex-menu' ) . '</h1>';

		PCFM_Admin::print_notices();

		if ( ! $ids ) {
			echo '<p>' . esc_html__( 'Nothing to clean up. Every published product is either on a schedule or outside the pickup categories.', 'pause-cafe-flex-menu' ) . '</p></div>';

			return;
		}

		printf(
			'<div class="notice notice-warning inline"><p>%s</p></div>',
			esc_html(
				sprintf(
					/* translators: %d: number of products. */
					_n(
						'%d published product is on no schedule and has no dates. Until it is archived, it can still be ordered through a direct link.',
						'%d published products are on no schedule and have no dates. Until they are archived, they can still be ordered through a direct link.',
						count( $ids ),
						'pause-cafe-flex-menu'
					),
					count( $ids )
				)
			)
		);

		echo '<p>' . esc_html__( 'Check anything that is an old weekly dish and archive it. Leave drinks, desserts and special orders alone — those are meant to stay on sale.', 'pause-cafe-flex-menu' ) . '</p>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'pcfm_archive_legacy' );
		echo '<input type="hidden" name="action" value="pcfm_archive_legacy">';

		echo '<table class="widefat striped pcfm-legacy"><thead><tr>';
		echo '<td class="check-column"><input type="checkbox" id="pcfm-check-all"></td>';
		echo '<th>' . esc_html__( 'Product', 'pause-cafe-flex-menu' ) . '</th>';
		echo '<th>' . esc_html__( 'Categories', 'pause-cafe-flex-menu' ) . '</th>';
		echo '<th>' . esc_html__( 'Price', 'pause-cafe-flex-menu' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $ids as $product_id ) {
			$product    = wc_get_product( $product_id );
			$categories = wp_get_post_terms( $product_id, 'product_cat', array( 'fields' => 'names' ) );

			printf(
				'<tr><th scope="row" class="check-column"><input type="checkbox" name="archive[]" value="%1$d"></th>
				<td><a href="%2$s">%3$s</a></td><td>%4$s</td><td>%5$s</td></tr>',
				(int) $product_id,
				esc_url( get_edit_post_link( $product_id ) ),
				esc_html( get_the_title( $product_id ) ),
				esc_html( is_wp_error( $categories ) || ! $categories ? __( 'Uncategorized', 'pause-cafe-flex-menu' ) : implode( ', ', $categories ) ),
				wp_kses_post( $product ? $product->get_price_html() : '' )
			);
		}

		echo '</tbody></table>';

		submit_button( __( 'Move selected to draft', 'pause-cafe-flex-menu' ), 'primary', 'submit', true );
		echo '</form>';

		?>
		<script>
		document.getElementById( 'pcfm-check-all' ).addEventListener( 'change', function ( event ) {
			document.querySelectorAll( 'input[name="archive[]"]' ).forEach( function ( box ) {
				box.checked = event.target.checked;
			} );
		} );
		</script>
		<?php

		echo '</div>';
	}

	public static function handle_archive() {
		if ( ! current_user_can( PCFM_Admin::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to archive products.', 'pause-cafe-flex-menu' ) );
		}

		check_admin_referer( 'pcfm_archive_legacy' );

		$ids       = isset( $_POST['archive'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['archive'] ) ) : array();
		$permitted = PCFM_Product::unscheduled_legacy_ids();
		$archived  = 0;

		foreach ( $ids as $product_id ) {
			// Only ever touch products this screen actually offered.
			if ( ! in_array( $product_id, $permitted, true ) ) {
				continue;
			}

			wp_update_post(
				array(
					'ID'          => $product_id,
					'post_status' => 'draft',
				)
			);

			++$archived;
		}

		PCFM_Visibility::flush();

		PCFM_Admin::add_notice(
			sprintf(
				/* translators: %d: number of products moved to draft. */
				_n( '%d product moved to draft.', '%d products moved to draft.', $archived, 'pause-cafe-flex-menu' ),
				$archived
			)
		);

		wp_safe_redirect( admin_url( 'admin.php?page=' . PCFM_Admin::PAGE_LEGACY ) );
		exit;
	}
}
