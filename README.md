InlineComments is a MediaWiki extension to allow users to highlight text on a page
and add a comment to it. All directly from page view. Comments are stored in a separate
slot, so they do not alter the wikitext while still being tied to the page and showing
up on watchlists and recent changes.

## Installing

Copy the extension into your extensions directory in a directory named InlineComments.

Add `wfLoadExtension( 'InlineComments' );` to the end of LocalSettings.php

## Using
If we are a logged in user (or otherwise have the inlinecomments-add user right) you can select some text. A small
icon will appear. You can add a comment by clicking the icon or typing ctrl+alt+m.

A dialog will appear to add your comment. Once you hit save, it will be shown to other users.

If you click on an already existing comment, tools appear to either resolve (delete) the comment or reply to it.

## Configuration

You can set `$wgInlineCommentsAutoResolveComments = false;` in your LocalSettings.php to disable
automatically removing comments that aren't attached to any text in the page.

You can adjust who can make inline comments by adjusting what user groups have the inlinecomments-add
user right using the $wgGroupPermissions variable. The default is logged in users only.

## Uninstalling

You can uninstall the extension by removing `wfLoadExtension( 'InlineComments' );` from LocalSettings.php

Pages that have comments on them will become unaccessible after uninstalling because MediaWiki does
not know how to display them without the extension.

If you want the comment data to be retained you can add the following stub to LocalSettings.php to
tell MediaWiki to simply ignore all comments and not display them.

```
$wgContentHandlers['annotation+json'] = FallbackContentHandler::class;
$wgHooks['MediaWikiServices'][] = static function ( $services ) { 
        $services->addServiceManipulator( 'SlotRoleRegistry', static function ( $reg ) { 
                if ( !$reg->isDefinedRole( 'inlinecomments' ) ) { 
                        $reg->defineRoleWithModel(
                                'inlinecomments',
                                'annotation+json',
                                [ 'display' => 'none' ]
                        );
                }   
        } );
};
```

If you want old comments to be viewable even without the extension installed, you can instead specify just
```
$wgContentHandlers['annotation+json'] = JsonContentHandler::class;
```
to have the comments be displayed as json data.

Alternatively you can run the maintenance script included with this extension to remove stored comment data.

There are two. removeComments.php will make an edit to all current pages, deleting all comments. The comments will
still be present on previous versions, which will leave those versions unviewable without the LocalSettings.php.
This does not delete any data, as all comments are still present on old revisions and can be viewed if the
extension is reinstalled.

There is a second maintenance script, convertComments.php, which can be used to convert the comments to
something mediawiki understands without the extension installed. You can specify --format json to convert
to json output. --format fallback makes MediaWiki just show a not supported message. --format comments undos
the work of the script. The other formats only work if InlineComments is not installed.

cd extensions/InlineComments/maintenance
php removeComments.php
php convertCommentsToFallback.php --format fallback

