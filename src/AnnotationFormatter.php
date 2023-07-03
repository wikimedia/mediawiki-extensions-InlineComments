<?php
namespace MediaWiki\Extension\InlineComments;

use Html;
use Language;
use Linker;
use LogicException;
use User;
use Wikimedia\RemexHtml\Serializer\HtmlFormatter;
use Wikimedia\RemexHtml\Serializer\SerializerNode;

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

	/** @var callable Callback to find out which annotations are used */
	private $annotationsToSkipCB;

	/** @var Language for formatting timestamps */
	private $reqLanguage;

	/** @var User for formatting timestamps */
	private $reqUser;

	/**
	 * @param array $options Options for the html formatter base class
	 * @param array $annotations
	 * @param callable $annotationsToSkipCB Callback to give an array of annotations to not show
	 * @param Language $reqLanguage
	 * @param User $reqUser
	 */
	public function __construct(
		$options,
		$annotations,
		callable $annotationsToSkipCB,
		Language $reqLanguage,
		User $reqUser
	) {
		parent::__construct( $options );
		$this->annotations = $annotations;
		$this->annotationsToSkipCB = $annotationsToSkipCB;
		$this->reqLanguage = $reqLanguage;
		$this->reqUser = $reqUser;
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

		$skippable = ( $this->annotationsToSkipCB )();
		$res = '<div id="mw-inlinecomment-annotations">';
		foreach ( $this->annotations as $annotation ) {
			if ( isset( $skippable[$annotation['id']] ) && $skippable[$annotation['id']] ) {
				continue;
			}
			$asideContent = '';
			foreach ( $annotation['comments'] as $comment ) {
				// User handling seems likely to be a forwards compatibility risk.
				$userId = $comment['userId'];
				$username = $comment['username'];
				// TODO: Do we want any formatting in comments? Newlines to <br>?
				$asideContent .= Html::element( 'p', [], $comment['comment'] );
				// Backwards compatibility: timestamp might not have been set
				if ( isset( $comment['timestamp'] ) ) {
					$timestamp = ' ' . $this->reqLanguage->userTimeAndDate(
						$comment['timestamp'],
						$this->reqUser
					);
				} else {
					$timestamp = '';
				}
				$asideContent .= $this->formatAuthor(
					$userId,
					$username,
					$timestamp
				);
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
	 * Format the author line for an annotation with the user and timestamp
	 *
	 * @todo MW is eventually moving to actor, we should be forward-compatible.
	 * @param int $userId Will be 0 for unregistered users
	 * @param string $username
	 * @param string $timestamp
	 * @return string HTML
	 */
	private function formatAuthor( $userId, $username, string $timestamp ) {
		return Html::rawElement(
			'div',
			[ 'class' => 'mw-inlinecomment-author' ],
			Linker::userLink( $userId, $username ) . $timestamp
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

		$childNumb = $parent->snData['curLinkId'];
		if ( $childNumb === null ) {
			return;
		}
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
					$newContents .= $this->openSpan( $item[self::KEY] );
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
	 * Make an opening span for a specific annotation key
	 *
	 * @param int $key Key into annotations array
	 * @return string html span element
	 */
	private function openSpan( $key ) {
		return Html::openElement(
			'span',
			[
				'class' => [
					'mw-annotation-highlight',
					'mw-annotation-' . $this->annotations[$key]['id']
				],
				'title' => $this->annotations[$key]['comments'][0]['comment'],
				'data-mw-highlight-id' => $this->annotations[$key]['id'],
			]
		);
	}

	/**
	 * Go through the html and add annotated spans where appropriate to highlight
	 *
	 * The snData has been pre-populated by AnnotationTreeHandler
	 *
	 * @inheritDoc
	 */
	public function element( SerializerNode $parent, SerializerNode $node, $contents ) {
		if (
			$node->name == 'div'
			&& ( $node->attrs->getValues()['class'] ?? '' ) === 'mw-parser-output'
			// Ensure the parent element has no `mw-parser-output` class
			&& ( $parent->attrs->getValues()['class'] ?? '' ) !== 'mw-parser-output'
		) {
			return parent::element( $parent, $node, $contents ) . $this->getAsides();
		}
		if ( !( $node->snData['annotations'] ?? null ) ) {
			return parent::element( $parent, $node, $contents );
		}

		$data = $node->snData['annotations'];
		$spanEnds = '';
		$spanBegins = '';
		$elmBegins = '';
		foreach ( $data as $item ) {
			if ( $item[self::POSITION] === self::SIBLING_END ) {
				// We have to close a tag so it can be
				// reopened inside the next sibling element.
				$spanEnds .= '</span>';
			}

			if ( $item[self::POSITION] === self::END ) {
				// One of the text children of this element end the span.
				// If we did not start the span here, we should
				// end and restart it, to prevent mis-nesting.
				// TODO: This should be moved to AnnotationTreeFormatter.
				if ( !$this->hasStartForKey( $node, $item[self::KEY] ) ) {
					$elmBegins = '</span>';
					$spanBegins = $this->openSpan( $item[self::KEY] );
				}
			}
		}
		return $elmBegins . parent::element( $parent, $node, $spanBegins . $contents . $spanEnds );
	}

	/**
	 * Check if a node has the start of an annotation.
	 *
	 * @param SerializerNode $node
	 * @param int $key Key into $this->annotations
	 * @return bool
	 */
	private function hasStartForKey( $node, $key ) {
		$data = $node->snData['annotations'];
		foreach ( $data as $item ) {
			if (
				$item[self::KEY] === $key &&
				$item[self::POSITION] === self::START
			) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Do not output a doctype.
	 * @inheritDoc
	 */
	public function startDocument( $fragmentNamespace, $fragmentName ) {
		return '';
	}
}
