InlineComments is a MediaWiki extension that allows users to highlight text on a page
and add comments to it, viewable inline within the page.

Comments are stored in a separate MediaWiki "slot", so they do not alter the wikitext,
while still being tied to the page and showing up on watchlists and recent changes.

For more information, see the online documentation at:
https://www.mediawiki.org/wiki/Extension:InlineComments

## Version
InlineComments is currently at version 1.1. It works with MediaWiki version 1.40 and
higher.

## Installing
Copy the extension into your extensions directory in a directory named InlineComments.

Add `wfLoadExtension( 'InlineComments' );` to the end of LocalSettings.php

## Using
If you have the "inlinecomments-add" user right (by default, available to all
logged-in users), if you select any text on a page, a small icon will appear. You can
then add a comment by clicking the icon or typing ctrl+alt+m; a dialog will then
appear to add your comment.

If you click on an already existing comment or discussion, tools appear to either
close (delete) the discussion, or reply to it.

## Configuration

You can set `$wgInlineCommentsAutoDeleteComments = false;` in your LocalSettings.php to disable
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

Alternatively you can run the maintenance scripts included with this extension to remove stored comment data:

- removeComments.php will delete all comments via MediaWiki edits. The comments will still be present on
previous versions within the "inlinecomments" slot, which means that they will become unviewable if/when
InlineComments is uninstalled - although they will of course be viewable again if it is re-installed.

- convertComments.php will convert the comments to something that MediaWiki understands without the extension
installed. You can specify the following parameters:
  - --format json converts to JSON output.
  - --format fallback makes MediaWiki just show a not supported message.
  - --format comments undoes the work of the script.

The "json" and "fallback" formats only work if InlineComments is not installed.

cd extensions/InlineComments/maintenance
php removeComments.php
php convertCommentsToFallback.php --format fallback

