( function() {

	var addComment, veActive = false;

	document.addEventListener( 'keydown', function (e) {
		// Note - on mac option+m gives a micro for e.key but an e.code of KeyM.
		if (
			( e.key === 'M' || e.key === 'm' || e.code === 'KeyM'  ) &&
			( e.ctrlKey || e.metaKey ) && e.altKey &&
			getSelection().rangeCount >= 1 &&
			getSelection().getRangeAt(0).collapsed === false &&
			!veActive
		) {
			addComment();
		}
	} );

	mw.hook( 've.activationComplete' ).add( function () { veActive = true } );
	mw.hook( 've.deactivationComplete' ).add( function () { veActive = false } );


	var icon = new OO.ui.IconWidget( {
		icon: 'speechBubbleAdd',
		title: mw.msg( 'inlinecomments-addcomment-tooltip' )
	} );
	var iconContainer = document.createElement( 'div' );
	iconContainer.id = 'mw-inlinecomments-addicon';
	iconContainer.style.display = 'none';
	$( iconContainer ).append( icon.$element );
	iconContainer.addEventListener( 'click', function(e) {
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
		var parent = document.querySelector( '#mw-content-text > .mw-parser-output');
		if (
			getSelection().rangeCount >= 1 &&
			getSelection().getRangeAt(0).collapsed === false &&
			!veActive &&
			parent &&
			parent.contains( getSelection().getRangeAt(0).commonAncestorContainer )
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

	var getOffset = function ( range ) {
		var item = range.startContainer;
		if ( item.offsetTop ) {
			return mw.inlineComments.manager.getOffset( item );
		}
		if ( item.nextElementSibling ) {
			return mw.inlineComments.manager.getOffset( item.nextElementSibling );
		}
		if ( item.parentElement ) {
			return mw.inlineComments.manager.getOffset( item.parentElement );
		}
		return mw.inlineComments.manager.getOffset( range.commonAncestorContainer );
	}

	var highlightRange = function ( asideId, range ) {
		var newClass = asideId.replace( /^mw-inlinecomment-aside-/, 'mw-annotation-' );
		var dataAttrib = asideId.replace( /^mw-inlinecomment-aside-/, '' );
		var combinedClass = 'mw-annotation-highlight ' + newClass
		var clickHandler = function (event) {
			if ( this.classList.contains( newClass ) ) {
				// If we don't have the class, that means the user cancelled.
				event.stopPropagation();
				mw.inlineComments.manager.select( asideId, mw.inlineComments.manager.getOffset( this ) );
			}
		};
		var textContent = '';

		if ( range.startContainer === range.endContainer ) {
			textContent = range.startContainer.textContent.substring( range.startOffset, range.endOffset );
			var span = document.createElement( 'span' );
			span.className = combinedClass;
			span.setAttribute( 'data-mw-highlight-id', dataAttrib );
			span.addEventListener( 'click', clickHandler, true );
			range.surroundContents( span );
			return textContent;
		}
		// We can't use range.surroundContents because doesn't work if spans elements
		// The complex case:

		var curNode = range.startContainer;
		// Start node.
		if ( range.startContainer.nodeType === Node.ELEMENT_NODE ) {
			// Not sure if this case is possible, but if it does happen
			// we should have the whole node highlighted.
			range.startContainer.classList.add( newClass );
			range.startContainer.classList.add( 'mw-annotation-highlight' );
			textContent += range.startContainer.textContent;
		} else if ( range.startContainer.nodeType === Node.TEXT_NODE ) {
			let startParent = range.startContainer.parentNode;
			let preText = document.createTextNode( range.startContainer.data.substring( 0, range.startOffset ) );
			let startHighlight = document.createTextNode( range.startContainer.data.substring( range.startOffset ) );
			textContent += startHighlight.textContent;
			let span = document.createElement( 'span' );
			span.className = combinedClass;
			span.setAttribute( 'data-mw-highlight-id', dataAttrib );
			span.addEventListener( 'click', clickHandler, true );
			span.appendChild( startHighlight );
			let frag = document.createDocumentFragment();
			frag.appendChild( preText );
			frag.appendChild( span );
			range.startContainer.parentNode.replaceChild( frag, range.startContainer );
			// We are replacing the startContainer. This can cause the start container
			// to become the entire parent element, so make sure the currentNode is the
			// span we just highlighted.
			curNode = span;
		} else {
			// ignore i guess.
			console.log( "Unexpected start node type " + range.startContainer.nodeType );
		}



		// Middle nodes
		// We know that endContainer !== startContainer because we used surroundContents in that case.
		while ( true ) {
			if (
				curNode.nextSibling === null &&
				curNode.parentNode
			) {
				// We hit the end of this branch of the tree, so go up the tree
				// and back down to next leaf.
				while (
					curNode.nextSibling === null &&
					curNode.parentNode
				) {
					curNode = curNode.parentNode;
				}
				if ( !curNode.nextSibling ) {
					// not expected to happen
					break;
				}
				curNode = curNode.nextSibling;
				if ( !range.commonAncestorContainer.contains( curNode ) ) {
					// not expected to happen.
					break;
				}
				while ( curNode && curNode.contains( range.endContainer ) ) {
					// descend into tree if we are at end.
					curNode = curNode.firstChild;
				}
			} else {
				curNode = curNode.nextSibling;
				while ( curNode && curNode.contains( range.endContainer ) ) {
					// descend into tree
					curNode = curNode.firstChild;
				}
			}
			if ( !curNode || curNode === range.endContainer ) {
				break;
			}

			if ( curNode.nodeType === Node.ELEMENT_NODE ) {
				textContent += curNode.textContent;
				curNode.classList.add( newClass );
				curNode.classList.add( 'mw-annotation-highlight' );
			} else if ( curNode.nodeType === Node.TEXT_NODE ) {
				// FIXME, maybe should not highlight nodes consisting of "\n"
				// Looks kind of ugly.
				let startParent = curNode.parentNode;
				textContent += curNode.data;
				let startHighlight = document.createTextNode( curNode.data );
				let span = document.createElement( 'span' );
				span.className = combinedClass;
				span.setAttribute( 'data-mw-highlight-id', dataAttrib );
				span.addEventListener( 'click', clickHandler, true );
				span.appendChild( startHighlight );
				curNode.parentNode.replaceChild( span, curNode );
				// Since we detached for document, change curNode reference so loop still works
				curNode = span;
			} else {
				// ignore i guess.
				console.log( "Unexpected node type during highlight " + curNode.nodeType );
			}
		}

		// End node
 		if ( range.endContainer.nodeType === Node.ELEMENT_NODE ) {
			// We are right up to the end of an element, so don't have to do anything else.
			// On firefox, this often happens when selecting headers.
		} else if ( range.endContainer.nodeType === Node.TEXT_NODE ) {
			let endParent = range.endContainer.parentNode;
			let postText = document.createTextNode( range.endContainer.data.substring( range.endOffset ) );
			let endHighlight = document.createTextNode( range.endContainer.data.substring( 0, range.endOffset ) );
			textContent += endHighlight.textContent;
			let span = document.createElement( 'span' );
			span.className = combinedClass;
			span.setAttribute( 'data-mw-highlight-id', dataAttrib );
			span.addEventListener( 'click', clickHandler, true );
			span.appendChild( endHighlight );
			let frag = document.createDocumentFragment();
			frag.appendChild( span );
			frag.appendChild( postText );
			range.endContainer.parentNode.replaceChild( frag, range.endContainer );
		} else {
			// ignore i guess.
			console.log( "Unexpected end node type " + range.endContainer.nodeType );
		}
		return textContent;
	}

	/**
	 * See how many times this text is in document, so we select the right one
	 */
	var getSkipCount = function( info, containerNode ) {
		var selector = '#mw-content-text > .mw-parser-output',
			containerClasses = [];
		if ( info.containerclass !== 'mw-parser-output' ) {
			selector += ' ' + CSS.escape( info.container );
			if ( info.containerid ) {
				selector += '#' + CSS.escape( info.containerid )
			}
			if ( info.containerclass ) {
				containerClasses = info.containerclass.split(' ');
				for ( let i = 0; i < containerClasses.length; i++ ) {
					selector += '.' + CSS.escape( containerClasses[i] );
				}
			}
		}
		var elms = document.querySelectorAll( selector );
		var count = -1;
		containers: for ( let i = 0; i < elms.length; i++ ) {
			var curNode;
			var iterator = document.createNodeIterator( elms[i], NodeFilter.SHOW_TEXT );
			var origText = info.pre + info.body;
			var textToFind = info.pre + info.body;
			while((curNode = iterator.nextNode())) {
				for ( let j = 0; j < curNode.data.length; j++ ) {
					if ( curNode.data[j] === textToFind[0] ) {
						textToFind = textToFind.substring(1);
						if ( textToFind.length === 0 ) {
							count++;
							if ( containerNode.contains( curNode ) ) {
								break containers;
							} else {
								continue containers;
							}
						}
					} else {
						textToFind = origText;
					}
				}
			}
		}
		if ( count < 0 ) {
			// We didn't find it. Some sort of error. Warn user.
			mw.notify( mw.msg( 'inlinecomments-error-notext' ), { type: 'error' } );
			// Still try to save and hope for the best.
			return 0;
		}
		return count;
	}

	var saveToServer = function( asideElm, containerNode, pre, body, comment ) {
		if (containerNode.nodeType !== Node.ELEMENT_NODE ) {
			containerNode = containerNode.parentElement;
		}
		// Make sure the container node we use isn't one inserted for highlighting
		// an annotation.
		while ( containerNode && containerNode.getAttribute( 'data-mw-highlight-id' ) !== null ) {
			containerNode = containerNode.parentElement;
		}
		if ( containerNode === null ) {
			// This should not happen.
			throw new Error( "All container nodes had data-mw-highlight-id attribute" );
		}
		var container = containerNode.tagName.toLowerCase();
		var data = {
			// Put an upper limit for how much of a prefix we match against.
			pre: pre.substring( pre.length - 150 ),
			body: body,
			comment: comment,
			container: container,
			format: 'json',
			formatversion: 2,
			action: 'inlinecomments-add',
			title: mw.config.get( 'wgPageName' )
		}
		if ( containerNode.hasAttribute( 'id' ) ) {
			data['containerid'] = containerNode.id;
		}
		if ( containerNode.hasAttribute( 'class' ) ) {
			data['containerclass'] = containerNode.className;
		}

		data['skipcount'] = getSkipCount( data, containerNode );

		mw.loader.using( 'mediawiki.api', function () {
			var api = new mw.Api();
			api.postWithToken( 'csrf', data ).then( function (res) {
				if ( !res['inlinecomments-add'] || !res['inlinecomments-add'].success ) {
					mw.notify( 'Unknown error', { type: 'error'} );
					return;
				}

				// Now that we have saved the comment, we have
				// an actual ID for it - change the temporary
				// ID to the real ID in the DOM, for both the
				// aside and the highlighted span, and then
				// (re-)connect the two together.
				var prevAsideId = asideElm.id.replace( mw.inlineComments.manager.opts.idRegex, '' );
				var newAsideId = res['inlinecomments-add'].id;
				asideElm.id = "mw-inlinecomment-aside-" + newAsideId;
				var prevClassName =  mw.inlineComments.manager.opts.annotationClassPrefix + prevAsideId
				var $highlightedSpan = $( 'span.' + $.escapeSelector( prevClassName ) );
				$highlightedSpan
					.attr('data-mw-highlight-id', newAsideId)
					.removeClass(prevClassName)
					.addClass(mw.inlineComments.manager.opts.annotationClassPrefix + newAsideId);

				$highlightedSpan[0].addEventListener( 'click', function( event ) {
					event.stopPropagation();
					mw.inlineComments.manager.select( asideElm.id, mw.inlineComments.manager.getOffset( this ) );
				}, true );

				// Also remove the comment-creation interface -
				// no longer needed.
				$(asideElm).find('.mw-inlinecomment-inittools').remove();

				var textDiv = document.createElement( 'div' );
				textDiv.className = 'mw-inlinecomment-text';
				// TODO this should look more like it does on the server.
				// (username should be a link, timestamp should be included)
				var p = document.createElement( 'p' );
				// .innerText ensures that newlines are replaced with <br />,
				// but all HTML is escaped.
				p.innerText = comment;
				if ( mw.config.get( 'wgUserName' ) !== null ) {
					// username will be null if anon.
					var author = document.createElement( 'div' );
					author.className = 'mw-inlinecomment-author';
					author.textContent = mw.config.get( 'wgUserName' );
					textDiv.append( p, author );
				} else {
					textDiv.append( p );
				}
				asideElm.prepend(textDiv);

				var asideElmOffset = mw.inlineComments.manager.getOffset( asideElm );
				mw.inlineComments.manager.addTools( asideElm, asideElmOffset );

			} ).fail( function ( code, data ) {
				mw.notify( api.getErrorMessage( data ), { type: 'error' } );
				throw new Error( "Error saving" );
			} );
		} );
	}

	var getForm = function( aside, containerNode, preText, bodyText ) {
		var textbox = new OO.ui.MultilineTextInputWidget( {
			value: '',
			autosize: true,
			placeholder: mw.msg( 'inlinecomments-placeholder' )
		} );
		var saveButton = new OO.ui.ButtonInputWidget( {
			label: mw.msg( 'inlinecomments-addcomment-save' ),
			flags: [ 'primary', 'progressive' ]
		} );
		var cancelButton = new OO.ui.ButtonInputWidget( {
			label: mw.msg( 'inlinecomments-addcomment-cancel' ),
			flags: [ 'destructive' ]
		} );
		var div = document.createElement( 'div' );
		div.className = 'mw-inlinecomments-editor';

		// Disable "Save" button until text is added.
		saveButton.setDisabled( true );
		textbox.$element.keyup( function () {
			saveButton.setDisabled( textbox.getValue().trim() == '' );
		} );

		saveButton.$element.click( function () {
			saveButton.setDisabled( true );
			cancelButton.setDisabled( true );
			saveToServer( aside, containerNode, preText, bodyText, textbox.getValue().trim() );
		} );
		cancelButton.$element.click( function () {
			mw.inlineComments.manager.remove( aside.id );
		} );
		var buttonsDiv = document.createElement( 'div' );
		buttonsDiv.className = 'mw-inlinecomment-buttons';
		buttonsDiv.replaceChildren( saveButton.$element[0], cancelButton.$element[0] );
		div.replaceChildren( textbox.$element[0], buttonsDiv );
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
			// "init tools" refers to the interface for creating
			// an initial comment; once the comment is saved, this
			// div gets replaced with the interface for replying
			// and deleting, with the class name "-tools".
			var initToolsDiv = document.createElement( 'div' );
			initToolsDiv.className = 'mw-inlinecomment-inittools';
			var preText = range.startContainer.textContent.substring( 0, range.startOffset );
			// Calling focus will unselect text, so highlight now.
			var bodyText = highlightRange( aside.id, range );
			var containerNode = range.commonAncestorContainer;
			initToolsDiv.appendChild( getForm( aside, containerNode, preText, bodyText ) );
			aside.appendChild( initToolsDiv );
			sidenoteContainer.appendChild( aside );

			mw.inlineComments.manager.add( aside, getOffset( range ) );
			aside.querySelector( 'textarea' ).focus();
		} );
	}
} )();
