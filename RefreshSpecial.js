/**
 * JavaScript helper function for RefreshSpecial extension
 */
$( document ).ready( function () {
	$( 'input#refreshSpecialCheckAll' ).on( 'click', function () {
		$( 'input[name="wpSpecial\\[\\]"]' ).prop( 'checked', !$( this ).prop( 'checked' ) );
	} );
} );