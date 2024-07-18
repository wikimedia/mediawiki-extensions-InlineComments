<?php

namespace MediaWiki\Extension\InlineComments;

class UpdatePresentationModel extends \EchoEventPresentationModel {
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
		$action = $this->event->getExtraParam( 'action' );
		$i18nLabel = 'inlinecomments-title-message';
		if ( $action == 'reply' ) {
			$i18nLabel = 'inlinecomments-title-reply-message';
		} elseif ( $action == 'close' ) {
			$i18nLabel = 'inlinecomments-title-close-message';
		}
		$msg = $this->msg( $i18nLabel );
		$msg->params( $this->event->getTitle(), $this->event->getExtraParam( 'commentor' ) );
		return $msg;
	}

}
