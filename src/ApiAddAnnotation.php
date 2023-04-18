<?php

namespace MediaWiki\Extension\InlineComments;

use ApiBase;
use CommentStoreComment;
use Language;
use LogicException;
use Title;
use Wikimedia\ParamValidator\ParamValidator;
use WikiPage;

class ApiAddAnnotation extends ApiBase {

	/** @var Language */
	private $contentLang;

	/**
	 * @param \ApiMain $parent parent module
	 * @param string $name module name
	 * @param Language $lang Language (Expected to be the content language)
	 */
	public function __construct( $parent, $name, Language $lang ) {
		$this->contentLang = $lang;
		parent::__construct( $parent, $name );
	}

	/**
	 * @inheritDoc
	 */
	public function execute() {
		$this->checkUserRightsAny( 'inlinecomments-add' );

		$user = $this->getUser();
		$data = $this->extractRequestParams();
		$item = [
			'pre' => $data['pre'],
			'body' => $data['body'],
			'post' => $data['post'],
			'container' => $data['container'],
			// FIXME do we want to enforce max length (Other than 2MB article limit)
			'comment' => $data['comment'],
			'id' => (string)mt_rand(),
			'username' => $user->getName(),
			'userId' => $user->getId(),
			'containerAttribs' => []
		];

		if ( isset( $data['containerId'] ) && $data['containerId'] !== '' ) {
			$item['containerAttribs']['id'] = $data['containerId'];
		}

		if ( isset( $data['containerClass'] ) ) {
			$item['containerAttribs']['class'] = explode( ' ', $data['containerClass'] );
		}

		if ( !AnnotationContent::validateItem( $item ) ) {
			$this->dieWithError( 'inlinecomments-invaliditem' );
		}

		$title = $this->getTitleFromTitleOrPageId( $data );
		if ( !$title || $title->getNamespace() < 0 ) {
			$this->dieWithError( 'inlinecomments-invalidtitle' );
		}

		// @phan-suppress-next-line PhanTypeMismatchArgumentNullable
		$this->addItemToTitle( $title, $item );

		$result = $this->getResult();
		$result->addValue(
			null,
			$this->getModuleName(),
			[
				'success' => true,
				'id' => $item['id']
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
		$wp = WikiPage::factory( $title );
		$pageUpdater = $wp->newPageUpdater( $this->getUser() );

		$prevRevision = $pageUpdater->grabParentRevision();
		if ( !$prevRevision ) {
			$this->dieWithError( "inlinecomments-missingpage" );
		}

		if ( $prevRevision->hasSlot( AnnotationContent::SLOT_NAME ) ) {
			$content = $prevRevision->getContent( AnnotationContent::SLOT_NAME );
		} else {
			// FIXME should use AnnotationContentHandler
			$content = new AnnotationContent( '[]' );
		}
		if ( !( $content instanceof AnnotationContent ) ) {
			throw new LogicException( "Unexpected content type" );
		}

		// TODO: In future, we might want to re-render page, check if
		// any annotations don't apply anymore, and remove them at this
		// point.
		$newContent = $content->newWithAddedItem( $item );

		$pageUpdater->setContent( AnnotationContent::SLOT_NAME, $newContent );
		// 70 is chosen very arbitrarily.
		$commentTruncated = $this->contentLang->truncateForVisual( $item['comment'], 70 );
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
			'pre' => [
				ParamValidator::PARAM_REQUIRED => true,
				ParamValidator::PARAM_TYPE => 'string',
			],
			'body' => [
				ParamValidator::PARAM_REQUIRED => true,
				ParamValidator::PARAM_TYPE => 'string',
			],
			// FIXME does required prevent empty string?
			'post' => [
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
			'containerId' => [
				ParamValidator::PARAM_TYPE => 'string',
			],
			'containerClass' => [
				ParamValidator::PARAM_TYPE => 'string',
			],
		];
	}
}
