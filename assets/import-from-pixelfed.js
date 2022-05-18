jQuery( document ).ready( function ( $ ) {
	$( '.settings_page_import-from-pixelfed .button-reset-settings' ).click( function( e ) {
		if ( ! confirm( import_from_pixelfed_obj.message ) ) {
			e.preventDefault();
		}
	} );
} );
