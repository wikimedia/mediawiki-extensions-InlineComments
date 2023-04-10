<?php
namespace MediaWiki\Extension\InlineComments;

use MediaWiki\Hook\BeforePageDisplayHook;

class Hooks implements BeforePageDisplayHook {

	private AnnotationFetcher $annotationFetcher;
	private AnnotationMarker $annotationMarker;

	public function __construct( AnnotationFetcher $annotationFetcher, AnnotationMarker $annotationMarker ) {
		$this->annotationFetcher = $annotationFetcher;
		$this->annotationMarker = $annotationMarker;
	}

	/**
	 * @inheritDoc
	 */
	public function onBeforePageDisplay( $out, $skin ): void {
		// Fixme, only if user has rights
		if (
			!$out->getTitle() ||
			$out->getTitle()->getNamespace() < 0 ||
			!$out->getTitle()->exists()
		) {
			return;
		}

		if (
			$out->getRequest()->getVal( 'action', 'view' ) === 'view' &&
			$out->isRevisionCurrent()
		) {
			$out->addModules( 'ext.inlineComments.makeComment' );
		}

		$annotations = $this->annotationFetcher->getAnnotations( $out->getRevisionId() );
		if ( !$annotations ) {
			return;
		}

		$html = $out->getHtml();
		$out->clearHtml();
		$result = $this->annotationMarker->markUp( $html, $annotations );
		$out->addHtml( $result );

		$out->addModules( 'ext.inlineComments.sidenotes' );
		$out->addModuleStyles( 'ext.inlineComments.sidenotes.styles' );
	}
}
