<?php
namespace MediaWiki\Extension\InlineComments;

class AnnotationFetcher {
	public const SERVICE_NAME = "InlineComments:AnnotationFetcher";

	private $revLookup;

	public function __construct( $revLookup ) {
		$this->revLookup = $revLookup;
	}

	public function getAnnotations( $revId ): ?AnnotationContent {
		if ( !$revId ) {
			return null;
		} 
		$revision = $this->revLookup->getRevisionById( $revId );
		if ( $revision->hasSlot( AnnotationContent::SLOT_NAME ) ) {
			return $revision->getContent( AnnotationContent::SLOT_NAME );
		}

		return null;
	}
}
