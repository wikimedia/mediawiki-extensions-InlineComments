<?php
namespace MediaWiki\Extension\InlineComments;

use CommentStoreComment;
use Config;
use DeferredUpdates;
use EchoEvent;
use Language;
use LogicException;
use MediaWiki\Api\Hook\ApiParseMakeOutputPageHook;
use MediaWiki\Hook\BeforePageDisplayHook;
use MediaWiki\Hook\OutputPageBeforeHTMLHook;
use MediaWiki\Page\WikiPageFactory;
use MediaWiki\Permissions\PermissionManager;
use MediaWiki\Storage\Hook\MultiContentSaveHook;
use MediaWiki\User\Hook\UserGetReservedNamesHook;
use RequestContext;
use Title;
use User;

class Hooks implements
	BeforePageDisplayHook,
	MultiContentSaveHook,
	UserGetReservedNamesHook,
	ApiParseMakeOutputPageHook,
	OutputPageBeforeHTMLHook
{

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
	/** @var WikiPageFactory */
	private $wikiPageFactory;
	/** @var bool Variable to guard against indef loop when removing comments */
	private static $loopCheck = false;

	/**
	 * @param AnnotationFetcher $annotationFetcher
	 * @param AnnotationMarker $annotationMarker
	 * @param PermissionManager $permissionManager
	 * @param Language $contentLanguage
	 * @param Config $config
	 * @param WikiPageFactory $wikiPageFactory
	 */
	public function __construct(
		AnnotationFetcher $annotationFetcher,
		AnnotationMarker $annotationMarker,
		PermissionManager $permissionManager,
		Language $contentLanguage,
		Config $config,
		WikiPageFactory $wikiPageFactory
	) {
		$this->annotationFetcher = $annotationFetcher;
		$this->annotationMarker = $annotationMarker;
		$this->permissionManager = $permissionManager;
		$this->contentLanguage = $contentLanguage;
		$this->config = $config;
		$this->wikiPageFactory = $wikiPageFactory;
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
		// Check if InlineComments should be enabled for the current page's namespace
		if ( !in_array( $out->getTitle()->getNamespace(), $this->config->get( 'InlineCommentsNamespaces' ) ) ) {
			return;
		}

		// Also exit if user can't view comments.
		$canViewComments = $this->permissionManager->userHasRight(
			$out->getUser(),
			'inlinecomments-view'
		);
		if ( !$canViewComments ) {
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
			$out->getUser(),
			$out->getTitle()
		);
		$out->addHtml( $result );

		$out->addJsConfigVars( 'wgInlineCommentsCanEdit', $canEditComments );
		$out->addModules( 'ext.inlineComments.sidenotes' );
		$out->addModuleStyles( 'ext.inlineComments.sidenotes.styles' );
	}

	/**
	 * @note This is a bit of a hack so that visual editor will add our module
	 * after save if it doesn't do a reload.
	 * @inheritDoc
	 */
	public function onApiParseMakeOutputPage( $module, $output ) {
		$params = $module->extractRequestParams();
		// Try to get original parameters.
		$origAction = RequestContext::getMain()->getRequest()->getVal( 'action' );
		if ( isset( $params['oldid'] ) && $origAction === 'visualeditoredit' ) {
			// This is not set by default in API but we need it.
			$output->setRevisionId( $params['oldid'] );
			$output->addHeadItem( 'InlineCommentsAPIParse', '' );
		}
	}

	/**
	 * @inheritDoc
	 */
	public function onOutputPageBeforeHTML( $out, &$text ) {
		if ( !$out->hasHeadItem( 'InlineCommentsAPIParse' ) ) {
			return;
		}

		if ( strpos( $text, '<div class="mw-parser-output">' ) === false ) {
			return;
		}
		$title = $out->getTitle();
		if ( !$title || $title->getNamespace() < 0 || !$title->exists() ) {
			return;
		}

		$canViewComments = $this->permissionManager->userCan(
			'inlinecomments-view',
			$out->getUser(),
			$out->getTitle(),
			PermissionManager::RIGOR_FULL
		);
		if ( !$canViewComments ) {
			return;
		}

		$canEditComments = $this->permissionManager->userCan(
			'inlinecomments-add',
			$out->getUser(),
			$out->getTitle(),
			PermissionManager::RIGOR_QUICK
		);
		if ( $canEditComments ) {
			// If we loaded ?veaction=edit directly (not clicking edit tab)
			// ensure we can edit comments after saving.
			$out->addModules( 'ext.inlineComments.makeComment' );
			$out->addJsConfigVars( 'wgInlineCommentsCanEdit', true );
		}
		$annotations = $this->annotationFetcher->getAnnotations( (int)$out->getRevisionId() );
		if ( !$annotations || $annotations->isEmpty() ) {
			// Don't bother if the page is unannotated.
			return;
		}

		$out->addModules( [ 'ext.inlineComments.forceReload' ] );
	}

	/**
	 * @inheritDoc
	 */
	public function onMultiContentSave( $renderedRevision, $user, $summary, $flags,
		$status
		) {
		if ( !$this->config->get( 'InlineCommentsAutoDeleteComments' ) || self::$loopCheck ) {
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
				$title = Title::newFromLinkTarget( $rev->getPageAsLinkTarget() );
				// Do this deferred, because parsing can take a lot of time.
				$html = $renderedRevision->getRevisionParserOutput()->getText();
				$sysUser = User::newSystemUser( 'InlineComments bot' );
				[ , $unused ] = $this->annotationMarker->markUpAndGetUnused(
					$html,
					$annotations,
					$this->contentLanguage,
					$sysUser,
					$title
				);
				if ( in_array( true, $unused ) ) {
					$count = 0;
					foreach ( $unused as $key => $value ) {
						if ( $value ) {
							$annotations = $annotations->removeItem( $key );
							$count++;
						}
					}
					$wp = $this->wikiPageFactory->newFromTitle( $title );
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
					$pageUpdater->addTag( 'inlinecomments' );
					$summary = CommentStoreComment::newUnsavedComment(
						wfMessage( 'inlinecomments-editsummary-autoclose' )
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
	 * @param EchoEvent $event
	 * @param User[] &$users
	 */
	public static function onEchoGetDefaultNotifiedUsers( $event, &$users ) {
		$extra = $event->getExtra();
		if ( $event->getType() == "inlinecomments-mention" ) {
			$newUsers = $extra['users'];
			$users = array_merge( $users, $newUsers );
		}
		if ( $event->getType() == "inlinecomments-title-notify" ) {
			$users[] = $extra['initiator'];
		}
	}

	/**
	 * @param array &$echoNotifications
	 * @param array $echoNotificationCategories
	 */
	public static function onBeforeCreateEchoEvent( &$echoNotifications, $echoNotificationCategories ) {
		$echoNotifications['inlinecomments-mention'] = [
			'section' => 'alert',
			'category' => 'mention',
			'primary-link' => [
				'message' => 'inlinecomments-primary-message',
				'destination' => 'title'
			],
			'presentation-model' => UserPingPresentationModel::class,
			'title-message' => 'inlinecomments-title-message',
			'title-params' => [ 'title' ],
			'bundle' => [
				'web' => true
			],
		];
		$echoNotifications['inlinecomments-title-notify'] = [
			'section' => 'alert',
			'category' => 'mention',
			'primary-link' => [
				'message' => 'inlinecomments-primary-message',
				'destination' => 'title'
			],
			'title-message' => 'inlinecomments-title-reply-message',
			'presentation-model' => UpdatePresentationModel::class,
			'title-params' => [ 'title' ],
			'bundle' => [
				'web' => true
			],
		];
	}

	/**
	 * @inheritDoc
	 */
	public static function onListDefinedTags( &$tags ) {
		$tags[] = 'inlinecomments';
		return true;
	}

	/**
	 * @inheritDoc
	 */
	public static function onChangeTagsListActive( &$tags ) {
		$tags[] = 'inlinecomments';
		return true;
	}

	/**
	 * @inheritDoc
	 */
	public function onUserGetReservedNames( &$reservedUsernames ) {
		$reservedUsernames[] = 'InlineComments bot';
	}

	public static function setDefaultNamespaces() {
		if ( !isset( $GLOBALS['wgInlineCommentsNamespaces'] ) ) {
			$GLOBALS['wgInlineCommentsNamespaces'] = $GLOBALS['wgContentNamespaces'];
		}
	}
}
