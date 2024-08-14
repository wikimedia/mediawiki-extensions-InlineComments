<?php
namespace MediaWiki\Extension\InlineComments;

use IContextSource;
use JsonContentHandler;

class AnnotationContentHandler extends JsonContentHandler {
	public function __construct() {
		parent::__construct( AnnotationContent::CONTENT_MODEL );
	}

	/**
	 * @inheritDoc
	 */
	public function makeEmptyContent() {
		return new AnnotationContent( '[]' );
	}

	/**
	 * @inheritDoc
	 */
	public function getContentClass() {
		return AnnotationContent::class;
	}

	/**
	 * @inheritDoc
	 */
	protected function getSlotDiffRendererWithOptions( IContextSource $context, $options = [] ) {
		return new CommentSlotDiffRenderer();
	}

}
