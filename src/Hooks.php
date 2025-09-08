<?php
namespace MediaWiki\Extension\InlineComments;

use Config;
use DeferredUpdates;
use EchoEvent;
use Language;
use LogicException;
use MediaWiki\CommentStore\CommentStoreComment;
use MediaWiki\Hook\BeforePageDisplayHook;
use MediaWiki\MediaWikiServices;
use MediaWiki\Page\WikiPageFactory;
use MediaWiki\Permissions\PermissionManager;
use MediaWiki\Storage\Hook\MultiContentSaveHook;
use MediaWiki\Title\Title;
use MediaWiki\User\Hook\UserGetReservedNamesHook;
use User;

class Hooks implements
	BeforePageDisplayHook,
	MultiContentSaveHook,
	UserGetReservedNamesHook
{
	private AnnotationFetcher $annotationFetcher;
	private AnnotationMarker $annotationMarker;
	private PermissionManager $permissionManager;
	private Language $contentLanguage;
	private Config $config;
	private WikiPageFactory $wikiPageFactory;
	/** @var bool Variable to guard against indef loop when removing comments */
	private static $loopCheck = false;

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
			$out->getRequest()->getRawVal( 'veaction' ) === 'edit' ||
			( $out->getRequest()->getRawVal( 'action' ) ?? 'view' ) !== 'view'
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
			( $out->getRequest()->getRawVal( 'action' ) ?? 'view' ) === 'view' &&
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
			throw new LogicException( 'Expected AnnotationContent content type' );
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
				$html = $renderedRevision->getRevisionParserOutput()->getContentHolderText();
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
			MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection( DB_PRIMARY )
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
