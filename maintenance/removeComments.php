<?php

namespace MediaWiki\Extensions\InlineComments\Maintenance;

$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = __DIR__ . '/../../..';
}
require_once "$IP/maintenance/Maintenance.php";

use CommentStoreComment;
use Maintenance;
use MediaWiki\MediaWikiServices;
use Title;
use User;

class RemoveComments extends Maintenance {

	/** @var \MediaWiki\Page\WikiPageFactory */
	private $wikiPageFactory;

	public function __construct() {
		parent::__construct();
		$this->addDescription(
			"Edit all pages to remove inline comments from current version\n\n" .
			"This is useful for when uninstalling the extension to prevent errors." .
			" It does not affect old revisions of pages or deleted pages"
		);
		$this->setBatchSize( 100 );
		$this->wikiPageFactory = MediaWikiServices::getInstance()->getWikiPageFactory();
	}

	public function execute() {
		$dbr = $this->getDB( DB_REPLICA );
		$slotRoleStore = MediaWikiServices::getInstance()->getSlotRoleStore();
		// Don't use constants so this still works after uninstall.
		$slotId = $slotRoleStore->acquireId( 'inlinecomments' );

		$tables = [
			'page',
			'slots',
		];
		$fields = [
			'page_id',
			'slot_role_id'
		];
		$conds = [];
		$options = [
			'LIMIT' => $this->getBatchSize(),
			'ORDER BY' => 'page_id asc'
		];
		$join = [
			'slots' => [
				'left join',
				[ 'page_latest = slot_revision_id', 'slot_role_id' => $slotId ]
			],
		];

		$res = $dbr->select( $tables, $fields, $conds, __METHOD__, $options, $join );
		while ( $res->numRows() ) {
			$edited = false;
			foreach ( $res as $row ) {
				if ( (int)$row->slot_role_id === $slotId ) {
					$edited = true;
					$this->deleteComments( $row->page_id );
				}
				$conds = [ 'page_id > ' . $dbr->addQuotes( $row->page_id ) ];
			}
			$this->output( '.' );
			if ( $edited ) {
				$this->waitForReplication();
			}
			$res = $dbr->select( $tables, $fields, $conds, __METHOD__, $options, $join );
		}
	}

	/**
	 * @param int $pageId the id of page to delete comments from
	 */
	private function deleteComments( $pageId ) {
		$title = Title::newFromId( $pageId, Title::READ_LATEST );
		$wp = $this->wikiPageFactory->newFromTitle( $title );
		// Replace with User::MAINTENANCE_SCRIPT_USER when we drop support for 1.35
		$user = User::newSystemUser( 'Maintenance script', [ 'steal' => true ] );
		$pageUpdater = $wp->newPageUpdater( $user );
		$prevRevision = $pageUpdater->grabParentRevision();
		if ( !$prevRevision ) {
			// Should not happen
			$this->output( "Page missing $pageId " . $title->getPrefixedText() . "\n" );
			return;
		}
		$pageUpdater->removeSlot( 'inlinecomments' );
		$summary = CommentStoreComment::newUnsavedComment( 'Removing inline comments' );
		$pageUpdater->saveRevision( $summary, EDIT_INTERNAL | EDIT_UPDATE | EDIT_SUPPRESS_RC );
		if ( !$pageUpdater->wasSuccessful() ) {
			$this->output( "Error updating " . $title->getPrefixedText() . "\n" );
			return;
		}

		$this->output( "Done page $pageId: " . $title->getPrefixedText() . ".\n" );
	}
}

$maintClass = RemoveComments::class;
require_once RUN_MAINTENANCE_IF_MAIN;
