( function ( $ ) {
	'use strict';

	/**
	 * Dish name autocomplete. Selecting an existing dish records its product ID
	 * in the paired hidden field so the server can copy the photo, description
	 * and price across instead of creating a bare product.
	 */
	function initAutocomplete() {
		$( '.pclm-dish-input' ).each( function () {
			var $input = $( this );
			var $source = $input.siblings( '.pclm-dish-source' );

			$input.autocomplete( {
				minLength: 2,
				source: function ( request, response ) {
					$.getJSON(
						pclmAdmin.ajaxUrl,
						{
							action: 'pclm_search_dishes',
							nonce: pclmAdmin.nonce,
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

	$( initAutocomplete );
} )( jQuery );
