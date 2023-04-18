<?php
namespace MediaWiki\Extension\InlineComments;

use MediaWiki\Hook\MediaWikiServicesHook;
use MediaWiki\Revision\SlotRoleRegistry;

class ServiceHooks implements MediaWikiServicesHook {

	/**
	 * @inheritDoc
	 */
	public function onMediaWikiServices( $services ) {
		$services->addServiceManipulator(
			'SlotRoleRegistry',
			static function ( SlotRoleRegistry $registry ) {
				$registry->defineRoleWithModel(
					AnnotationContent::SLOT_NAME,
					AnnotationContent::CONTENT_MODEL,
					[ 'display' => 'none' ]
				);
			}
		);
	}
}
