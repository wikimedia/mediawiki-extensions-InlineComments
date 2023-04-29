<?php

namespace MediaWiki\Extension\InlineComments;

use LogicException;
use RemexHtml\TreeBuilder\Element;
use RemexHtml\TreeBuilder\RelayTreeHandler;
use RemexHtml\TreeBuilder\TreeHandler;

class AnnotationTreeHandler extends RelayTreeHandler {
	private const INACTIVE = 1;
	private const LOOKING_PRE = 2;
	private const LOOKING_BODY = 3;
	private const LOOKING_SIBLING_RESTART = 4;
	private const LOOKING_POST = 5;
	private const DONE = 6;

	/** @var array Annotations (as an array) */
	private $annotations;

	/**
	 * @param TreeHandler $serializer Base serializer class that provides fallback
	 * @param array $annotations List of annotations to add
	 */
	public function __construct( TreeHandler $serializer, $annotations ) {
		parent::__construct( $serializer );
		// Can access serializer via $this->nextHandler.
		$this->annotations = $annotations;
		$this->initStateMachine();
	}

	/**
	 * Initialise annotation structure with state data
	 *
	 * Initial structure is: [ pre, body, post, container, containerAttribs ]
	 *
	 * We add preRemaining, bodyRemaining, postRemaining, state.
	 * Assumption - block elements stop matching
	 *   pre text does not span elements?
	 */
	private function initStateMachine() {
		foreach ( $this->annotations as &$annotation ) {
			$annotation['state'] = self::INACTIVE;
			$annotation['startElement'] = null;
			$annotation['topElements'] = [];
			$annotation['endElement'] = null;
		}
	}

	/**
	 * Mark a specific annotation as currently being in progress matched
	 *
	 * @param int $key The key in the annotations array (not the annotation id)
	 */
	private function markActive( $key ) {
		$annotation =& $this->annotations[$key];
		if ( $annotation['state'] === self::DONE ) {
			return;
		}
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
			} else {
				break;
			}
			/* fallthrough */
		case self::LOOKING_BODY:
			if ( $annotation['bodyRemaining'] === '' ) {
				$annotation['state'] = self::LOOKING_POST;
				$annotation['postRemaining'] = $annotation['post'];
			} else {
				break;
			}
			/* fallthrough */
		case self::LOOKING_POST:
		// FIXME this is broken.
			if ( $annotation['postRemaining'] === '' ) {
				$annotation['state'] = self::DONE;
		/*		$attr = new PlainAttributes( [ 'class' => 'mw-highlight-aside' ] );
				$asideElement = new Element( HTMLData::NS_HTML, 'aside', $attr );
				$this->nextHandler->insertElement( TreeBuilder::ROOT, null, $asideElement, false, 0, 0 ); */
			}
			break;
		}
	}

	/**
	 * @inheritDoc
	 */
	public function characters( $preposition, $ref, $text, $start, $length, $sourceStart, $sourceLength ) {
		if ( !( $ref instanceof Element ) ) {
			parent::characters( $preposition, $ref, $text, $start, $length, $sourceStart, $sourceLength );
			return;
		}
		for ( $i = $start; $i < $start + $length; $i++ ) {
			foreach ( $this->annotations as $key => &$annotation ) {
				switch ( $annotation['state'] ) {
				case self::LOOKING_PRE:
					$nextChar = substr( $annotation['preRemaining'], 0, 1 );
					if ( $nextChar === $text[$i] ) {
						$annotation['preRemaining'] = substr( $annotation['preRemaining'], 1 );
					}
					if ( strlen( $annotation['preRemaining'] ) !== 0 ) {
						break;
					} else {
						$this->maybeTransition( $key );
					}
					/* fallthrough */
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
							if ( $ref->userData->snData === null ) {
								$ref->userData->snData = [];
							}
							$ref->userData->snData['annotations'][] = [
								$key,
								$i - $start,
								count( $ref->userData->children ),
								AnnotationFormatter::START
							];
							$annotation['startElement'] = $ref;
							$annotation['topElements'][] = $ref;
						}
						$annotation['bodyRemaining'] = substr(
							$annotation['bodyRemaining'],
							1
						);
					}
					if ( strlen( $annotation['bodyRemaining'] ) !== 0 ) {
						break;
					} else {
						$ref->userData->snData['annotations'][] = [
							$key,
							$i - $start + 1,
							count( $ref->userData->children ),
							AnnotationFormatter::END
						];
						$annotation['endElement'] = $ref;
						$this->maybeTransition( $key );
					}
					/* fallthrough */

				case self::LOOKING_POST:
					$nextChar = substr( $annotation['postRemaining'], 0, 1 );
					if ( $nextChar === $text[$i] ) {
						$annotation['postRemaining'] = substr( $annotation['postRemaining'], 1 );
					}
					if ( strlen( $annotation['postRemaining'] ) === 0 ) {
						$this->maybeTransition( $key );
					}
					break;
				}
			}
		}
		parent::characters( $preposition, $ref, $text, $start, $length, $sourceStart, $sourceLength );
	}

	/**
	 * @inheritDoc
	 */
	public function insertElement( $preposition, $ref, Element $element, $void, $sourceStart, $sourceLength ) {
		// FIXME, should we do something with preposition/ref?
		$this->getMatchingContainer( $element );
		parent::insertElement( $preposition, $ref, $element, $void, $sourceStart, $sourceLength );
	}

	/**
	 * @inheritDoc
	 */
	public function endTag( Element $element, $sourceStart, $sourceLength ) {
		// If the tag we are in ended, we need to restart at next sibing tag
		foreach ( $this->annotations as $key => &$annotation ) {
			if (
				$annotation['state'] === self::LOOKING_BODY &&
				in_array( $element, $annotation['topElements'] )
			) {
				// FIXME maybe also check no more body data
				$annotation['state'] = self::LOOKING_SIBLING_RESTART;
				$element->userData->snData['annotations'][] =
					[ $key, -1, -1, AnnotationFormatter::SIBLING_END ];
			}
		}

		// FIXME this needs to be rewritten.
		$keys = $element->userData->snData['annotationMaybe'] ?? [];

		// Assumption: we cannot have recusive containers
		// Assumption: All children get called before the endTag method is called.
		foreach ( $keys as $key ) {
			if ( $this->annotations[$key]['state'] !== self::DONE ) {
				// We didn't match it all
				$this->annotations[$key]['state'] = self::INACTIVE;
				$startElm = $this->annotations[$key]['startElement'];
				if ( $startElm ) {
					$compare = static function ( $value ) use ( $key ) {
						return $value[AnnotationFormatter::KEY] !== $key;
					};
					$startElm->userData->snData['annotations'] = array_filter(
						$startElm->userData->snData['annotations'],
						$compare
					);
					$this->annotations[$key]['startElement'] = null;
				}
				$endElm = $this->annotations[$key]['endElement'];
				if ( $endElm ) {
					$compare = static function ( $value ) use ( $key ) {
						return $value[AnnotationFormatter::KEY] !== $key;
					};
					$endElm->userData->snData['annotations'] = array_filter(
						$endElm->userData->snData['annotations'],
						$compare
					);
					$this->annotations[$key]['endElement'] = null;
				}
			}
		}

		unset( $element->userData->snData['annotationMaybe'] );
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
			/*
			if ( $node->userData->snData === null ) {
				$node->userData->snData = [ 'annotationsMaybe' => [] ];
			}
			$node->userData->snData['annotationsMaybe'][] = $key;
			*/
			$newList[] = $key;
		}
	}
}
