<?php

namespace MediaWiki\Extensions\InlineComments\Maintenance;

$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = __DIR__ . '/../../..';
}
require_once "$IP/maintenance/Maintenance.php";

use Maintenance;
use MediaWiki\MediaWikiServices;

class ConvertComments extends Maintenance {

	public function __construct() {
		parent::__construct();
		$this->addDescription(
			"Change the content model of inline comments\n\n" .
			"This is useful for when uninstalling the extension to prevent errors." .
			" It does not affect old revisions of pages or deleted pages"
		);
		$this->addOption( 'format',
			'What to convert to. One of json, fallback, or comments. fallback causes' .
			' the comments to display as not supported. json causes them to display as raw' .
			' json data. comments reverses the action of a previous run of the script',
		true, true );
		$this->setBatchSize( 100 );
	}

	public function execute() {
		$dbw = $this->getDB( DB_PRIMARY );
		$slotRoleStore = MediaWikiServices::getInstance()->getSlotRoleStore();
		$contentModelStore = MediaWikiServices::getInstance()->getContentModelStore();
		// Don't use constants so this still works after uninstall.
		$commentsId = $slotRoleStore->acquireId( 'inlinecomments' );
		$newId = null;
		switch ( $this->getOption( 'format' ) ) {
			case 'json':
				$newId = $contentModelStore->acquireId( CONTENT_MODEL_JSON );
				break;
			case 'fallback':
				$newId = $contentModelStore->acquireId( CONTENT_MODEL_UNKNOWN );
				break;
			case 'comments':
				$newId = $contentModelStore->acquireId( 'annotation+json' );
				break;
			default:
				$this->fatalError( "Invalid value for --format" );
		}

		$tables = [
			'slots',
			'content'
		];
		$fields = [
			'slot_revision_id',
			'slot_role_id',
			'content_model',
			'content_id'
		];
		$conds = [ 'slot_content_id = content_id' ];
		$newConds = $conds;
		$options = [
			'LIMIT' => $this->getBatchSize(),
			'ORDER BY' => 'slot_revision_id asc, slot_role_id asc'
		];

		$total = 0;
		$count = 0;
		$skipped = 0;
		$res = $dbw->select( $tables, $fields, $conds, __METHOD__, $options );
		while ( $res->numRows() ) {
			$edited = false;
			foreach ( $res as $row ) {
				$total++;
				if ( (int)$row->slot_role_id === $commentsId ) {
					if ( (int)$row->content_model !== $newId ) {
						$edited = true;
						$dbw->update(
							'content',
							[ 'content_model' => $newId ],
							[ 'content_id' => $row->content_id ],
							__METHOD__
						);
						$count++;
					} else {
						$skipped++;
					}
				}
				$newConds = array_merge( [ $dbw->makeList(
					[
						'slot_revision_id >= ' . $dbw->addQuotes( $row->slot_revision_id ),
						'slot_role_id > ' . $dbw->addQuotes( $row->slot_role_id )
					],
					$dbw::LIST_AND
				) ], $conds );
			}
			$this->output( '.' );
			if ( $edited ) {
				$this->waitForReplication();
			}
			$res = $dbw->select( $tables, $fields, $newConds, __METHOD__, $options );
		}
		$this->output( "\n Updated $count out of $total rows. $skipped rows were already correct.\n" );
	}
}

$maintClass = ConvertComments::class;
require_once RUN_MAINTENANCE_IF_MAIN;
