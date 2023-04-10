( function() {

	var addComment;

	document.addEventListener( 'keyup', function (e) {
		if (
			( e.key === 'M' || e.key === 'm'  ) &&
			e.ctrlKey && e.altKey &&
			getSelection().rangeCount >= 1 &&
			getSelection().getRangeAt(0).collapsed === false
		) {
			addComment();
		}
	} );

	
	var icon = new OO.ui.IconWidget( {
		icon: 'speechBubbleAdd',
		title: mw.msg( 'inlinecomments-addcomment-tooltip' )
	} );
	var iconContainer = document.createElement( 'div' );
	iconContainer.id = 'mw-inlinecomments-addicon';
	iconContainer.style.display = 'none';
	$( iconContainer ).append( icon.$element );
	iconContainer.addEventListener( 'click', function(e) {
		// Can clicking the icon ever cause the selection to go away?
		if (
			getSelection().rangeCount >= 1 &&
			getSelection().getRangeAt(0).collapsed === false
		) {
			iconContainer.style.display = 'none';
			e.stopPropagation();
			e.preventDefault();
			addComment();
		}
	} );

	document.body.appendChild( iconContainer );
	var checkForNewSelection = function () {
		if (
			getSelection().rangeCount >= 1 &&
			getSelection().getRangeAt(0).collapsed === false
		) {
			var rect = getSelection().getRangeAt(0).getBoundingClientRect();
			iconContainer.style.position = 'fixed';
			iconContainer.style.left = ( rect.left + rect.width ) + 'px';
			iconContainer.style.top = ( rect.top - 20 ) + 'px';
			iconContainer.style.display = 'block';
		} else {
			iconContainer.style.display = 'none';
		}
	}

	// There are edge cases this doesn't catch, e.g. selecting via keyboard.
	document.addEventListener( 'pointerup', checkForNewSelection );

	// Try and get an offset for the selection
	// FIXME doesn't work if article body contains nested positioned elements.
	var getOffset = function ( range ) {
		item = range.startContainer;
		if ( item.offsetTop ) {
			return item.offsetTop;
		}
		if ( item.nextElementSibling ) {
			return item.nextElementSibling.offsetTop;
		}
		if ( item.parentElement ) {
			return item.parentElement.offsetTop;
		}
		return range.commonAncestorContainer.offsetTop;
	}

	var getForm = function() {
		var textbox = new OO.ui.MultilineTextInputWidget( {
			value: '',
			placeholder: 'Enter your comment here'
		} );
		var save = new OO.ui.ButtonInputWidget( {
			label: mw.msg( 'inlinecomments-addcomment-save' ),
			flags: [ 'primary', 'progressive' ]
		} );
		var cancel = new OO.ui.ButtonInputWidget( {
			label: mw.msg( 'inlinecomments-addcomment-cancel' ),
			flags: [ 'destructive' ]
		} );
		// FIXME add a cancel button somewhere, or maybe reuse the UI for resolving comment.
		var div = document.createElement( 'div' );
		div.className = 'mw-inlinecomments-editor';
		save.$element.click( function () { alert( "FIXME" ) } );
		$( div ).append( textbox.$element, save.$element, cancel.$element );
		return div;
	}

	addComment = function () {
		// A future todo might be to support multiple ranges
		// This only works in firefox when selecting with ctrl-click, so
		// maybe not worth it.
		var range = getSelection().getRangeAt(0);

		var parent = document.querySelector( '#mw-content-text > .mw-parser-output');
		if (range.collapsed || !parent.contains( range.commonAncestorContainer ) ) {
			return;
		}

		var sidenoteContainer = document.getElementById( 'mw-inlinecomment-annotations' );
		if ( !sidenoteContainer ) {
			var sidenoteParent = document.getElementById( 'mw-content-text' );
			sidenoteContainer = document.createElement( 'div' );
			sidenoteContainer.id = 'mw-inlinecomment-annotations';
			sidenoteParent.appendChild( sidenoteContainer );
		}
		mw.loader.using( [ 'ext.inlineComments.sidenotes', 'ext.inlineComments.sidenotes.styles' ], function () {
			var aside = document.createElement( 'aside' );
			aside.className = 'mw-inlinecomment-aside';
			aside.id = 'mw-inlinecomment-aside-' + Math.random();
			aside.appendChild( getForm() );
			sidenoteContainer.appendChild( aside );

			mw.inlineComments.manager.add( aside, getOffset( range ) );
		} );
	}
} )();
