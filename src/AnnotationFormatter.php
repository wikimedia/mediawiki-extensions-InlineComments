<?php
namespace MediaWiki\Extension\InlineComments;

use Language;
use LogicException;
use MediaWiki\Html\Html;
use MediaWiki\Permissions\PermissionManager;
use MediaWiki\Title\Title;
use RequestContext;
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

	/** @var array Annotations (as in array, not a content obect) */
	private array $annotations;

	/** @var callable Callback to find out which annotations are used */
	private $annotationsToSkipCB;

	/** @var Language for formatting timestamps */
	private Language $reqLanguage;

	/** @var User for formatting timestamps */
	private User $reqUser;
	private AnnotationUtils $utils;
	private PermissionManager $permissionManager;

	/** @var Title for checking admin permission */
	private Title $reqTitle;

	/**
	 * @param array $options Options for the html formatter base class
	 * @param array $annotations
	 * @param callable $annotationsToSkipCB Callback to give an array of annotations to not show
	 * @param Language $reqLanguage
	 * @param User $reqUser
	 * @param AnnotationUtils $utils
	 * @param PermissionManager $permissionManager
	 * @param Title $reqTitle
	 */
	public function __construct(
		array $options,
		array $annotations,
		callable $annotationsToSkipCB,
		Language $reqLanguage,
		User $reqUser,
		AnnotationUtils $utils,
		PermissionManager $permissionManager,
		Title $reqTitle
	) {
		parent::__construct( $options );
		$this->annotations = $annotations;
		$this->annotationsToSkipCB = $annotationsToSkipCB;
		$this->reqLanguage = $reqLanguage;
		$this->reqUser = $reqUser;
		$this->utils = $utils;
		$this->permissionManager = $permissionManager;
		$this->reqTitle = $reqTitle;
	}

	/**
	 * Get the html for the right hand actual comments
	 *
	 * @return string html
	 */
	private function getAsides(): string {
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
				$commentText = str_replace( "\n", '<br>', htmlspecialchars( $comment['comment'] ) );
				if ( isset( $comment['edited'] ) && $comment['edited'] ) {
					$editedStamp = '<span class="mw-inlinecomments-editedlabel"> '
						. wfMessage( 'parentheses' )->params(
							wfMessage( 'inlinecomments-edited-label' )->plain()
						)->escaped()
						. '</span>';
					$commentText .= $editedStamp;
				}
				if ( isset( $comment['timestamp'] ) ) {
					$timestamp = ' ' . $this->reqLanguage->userTimeAndDate(
						$comment['timestamp'],
						$this->reqUser
					);
				} else {
					$timestamp = '';
				}
				$isAdmin = $this->permissionManager->userCan(
					'inlinecomments-edit-all',
					$this->reqUser,
					$this->reqTitle,
					PermissionManager::RIGOR_QUICK
				);
				$canEdit = !$this->reqUser->isAnon() &&
					( $this->reqUser->getId() == $comment['userId'] || $isAdmin );
				// Disable editing for older revisions of the page
				$request = RequestContext::getMain()->getRequest();
				$canEdit = $canEdit && !$request->getCheck( 'oldid' );
				$asideContent .= $this->utils->renderComment(
					$comment['userId'],
					$comment['username'],
					$timestamp,
					$commentText,
					$canEdit
				)[ 'commentHTML' ];
			}
			$textDiv = Html::rawElement(
				'div',
				[ 'class' => 'mw-inlinecomment-text' ],
				$asideContent
			);
			$res .= Html::rawElement(
				'aside',
				[
					'id' => 'mw-inlinecomment-aside-' . $annotation['id'],
					'class' => 'mw-inlinecomment-aside',
				],
				$textDiv
			);
		}
		return $res . '</div>';
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

		// Try and figure out what annotations were opened in a
		// previous text node and have not been closed yet.
		$pendingEnds = [];
		$pendingStarts = [];
		$endingAnnotations = [];
		foreach ( $parent->snData['annotations'] as $val ) {
			if (
				$val[self::POSITION] === self::END &&
				$val[self::CHILD] < $childNumb
			) {
				$pendingEnds[$val[self::KEY]] = true;
			} elseif (
				$val[self::POSITION] === self::END
			) {
				$endingAnnotations[] = $val[self::KEY];
			} elseif (
				$val[self::POSITION] === self::START
			) {
				$pendingStarts[$val[self::KEY]] = true;
			}
		}
		$pendingAnnotationsStart = array_filter(
			$parent->snData['annotations'],
			static function ( $val ) use ( $childNumb, $pendingEnds ) {
				return $val[self::POSITION] === self::START &&
					$val[self::CHILD] < $childNumb &&
					!isset( $pendingEnds[$val[self::KEY]] );
			}
		);
		usort( $pendingAnnotationsStart, static function ( $a, $b ) {
			if ( $a[self::CHILD] !== $b[self::CHILD] ) {
				return $a[self::CHILD] - $b[self::CHILD];
			}
			return $a[self::OFFSET] - $b[self::OFFSET];
		} );
		$currentOpenStack = array_map(
			static function ( $val ) {
				return $val[self::KEY];
			},
			$pendingAnnotationsStart
		);
		// Data for this particular text node.
		$data = array_filter(
			$parent->snData['annotations'],
			static function ( $val ) use ( $childNumb ) {
				return $val[self::CHILD] === $childNumb;
			}
		);
		usort( $data, static function ( $a, $b ) {
			return $a[self::OFFSET] - $b[self::OFFSET];
		} );
		// We also have to add to the beginning any that are closed
		// here, but started elsewhere.
		foreach ( $endingAnnotations as $key ) {
			if ( !isset( $pendingStarts[$key] ) ) {
				array_push( $currentOpenStack, $key );
			}
		}
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
					$currentOpenStack[] = $item[self::KEY];
					break;
				case self::END:
					// Ensure we nest everything properly with multiple overlapping
					// spans.
					$reopen = '';
					$tmpStack = [];
					if ( count( $currentOpenStack ) === 0 ) {
						// Just in case something goes wrong. Should not happen.
						wfDebugLog( 'InlineComments', 'stack mismatch' );
						// Try and fail safely and just close the span
						$newContents .= Html::closeElement( 'span' ) . '<!-- mismatch -->';
					}
					for ( $i = count( $currentOpenStack ) - 1; $i >= 0; $i-- ) {
						$cur = array_pop( $currentOpenStack );
						$newContents .= Html::closeElement( 'span' );
						if ( $cur === $item[self::KEY] ) {
							break;
						}
						// We want to re-open this span
						$tmpStack[] = $cur;
					}
					for ( $i = count( $tmpStack ) - 1; $i >= 0; $i-- ) {
						$currentOpenStack[] = $tmpStack[$i];
						$newContents .= $this->openSpan( $tmpStack[$i] );
					}
					break;
				// It should be impossible for sibling end to have valid child offset.
				case self::SIBLING_END:
				default:
					throw new LogicException( 'Unrecognized position' );
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
	private function openSpan( int $key ): string {
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
			&& in_array( 'mw-parser-output', explode( ' ', ( $node->attrs->getValues()['class'] ?? '' ) ) )
			// Ensure the parent element has no `mw-parser-output` class
			&& !in_array( 'mw-parser-output', explode( ' ', ( $parent->attrs->getValues()['class'] ?? '' ) ) )
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
	private function hasStartForKey( SerializerNode $node, int $key ): bool {
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
