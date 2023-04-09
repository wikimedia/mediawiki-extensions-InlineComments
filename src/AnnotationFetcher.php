<?php
namespace MediaWiki\Extension\InlineComments;

class AnnotationFetcher {
	public const SERVICE_NAME = "InlineComments:AnnotationFetcher";

	public function getAnnotations( $title ) {
		return new AnnotationList( [
			[
				'pre' => 'Foo ',
				'body' => 'bar',
				'post' => 'baz',
				'container' => 'div',
				'containerAttribs => [ 'id' => 'foo', 'class' => [ 'bar' ] ]
			]
		] );
	}
}
