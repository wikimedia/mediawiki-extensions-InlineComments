<?php
namespace MediaWiki\Extension\InlineComments;

use Content;
use MediaWiki\Html\Html;
use MediaWiki\MediawikiServices;
use SlotDiffRenderer;

class CommentSlotDiffRenderer extends SlotDiffRenderer {

	/** @inheritDoc */
	public function addModules( \OutputPage $output ) {
		$output->addModules( 'ext.inlineComments.diff.styles' );
	}

	/**
	 * Get old item if exists by comment ID
	 * @param array $oldData
	 * @param int $id
	 * @return array|null $item
	 */
	private function getOldItem( array $oldData, int $id ): ?array {
		foreach ( $oldData as $oldItem ) {
			if ( $oldItem[ 'id' ] == $id ) {
				return $oldItem;
			}
		}
		return null;
	}

	/**
	 * @inheritDoc
	 */
	public function getDiff( ?Content $oldContent = null, ?Content $newContent = null ) {
		$this->normalizeContents( $oldContent, $newContent, [ AnnotationContent::class, AnnotationContent::class ] );
		$output = '';
		if ( $oldContent instanceof AnnotationContent && $newContent instanceof AnnotationContent ) {
			$oldData = $oldContent->getData()->getValue();
			$newData = $newContent->getData()->getValue();
			$output = Html::openElement( 'div', [ 'class' => 'mw-inlinecomments-diff' ] );

			$processedItems = [];

			foreach ( $newData as $newItem ) {
				$oldItem = $this->getOldItem( $oldData, $newItem['id'] );
				$changed = false;
				$diffHTMLs = [];
				foreach ( $newItem['comments'] as $innerKey => $comment ) {
					$diff = $this->renderJsonObjectDiff(
						$oldItem['comments'][$innerKey] ?? null,
						$comment
					);
					$diffHTMLs[] = $diff['html'];
					if ( $diff[ 'changed' ] ) {
						$changed = true;
					}
				}
				$processedItems[] = $newItem[ 'id' ];
				if ( !$changed ) {
					continue;
				}
				$output .= '<hr>';
				$context = Html::element(
					'b',
					[],
					$newItem[ 'body' ]
				);
				$pre = Html::element(
					'span',
					[],
					$newItem[ 'pre' ]
				);
				$output .= Html::rawElement(
					'p',
					[ 'class' => 'mw-inlinecomments-diff-target-text' ],
					$pre . $context
				);
				foreach ( $diffHTMLs as $change ) {
					$output .= $change;
				}

			}

			foreach ( $oldData as $oldItem ) {
				if ( in_array( $oldItem[ 'id' ], $processedItems ) ) {
					continue;
				}
				$diffHTMLs = [];
				foreach ( $oldItem['comments'] as $innerKey => $comment ) {
					$diff = $this->renderJsonObjectDiff( $comment, null );
					$diffHTMLs[] = $diff['html'];
				}
				$processedItems[] = $oldItem[ 'id' ];
				$output .= '<hr>';
				$context = Html::element(
					'b',
					[],
					$oldItem[ 'body' ]
				);
				$pre = Html::element(
					'span',
					[],
					$oldItem[ 'pre' ]
				);
				$output .= Html::rawElement(
					'p',
					[ 'class' => 'mw-inlinecomments-diff-target-text' ],
					$pre . $context
				);
				foreach ( $diffHTMLs as $change ) {
					$output .= $change;
				}
			}

			$output .= Html::closeElement( 'div' );
		}
		return $output;
	}

	/**
	 * Categorizes the comment
	 * @param array|null $oldItem
	 * @param array|null $newItem
	 * @return array diff
	 */
	private function renderJsonObjectDiff( ?array $oldItem, ?array $newItem ): array {
		$output = '';
		$changed = true;
		if ( $oldItem === null && $newItem !== null ) {
			$newUsername = $this->getUsernameFromActorId( $newItem['actorId'] );
			$output .= Html::rawElement(
				'div',
				[ 'class' => 'mw-inlinecomments-comment-diff' ],
				Html::element( 'span', [ 'class' => 'mw-inlinecomments-diff-sign' ], '+' )
				. Html::rawElement(
					'div',
					[ 'class' => 'mw-inlinecomments-comment-added' ],
					Html::element(
						'span',
						[],
						$newItem['comment']
					) .
					Html::element(
						'span',
						[ 'class' => 'mw-inlinecomments-diff-username' ],
						' —' . $newUsername
					)
				)
			);
		} elseif ( $newItem === null && $oldItem !== null ) {
			$oldUsername = $this->getUsernameFromActorId( $oldItem['actorId'] );
			$output .= Html::rawElement(
				'div',
				[ 'class' => 'mw-inlinecomments-comment-diff' ],
				Html::element( 'span', [ 'class' => 'mw-inlinecomments-diff-sign' ], '-' )
				. Html::rawElement(
					'div',
					[ 'class' => 'mw-inlinecomments-comment-removed' ],
					Html::element(
						'span',
						[],
						$oldItem['comment']
					) .
					Html::element(
						'span',
						[ 'class' => 'mw-inlinecomments-diff-username' ],
						' —' . $oldUsername
					)
				)
			);
		} elseif ( ( $oldItem !== null && $newItem !== null ) && $oldItem['comment'] !== $newItem['comment'] ) {
			$oldUsername = $this->getUsernameFromActorId( $oldItem['actorId'] );
			$oldItemHtml = Html::element(
				'span',
				[ 'class' => 'mw-inlinecomments-comment-changed-old' ],
				$oldItem['comment']
			);
			$output .= Html::rawElement(
				'div',
				[ 'class' => 'mw-inlinecomments-comment-diff' ],
				Html::element( 'span', [ 'class' => 'mw-inlinecomments-diff-sign' ], '±' )
				. Html::rawElement(
					'div',
					[ 'class' => 'mw-inlinecomments-comment-changed' ],
					Html::rawElement(
						'span',
						[],
						$oldItemHtml . ' ' . Html::element(
							'span',
							[],
							$newItem[ 'comment' ]
						)
					) .
					Html::element(
						'span',
						[ 'class' => 'mw-inlinecomments-diff-username' ],
						' —' . $oldUsername
					)
				)
			);
		} else {
			if ( $newItem !== null ) {
				$newUsername = $this->getUsernameFromActorId( $newItem['actorId'] );
				$output .= Html::rawElement(
					'div',
					[ 'class' => 'mw-inlinecomments-comment-unchanged' ],
					Html::element(
						'span',
						[],
						$newItem['comment']
					) .
					Html::element(
						'span',
						[ 'class' => 'mw-inlinecomments-diff-username' ],
						' —' . $newUsername
					)
				);
				$changed = false;
			}
		}
		return [ 'html' => $output, 'changed' => $changed ];
	}

	/**
	 * Fetch username using actorId
	 *
	 * @param int $actorId
	 * @return string
	 */
	private function getUsernameFromActorId( int $actorId ) {
		$services = MediaWikiServices::getInstance();
		$user = $services->getUserFactory()->newFromActorId( $actorId );
		return $user->getName();
	}
}
