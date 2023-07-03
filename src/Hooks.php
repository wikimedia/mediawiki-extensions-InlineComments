<?php
namespace MediaWiki\Extension\InlineComments;

use CommentStoreComment;
use Config;
use DeferredUpdates;
use Language;
use LogicException;
use MediaWiki\Hook\BeforePageDisplayHook;
use MediaWiki\Permissions\PermissionManager;
use MediaWiki\Storage\Hook\MultiContentSaveHook;
use MediaWiki\User\Hook\UserGetReservedNamesHook;
use Title;
use User;
use WikiPage;

class Hooks implements BeforePageDisplayHook, MultiContentSaveHook, UserGetReservedNamesHook {

	/** @var AnnotationFetcher */
	private AnnotationFetcher $annotationFetcher;
	/** @var AnnotationMarker */
	private AnnotationMarker $annotationMarker;
	/** @var PermissionManager */
	private $permissionManager;
	/** @var Language */
	private $contentLanguage;
	/** @var Config */
	private $config;
	/** @var bool Variable to guard against indef loop when removing comments */
	private static $loopCheck = false;

	/**
	 * @param AnnotationFetcher $annotationFetcher
	 * @param AnnotationMarker $annotationMarker
	 * @param PermissionManager $permissionManager
	 * @param Language $contentLanguage
	 * @param Config $config
	 */
	public function __construct(
		AnnotationFetcher $annotationFetcher,
		AnnotationMarker $annotationMarker,
		PermissionManager $permissionManager,
		Language $contentLanguage,
		Config $config
	) {
		$this->annotationFetcher = $annotationFetcher;
		$this->annotationMarker = $annotationMarker;
		$this->permissionManager = $permissionManager;
		$this->contentLanguage = $contentLanguage;
		$this->config = $config;
	}

	/**
	 * @inheritDoc
	 */
	public function onBeforePageDisplay( $out, $skin ): void {
		if (
			!$out->getTitle() ||
			$out->getTitle()->getNamespace() < 0 ||
			!$out->getTitle()->exists() ||
			$out->getRequest()->getVal( 'veaction' ) === 'edit' ||
			$out->getRequest()->getVal( 'action', 'view' ) !== 'view'
		) {
			// TODO: In the future we might not exit on action=submit
			// and instead show the previous annotations on page preview.
			return;
		}
		$canEditComments = $this->permissionManager->userCan(
			'inlinecomments-add',
			$out->getUser(),
			$out->getTitle(),
			PermissionManager::RIGOR_QUICK
		);
		if (
			$out->getRequest()->getVal( 'action', 'view' ) === 'view' &&
			$out->isRevisionCurrent() &&
			$canEditComments
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
		$result = $this->annotationMarker->markUp(
			$html,
			$annotations,
			$out->getLanguage(),
			$out->getUser()
		);
		$out->addHtml( $result );

		$out->addJsConfigVars( 'wgInlineCommentsCanEdit', $canEditComments );
		$out->addModules( 'ext.inlineComments.sidenotes' );
		$out->addModuleStyles( 'ext.inlineComments.sidenotes.styles' );
	}

	/**
	 * @inheritDoc
	 */
	public function onMultiContentSave( $renderedRevision, $user, $summary, $flags,
		$status
		) {
		if ( !$this->config->get( 'InlineCommentsAutoResolveComments' ) || self::$loopCheck ) {
			return;
		}

		$rev = $renderedRevision->getRevision();
		if ( !$rev->hasSlot( AnnotationContent::SLOT_NAME ) ) {
			return;
		}
		$annotations = $rev->getSlot( AnnotationContent::SLOT_NAME )->getContent();
		if ( !$annotations instanceof AnnotationContent ) {
			throw new LogicException( "Expected AnnotationContent content type" );
		}
		if ( $annotations->isEmpty() ) {
			return;
		}

		DeferredUpdates::addCallableUpdate(
			function () use ( $renderedRevision, $annotations, $user ) {
				self::$loopCheck = true;
				$rev = $renderedRevision->getRevision();
				// Do this deferred, because parsing can take a lot of time.
				$html = $renderedRevision->getRevisionParserOutput()->getText();
				$sysUser = User::newSystemUser( 'InlineComments bot' );
				[ , $unused ] = $this->annotationMarker->markUpAndGetUnused(
					$html,
					$annotations,
					$this->contentLanguage,
					$sysUser
				);
				if ( in_array( true, $unused ) ) {
					$count = 0;
					foreach ( $unused as $key => $value ) {
						if ( $value ) {
							$annotations = $annotations->removeItem( $key );
							$count++;
						}
					}
					$wp = WikiPage::factory( Title::newFromLinkTarget( $rev->getPageAsLinkTarget() ) );
					$pageUpdater = $wp->newPageUpdater( $sysUser );
					$prevRevision = $pageUpdater->grabParentRevision();
					if (
						!$prevRevision ||
						!$prevRevision->hasSlot( AnnotationContent::SLOT_NAME ) ||
						$prevRevision->getSha1() !== $rev->getSha1()
					) {
						return;
					}
					$pageUpdater->setContent( AnnotationContent::SLOT_NAME, $annotations );
					$summary = CommentStoreComment::newUnsavedComment(
						wfMessage( 'inlinecomments-editsummary-autoresolve' )
							->numParams( $count )
							->params( $user->getName() )
							->inContentLanguage()
							->text()
					);
					$pageUpdater->saveRevision(
						$summary,
						EDIT_INTERNAL | EDIT_UPDATE | EDIT_MINOR | EDIT_FORCE_BOT
					);
				}
			},
			DeferredUpdates::POSTSEND,
			wfGetDB( DB_PRIMARY )
		);
	}

	/**
	 * @inheritDoc
	 */
	public function onUserGetReservedNames( &$reservedUsernames ) {
		$reservedUsernames[] = 'InlineComments bot';
	}
}
