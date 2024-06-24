( function () {
	var SidenoteManager = function ( notes, opts ) {
		this.opts = opts || {};
		this.opts.padding = this.opts.padding || 10;
		this.opts.idRegex = this.opts.idRegex || /^mw-inlinecomment-aside-/;
		this.opts.annotationClassPrefix = 'mw-annotation-';
		this.opts.selectedClass = this.opts.selectedClass  || 'mw-inlinecomment-selected';
		this.opts.selectedAnnotationClass = this.opts.selectedAnnotationClass  || 'mw-annotation-selected';
		this.opts.offsetReference = this.opts.offsetReference ||
			document.querySelector( '#mw-content-text #mw-inlinecomment-annotations' )?.offsetParent;

		this.items = [];

		for ( var i = 0; i < notes.length; i++ ) {
			var annotationClass = notes[i].id.replace(
				this.opts.idRegex,
				this.opts.annotationClassPrefix
			);
			var annotations = document.getElementsByClassName( annotationClass );
			let preferredOffset = 0;
			let offsetTiebreaker = 0;
			if ( annotations.length >= 1 ) {
				preferredOffset = this.getOffset( annotations[0] );
				offsetTiebreaker = annotations[0].offsetLeft;
			}

			this.items[this.items.length] = {
				preferredOffset: preferredOffset,
				offsetTiebreaker: offsetTiebreaker,
				element: notes[i],
			};

			let id = notes[i].id;
			let that = this;
			notes[i].addEventListener(
				'click',
				function (event) {
					event.stopPropagation();
					that.select( id, preferredOffset );
				}
			);
			if ( mw.config.get( 'wgInlineCommentsCanEdit' ) === true ) {
				this.addTools( notes[i] );
			}
		}

		this.sortItems();
		this.renderUnselected();
	};

	SidenoteManager.prototype = {
		renderUnselected: function () {
			var curOffset = 0;
			for ( var i = 0; i < this.items.length; i++ ) {
				var item = this.items[i];

				// Remove class before measuing height as class changes height.
				item.element.classList.remove( this.opts.selectedClass );
				if ( item.preferredOffset > curOffset ) {
					curOffset = item.preferredOffset;
				}
				item.element.style.position = 'absolute';
				item.element.style.top = curOffset + 'px';
				curOffset += item.element.offsetHeight;
				curOffset += this.opts.padding;
			}
			this.deselectAnnotation();
			$('.mw-inlinecomment-editlink').show();
		},
		// This deselects the highlighted text not the actual comments.
		deselectAnnotation: function () {
			var annotations = document.getElementsByClassName( this.opts.selectedAnnotationClass );
			for ( var i = annotations.length - 1; i >= 0; i-- ) {
				annotations[i].classList.remove( this.opts.selectedAnnotationClass );
			}

		},
		select: function ( itemId, offset ) {
			var itemIndex = null;
			for ( var i = 0; i < this.items.length; i++ ) {
				if ( this.items[i].element.id === itemId ) {
					itemIndex = i;
					this.items[i].element.preferredOffset = offset;
					this.items[i].element.style.top = offset + 'px';
					this.items[i].element.classList.add( this.opts.selectedClass );
					break;
				}
			}
			if ( itemIndex === null || itemIndex > this.items.length ) {
				throw new Error( "Could not locate sidenote " + itemId );
			}
			this.sortItems();
			// Sorting may have changed element order, so re-find itemIndex
			for ( var i = 0; i < this.items.length; i++ ) {
				if ( this.items[i].element.id === itemId ) {
					itemIndex = i;
					break;
				}
			}

			// We must ensure our selected item has its preferred offset, so go
			// through the items before it in backwards order.

			var curOffset = offset;
			for ( var i = itemIndex-1; i >= 0; i-- ) {
				// If we run out of room, unclear if it is best to have them
				// zoom off the page, stack on top of each other, or be put at
				// bottom of page.
				this.items[i].element.classList.remove( this.opts.selectedClass );
				curOffset -= this.items[i].element.offsetHeight + this.opts.padding;
				if ( curOffset > this.items[i].preferredOffset ) {
					curOffset = this.items[i].preferredOffset;
				}
				this.items[i].element.style.top = curOffset + 'px';
			}

			curOffset = offset + this.items[itemIndex].element.offsetHeight + this.opts.padding;
			for ( var i = itemIndex+1; i < this.items.length; i++ ) {
				this.items[i].element.classList.remove( this.opts.selectedClass );
				if ( curOffset < this.items[i].preferredOffset ) {
					curOffset = this.items[i].preferredOffset;
				}
				this.items[i].element.style.top = curOffset + 'px';

				curOffset += this.items[i].element.offsetHeight + this.opts.padding;
			}

			// If selected aside not in view, scroll into view.
			var html = document.documentElement;
			var rect = this.items[itemIndex].element.getBoundingClientRect();
			if (
				rect && (
				rect.bottom <= 0 ||
				rect.right <= 0 ||
				rect.left >= html.clientWidth ||
				rect.top >= html.clientHeight )
			) {
				this.items[itemIndex].element.scrollIntoView(false);
			}

			this.deselectAnnotation();
			var annotationClass = this.items[itemIndex].element.id.replace(
				this.opts.idRegex,
				this.opts.annotationClassPrefix
			);
			var annotations = document.getElementsByClassName( annotationClass );
			for ( var i = 0; i < annotations.length; i++ ) {
				annotations[i].classList.add( this.opts.selectedAnnotationClass );
			}
		},
		sortItems: function () {
			this.items.sort( function (a,b) {
				if ( a.preferredOffset === b.preferredOffset ) {
					return a.offsetTiebreaker - b.offsetTiebreaker;
				}
				return a.preferredOffset - b.preferredOffset;
			} );
		},
		// Add a new sidenote and select it. Assumed already inserted into dom
		add: function ( element, offset ) {
			this.items[this.items.length] = {
				preferredOffset: offset,
				element: element,
			};
			element.style.position = 'absolute';
			this.select( element.id, offset );

			// Allow this new sidenote to be clickable.
			let that = this;
			element.addEventListener(
				'click',
				function (event) {
					event.stopPropagation();
					that.select( this.id, offset );
				}
			);
		},
		remove: function ( id ) {
			// For now, we assume no highlight in document yet.
			this.items.filter( function (a) {
				if ( a.element.id === id ) {
					a.element.remove();
					return false;
				}
				return true;
			} );
			var highlightClass = id.replace(
				this.opts.idRegex,
				this.opts.annotationClassPrefix
			);
			var hls = document.getElementsByClassName( highlightClass );
			// Elements get removed from collection when class changes!
			for ( let i = hls.length - 1; i >= 0; i-- ) {
				// TODO: if there are multiple annotations, we maybe shouldn't.
				hls[i].classList.remove( 'mw-annotation-highlight' );
				hls[i].classList.remove( highlightClass );
			}
			this.renderUnselected();
		},
		// Add "Reply" and "Close discussion" buttons to an aside.
		addTools: function ( aside ) {
			var that = this;
			var asideId = aside.id.replace( this.opts.idRegex, '' );
			var toolsDiv = document.createElement( 'div' );
			toolsDiv.className = 'mw-inlinecomment-tools';
			var replyButton = new OO.ui.ButtonInputWidget( {
				label: mw.msg( 'inlinecomments-addcomment-reply' ),
				flags: [ 'progressive' ]
			} );
			var closeDiscussionButton = new OO.ui.ButtonInputWidget( {
				label: mw.msg( 'inlinecomments-addcomment-close' ),
				flags: [ 'desctructive' ]
			} );

			var textbox = new OO.ui.MultilineTextInputWidget( {
				value: '',
				autosize: true,
				placeholder: mw.msg( 'inlinecomments-placeholder' )
			} );
			var saveReplyButton = new OO.ui.ButtonInputWidget( {
				label: mw.msg( 'inlinecomments-addcomment-save' ),
				flags: [ 'primary', 'progressive' ]
			} );
			var cancelReplyButton = new OO.ui.ButtonInputWidget( {
				label: mw.msg( 'inlinecomments-addcomment-cancel' ),
				flags: [ 'destructive' ]
			} );
			var autocompleteDropdown = $( '<div></div>' );
			autocompleteDropdown.attr( 'class', 'userAutocompleteDropdown' );
			autocompleteDropdown.hide();
			if ( $( '.userAutocompleteDropdown' ).length == 0 ) {
				textbox.$element.append( autocompleteDropdown[0] );
			}

			var initializeEditFunc = function () {
				$( aside ).find( '.mw-inlinecomment-comment' ).each( function ( idx, commentElem ) {
					$( commentElem ).find( '.mw-inlinecomment-editlink' ).click( function (event) {
						$( aside ).find( '.mw-inlinecomment-editlink' ).show();
						$(this).hide();
						editClickFunc( event, idx, $( commentElem ) );
					});
				});
			}

			var saveReplyFunc = function () {
				saveReplyButton.setDisabled( true );
				mw.loader.using( 'mediawiki.api', function () {
					var api = new mw.Api();
					var text = textbox.getValue().trim();
					var data = {
						title: mw.config.get( 'wgPageName' ),
						id: asideId,
						action: 'inlinecomments-addreply',
						comment: text
					};
					api.postWithToken( 'csrf', data ).then( function (res) {
						const commentHTML = res['inlinecomments-addreply'].comment;
						textbox.setValue('');
						var textDiv = $( aside ).find('.mw-inlinecomment-text')[0];
						const DOMs = $.parseHTML( commentHTML );
						$('.mw-inlinecomment-editlink').show();
						textDiv.appendChild( DOMs[0] );
						toolsDiv.replaceChildren( replyButton.$element[0], closeDiscussionButton.$element[0] );
						initializeEditFunc();
					} ).fail( function ( code, data ) {
						mw.notify( api.getErrorMessage( data ), { type: 'error' } );
					} );
				} );
			};
			var cancelReplyFunc = function () {
				textbox.setValue('');
				saveReplyButton.setLabel( mw.msg( 'inlinecomments-addcomment-save' ) );
				toolsDiv.replaceChildren( replyButton.$element[0], closeDiscussionButton.$element[0] );
				$( aside ).find( '.currentedit' ).removeClass( 'currentedit' );
				$( aside ).find( '.mw-inlinecomment-editlink' ).show();
			};
			var replyFunc = function (edit=false, existingCommentObject=null,
				commentIdx=null) {
				if ( edit ) {
					existingCommentObject.addClass('currentedit');
				}
				textbox.$element.keyup( async function ( event ) {
					// Disable "Save" button until text is added.
					saveReplyButton.setDisabled( textbox.getValue().trim() == '' );
					autocompleteDropdown.val( '' );
					var cursorPosition = event.target.selectionStart;
					var textBeforeCursor = event.target.value.substring( 0, cursorPosition );
					var textAfterCursor = event.target.value.substring( cursorPosition );
					var atIndex = textBeforeCursor.lastIndexOf( '@' );
					if ( atIndex !== -1 ) {
						var query = textBeforeCursor.substring( atIndex + 1 );
						if ( query.length > 0 ) {
							const api = new mw.Api();
							const requestParams = {
								'action': 'query',
								'format': 'json',
								'list': 'allusers',
								'auprefix': query,
								'aulimit': 5
							};
							let response = await api.get( requestParams );
							const usernames = response.query.allusers.map( function ( user ) {
								return user.name;
							} );
							const usernamesDivs = usernames.map( function ( username ) {
								let item = $('<a></a>');
								item.attr( 'class', 'userAutocompleteDropdownItem' );
								item.text( username );
								item.on( 'click', function () {
									username = username.replace( ' ', '_' );
									var newText = textBeforeCursor.substring( 0, atIndex + 1 ) + username + ' ';
									event.target.value = newText + textAfterCursor;
									event.target.selectionStart = event.target.selectionEnd = newText.length;
									autocompleteDropdown.hide();
								} );
								return item;
							} );
							autocompleteDropdown.empty();
							if( usernames.length > 0 ) {
								usernamesDivs.forEach( function ( div ) {
									autocompleteDropdown.append( div );
								});
								autocompleteDropdown.show();
							} else {
								autocompleteDropdown.hide();
							}
							event.currentTarget.append( autocompleteDropdown[0] );
						} else {
							autocompleteDropdown.hide();
						}
					} else {
						autocompleteDropdown.hide();
					}
				} );
				// We call unbind() to avoid these functions getting called multiple times,
				// if the buttons were cancelled and re-added.
				saveReplyButton.$element.unbind('click').click( function () {
					if ( edit ) {
						editCommentFunc( existingCommentObject, commentIdx );
						edit = false;
					} else {
						saveReplyFunc();
					}
				} );
				cancelReplyButton.$element.unbind('click').click( cancelReplyFunc );
				var buttonsDiv = document.createElement( 'div' );
				buttonsDiv.className = 'mw-inlinecomment-buttons';
				buttonsDiv.replaceChildren(  saveReplyButton.$element[0], cancelReplyButton.$element[0] );
				toolsDiv.replaceChildren( textbox.$element[0], buttonsDiv );
			}
			var editCommentFunc = function ( existingCommentObject, commentIdx ) {
				$('.currentedit').removeClass('currentedit');
				saveReplyButton.setDisabled( true );
				mw.loader.using( 'mediawiki.api', function () {
					var api = new mw.Api();
					var text = textbox.getValue().trim();
					const action = 'inlinecomments-editcomment';
					var data = {
						title: mw.config.get( 'wgPageName' ),
						id: asideId,
						action: action,
						comment: text,
						existing_comment_idx: commentIdx
					};
					api.postWithToken( 'csrf', data ).then( function (res) {
						const commentHTML = res[action].comment;
						textbox.setValue('');
						saveReplyButton.setLabel( mw.msg( 'inlinecomments-addcomment-save' ) );
						existingCommentObject.removeClass('currentedit');
						existingCommentObject[0].innerHTML = commentHTML;
						let editedLabel = $('<span></span>');
						editedLabel.addClass('mw-inlinecomments-editedlabel');
						editedLabel.text( ' ' + mw.msg( 'parentheses', mw.msg( 'inlinecomments-edited-label' ) ) );
						existingCommentObject.children().first().find('p').first().append(editedLabel);
						$('.mw-inlinecomment-editlink').show();
						initializeEditFunc();
					} ).fail( function ( code, data ) {
						mw.notify( api.getErrorMessage( data ), { type: 'error' } );
					} );
				} );
			}
			var closeDiscussionFunc = function () {
				closeDiscussionButton.setDisabled( true );
				mw.loader.using( 'mediawiki.api', function () {
					var api = new mw.Api();
					var data = {
						title: mw.config.get( 'wgPageName' ),
						id: asideId,
						action: 'inlinecomments-close'
					}
					api.postWithToken( 'csrf', data ).then( function () {
						that.remove( aside.id );
					} ).fail( function ( code, data ) {
						mw.notify( api.getErrorMessage( data ), { type: 'error' } );
					} );
				} );
			}

			var editClickFunc = function ( event, commentIdx, comment ) {
				event.preventDefault();
				textbox.setValue('');
				$( aside ).find( '.currentedit' ).removeClass( 'currentedit' );
				$(this).parent().addClass('currentedit');
				let existingComment = comment.children().first().children().first().clone(true);
				existingComment.find('span').remove();
				existingComment.find( 'bdi' ).each( function () {
					let current = $( this ).text();
					current = current.replaceAll( ' ', '_' );
					$( this ).text( current );
				} );
				let escapedHTML = existingComment.html().replaceAll( '<br>', '\n' );
				escapedHTML = $.parseHTML( escapedHTML );
				const processedContent = $( escapedHTML ).text();
				textbox.setValue(processedContent);
				saveReplyButton.setLabel( mw.msg( 'inlinecomments-editcomment-publish' ) );
				replyFunc(true, comment, commentIdx);
			}

			closeDiscussionButton.$element.click( closeDiscussionFunc );
			replyButton.$element.unbind('click').click( function () {
				replyFunc();
			} );
			$( toolsDiv ).append( replyButton.$element, closeDiscussionButton.$element );
			aside.appendChild( toolsDiv );
			initializeEditFunc();
		},
		/**
		 * Get the vertical offset from the container element
		 *
		 * For positioned elements and tables, we have to check recursively
		 */
		getOffset: function ( elm ) {
			var offset = 0, cur = elm;
			do {
				offset += cur.offsetTop;
				cur = cur.offsetParent;
			} while (
				cur &&
				cur !== this.opts.offsetReference
			);
			return offset;
		}
	};
	mw.inlineComments = mw.inlineComments || {};
	mw.inlineComments.SidenoteManager = SidenoteManager;
} )();
