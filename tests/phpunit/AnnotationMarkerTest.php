<?php

use MediaWiki\Extension\InlineComments\AnnotationContent;
use MediaWiki\Extension\InlineComments\AnnotationMarker;

class AnnotationMarkerTest extends MediaWikiTestCase {

	public function setUp(): void {
		parent::setUp();
		$this->setMwGlobals( [
			'wgArticlePath' => '/wiki/$1',
			'wgScriptPath' => '/w/'
		] );
	}

	/**
	 * @covers MediaWiki\Extension\InlineComments\AnnotationMarker::markUp
	 * @dataProvider provideMarkingUp
	 */
	public function testMarkUp( string $inputHtml, array $annotations, string $expectedOutput, string $info ) {
		$content = $this->getAC( $annotations );
		$marker = new AnnotationMarker;
		$res = $marker->markUp( $inputHtml, $content );
		$this->assertEquals( $expectedOutput, $res, $info );
	}

	private function getAC( $data ) {
		$json = json_encode( $data );
		return new AnnotationContent( $json );
	}

	// phpcs:disable Generic.Files.LineLength.TooLong
	public function provideMarkingUp() {
		yield [
			'<div class="mw-parser-output"><div id="foo">bar</div></div>',
			[],
			'<div class="mw-parser-output"><div id="foo">bar</div></div>',
			'no annotations'
		];
		yield [
			'<div class="mw-parser-output"><div id="foo">bar</div></div>',
			[
				[
					'id' => 'abc',
					'pre' => 'b',
					'body' => 'a',
					'post' => 'r',
					'container' => 'div',
					'containerAttribs' => [ 'id' => 'foo' ],
					'comments' => [[
						'comment' =>'Hello',
						'userId' => 0,
						'actorId' => 1, // Not valid, but not used yet.
						'username' => '127.0.0.1'
					]],
				]
			],
			'<div class="mw-parser-output"><div id="foo">b<span class="mw-annotation-highlight mw-annotation-abc" title="Hello" data-mw-highlight-id="abc">a</span>r</div></div><div id="mw-inlinecomment-annotations"><aside id="mw-inlinecomment-aside-abc" class="mw-inlinecomment-aside"><p>Hello</p><div class="mw-inlinecomment-author"><a href="/wiki/Special:Contributions/127.0.0.1" class="mw-userlink mw-anonuserlink" title="Special:Contributions/127.0.0.1"><bdi>127.0.0.1</bdi></a> <span class="mw-usertoollinks">(<a href="/w/index.php?title=User_talk:127.0.0.1&amp;action=edit&amp;redlink=1" class="new mw-usertoollinks-talk" title="User talk:127.0.0.1 (page does not exist)">talk</a>)</span></div></aside></div>',
			'no annotations'
		];
		yield [
			'<div class="mw-parser-output"><div>bar</div></div>',
			[
				[
					'id' => 'abc',
					'pre' => 'b',
					'body' => 'a',
					'post' => 'r',
					'container' => 'div',
					'comments' => [[
						'comment' => 'Hello',
						'userId' => 0,
						'actorId' => 1, // Not valid, but not used yet.
						'username' => '127.0.0.1'
					]],
				]
			],
			'<div class="mw-parser-output"><div>b<span class="mw-annotation-highlight mw-annotation-abc" title="Hello" data-mw-highlight-id="abc">a</span>r</div></div><div id="mw-inlinecomment-annotations"><aside id="mw-inlinecomment-aside-abc" class="mw-inlinecomment-aside"><p>Hello</p><div class="mw-inlinecomment-author"><a href="/wiki/Special:Contributions/127.0.0.1" class="mw-userlink mw-anonuserlink" title="Special:Contributions/127.0.0.1"><bdi>127.0.0.1</bdi></a> <span class="mw-usertoollinks">(<a href="/w/index.php?title=User_talk:127.0.0.1&amp;action=edit&amp;redlink=1" class="new mw-usertoollinks-talk" title="User talk:127.0.0.1 (page does not exist)">talk</a>)</span></div></aside></div>',
			'no annotations'
		];
	}
}
