<?php
namespace MediaWiki\Extension\InlineComments;

use FormatJson;
use JsonContent;
use LogicException;
use User;
use Wikimedia\Timestamp\ConvertibleTimestamp;

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
			'pre', 'body', 'container', 'containerAttribs',
			'comments'
		];
		foreach ( $requiredKeys as $key ) {
			if ( !isset( $item[$key] ) ) {
				return false;
			}
		}
		foreach ( $item as $key => $value ) {
			switch ( $key ) {
				case 'pre':
					if ( !is_string( $value ) ) {
						return false;
					}
					break;
				case 'container':
				case 'body':
				case 'id':
					if ( !is_string( $value ) || $value === '' ) {
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
				case 'comments':
					if ( !is_array( $value ) || count( $value ) === 0 ) {
						return false;
					}
					foreach ( $value as $commentVal ) {
						if (
							!isset( $commentVal['userId'] ) ||
							!is_int( $commentVal['userId'] ) ||
							$commentVal['userId'] < 0 ||
							!isset( $commentVal['username'] ) ||
							!is_string( $commentVal['username'] ) ||
							$commentVal['username'] === '' ||
							!isset( $commentVal['actorId'] ) ||
							!is_int( $commentVal['actorId'] ) ||
							$commentVal['actorId'] <= 0 ||
							!isset( $commentVal['comment'] ) ||
							!is_string( $commentVal['comment'] ) ||
							// Backwards compatibility: timestamp is not
							// required, but if set must be valid as TS_MW
							(
								isset( $commentVal['timestamp'] )
								&& ConvertibleTimestamp::convert(
									TS_MW,
									$commentVal['timestamp']
								) === false
							)
						) {
							return false;
						}
					}
					break;
				case 'skipCount':
					// skipCount not required parameter for back-compat.
					if ( !is_int( $value ) || $value < 0 ) {
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

	/**
	 * Do we have an annotation with a specific item id
	 *
	 * @param string $itemId
	 * @return bool
	 */
	public function hasItem( string $itemId ) {
		$data = $this->getData()->getValue();
		foreach ( $data as $annotation ) {
			if ( $annotation['id'] === $itemId ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Check if data is empty
	 * @return bool
	 */
	public function isEmpty() {
		$data = $this->getData()->getValue();
		return count( $data ) === 0;
	}

	/**
	 * Get a new content object but with a specific item removed.
	 *
	 * @param string $itemId
	 * @return AnnotationContent
	 */
	public function removeItem( string $itemId ) {
		$data = $this->getData()->getValue();
		for ( $i = 0; $i < count( $data ); $i++ ) {
			if ( $data[$i]['id'] === $itemId ) {
				unset( $data[$i] );
				break;
			}
		}
		return new AnnotationContent( FormatJson::encode( array_values( $data ), true, FormatJson::UTF8_OK ) );
	}

	/**
	 * Add a reply to a comment
	 *
	 * @param string $itemId
	 * @param string $comment
	 * @param User $user
	 * @return AnnotationContent A new content object with the changes made.
	 */
	public function addReply( string $itemId, string $comment, User $user ) {
		$data = $this->getData()->getValue();
		if ( !$this->hasItem( $itemId ) ) {
			throw new LogicException( "No item by that id" );
		}
		for ( $i = 0; $i < count( $data ); $i++ ) {
			if ( $data[$i]['id'] === $itemId ) {
				$data[$i]['comments'][] = [
					'comment' => $comment,
					'userId' => $user->getId(),
					'username' => $user->getName(),
					'actorId' => $user->getActorId(),
					'timestamp' => wfTimestampNow(),
					'edited' => false
				];
				break;
			}
		}
		return new AnnotationContent( FormatJson::encode( $data, true, FormatJson::UTF8_OK ) );
	}

	/**
	 * Get the author user ID of the existing comment
	 *
	 * @param string $itemId
	 * @param int $existingCommentIdx
	 * @return User
	 */
	public function getCommentAuthor( string $itemId, int $existingCommentIdx ) {
		$data = $this->getData()->getValue();
		if ( !$this->hasItem( $itemId ) ) {
			throw new LogicException( "No item by that id" );
		}
		$author = null;
		for ( $i = 0; $i < count( $data ); $i++ ) {
			if ( $data[ $i ][ 'id' ] === $itemId ) {
				$comment = $data[ $i ][ 'comments' ][$existingCommentIdx];
				$author = User::newFromActorId( $comment[ 'actorId' ] );
				break;
			}
		}
		if ( $author == null ) {
			throw new LogicException( "Author not found" );
		}
		return $author;
	}

	/**
	 * Edit a comment
	 *
	 * @param string $itemId
	 * @param string $comment
	 * @param User $user
	 * @param int $existingCommentIdx
	 * @return AnnotationContent A new content object with the changes made.
	 */
	public function editComment(
		string $itemId,
		string $comment,
		User $user,
		int $existingCommentIdx
	) {
		$data = $this->getData()->getValue();
		if ( !$this->hasItem( $itemId ) ) {
			throw new LogicException( "No item by that id" );
		}
		for ( $i = 0; $i < count( $data ); $i++ ) {
			if ( $data[$i]['id'] === $itemId ) {
				if ( !isset( $data[$i]['comments'][$existingCommentIdx] ) ) {
					throw new LogicException( "No comment by that id" );
				}
				$data[$i]['comments'][$existingCommentIdx] = [
					'comment' => $comment,
					'userId' => $user->getId(),
					'username' => $user->getName(),
					'actorId' => $user->getActorId(),
					'timestamp' => wfTimestampNow(),
					'edited' => true
				];
				break;
			}
		}
		return new AnnotationContent( FormatJson::encode( $data, true, FormatJson::UTF8_OK ) );
	}
}
