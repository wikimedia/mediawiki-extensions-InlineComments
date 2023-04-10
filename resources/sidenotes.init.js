$( function () {

	var noteElms = document.querySelectorAll( '#mw-content-text #mw-inlinecomment-annotations .mw-inlinecomment-aside' );
	var sidenoteManager = new mw.inlineComments.SidenoteManager(
		noteElms
	);

	var elms = document.querySelectorAll( '#mw-content-text .mw-annotation-highlight' );
	for ( var i = 0; i < elms.length; i++ ) {
		// TODO handle relatively positioned elements.
		let elmOffset = elms[i].offsetTop;
		if ( !elms[i].dataset.mwHighlightId ) {
			continue;
		}
		let asideId = 'mw-inlinecomment-aside-' + elms[i].dataset.mwHighlightId

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
		function () { sidenoteManager.renderUnselected(); }
	);
 
	// Todo: Have some way to deselct all.
} );
