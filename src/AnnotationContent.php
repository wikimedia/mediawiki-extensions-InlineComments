<?php
namespace MediaWiki\Extension\InlineComments;

use FormatJson;
use JsonContent;
use LogicException;

class AnnotationContent extends JsonContent {
	public const CONTENT_MODEL = 'annotation+json';
	public const SLOT_NAME = 'inlinecomments';

	/**
	 * @param string $text JSON data
	 * @param string $modelId for subclassing
	 */
	public function __construct( $text, $modelId = self::CONTENT_MODEL ) {
		parent::__construct( $text, $modelId );
	}

	/**
	 * @todo Why is this needed it should not be.
	 * @inheritDoc
	 */
	public function getModel() {
		// FIXME FIXME this should not be needed
		return self::CONTENT_MODEL;
	}

	/**
	 * Is data valid. Check it has right properties
	 *
	 * @return bool
	 */
	public function isValid() {
		if ( !parent::isValid() ) {
			return false;
		}
		$data = $this->getData()->getValue();
		if ( !is_array( $data ) ) {
			return false;
		}
		foreach ( $data as $item ) {
			if ( !self::validateItem( $item ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Check if a specific annotation is valid
	 *
	 * Static so it can be called from API
	 *
	 * @param array $item
	 * @return bool
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
		foreach ( $item as $key => $value ) {
			switch ( $key ) {
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

	/**
	 * Make a new annotation content but with extra item and return it.
	 *
	 * Does not affect this object, returns a new one.
	 *
	 * @param array $item New item to add
	 * @return self
	 */
	public function newWithAddedItem( array $item ) {
		if ( !$this->isValid() || !self::validateItem( $item ) ) {
			throw new LogicException( "Invalid annotation data" );
		}

		$data = $this->getData()->getValue();
		foreach ( $data as $annotation ) {
			if ( $annotation['id'] === $item['id'] ) {
				// Should not be possible.
				throw new LogicException( "ID collision" );
			}
		}

		$data[] = $item;
		return new AnnotationContent( FormatJson::encode( $data, true, FormatJson::UTF8_OK ) );
	}
}
