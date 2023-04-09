<?php

namespace MediaWiki\Extension\InlineComments;

class AnnotationList {
	private $annotations;

	public function __construct( $annotations ) {
		$this->annotations = $annotations;
	}

	public function getMatchingContainer( SerializerNode $node ) {
		$newList = [];
		foreach( $this->annotations as $annotation ) {
			// FIXME how does case work?
			if ( $node->name !== $annotation['container'] ) {
				continue;
			}

			$id = $node->attrs['id'] ?? false;
			if ( $id !== $annotation['containerAttribs']['id'] ) {
				continue;
			}
			$class = $node->attrs['class'] ?? [];
			$aClass = $annotation['containerAttribs']['class'] ?? [];
			if ( count( $class ) !== count( $aClass ) ) {
				continue;
			}

			// FIXME do multiple class case.
			if ( count( $class ) < 2 && implode( ' ', $class ) !== implode( ' ', $aClass ) ) {
				continue;
			}
			$newList[] = $annotation;
		}
		return new self( $newList );
	}

	// TODO: Right now it assumes prefix entirely appears in parent node.
	public function matchesPrefix( $text ) {
		// This is inefficient
		foreach ( $this->annotations as $annotation ) {
			if ( strpos( $text, $annotation['pre'] ) ) {

			}
		}
	}

	public function getAnnotations() {
		return $this->annotations;
	}

	public function isEmpty() {
		return count( $this->annotations ) === 0;
	}
}
