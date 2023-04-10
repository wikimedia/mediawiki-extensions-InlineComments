<?php
namespace MediaWiki\Extension\InlineComments;

use JsonContent;
use FormatJson;
use LogicException;

class AnnotationContent extends JsonContent {
	const CONTENT_MODEL = 'annotation+json';
	const SLOT_NAME = 'inlinecomments';

	public function __construct( $text, $modelId = null ) {
		parent::__construct( $text, $modelId ?: self::CONTENT_MODEL );
	}

	public function getModel() {
		// FIXME FIXME this should not be needed
		return self::CONTENT_MODEL;
	}

	public function isValid() {
		if ( !parent::isValid() ) {
			return false;
		}
		$data = $this->getData()->getValue();
		if ( !is_array( $data ) ) {
			return false;
		}
		foreach( $data as $item ) {
			if ( !self::validateItem( $item ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * @param array $item
	 */
	public static function validateItem( array $item ) {
		// TODO: Should we use more user-friendly key names? They may be shown in diffs.
		$requiredKeys = [
			'pre', 'body', 'post', 'container', 'containerAttribs',
			'comment', 'id', 'userId', 'username'
		];
		foreach ( $requiredKeys as $key ) {
			if ( !isset( $item[$key] ) ) {
				return false;
			}
		}
		foreach( $item as $key => $value ) {
			switch( $key ) {
			case 'pre':
			case 'post':
			if ( !is_string( $value ) ) {
				return false;
			}
			break;
			case 'container':
			case 'body':
			case 'comment':
			case 'id':
			case 'username':
				if ( !is_string( $value ) || strlen( $value ) === 0 ) {
					return false;
				}
				break;
			case 'userId':
				if ( !is_int( $value ) || $value < 0 ) {
					// May be 0 for anon.
					return false;
				}
				break;
			case 'containerAttribs':
				if ( !is_array( $value ) ) {
					return false;
				}
				if ( isset( $value['id'] ) && !is_string( $value['id'] ) ) {
					return false;
				}
				if ( isset( $value['class'] ) && !is_array( $value['class'] ) ) {
					return false;
				}
				break;
			default:
				return false;
			}
		}
		return true;
	}

	/**
	 * Use php arrays instead of stdClass, because it just makes things easier
	 * @inheritDoc
	 */
	public function getData() {
		if ( $this->jsonParse === null ) {
			$this->jsonParse = FormatJson::parse( $this->getText(), FormatJson::FORCE_ASSOC );
		}
		return $this->jsonParse;
	}

	public function newWithAddedItem( array $item ) {
		if ( !$this->isValid() || !self::validateItem( $item ) ) {
			throw new LogicException( "Invalid annotation data" );
		}

		$data = $this->getData()->getValue();
		foreach( $data as $annotation ) {
			if ( $annotation['id'] === $item['id'] ) {
				// Should not be possible.
				throw new LogicException( "ID collision" );
			}
		}

		$data[] = $item;
		return new self( FormatJson::encode( $data ), true, FormatJson::UTF8_OK );
	}
}
