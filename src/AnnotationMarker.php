<?php
namespace MediaWiki\Extension\InlineComments;

use Wikimedia\RemexHtml\Serializer\HtmlFormatter;

// Based on MediaWiki\Html\HtmlHelper

class AnnotationMarker extends HtmlFormatter {
	public const SERVICE_NAME = "InlineComments:AnnotationMarker";

	public function markUp( string $html, $annotations ) {

		$serializer = new Serializer( $this );
		$treeBuilder = new TreeBuilder( $serializer );
		$dispatcher = new Dispatcher( $treeBuilder );
		$tokenizer = new Tokenizer( $dispatcher, $htmlFragment );
		$tokenizer = $this->getTokenizer( $html );
		$tokenizer->execute( [
			'fragmentNamespace' => HTMLData::NS_HTML,
			'fragmentName' => 'body'
		] );
		return $serializer->getResults();

	}

	public function element( 
}
