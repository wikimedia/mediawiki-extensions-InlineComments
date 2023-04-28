<?php

namespace MediaWiki\Extension\InlineComments\Api;

use ApiBase;
use CommentStoreComment;
use LogicException;
use MediaWiki\Extension\InlineComments\AnnotationContent;
use MediaWiki\Extension\InlineComments\AnnotationContentHandler;
use Title;
use Wikimedia\ParamValidator\ParamValidator;
use WikiPage;

class ApiResolveAnnotation extends ApiBase {

	/**
	 * @inheritDoc
	 */
	public function execute() {
		$this->checkUserRightsAny( 'inlinecomments-add' );

		$user = $this->getUser();
		$data = $this->extractRequestParams();
		$title = $this->getTitleFromTitleOrPageId( $data );
		if ( !$title || $title->getNamespace() < 0 ) {
			$this->dieWithError( 'inlinecomments-invalidtitle' );
		}

		// @phan-suppress-next-line PhanTypeMismatchArgumentNullable
		$this->removeItemFromTitle( $title, $data['id'] );

		$result = $this->getResult();
		$result->addValue(
			null,
			$this->getModuleName(),
			[
				'success' => true,
			]
		);
	}

	/**
	 * Remove an item from a page.
	 *
	 * @param Title $title Page to remove annotation from
	 * @param string $id Id of annotation to remove
	 */
	private function removeItemFromTitle( Title $title, $id ) {
		$wp = WikiPage::factory( $title );
		$pageUpdater = $wp->newPageUpdater( $this->getUser() );

		$prevRevision = $pageUpdater->grabParentRevision();
		if ( !$prevRevision ) {
			$this->dieWithError( "inlinecomments-missingpage" );
		}

		if ( $prevRevision->hasSlot( AnnotationContent::SLOT_NAME ) ) {
			$content = $prevRevision->getContent( AnnotationContent::SLOT_NAME );
		} else {
			$content = ( new AnnotationContentHandler )->makeEmptyContent();
		}
		if ( !( $content instanceof AnnotationContent ) ) {
			throw new LogicException( "Unexpected content type" );
		}

		// TODO: In future, we might want to re-render page, check if
		// any annotations don't apply anymore, and remove them at this
		// point.
		if ( !$content->hasItem( $id ) ) {
			$this->dieWithError( "inlinecomments-noitembyid" );
		}
		$newContent = $content->removeItem( $id );

		$pageUpdater->setContent( AnnotationContent::SLOT_NAME, $newContent );
		$summary = CommentStoreComment::newUnsavedComment(
			$this->msg( 'inlinecomments-editsummary-resolve' )
				->inContentLanguage()
		);
		// TODO: If someone edits the page between when we ran grabRevision()
		// and saveRevision an exception will happen, which we do not handle gracefully.
		// FIXME: Should comment adds go in RC? Should they be marked minor? Tagged?
		$pageUpdater->saveRevision( $summary, EDIT_INTERNAL | EDIT_UPDATE );
		if ( !$pageUpdater->wasSuccessful() ) {
			$this->dieWithError( 'inlinecomments-saveerror' );
		}
	}

	/**
	 * @inheritDoc
	 */
	public function needsToken() {
		return 'csrf';
	}

	/**
	 * @inheritDoc
	 */
	public function isWriteMode() {
		return true;
	}

	/**
	 * @inheritDoc
	 */
	public function getAllowedParams() {
		return [
			'title' => [
				ParamValidator::PARAM_TYPE => 'string',
			],
			'pageid' => [
				ParamValidator::PARAM_TYPE => 'string',
			],
			'id' => [
				ParamValidator::PARAM_REQUIRED => true,
				ParamValidator::PARAM_TYPE => 'string',
			]
		];
	}
}
