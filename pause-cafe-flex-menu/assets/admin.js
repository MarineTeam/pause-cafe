( function ( $ ) {
	'use strict';

	/**
	 * Dish name autocomplete. Selecting an existing dish records its product ID
	 * in the paired hidden field so the server can copy the photo, description
	 * and price across instead of creating a bare product.
	 */
	function initAutocomplete() {
		$( '.pcfm-dish-input' ).each( function () {
			var $input = $( this );
			var $source = $input.siblings( '.pcfm-dish-source' );

			$input.autocomplete( {
				minLength: 2,
				source: function ( request, response ) {
					$.getJSON(
						pcfmAdmin.ajaxUrl,
						{
							action: 'pcfm_search_dishes',
							nonce: pcfmAdmin.nonce,
							term: request.term
						},
						response
					);
				},
				select: function ( event, ui ) {
					$source.val( ui.item.id );
				}
			} );

			// Typing a name by hand means it is a new dish, not a reused one.
			$input.on( 'input', function () {
				$source.val( '' );
			} );
		} );
	}

	/**
	 * The schedule editor lists the rules for every mode. Only the selected
	 * mode's panel is relevant, so hide the rest rather than making someone work
	 * out which fields apply.
	 */
	function initModePanels() {
		var $select = $( '.pcfm-mode-select' );

		if ( ! $select.length ) {
			return;
		}

		function sync() {
			var mode = $select.val();

			$( '.pcfm-mode-panel' ).each( function () {
				$( this ).toggle( $( this ).data( 'mode' ) === mode );
			} );
		}

		$select.on( 'change', sync );
		sync();
	}

	$( function () {
		initAutocomplete();
		initModePanels();
	} );
} )( jQuery );
