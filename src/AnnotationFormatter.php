<?php
namespace MediaWiki\Extension\InlineComments;

use Html;
use Linker;
use RemexHtml\Serializer\HtmlFormatter;
use RemexHtml\Serializer\SerializerNode;

// Based on MediaWiki\Html\HtmlHelper

class AnnotationFormatter extends HtmlFormatter {

	// snData position constants
	public const START = 0;
	public const END = 1;
	public const SIBLING_END = 2;

	// snData indicies
	public const KEY = 0;
	public const OFFSET = 1;
	public const CHILD = 2;
	public const POSITION = 3;

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
			$asideContent = '';
			foreach ( $annotation['comments'] as $comment ) {
				// User handling seems likely to be a forwards compatibility risk.
				$userId = $comment['userId'];
				$username = $comment['username'];
				// FIXME, don't show missing.
				// TODO: Do we want any formatting in comments? Newlines to <br>?
				$asideContent .= Html::element( 'p', [], $comment['comment'] );
				$asideContent .= $this->formatUser( $userId, $username );
			}
			$res .= Html::rawElement(
				'aside',
				[
					'id' => 'mw-inlinecomment-aside-' . $annotation['id'],
					'class' => 'mw-inlinecomment-aside',
				],
				$asideContent
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
	 * When we encounter a text node, insert <span>'s in it to highlight text
	 *
	 * @inheritDoc
	 */
	public function characters( SerializerNode $parent, $text, $start, $length ) {
		if ( !( $parent->snData['annotations'] ?? null ) ) {
			return parent::characters( $parent, $text, $start, $length );
		}

		$childNumb = count( $parent->children );
		$data = array_filter(
			$parent->snData['annotations'],
			static function ( $val ) use ( $childNumb ) {
				return $val[self::CHILD] === $childNumb;
			}
		);
		usort( $data, static function ( $a, $b ) {
			return $a[self::OFFSET] - $b[self::OFFSET];
		} );
		$curIndex = $start;
		$newContents = '';
		foreach ( $data as $item ) {
			$index = $item[self::OFFSET] + $start;
			$advance = $index - $curIndex;
			if ( $advance ) {
				$newContents .= parent::characters( $parent, $text, $curIndex, $advance );
			}
			switch ( $item[self::POSITION] ) {
				case self::START:
					$newContents .= Html::openElement(
						'span',
						[
							'class' => [
								'mw-annotation-highlight',
								'mw-annotation-' . $this->annotations[$item[0]]['id']
							],
							'title' => $this->annotations[$item[0]]['comments'][0]['comment'],
							'data-mw-highlight-id' => $this->annotations[$item[0]]['id'],
						]
					);
					break;
				case self::END:
					$newContents .= Html::closeElement( 'span' );
					break;
				// It should be impossible for sibling end to have valid child offset.
				case self::SIBLING_END:
				default:
					throw new LogicException( "Unrecognized position" );
			}
			$curIndex += $advance;
		}
		$newContents .= parent::characters( $parent, $text, $curIndex, $start + $length - $curIndex );
		return $newContents;
	}

	/**
	 * Go through the html and add annotated spans where appropriate to highlight
	 *
	 * The snData has been pre-populated by AnnotationTreeHandler
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
		$spanEnds = '';
		foreach ( $data as $item ) {
			if ( $item[self::POSITION] === self::SIBLING_END ) {
				// We have to close a tag so it can be
				// reopened inside the next sibling element.
				$spanEnds .= '</span>';
			}
		}
		return parent::element( $parent, $node, $contents . $spanEnds );
	}

	/**
	 * Do not output a doctype.
	 * @inheritDoc
	 */
	public function startDocument( $fragmentNamespace, $fragmentName ) {
		return '';
	}
}
