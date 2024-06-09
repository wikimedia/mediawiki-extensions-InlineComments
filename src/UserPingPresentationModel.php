<?php

namespace MediaWiki\Extension\InlineComments;

class UserPingPresentationModel extends \EchoEventPresentationModel {
	/** @inheritDoc */
	public function getIconType() {
		return 'placeholder';
	}

	/** @inheritDoc */
	public function getPrimaryLink() {
		return [
			'url' => $this->event->getExtraParam( 'title' )->getFullURL(),
			'label' => $this->msg( 'inlinecomments-mention-label' )->text(),
		];
	}

	/** @inheritDoc */
	public function getHeaderMessage() {
		$msg = $this->msg( 'inlinecomments-title-message' );
		$msg->params( $this->event->getExtraParam( 'title' ), $this->event->getExtraParam( 'commentor' ) );
		return $msg;
	}

}
