<?php

namespace MediaWiki\Extension\InlineComments;

use LogicException;
use Wikimedia\RemexHtml\Serializer\Serializer;
use Wikimedia\RemexHtml\TreeBuilder\Element;
use Wikimedia\RemexHtml\TreeBuilder\RelayTreeHandler;
use Wikimedia\RemexHtml\TreeBuilder\TreeBuilder;

class AnnotationTreeHandler extends RelayTreeHandler {
	private const INACTIVE = 1;
	private const LOOKING_PRE = 2;
	private const LOOKING_BODY = 3;
	private const LOOKING_SIBLING_RESTART = 4;
	private const DONE = 5;
	// Event types
	private const CHARACTER = 0;
	private const ELEMENT = 1;

	/** @var array Annotations (as an array) */
	private $annotations;

	/** @var int How many annotations are in progress? */
	private $annotationsInFlight = 0;

	/** @var array stack of events to send */
	private $pendingEvents = [];

	/** @var int next available link id */
	private $linkId = 1;

	/**
	 * @param Serializer $serializer Base serializer class that provides fallback
	 * @param array $annotations List of annotations to add
	 */
	public function __construct( Serializer $serializer, $annotations ) {
		parent::__construct( $serializer );
		// Can access serializer via $this->nextHandler.
		$this->annotations = $annotations;
		$this->initStateMachine();
	}

	/**
	 * Initialise annotation structure with state data
	 *
	 * Initial structure is: [ pre, body, container, containerAttribs ]
	 *
	 * We add preRemaining, bodyRemaining, state.
	 * Assumption - block elements stop matching
	 *   pre text does not span elements?
	 */
	private function initStateMachine() {
		foreach ( $this->annotations as $key => $annotation ) {
			$this->resetAnnotation( $key );
		}
	}

	/**
	 * reset the annotation state machine back to start
	 *
	 * @param int $key The key for the annotation to reset
	 */
	private function resetAnnotation( int $key ) {
		$oldState = $this->annotations[$key]['state'] ?? null;
		$this->annotations[$key]['state'] = self::INACTIVE;
		$this->annotations[$key]['startElement'] = null;
		$this->annotations[$key]['endElement'] = null;
		$this->annotations[$key]['preRemaining'] = $this->annotations[$key]['pre'];
		$this->annotations[$key]['bodyRemaining'] = $this->annotations[$key]['body'];
		if ( !isset( $this->annotations[$key]['skipCount'] ) ) {
			// skipCount introduced later, old comments might not have it.
			$this->annotations[$key]['skipCount'] = 0;
		}
		$topElems = $this->annotations[$key]['topElements'] ?? [];
		foreach ( $topElems as $elem ) {
			// TODO: Potentially there may be circurmstances where this is called too late.
			if ( isset( $elem->userData->snData['annotations'] ) ) {
				$elem->userData->snData['annotations'] = array_filter(
					$elem->userData->snData['annotations'],
					static function ( $i ) use ( $key ) {
						return $i[AnnotationFormatter::KEY] !== $key;
					}
				);
			}
		}
		$this->annotations[$key]['topElements'] = [];

		if ( $oldState >= self::LOOKING_BODY && $this->annotations[$key]['skipCount'] === 0 ) {
			// We're resetting something considered in flight
			$this->decrAnnotationsInFlight();
		}
	}

	/**
	 * Mark a specific annotation as currently being in progress matched
	 *
	 * @param int $key The key in the annotations array (not the annotation id)
	 */
	private function markActive( $key ) {
		$annotation =& $this->annotations[$key];
		if ( $annotation['state'] !== self::INACTIVE ) {
			throw new LogicException( "Annotation $key already active" );
		}
		$annotation['state'] = self::LOOKING_PRE;
		$annotation['preRemaining'] = $annotation['pre'];
		$this->maybeTransition( $key );
	}

	/**
	 * Check if a specific annotation should change state
	 *
	 * @param int $key Annotation key to check
	 */
	private function maybeTransition( $key ) {
		$annotation =& $this->annotations[$key];
		switch ( $annotation['state'] ) {
			case self::LOOKING_PRE:
				if ( $annotation['preRemaining'] === '' ) {
					$annotation['state'] = self::LOOKING_BODY;
					$annotation['bodyRemaining'] = $annotation['body'];
					if ( $annotation['skipCount'] === 0 ) {
						$this->incrAnnotationsInFlight();
					}
				} else {
					break;
				}
				/* fallthrough */
			case self::LOOKING_BODY:
				if ( $annotation['bodyRemaining'] === '' ) {
					if ( $annotation['skipCount'] ) {
						$this->resetAnnotation( $key );
						$annotation['skipCount']--;
						$annotation['state'] = self::LOOKING_PRE;
						$this->maybeTransition( $key );
					} else {
						$annotation['state'] = self::DONE;
						$this->decrAnnotationsInFlight();
					}
				}
				break;
		}
	}

	/**
	 * @inheritDoc
	 */
	public function characters( $preposition, $ref, $text, $start, $length, $sourceStart, $sourceLength ) {
		if ( !( $ref instanceof Element ) ) {
			$this->sendCharacters(
				[ $preposition, $ref, $text, $start, $length, $sourceStart, $sourceLength ],
				null
			);
			return;
		}
		$this->linkId++;
		for ( $i = $start; $i < $start + $length; $i++ ) {
			foreach ( $this->annotations as $key => &$annotation ) {
				switch ( $annotation['state'] ) {
					case self::DONE:
					case self::INACTIVE:
						break;
					case self::LOOKING_PRE:
						$nextChar = substr( $annotation['preRemaining'], 0, 1 );
						if ( $nextChar === $text[$i] ) {
							$annotation['preRemaining'] = substr( $annotation['preRemaining'], 1 );
						} else {
							// Restart looking for the prefix.
							$annotation['preRemaining'] = $annotation['pre'];
							break;
						}
						if ( strlen( $annotation['preRemaining'] ) !== 0 ) {
							break;
						} else {
							$this->maybeTransition( $key );
						}
						break;
					case self::LOOKING_SIBLING_RESTART:
					case self::LOOKING_BODY:
						$nextChar = substr( $annotation['bodyRemaining'], 0, 1 );
						if ( $nextChar === $text[$i] ) {
							if (
								$annotation['bodyRemaining'] === $annotation['body']
								|| $annotation['state'] === self::LOOKING_SIBLING_RESTART
							) {
								// Matched first character of body or
								// we are at a sibling node and need to reopen
								// the span tag
								$annotation['state'] = self::LOOKING_BODY;
								if ( !$annotation['skipCount'] ) {
									if ( $ref->userData->snData === null ) {
										$ref->userData->snData = [];
									}
									$ref->userData->snData['annotations'][] = [
										$key,
										$i - $start,
										$this->linkId,
										AnnotationFormatter::START
									];
									$annotation['startElement'] = $ref;
									$annotation['topElements'][] = $ref;
								}
							}
							$annotation['bodyRemaining'] = substr(
								$annotation['bodyRemaining'],
								1
							);
						} else {
							$this->resetAnnotation( $key );
							// We are still in the right container, so we
							// can try for another match.
							$this->markActive( $key );
							break;
						}
						if ( strlen( $annotation['bodyRemaining'] ) !== 0 ) {
							break;
						} else {
							if ( !$annotation['skipCount'] ) {
								$ref->userData->snData['annotations'][] = [
									$key,
									$i - $start + 1,
									$this->linkId,
									AnnotationFormatter::END
								];
								$annotation['endElement'] = $ref;
							}
							$this->maybeTransition( $key );
						}
						break;
					default:
						throw new LogicException( 'Unrecognized state' );
				}
			}
		}
		$this->sendCharacters(
			[ $preposition, $ref, $text, $start, $length, $sourceStart, $sourceLength ],
			$this->linkId
		);
	}

	/**
	 * @inheritDoc
	 */
	public function insertElement( $preposition, $ref, Element $element, $void, $sourceStart, $sourceLength ) {
		$this->getMatchingContainer( $element );
		$event = [ $preposition, $ref, $element, $void, $sourceStart, $sourceLength ];
		if ( $this->annotationsInFlight === 0 ) {
			parent::insertElement( ...$event );
		} else {
			if ( $preposition === TreeBuilder::UNDER ) {
				// Remex doesn't allow us to insert character data
				// before an element with any siblings (i.e. not lastChild)
				// so as a hack we insert it somewhere else and then reparent
				// so we only ever have to insert a last child.
				$modifiedEvent = $event;
				$modifiedEvent[0] = TreeBuilder::ROOT;
				$modifiedEvent[1] = null;
				parent::insertElement( ...$modifiedEvent );
				$this->pendingEvents[] = [ self::ELEMENT, $event, null ];
			}
		}
	}

	/**
	 * @inheritDoc
	 */
	public function endTag( Element $element, $sourceStart, $sourceLength ) {
		// If the tag we are in ended, we need to restart at next sibing tag
		foreach ( $this->annotations as $key => &$annotation ) {
			if (
				$annotation['state'] === self::LOOKING_BODY &&
				!$annotation['skipCount'] &&
				in_array( $element, $annotation['topElements'] )
			) {
				$annotation['state'] = self::LOOKING_SIBLING_RESTART;
				$element->userData->snData['annotations'][] =
					[ $key, -1, -1, AnnotationFormatter::SIBLING_END ];
			}
		}

		if ( $this->annotationsInFlight === 0 ) {
			// This is treated as a hint that we are done manipulating this tag.
			// Only send if we actually are done with the tag.
			parent::endTag( $element, $sourceStart, $sourceLength );
		}
	}

	/**
	 * Figure out which annotations to look for at this container
	 *
	 * @param Element $node
	 */
	private function getMatchingContainer( Element $node ) {
		$newList = [];
		foreach ( $this->annotations as $key => $annotation ) {
			if ( $annotation['state'] !== self::INACTIVE ) {
				continue;
			}
			if ( $node->name !== $annotation['container'] ) {
				continue;
			}

			$id = $node->attrs['id'] ?? null;
			if ( $id !== ( $annotation['containerAttribs']['id'] ?? null ) ) {
				continue;
			}
			$nodeClass = $node->attrs['class'] ?? '';
			$nodeClassArray = $nodeClass ? explode( ' ', $nodeClass ) : [];
			$aClass = $annotation['containerAttribs']['class'] ?? [];
			if ( count( $nodeClassArray ) !== count( $aClass ) ) {
				continue;
			}

			sort( $nodeClassArray );
			sort( $aClass );
			if ( implode( ' ', $nodeClassArray ) !== implode( ' ', $aClass ) ) {
				continue;
			}
			$this->markActive( $key );
			$newList[] = $key;
		}
	}

	/**
	 * We are in middle of processing an annotation, so halt sending events
	 *
	 * We consider annotations that have progressed to the LOOKING_BODY stage as in flight.
	 */
	private function incrAnnotationsInFlight() {
		$this->annotationsInFlight++;
	}

	/**
	 * No longer processing, resume sending events if nothing else pending.
	 */
	private function decrAnnotationsInFlight() {
		$this->annotationsInFlight--;
		if ( $this->annotationsInFlight === 0 ) {
			$this->flushEvents();
		}
		if ( $this->annotationsInFlight < 0 ) {
			throw new LogicException( 'Counter negative' );
		}
	}

	/**
	 * Call parent class or queue for calling later
	 *
	 * This allows us to store pending calls, in case we might
	 * change our mind about an annotation matching later
	 *
	 * @param array $args Arguments to method call
	 * @param int|null $linkId
	 */
	private function sendCharacters( array $args, $linkId ) {
		if ( $this->annotationsInFlight === 0 ) {
			$this->setLinkId( $args[0] /* preposition */, $args[1] /* ref */, $linkId );
			// @phan-suppress-next-line PhanParamTooFewUnpack
			parent::characters( ...$args );
		} else {
			$this->pendingEvents[] = [ self::CHARACTER, $args, $linkId ];
		}
	}

	/**
	 * Set the current link id for most recently emitted comments
	 *
	 * @todo This is a bit hacky, think up a better way of communicating this info
	 *
	 * @param int $preposition One of the TreeBuilder constants
	 * @param Element $element The element the annotations are attached to
	 * @param int|null $linkId Which link id we are currently processing
	 */
	private function setLinkId( $preposition, Element $element, $linkId ) {
		// Hacky. AnnotationFormatter needs to know
		// which child we are processing to find the
		// right annotation. Originally this used the child number,
		// but that turned out to not work when things were out of order.
		if ( $preposition === TreeBuilder::ROOT ) {
			return;
		}
		$elm = $preposition === TreeBuilder::UNDER ?
			$element->userData :
			// @phan-suppress-next-line PhanUndeclaredMethod
			$this->nextHandler->getParentNode( $element->userData );
		// Only bother with this if there are annotations in play.
		if ( isset( $elm->snData ) ) {
			$elm->snData['curLinkId'] = $linkId;
		}
	}

	/**
	 * Send all pending character events
	 * @suppress PhanTypeArraySuspiciousNullable
	 */
	private function flushEvents() {
		foreach ( $this->pendingEvents as [ $type, $event, $linkId ] ) {
			if ( $type === self::CHARACTER ) {
				$this->setLinkId( $event[0], $event[1], $linkId );
				// @phan-suppress-next-line PhanParamTooFewUnpack
				parent::characters( ...$event );
			} elseif ( $type === self::ELEMENT ) {
				// @phan-suppress-next-line PhanParamTooFewUnpack
				parent::insertElement( ...$event );
			}
		}
		$this->pendingEvents = [];
	}

	/**
	 * Get an associative array of which annotations are unused.
	 *
	 * @return array
	 */
	public function getUnusedAnnotations() {
		$unused = [];
		foreach ( $this->annotations as $annotation ) {
			$unused[$annotation['id']] = $annotation['state'] !== self::DONE;
		}
		return $unused;
	}
}
