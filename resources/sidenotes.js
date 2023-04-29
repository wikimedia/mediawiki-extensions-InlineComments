( function () {
	var SidenoteManager = function ( notes, opts ) {
		this.opts = opts || {};
		this.opts.padding = this.opts.padding || 10;
		this.opts.idRegex = this.opts.idRegex || /^mw-inlinecomment-aside-/;
		this.opts.annotationClassPrefix = 'mw-annotation-';
		this.opts.selectedClass = this.opts.selectedClass  || 'mw-inlinecomment-selected';
		this.opts.selectedAnnotationClass = this.opts.selectedAnnotationClass  || 'mw-annotation-selected';

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
				// Todo, should check what offsetParent is, and combine if appropriate.
				// In case someone uses position css in page body.
				preferredOffset = annotations[0].offsetTop;
				offsetTiebreaker = annotations[0].offsetLeft;
			}

			this.items[this.items.length] = {
				preferredOffset: preferredOffset,
				offsetTiebreaker: offsetTiebreaker,
				// FIXME do we really need to store this.
				annotations: annotations,
				element: notes[i],

			}

			let id = notes[i].id;
			let that = this;
			notes[i].addEventListener(
				'click',
				function (event) {
					event.stopPropagation();
					that.select( id, preferredOffset );
				}
			);
			this.addTools( notes[i] );
		}

		this.sortItems();
		this.renderUnselected();
	}

	SidenoteManager.prototype = {
		renderUnselected: function () {
			var curOffset = 0;
			for ( var i = 0; i < this.items.length; i++ ) {
				var item = this.items[i];

				if ( item.preferredOffset > curOffset ) {
					curOffset = item.preferredOffset;
				}
				item.element.style.position = 'absolute';
				item.element.style.top = curOffset + 'px';
				curOffset += item.element.offsetHeight;
				curOffset += this.opts.padding;
				item.element.classList.remove( this.opts.selectedClass );
			}
			this.deselectAnnotation();
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
				curOffset -= this.items[i].element.offsetHeight + this.opts.padding;
				if ( curOffset > this.items[i].preferredOffset ) {
					curOffset = this.items[i].preferredOffset;
				}
				this.items[i].element.style.top = curOffset + 'px';
				this.items[i].element.classList.remove( this.opts.selectedClass );
			}

			curOffset = offset + this.items[itemIndex].element.offsetHeight + this.opts.padding;
			for ( var i = itemIndex+1; i < this.items.length; i++ ) {
				if ( curOffset < this.items[i].preferredOffset ) {
					curOffset = this.items[i].preferredOffset;
				}
				this.items[i].element.style.top = curOffset + 'px';
				this.items[i].element.classList.remove( this.opts.selectedClass );

				curOffset += this.items[i].element.offsetHeight + this.opts.padding;
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
				annotations: [],
			};
			element.style.position = 'absolute';
			this.select( element.id, offset );
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
		// Add reply and resolve buttons to an aside
		addTools: function ( aside ) {
			var that = this;
			var asideId = aside.id.replace( this.opts.idRegex, '' );
			var div = document.createElement( 'div' );
			div.className = 'mw-inlinecomment-tools';
			var replyButton = new OO.ui.ButtonInputWidget( {
				label: mw.msg( 'inlinecomments-addcomment-reply' ),
				flags: [ 'progressive' ]
			} );
			var resolveButton = new OO.ui.ButtonInputWidget( {
				label: mw.msg( 'inlinecomments-addcomment-resolve' ),
				flags: [ 'desctructive' ]
			} );

			var textbox = new OO.ui.MultilineTextInputWidget( {
				value: '',
				placeholder: mw.msg( 'inlinecomments-placeholder' )
			} );
			var saveButton = new OO.ui.ButtonInputWidget( {
				label: mw.msg( 'inlinecomments-addcomment-save' ),
				flags: [ 'primary', 'progressive' ]
			} );

			var saveFunc = function () {
				saveButton.setDisabled( true );
				mw.loader.using( 'mediawiki.api', function () {
					var api = new mw.Api();
					var text = textbox.getValue();
					var data = {
						title: mw.config.get( 'wgPageName' ),
						id: asideId,
						action: 'inlinecomments-addreply',
						comment: text
					};
					api.postWithToken( 'csrf', data ).then( function () {
						div.remove();
						var p = document.createElement( 'p' );
						p.textContent = text;
						aside.appendChild( p );
						if ( mw.config.get( 'wgUserName' ) !== null ) {
							var author = document.createElement( 'div' );
							author.className = 'mw-inlinecomment-author';
							author.textContent = mw.config.get( 'wgUserName' );
							aside.appendChild( author );
						}
					} ).fail( function ( code, data ) {
						mw.notify( api.getErrorMessage( data ), { type: 'error' } );
					} );
				} );
			};
			var replyFunc = function () {
				saveButton.$element.click( saveFunc );
				// TODO maybe a cancel button.
				div.replaceChildren( textbox.$element[0], saveButton.$element[0] );
			}
			var resolveFunc = function () {
				resolveButton.setDisabled( true );
				mw.loader.using( 'mediawiki.api', function () {
					var api = new mw.Api();
					var data = {
						title: mw.config.get( 'wgPageName' ),
						id: asideId,
						action: 'inlinecomments-resolve'
					}
					api.postWithToken( 'csrf', data ).then( function () {
						that.remove( aside.id );
						// FIXME need to remove highlighting. 
					} ).fail( function ( code, data ) {
						mw.notify( api.getErrorMessage( data ), { type: 'error' } );
					} );
				} );
			}

			resolveButton.$element.click( resolveFunc );
			replyButton.$element.click( replyFunc );
			$( div ).append( replyButton.$element, resolveButton.$element );
			aside.appendChild( div );
		}
	};
	mw.inlineComments = mw.inlineComments || {};
	mw.inlineComments.SidenoteManager = SidenoteManager;
} )();
