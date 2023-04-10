<?php
use MediaWiki\Extension\InlineComments\AnnotationFetcher;
use MediaWiki\Extension\InlineComments\AnnotationMarker;
use MediaWiki\MediaWikiServices;

return [
	AnnotationMarker::SERVICE_NAME => static function ( MediaWikiServices $services ): AnnotationMarker {
		return new AnnotationMarker;
	},
	AnnotationFetcher::SERVICE_NAME => static function ( MediaWikiServices $services ): AnnotationFetcher {
		return new AnnotationFetcher( $services->getRevisionLookup() );
	}
];
