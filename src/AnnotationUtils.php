<?php
namespace MediaWiki\Extension\InlineComments;

use Html;
use Linker;
use MediaWiki\User\UserFactory;

class AnnotationUtils {
	public const SERVICE_NAME = "InlineComments:AnnotationUtils";

	/** @var UserFactory */
	private $userFactory;

	/** @var array */
	private $users;

	/**
	 * @param UserFactory $userFactory
	 */
	public function __construct( UserFactory $userFactory ) {
		$this->userFactory = $userFactory;
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
		$user = $this->userFactory->newFromId( $userId );
		if ( $user->isHidden() ) {
			$displayName = Html::element(
				'span',
				[ 'class' => 'history-deleted mw-history-suppressed' ],
				wfMessage( 'rev-deleted-user' )->text()
			);
		} else {
			$displayName = Linker::userLink( $userId, $username );
		}
		$commentHTML .= Html::rawElement(
			'div',
			[ 'class' => 'mw-inlinecomment-author' ],
			$displayName . $timestamp
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
}
