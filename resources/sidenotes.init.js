$( function () {
	var $preContainer = $('<div></div>');
	$preContainer.attr( 'id', 'mw-inlinecomments-precontainer' );
	var $content = $('#mw-content-text > .mw-parser-output').first();
	$content.append($preContainer);

	$('#mw-content-text > .mw-parser-output').first().children().not($preContainer).appendTo($preContainer);

	// Move #mw-annotations to be a sibling of the new div
	$preContainer = $('#mw-inlinecomments-precontainer');
	$annotations = $('#mw-inlinecomment-annotations');
	if ( $annotations.length && !window.matchMedia("not (min-width: 600px)").matches ) {
		var annotationsWidth = $annotations.outerWidth();
		$preContainer.css('width', 'calc(100% - ' + (annotationsWidth + 20) + 'px)');
	} else {
		$preContainer.css('width', '100%');
	}
	$annotations.insertAfter($preContainer);

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

	// VisualEditor does not reload the page on save; instead it simply modifies the DOM. If there was
	// a VE save, reload the page, so that the JavaScript is called that will display annotations.
	mw.hook( 'postEdit' ).add( function () {
		if ( ve && ve.init && ve.init.articleTarget && ve.init.articleTarget.teardownUnloadHandlers ) {
			ve.init.articleTarget.teardownUnloadHandlers();
			location.replace( location.href );
		}
	});

} );
