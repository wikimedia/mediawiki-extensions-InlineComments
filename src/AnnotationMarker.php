<?php
namespace MediaWiki\Extension\InlineComments;

// Namespace got renamed to be prefixed with Wikimedia!
use RemexHtml\HTMLData;
use RemexHtml\Serializer\Serializer;
use RemexHtml\Tokenizer\Tokenizer;
use RemexHtml\TreeBuilder\Dispatcher;
use RemexHtml\TreeBuilder\TreeBuilder;

// Based on MediaWiki\Html\HtmlHelper

class AnnotationMarker {
	public const SERVICE_NAME = "InlineComments:AnnotationMarker";

	/**
	 * Add annotations to html
	 *
	 * @param string $html Input html
	 * @param AnnotationContent $annotationsContent The annotations to add
	 * @return string HTML with annotations and asides added
	 */
	public function markUp( string $html, AnnotationContent $annotationsContent ) {
		$annotations = $annotationsContent->getData()->getValue();
		$annotationFormatter = new AnnotationFormatter( [], $annotations );
		$serializer = new Serializer( $annotationFormatter );
		$alist = new AnnotationList( $serializer, $annotations );
		$treeBuilder = new TreeBuilder( $alist );
		$dispatcher = new Dispatcher( $treeBuilder );
		$tokenizer = new Tokenizer( $dispatcher, $html );
		$tokenizer->execute( [
			'fragmentNamespace' => HTMLData::NS_HTML,
			'fragmentName' => 'body'
		] );
		return $serializer->getResult();
	}
}
