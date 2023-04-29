<?php
namespace MediaWiki\Extension\InlineComments;

// Namespace got renamed to be prefixed with Wikimedia!
use Config;
use RemexHtml\HTMLData;
use RemexHtml\Serializer\Serializer;
use RemexHtml\Tokenizer\Tokenizer;
use RemexHtml\TreeBuilder\Dispatcher;
use RemexHtml\TreeBuilder\TreeBuilder;

// Based on MediaWiki\Html\HtmlHelper

class AnnotationMarker {
	public const SERVICE_NAME = "InlineComments:AnnotationMarker";

	/** @var Config */
	private $config;

	/**
	 * @param Config $config
	 */
	public function __construct( Config $config ) {
		$this->config = $config;
	}

	/**
	 * Add annotations to html
	 *
	 * @param string $html Input html
	 * @param AnnotationContent $annotationContent The annotations to add
	 * @return string HTML with annotations and asides added
	 */
	public function markUp( string $html, AnnotationContent $annotationContent ) {
		return $this->markUpAndGetUnused( $html, $annotationContent )[0];
	}

	/**
	 * Add annotations to html and get unused arrays.
	 *
	 * @param string $html Input html
	 * @param AnnotationContent $annotationsContent The annotations to add
	 * @return array HTML with annotations and asides added, plus array of annotations used.
	 */
	public function markUpAndGetUnused( string $html, AnnotationContent $annotationsContent ) {
		$annotations = $annotationsContent->getData()->getValue();
		// TODO: We may want to set the performance optimisation options.

		$alist = null;
		if ( $this->config->get( 'InlineCommentsAutoResolveComments' ) ) {
			$getUnusedAnnotations = static function () use ( &$alist ) {
				// @phan-suppress-next-line PhanNonClassMethodCall
				return $alist->getUnusedAnnotations();
			};
		} else {
			$getUnusedAnnotations = static function () {
				return [];
			};
		}

		$annotationFormatter = new AnnotationFormatter( [], $annotations, $getUnusedAnnotations );
		$serializer = new Serializer( $annotationFormatter );
		$alist = new AnnotationTreeHandler( $serializer, $annotations );
		$treeBuilder = new TreeBuilder( $alist );
		$dispatcher = new Dispatcher( $treeBuilder );
		$tokenizer = new Tokenizer( $dispatcher, $html );
		$tokenizer->execute( [
			'fragmentNamespace' => HTMLData::NS_HTML,
			'fragmentName' => 'body'
		] );
		return [ $serializer->getResult(), $alist->getUnusedAnnotations() ];
	}
}
