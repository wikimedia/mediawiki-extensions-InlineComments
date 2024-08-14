( function () {
    // Move default diff table to the top of page (above the custom diff display for InlineComments)
    const params = new URLSearchParams( window.location.search );
    if ( params.get( 'oldid' ) !== null ) {
        $('#mw-content-text table.diff').insertBefore( $( '.mw-inlinecomments-diff' ) );
        return;
    }
} )()
