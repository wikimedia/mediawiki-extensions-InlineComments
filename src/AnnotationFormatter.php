<?php
namespace MediaWiki\Extension\InlineComments;

use Wikimedia\RemexHtml\Serializer\HtmlFormatter;

// Based on MediaWiki\Html\HtmlHelper

class AnnotationFormatter extends HtmlFormatter {

	private $annotations;

	public function __construct( $options, $annotations ) {
		parent::__construct( $options );
		$this->annotations = $annotations;
	}

	public function element( SerializerNode $parent, SerializerNode, $node, $contents ) {


	}
}
