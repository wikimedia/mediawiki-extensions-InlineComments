<?php
namespace MediaWiki\Extension\InlineComments;

use Html;
use Linker;
use RemexHtml\Serializer\HtmlFormatter;
use RemexHtml\Serializer\SerializerNode;

// Based on MediaWiki\Html\HtmlHelper

class AnnotationFormatter extends HtmlFormatter {

	public const START = 0;
	public const END = 1;

	/**
	 * @var array Annotations (as in array, not a content obect)
	 */
	private $annotations;

	/**
	 * @param array $options Options for the html formatter base class
	 * @param array $annotations
	 */
	public function __construct( $options, $annotations ) {
		parent::__construct( $options );
		$this->annotations = $annotations;
	}

	/**
	 * Get the html for the right hand actual comments
	 *
	 * @return string html
	 */
	private function getAsides() {
		if ( !$this->annotations ) {
			return '';
		}

		$res = '<div id="mw-inlinecomment-annotations">';
		foreach ( $this->annotations as $annotation ) {
			// User handling seems likely to be a forwards compatibility risk.
			$userId = $annotation['userId'];
			$username = $annotation['username'];
			// FIXME, don't show missing.
			$res .= Html::rawElement(
				'aside',
				[
					'id' => 'mw-inlinecomment-aside-' . $annotation['id'],
					'class' => 'mw-inlinecomment-aside',
				],
				// TODO: Do we want any formatting in comments? Newlines to <br>?
				Html::element( 'p', [], $annotation['comment'] ) .
				$this->formatUser( $userId, $username )
			);
		}
		return $res . '</div>';
	}

	/**
	 * Format the user author line for an annotation
	 *
	 * @todo MW is eventually moving to actor, we should be forward-compatible.
	 * @param int $userId Will be 0 for unregistered users
	 * @param string $username
	 * @return string HTML
	 */
	private function formatUser( $userId, $username ) {
		return Html::rawElement(
			'div',
			[ 'class' => 'mw-inlinecomment-author' ],
			Linker::userLink( $userId, $username ) .
			Linker::userToolLinks( $userId, $username, false, Linker::TOOL_LINKS_NOBLOCK )
		);
	}

	/**
	 * Go through the html and add annotated spans where appropriate to highlight
	 *
	 * The snData has been pre-populated by AnnotationList
	 *
	 * @inheritDoc
	 */
	public function element( SerializerNode $parent, SerializerNode $node, $contents ) {
		if ( $node->name == 'div' && ( $node->attrs->getValues()['class'] ?? '' ) === 'mw-parser-output' ) {
			return parent::element( $parent, $node, $contents ) . $this->getAsides();
		}
		if ( !( $node->snData['annotations'] ?? null ) ) {
			return parent::element( $parent, $node, $contents );
		}

		$data = $node->snData['annotations'];
		usort( $data, static function ( $a, $b ) { return $a[1] - $b[1];
		} );
		$curIndex = 0;
		$newContents = '';
		foreach ( $data as $item ) {
			$index = $item[1];
			$advance = $index - $curIndex;
			if ( $advance ) {
				$newContents .= substr( $contents, $curIndex, $advance );
			}
			if ( $item[2] === self::START ) {
				$newContents .= Html::openElement(
					'span',
					[
						'class' => [ 'mw-annotation-highlight', 'mw-annotation-' . $this->annotations[$item[0]]['id'] ],
						'title' => $this->annotations[$item[0]]['comment'],
						'data-mw-highlight-id' => $this->annotations[$item[0]]['id'],
					]
				);
			} else {
				$newContents .= Html::closeElement( 'span' );
			}
			$curIndex += $advance;
		}
		$newContents .= substr( $contents, $curIndex );
		return parent::element( $parent, $node, $newContents );
	}

	/**
	 * Do not output a doctype.
	 * @inheritDoc
	 */
	public function startDocument( $fragmentNamespace, $fragmentName ) {
		'';
	}
}
