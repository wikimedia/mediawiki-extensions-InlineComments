<?php
namespace MediaWiki\Extension\InlineComments;

use MediaWiki\Revision\RevisionLookup;

class AnnotationFetcher {
	public const SERVICE_NAME = "InlineComments:AnnotationFetcher";

	/** @var RevisionLookup */
	private $revLookup;

	/**
	 * @param RevisionLookup $revLookup
	 */
	public function __construct( RevisionLookup $revLookup ) {
		$this->revLookup = $revLookup;
	}

	/**
	 * Get annotations for a specific revision
	 *
	 * @param int $revId Revision id to lookup
	 * @return ?AnnotationContent
	 */
	public function getAnnotations( int $revId ): ?AnnotationContent {
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
