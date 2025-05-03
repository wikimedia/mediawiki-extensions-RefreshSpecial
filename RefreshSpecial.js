/**
 * JavaScript helper function for RefreshSpecial extension
 */
$( () => {
	$( 'input#refreshSpecialCheckAll' ).on( 'click', function () {
		$( 'input[name="wpSpecial\\[\\]"]' ).prop( 'checked', $( this ).prop( 'checked' ) );
	} );
} );
