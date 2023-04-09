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

	public function onBeforePageDisplay( $out, $skin ): void {
		$html = $out->getHtml();
		$out->clearHtml();

		$annotations = $this->annotationFetcher->getAnnotations( $out->getTitle() );
		$result = $this->annotationMarker->markUp( $html, $annotations );
		$out->addHtml( $result );
	}
}
