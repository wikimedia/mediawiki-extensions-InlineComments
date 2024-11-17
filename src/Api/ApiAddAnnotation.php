<?php

namespace MediaWiki\Extension\InlineComments\Api;

use ApiBase;
use EchoEvent;
use ExtensionRegistry;
use Language;
use LogicException;
use MediaWiki\CommentStore\CommentStoreComment;
use MediaWiki\Extension\InlineComments\AnnotationContent;
use MediaWiki\Extension\InlineComments\AnnotationContentHandler;
use MediaWiki\Extension\InlineComments\AnnotationUtils;
use MediaWiki\Page\WikiPageFactory;
use MediaWiki\Title\Title;
use Wikimedia\ParamValidator\ParamValidator;

class ApiAddAnnotation extends ApiBase {

	/** @var Language */
	private $contentLang;
	/** @var WikiPageFactory */
	private $wikiPageFactory;
	/** @var AnnotationUtils */
	private $utils;

	/**
	 * @param \ApiMain $parent parent module
	 * @param string $name module name
	 * @param Language $lang Language (Expected to be the content language)
	 * @param WikiPageFactory $wpf
	 * @param AnnotationUtils $utils
	 */
	public function __construct(
		$parent,
		$name,
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
		$timestamp = wfTimestampNow();
		if ( !$title || $title->getNamespace() < 0 ) {
			$this->dieWithError( 'inlinecomments-invalidtitle' );
		}
		$this->checkTitleUserPermissions( $title, 'inlinecomments-add' );

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
					'users' => $users,
					'commentor' => $user->getName()
				],
				'title' => $title
			] );
		}
		$actorId = $this->utils->getActorId( $user );
		$item = [
			// Sometimes there is disagreement about if text starts with a newline so left trim.
			'pre' => ltrim( $data['pre'] ),
			'body' => $data['body'],
			'container' => $data['container'],
			// FIXME do we want to enforce max length (Other than 2MB article limit)
			'comments' => [
				[
					'comment' => $data['comment'],
					'actorId' => $actorId,
					'userId' => $user->getId(),
					'username' => $user->getName(),
					'timestamp' => $timestamp,
					'edited' => false
				]
			],
			'id' => (string)mt_rand(),
			'containerAttribs' => [],
			'skipCount' => $data['skipcount']
		];

		if ( isset( $data['containerid'] ) && $data['containerid'] !== '' ) {
			$item['containerAttribs']['id'] = $data['containerid'];
		}

		if ( isset( $data['containerclass'] ) ) {
			$item['containerAttribs']['class'] = explode( ' ', $data['containerclass'] );
		}

		if ( !AnnotationContent::validateItem( $item ) ) {
			$this->dieWithError( 'inlinecomments-invaliditem' );
		}

		$this->addItemToTitle( $title, $item );

		$result = $this->getResult();
		$result->addValue(
			null,
			$this->getModuleName(),
			[
				'success' => true,
				'id' => $item['id'],
				'comment' => $commentHTML
			]
		);
	}

	/**
	 * Add an annotation item to those stored for a specific title
	 *
	 * @param Title $title Page to add annotation to
	 * @param array $item Item to add
	 */
	private function addItemToTitle( Title $title, array $item ) {
		$wp = $this->wikiPageFactory->newFromTitle( $title );
		$pageUpdater = $wp->newPageUpdater( $this->getUser() );

		$prevRevision = $pageUpdater->grabParentRevision();
		if ( !$prevRevision ) {
			$this->dieWithError( 'inlinecomments-missingpage' );
		}

		if ( $prevRevision->hasSlot( AnnotationContent::SLOT_NAME ) ) {
			$content = $prevRevision->getContent( AnnotationContent::SLOT_NAME );
		} else {
			$content = ( new AnnotationContentHandler )->makeEmptyContent();
		}
		if ( !( $content instanceof AnnotationContent ) ) {
			throw new LogicException( 'Unexpected content type' );
		}

		// TODO: In future, we might want to re-render page, check if
		// any annotations don't apply anymore, and remove them at this
		// point.
		$newContent = $content->newWithAddedItem( $item );

		$pageUpdater->setContent( AnnotationContent::SLOT_NAME, $newContent );
		$pageUpdater->addTag( 'inlinecomments' );
		// 70 is chosen very arbitrarily.
		$commentTruncated = $this->contentLang->truncateForVisual(
			$item['comments'][0]['comment'],
			70
		);
		// TODO: Use comment data to make the edit summary follow user language.
		$summary = CommentStoreComment::newUnsavedComment(
			$this->msg( 'inlinecomments-editsummary-add' )
				->inContentLanguage()
				->params( $commentTruncated )
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
	 * Retrieves and formats the current timestamp
	 *
	 * @param string $timestamp The timestamp to format
	 * @return string Formatted current timestamp
	 */
	private function getCurrentTimestamp( $timestamp ) {
		$formattedTimestamp = $this->contentLang->userTimeAndDate( $timestamp, $this->getUser() );
		return ' ' . $formattedTimestamp;
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
				ParamValidator::PARAM_TYPE => 'integer',
			],
			'pre' => [
				ParamValidator::PARAM_TYPE => 'string',
			],
			'body' => [
				ParamValidator::PARAM_REQUIRED => true,
				ParamValidator::PARAM_TYPE => 'string',
			],
			'container' => [
				ParamValidator::PARAM_REQUIRED => true,
				ParamValidator::PARAM_TYPE => 'string',
			],
			'comment' => [
				ParamValidator::PARAM_REQUIRED => true,
				ParamValidator::PARAM_TYPE => 'string',
			],
			'containerid' => [
				ParamValidator::PARAM_TYPE => 'string',
			],
			'containerclass' => [
				ParamValidator::PARAM_TYPE => 'string',
			],
			'skipcount' => [
				ParamValidator::PARAM_TYPE => 'integer',
				ParamValidator::PARAM_REQUIRED => true,
			]
		];
	}
}
