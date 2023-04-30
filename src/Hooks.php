<?php
namespace MediaWiki\Extension\InlineComments;

use Config;
use MediaWiki\Hook\BeforePageDisplayHook;

class Hooks implements BeforePageDisplayHook {

	/** @var AnnotationFetcher */
	private AnnotationFetcher $annotationFetcher;
	/** @var AnnotationMarker */
	private AnnotationMarker $annotationMarker;
	/** @var Config */
	private $config;

	/**
	 * @param AnnotationFetcher $annotationFetcher
	 * @param AnnotationMarker $annotationMarker
	 * @param Config $config
	 */
	public function __construct(
		AnnotationFetcher $annotationFetcher,
		AnnotationMarker $annotationMarker,
		Config $config
	) {
		$this->annotationFetcher = $annotationFetcher;
		$this->annotationMarker = $annotationMarker;
		$this->config = $config;
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

		// TODO: Should page previews have annotations?
		$annotations = $this->annotationFetcher->getAnnotations( (int)$out->getRevisionId() );
		if ( !$annotations || $annotations->isEmpty() ) {
			return;
		}

		$html = $out->getHtml();
		$out->clearHtml();
		$result = $this->annotationMarker->markUp( $html, $annotations );
		$out->addHtml( $result );

		$out->addModules( 'ext.inlineComments.sidenotes' );
		$out->addModuleStyles( 'ext.inlineComments.sidenotes.styles' );
	}

	/**
	 * Setup function.
	 *
	 * Make forwards compatible with 1.39
	 */
	public static function setup() {
		if (
			!class_exists( \RemexHtml\HTMLData::class ) &&
			class_exists( \Wikimedia\RemexHtml\HTMLData::class )
		) {
			class_alias(
				\Wikimedia\RemexHtml\Serializer\HtmlFormatter::class,
				\RemexHtml\Serializer\HtmlFormatter::class
			);
			class_alias(
				\Wikimedia\RemexHtml\Serializer\SerializerNode::class,
				\RemexHtml\Serializer\SerializerNode::class
			);
			class_alias(
				\Wikimedia\RemexHtml\Serializer\Serializer::class,
				\RemexHtml\Serializer\Serializer::class
			);
			class_alias(
				\Wikimedia\RemexHtml\Tokenizer\Tokenizer::class,
				\RemexHtml\Tokenizer\Tokenizer::class
			);
			class_alias(
				\Wikimedia\RemexHtml\TreeBuilder\Dispatcher::class,
				\RemexHtml\TreeBuilder\Dispatcher::class
			);
			class_alias(
				\Wikimedia\RemexHtml\TreeBuilder\TreeBuilder::class,
				\RemexHtml\TreeBuilder\TreeBuilder::class
			);
			class_alias(
				\Wikimedia\RemexHtml\TreeBuilder\Element::class,
				\RemexHtml\TreeBuilder\Element::class
			);
			class_alias(
				\Wikimedia\RemexHtml\TreeBuilder\RelayTreeHandler::class,
				\RemexHtml\TreeBuilder\RelayTreeHandler::class
			);
			class_alias(
				\Wikimedia\RemexHtml\TreeBuilder\TreeHandler::class,
				\RemexHtml\TreeBuilder\TreeHandler::class
			);
			class_alias(
				\Wikimedia\RemexHtml\HTMLData::class,
				\RemexHtml\HTMLData::class
			);
		}
	}
}
