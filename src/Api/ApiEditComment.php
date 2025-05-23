<?php

namespace MediaWiki\Extension\InlineComments\Api;

use ApiBase;
use ApiMain;
use EchoEvent;
use ExtensionRegistry;
use Language;
use LogicException;
use MediaWiki\CommentStore\CommentStoreComment;
use MediaWiki\Extension\InlineComments\AnnotationContent;
use MediaWiki\Extension\InlineComments\AnnotationUtils;
use MediaWiki\Page\WikiPageFactory;
use MediaWiki\Title\Title;
use Wikimedia\ParamValidator\ParamValidator;

class ApiEditComment extends ApiBase {

	private Language $contentLang;
	private WikiPageFactory $wikiPageFactory;
	private AnnotationUtils $utils;

	/**
	 * @param ApiMain $parent parent module
	 * @param string $name module
	 * @param Language $lang Language (Expected to be the content language)
	 * @param WikiPageFactory $wpf
	 * @param AnnotationUtils $utils
	 */
	public function __construct(
		ApiMain $parent,
		string $name,
		Language $lang,
		WikiPageFactory $wpf,
		AnnotationUtils $utils
	) {
		$this->contentLang = $lang;
		$this->wikiPageFactory = $wpf;
		$this->utils = $utils;
		parent::__construct( $parent, $name );
	}

	/**
	 * @inheritDoc
	 */
	public function execute() {
		$data = $this->extractRequestParams();
		$title = $this->getTitleFromTitleOrPageId( $data );
		$existingComment = $data['existing_comment_idx'];
		$timestamp = wfTimestampNow();
		if ( !$title || $title->getNamespace() < 0 ) {
			$this->dieWithError( 'inlinecomments-invalidtitle' );
		}

		$user = $this->getUser();
		$commentText = str_replace( "\n", '<br>', htmlspecialchars( $data['comment'] ) );
		$result = $this->utils->renderComment(
			$user->getId(),
			$user->getName(),
			$this->getCurrentTimestamp( $timestamp ),
			$commentText,
			true
		);
		$commentHTML = $result[ 'commentHTML' ];
		$users = $result[ 'users' ];
		if ( ExtensionRegistry::getInstance()->isLoaded( 'Echo' ) ) {
			EchoEvent::create( [
				'type' => 'inlinecomments-mention',
				'extra' => [
					'title' => $title,
					'users' => $users,
					'commentor' => $user->getName()
				],
				'title' => $title
			] );
		}
		$this->editComment( $title, $data['id'], $data['comment'], $existingComment );

		$result = $this->getResult();
		$result->addValue(
			null,
			$this->getModuleName(),
			[
				'success' => true,
				'comment' => $commentHTML
			]
		);
	}

	/**
	 * Add a reply to an annotation
	 *
	 * @param Title $title Page to add annotation to
	 * @param string $id ID of comment to add it to
	 * @param string $comment Text of comment
	 * @param int $existingCommentIdx Existing comment index
	 */
	private function editComment(
		Title $title,
		string $id,
		string $comment,
		int $existingCommentIdx
	): void {
		$wp = $this->wikiPageFactory->newFromTitle( $title );
		$pageUpdater = $wp->newPageUpdater( $this->getUser() );

		$prevRevision = $pageUpdater->grabParentRevision();
		if ( !$prevRevision ) {
			$this->dieWithError( 'inlinecomments-missingpage' );
		}

		$content = $prevRevision->getContent( AnnotationContent::SLOT_NAME );
		if ( !( $content instanceof AnnotationContent ) ) {
			throw new LogicException( 'Unexpected content type' );
		}

		// Check if the existing comment is made by the current user or if the current user is an edit admin
		$user = $this->getUser();
		$existingCommentAuthor = $content->getCommentAuthor( $id, $existingCommentIdx );
		$canEditAllComments = true;
		try {
			$this->checkTitleUserPermissions( $title, 'inlinecomments-edit-all' );
		} catch ( \Exception $e ) {
			$canEditAllComments = false;
		}
		if ( $user->equals( $existingCommentAuthor ) && !$canEditAllComments ) {
			$this->dieWithError( 'inlinecomments-editcomment-unauthorized-error' );
		}

		// TODO: In future, we might want to re-render page, check if
		// any annotations don't apply anymore, and remove them at this
		// point.
		if ( !$content->hasItem( $id ) ) {
			$this->dieWithError( 'inlinecomments-addcomment-noitembyid' );
		}
		$actorId = $this->utils->getActorId( $user );
		$newContent = $content->editComment( $id, $comment, $user, $existingCommentIdx, $actorId );

		$pageUpdater->setContent( AnnotationContent::SLOT_NAME, $newContent );
		$pageUpdater->addTag( 'inlinecomments' );
		$summary = CommentStoreComment::newUnsavedComment(
			$this->msg( 'inlinecomments-editsummary-editcomment' )
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
	 * Retrieves and formats the current timestamp
	 *
	 * @param string $timestamp The timestamp to format
	 * @return string Formatted current timestamp
	 */
	private function getCurrentTimestamp( string $timestamp ): string {
		$user = $this->getUser();
		$language = $this->getLanguage();
		$formattedTimestamp = $language->userTimeAndDate( $timestamp, $user );
		return ' ' . $formattedTimestamp;
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
			],
			'comment' => [
				ParamValidator::PARAM_REQUIRED => true,
				ParamValidator::PARAM_TYPE => 'string',
			],
			'existing_comment_idx' => [
				ParamValidator::PARAM_REQUIRED => true,
				ParamValidator::PARAM_TYPE => 'integer',
			]
		];
	}

}
