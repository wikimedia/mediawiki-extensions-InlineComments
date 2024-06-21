<?php

namespace MediaWiki\Extension\InlineComments;

class UserPingPresentationModel extends \EchoEventPresentationModel {
	/** @inheritDoc */
	public function getIconType() {
		return 'mention';
	}

	/** @inheritDoc */
	public function getPrimaryLink() {
		return [
			'url' => $this->event->getTitle()->getFullURL(),
			'label' => $this->msg( 'inlinecomments-mention-label' )->text(),
		];
	}

	/** @inheritDoc */
	public function getHeaderMessage() {
		$msg = $this->msg( 'inlinecomments-title-message' );
		$msg->params( $this->event->getTitle(), $this->event->getExtraParam( 'commentor' ) );
		return $msg;
	}

}
