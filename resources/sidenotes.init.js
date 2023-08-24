$( function () {

	var noteElms = document.querySelectorAll( '#mw-content-text #mw-inlinecomment-annotations .mw-inlinecomment-aside' );
	var sidenoteManager = new mw.inlineComments.SidenoteManager(
		noteElms
	);

	var elms = document.querySelectorAll( '#mw-content-text .mw-annotation-highlight' );
	for ( var i = 0; i < elms.length; i++ ) {
		let elmOffset = sidenoteManager.getOffset( elms[i] );
		if ( !elms[i].dataset.mwHighlightId ) {
			continue;
		}
		let asideId = 'mw-inlinecomment-aside-' + elms[i].dataset.mwHighlightId;

		elms[i].addEventListener(
			'click',
			function (event) {
				event.stopPropagation();
				sidenoteManager.select( asideId, elmOffset );
			},
			true
		);
	}

	mw.inlineComments.manager = sidenoteManager;

	document.addEventListener(
		'click',
		function (e) {
			if (
				e.target.tagName === 'TEXTAREA' ||
				e.target.classList.contains( 'oo-ui-labelElement-label' )
			) {
				return;
			}
			sidenoteManager.renderUnselected();
		}
	);
} );
