<?php
namespace MediaWiki\Extension\InlineComments;

use Html;
use Linker;
use MediaWiki\User\ActorStore;
use MediaWiki\User\UserFactory;
use User;
use Wikimedia\Rdbms\LBFactory;

class AnnotationUtils {
	public const SERVICE_NAME = "InlineComments:AnnotationUtils";

	/** @var UserFactory */
	private $userFactory;
	/** @var ActorStore */
	private $actorStore;
	/** @var LBFactory */
	private $dbLoadBalancerFactory;

	/** @var array */
	private $users;

	/**
	 * @param UserFactory $userFactory
	 */
	public function __construct(
		UserFactory $userFactory,
		LBFactory $dbLoadBalancerFactory,
		ActorStore $actorStore
	) {
		$this->userFactory = $userFactory;
		$this->dbLoadBalancerFactory = $dbLoadBalancerFactory;
		$this->actorStore = $actorStore;
		$this->users = [];
	}

	/**
	 *
	 * @param int $userId
	 * @param string $username
	 * @param string $timestamp
	 * @param string $comment
	 * @param bool $editable
	 * @return array
	 */
	public function renderComment( $userId, $username, $timestamp, $comment, $editable = false ) {
		$this->users = [];
		$commentHTML = preg_replace_callback(
			"/@(\S+)/u",
			[ $this, 'handleUserMention' ],
			$comment
		);
		$commentHTML = Html::rawElement(
			'p',
			[],
			str_replace( "\n", '<br>', $commentHTML )
		);
		$commentHTML .= Html::rawElement(
			'div',
			[ 'class' => 'mw-inlinecomment-author' ],
			Linker::userLink( $userId, $username ) . $timestamp
		);
		$commentHTML = Html::rawElement(
			'div',
			[],
			$commentHTML
		);
		if ( $editable ) {
			$commentHTML .= Html::element(
				'button',
				[ 'class' => 'mw-inlinecomment-editlink', 'title' => wfMessage( 'edit' )->text() ],
				"🖉"
			);
		}
		$commentHTML = Html::rawElement(
			'div',
			[ 'class' => 'mw-inlinecomment-comment' ],
			$commentHTML
		);
		$result = [
			'commentHTML' => $commentHTML,
			'users' => $this->users
		];
		return $result;
	}

	/**
	 * Replace user mention text with appropriate user link
	 *
	 * @param array $matches
	 * @return string replacement
	 */
	private function handleUserMention( $matches ) {
		$match = $matches[1];
		$replacement = "@$match";
		$mentionedUser = $this->userFactory->newFromName( $match );
		if ( $mentionedUser ) {
			$mentionedUserId = $mentionedUser->getId();
			if ( $mentionedUserId != 0 ) {
				$displayName = str_replace( '_', ' ', $match );
				$link = Linker::userLink( $mentionedUserId, $mentionedUser->getName(), $displayName );
				$replacement = "@$link";
				$this->users[] = $mentionedUser;
			}
		}
		return $replacement;
	}

	/**
	 * Returns the actor ID of the user
	 * @param User $user
	 * @return int actorId
	 */
	public function getActorId( $user ) {
		if ( method_exists( $this->dbLoadBalancerFactory, 'getPrimaryDatabase' ) ) {
			// MW 1.40+
			$db = $this->dbLoadBalancerFactory->getPrimaryDatabase();
		} else {
			$db = $this->dbLoadBalancerFactory->getMainLB()->getConnection( DB_PRIMARY );
		}
		$actorId = $this->actorStore->acquireActorId( $user, $db );
		return $actorId;
	}
}
