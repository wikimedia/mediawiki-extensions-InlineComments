<?php

use MediaWiki\Extension\InlineComments\AnnotationContent;
use MediaWiki\Extension\InlineComments\AnnotationMarker;
use MediaWiki\MediaWikiServices;

class AnnotationMarkerTest extends MediaWikiIntegrationTestCase {

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
		$config = new HashConfig( [ 'InlineCommentsAutoResolveComments' => true ] );
		$marker = new AnnotationMarker( $config );
		$lang = MediaWikiServices::getInstance()->getLanguageFactory()->getLanguage( 'en' );
		$user = User::newFromName( '127.0.0.1', false );
		$res = $marker->markUp( $inputHtml, $content, $lang, $user );
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
					'container' => 'div',
					'containerAttribs' => [ 'id' => 'foo' ],
					'comments' => [ [
						'comment' => 'Hello',
						'userId' => 0,
						'actorId' => 1,
						'username' => '127.0.0.1'
					] ],
				]
			],
			'<div class="mw-parser-output"><div id="foo">b<span class="mw-annotation-highlight mw-annotation-abc" title="Hello" data-mw-highlight-id="abc">a</span>r</div></div><div id="mw-inlinecomment-annotations"><aside id="mw-inlinecomment-aside-abc" class="mw-inlinecomment-aside"><p>Hello</p><div class="mw-inlinecomment-author"><a href="/wiki/Special:Contributions/127.0.0.1" class="mw-userlink mw-anonuserlink" title="Special:Contributions/127.0.0.1"><bdi>127.0.0.1</bdi></a></div></aside></div>',
			'simple'
		];
		yield [
			'<div class="mw-parser-output"><div>bar</div></div>',
			[
				[
					'id' => 'abc',
					'pre' => 'b',
					'body' => 'a',
					'container' => 'div',
					'comments' => [ [
						'comment' => 'Hello',
						'userId' => 0,
						'actorId' => 1,
						'username' => '127.0.0.1'
					] ],
				]
			],
			'<div class="mw-parser-output"><div>b<span class="mw-annotation-highlight mw-annotation-abc" title="Hello" data-mw-highlight-id="abc">a</span>r</div></div><div id="mw-inlinecomment-annotations"><aside id="mw-inlinecomment-aside-abc" class="mw-inlinecomment-aside"><p>Hello</p><div class="mw-inlinecomment-author"><a href="/wiki/Special:Contributions/127.0.0.1" class="mw-userlink mw-anonuserlink" title="Special:Contributions/127.0.0.1"><bdi>127.0.0.1</bdi></a></div></aside></div>',
			'inside div'
		];
		yield [
			'<div class="mw-parser-output"><div>More text. bar</div></div>',
			[
				[
					'id' => 'abc',
					'pre' => 'b',
					'body' => 'a',
					'container' => 'div',
					'comments' => [ [
						'comment' => 'Hello',
						'userId' => 0,
						'actorId' => 1,
						'username' => '127.0.0.1'
					] ],
				]
			],
			'<div class="mw-parser-output"><div>More text. b<span class="mw-annotation-highlight mw-annotation-abc" title="Hello" data-mw-highlight-id="abc">a</span>r</div></div><div id="mw-inlinecomment-annotations"><aside id="mw-inlinecomment-aside-abc" class="mw-inlinecomment-aside"><p>Hello</p><div class="mw-inlinecomment-author"><a href="/wiki/Special:Contributions/127.0.0.1" class="mw-userlink mw-anonuserlink" title="Special:Contributions/127.0.0.1"><bdi>127.0.0.1</bdi></a></div></aside></div>',
			'Not full prefix'
		];
		yield [
			'<div class="mw-parser-output"><p>This is first paragraph.' . "\n" . '</p><p>This is the second.' . "\n" . '</p></div>',
			[
				[
					'id' => 'abc',
					'pre' => 'This is ',
					'body' => 'first paragraph',
					'container' => 'p',
					'comments' => [ [
						'comment' => 'Hello Paragraph',
						'userId' => 0,
						'actorId' => 1,
						'username' => '127.0.0.2'
					] ],
				]
			],
			'<div class="mw-parser-output"><p>This is <span class="mw-annotation-highlight mw-annotation-abc" title="Hello Paragraph" data-mw-highlight-id="abc">first paragraph</span>.' . "\n" . '</p><p>This is the second.' . "\n" . '</p></div><div id="mw-inlinecomment-annotations"><aside id="mw-inlinecomment-aside-abc" class="mw-inlinecomment-aside"><p>Hello Paragraph</p><div class="mw-inlinecomment-author"><a href="/wiki/Special:Contributions/127.0.0.2" class="mw-userlink mw-anonuserlink" title="Special:Contributions/127.0.0.2"><bdi>127.0.0.2</bdi></a></div></aside></div>',
			'inside paragraph'
		];
		yield [
			'<div class="mw-parser-output"><p>This is first p<b>a</b>ragraph.' . "\n" . '</p><p>This is the second.' . "\n" . '</p></div>',
			[
				[
					'id' => 'abc',
					'pre' => 'This is ',
					'body' => 'first paragraph',
					'container' => 'p',
					'comments' => [ [
						'comment' => 'Hello Paragraph',
						'userId' => 0,
						'actorId' => 1,
						'username' => '127.0.0.2'
					] ],
				]
			],
			'<div class="mw-parser-output"><p>This is <span class="mw-annotation-highlight mw-annotation-abc" title="Hello Paragraph" data-mw-highlight-id="abc">first p<b>a</b>ragraph</span>.' . "\n" . '</p><p>This is the second.' . "\n" . '</p></div><div id="mw-inlinecomment-annotations"><aside id="mw-inlinecomment-aside-abc" class="mw-inlinecomment-aside"><p>Hello Paragraph</p><div class="mw-inlinecomment-author"><a href="/wiki/Special:Contributions/127.0.0.2" class="mw-userlink mw-anonuserlink" title="Special:Contributions/127.0.0.2"><bdi>127.0.0.2</bdi></a></div></aside></div>',
			'inside paragraph with formatting'
		];
		yield [
			'<div class="mw-parser-output"><p>This is first paragraph.' . "\n" . '</p><p>This is the second.' . "\n" . '</p></div>',
			[
				[
					'id' => 'abc',
					'pre' => 'This is ',
					'body' => "first paragraph.\nThis",
					'container' => 'p',
					'comments' => [ [
						'comment' => 'Hello Paragraph',
						'userId' => 0,
						'actorId' => 1,
						'username' => '127.0.0.2'
					] ],
				]
			],
			'<div class="mw-parser-output"><p>This is <span class="mw-annotation-highlight mw-annotation-abc" title="Hello Paragraph" data-mw-highlight-id="abc">first paragraph.' . "\n" . '</span></p><p><span class="mw-annotation-highlight mw-annotation-abc" title="Hello Paragraph" data-mw-highlight-id="abc">This</span> is the second.' . "\n" . '</p></div><div id="mw-inlinecomment-annotations"><aside id="mw-inlinecomment-aside-abc" class="mw-inlinecomment-aside"><p>Hello Paragraph</p><div class="mw-inlinecomment-author"><a href="/wiki/Special:Contributions/127.0.0.2" class="mw-userlink mw-anonuserlink" title="Special:Contributions/127.0.0.2"><bdi>127.0.0.2</bdi></a></div></aside></div>',
			'spanning paragraph'
		];
		yield [
			'<div class="mw-parser-output"><p>This is first paragraph.' . "\n" . '</p><p>This is the second.' . "\n" . '</p></div>',
			[
				[
					'id' => 'abc',
					'pre' => 'Th',
					'body' => "is is the sec",
					'container' => 'p',
					'comments' => [ [
						'comment' => 'Hello Paragraph',
						'userId' => 0,
						'actorId' => 1,
						'username' => '127.0.0.2'
					] ],
				]
			],
			'<div class="mw-parser-output"><p>This is first paragraph.' . "\n" . '</p><p>Th<span class="mw-annotation-highlight mw-annotation-abc" title="Hello Paragraph" data-mw-highlight-id="abc">is is the sec</span>ond.' . "\n" . '</p></div><div id="mw-inlinecomment-annotations"><aside id="mw-inlinecomment-aside-abc" class="mw-inlinecomment-aside"><p>Hello Paragraph</p><div class="mw-inlinecomment-author"><a href="/wiki/Special:Contributions/127.0.0.2" class="mw-userlink mw-anonuserlink" title="Special:Contributions/127.0.0.2"><bdi>127.0.0.2</bdi></a></div></aside></div>',
			'Prefix appears twice'
		];
		yield [
			'<div class="mw-parser-output"><ul><li>Foo</li><li>Foo</li><li>Foo</li></div>',
			[
				[
					'id' => 'abc',
					'pre' => 'F',
					'body' => 'oo',
					'container' => 'li',
					'comments' => [ [
						'comment' => 'Hello',
						'userId' => 0,
						'actorId' => 1,
						'username' => '127.0.0.1'
					] ],
					'skipCount' => 1
				]
			],
			'<div class="mw-parser-output"><ul><li>Foo</li><li>F<span class="mw-annotation-highlight mw-annotation-abc" title="Hello" data-mw-highlight-id="abc">oo</span></li><li>Foo</li></ul></div><div id="mw-inlinecomment-annotations"><aside id="mw-inlinecomment-aside-abc" class="mw-inlinecomment-aside"><p>Hello</p><div class="mw-inlinecomment-author"><a href="/wiki/Special:Contributions/127.0.0.1" class="mw-userlink mw-anonuserlink" title="Special:Contributions/127.0.0.1"><bdi>127.0.0.1</bdi></a></div></aside></div>',
			'Skip first'
		];
		yield [
			'<div class="mw-parser-output"><ul><li>Foo</li><li>Foo</li><li>Foo</li></div>',
			[
				[
					'id' => 'abc',
					'pre' => '',
					'body' => 'Foo',
					'container' => 'li',
					'comments' => [ [
						'comment' => 'Hello',
						'userId' => 0,
						'actorId' => 1,
						'username' => '127.0.0.1'
					] ],
					'skipCount' => 1
				]
			],
			'<div class="mw-parser-output"><ul><li>Foo</li><li><span class="mw-annotation-highlight mw-annotation-abc" title="Hello" data-mw-highlight-id="abc">Foo</span></li><li>Foo</li></ul></div><div id="mw-inlinecomment-annotations"><aside id="mw-inlinecomment-aside-abc" class="mw-inlinecomment-aside"><p>Hello</p><div class="mw-inlinecomment-author"><a href="/wiki/Special:Contributions/127.0.0.1" class="mw-userlink mw-anonuserlink" title="Special:Contributions/127.0.0.1"><bdi>127.0.0.1</bdi></a></div></aside></div>',
			'Skip first no prefix'
		];
		yield [
			'<div class="mw-parser-output"><p><i>This is</i> first paragraph.' . "\n" . '</p><p>This is the second.' . "\n" . '</p></div>',
			[
				[
					'id' => 'abc',
					'pre' => 'Th',
					'body' => "is is the sec",
					'container' => 'p',
					'comments' => [ [
						'comment' => 'Hello Paragraph',
						'userId' => 0,
						'actorId' => 1,
						'username' => '127.0.0.2'
					] ],
				]
			],
			'<div class="mw-parser-output"><p><i>This is</i> first paragraph.' . "\n" . '</p><p>Th<span class="mw-annotation-highlight mw-annotation-abc" title="Hello Paragraph" data-mw-highlight-id="abc">is is the sec</span>ond.' . "\n" . '</p></div><div id="mw-inlinecomment-annotations"><aside id="mw-inlinecomment-aside-abc" class="mw-inlinecomment-aside"><p>Hello Paragraph</p><div class="mw-inlinecomment-author"><a href="/wiki/Special:Contributions/127.0.0.2" class="mw-userlink mw-anonuserlink" title="Special:Contributions/127.0.0.2"><bdi>127.0.0.2</bdi></a></div></aside></div>',
			'Prefix appears twice with first prefix in nested elm'
		];
		yield [
			'<div class="mw-parser-output"><p>This is <i>first</i> paragraph.' . "\n" . '</p><p>This is the second.' . "\n" . '</p></div>',
			[
				[
					'id' => 'abc',
					'pre' => 'Th',
					'body' => "is is first",
					'container' => 'p',
					'comments' => [ [
						'comment' => 'Hello Paragraph',
						'userId' => 0,
						'actorId' => 1,
						'username' => '127.0.0.2'
					] ],
				]
			],
			'<div class="mw-parser-output"><p>Th<span class="mw-annotation-highlight mw-annotation-abc" title="Hello Paragraph" data-mw-highlight-id="abc">is is </span><i><span class="mw-annotation-highlight mw-annotation-abc" title="Hello Paragraph" data-mw-highlight-id="abc">first</span></i> paragraph.' . "\n" . '</p><p>This is the second.' . "\n" . '</p></div><div id="mw-inlinecomment-annotations"><aside id="mw-inlinecomment-aside-abc" class="mw-inlinecomment-aside"><p>Hello Paragraph</p><div class="mw-inlinecomment-author"><a href="/wiki/Special:Contributions/127.0.0.2" class="mw-userlink mw-anonuserlink" title="Special:Contributions/127.0.0.2"><bdi>127.0.0.2</bdi></a></div></aside></div>',
			'annotation ends in child element'
		];

		yield [
			'<div class="mw-parser-output"><p>Begin. First overlap second. End</p></div>',
			[
				[
					'id' => 'first',
					'pre' => 'Begin. ',
					'body' => "First overlap",
					'container' => 'p',
					'comments' => [ [
						'comment' => 'f',
						'userId' => 0,
						'actorId' => 1,
						'username' => '127.0.0.2'
					] ],
				],
				[
					'id' => 'second',
					'pre' => 'Begin. First ',
					'body' => "overlap second.",
					'container' => 'p',
					'comments' => [ [
						'comment' => 's',
						'userId' => 0,
						'actorId' => 1,
						'username' => '127.0.0.3'
					] ],
				]
			],
			'<div class="mw-parser-output"><p>Begin. <span class="mw-annotation-highlight mw-annotation-first" title="f" data-mw-highlight-id="first">First <span class="mw-annotation-highlight mw-annotation-second" title="s" data-mw-highlight-id="second">overlap</span></span><span class="mw-annotation-highlight mw-annotation-second" title="s" data-mw-highlight-id="second"> second.</span> End</p></div><div id="mw-inlinecomment-annotations"><aside id="mw-inlinecomment-aside-first" class="mw-inlinecomment-aside"><p>f</p><div class="mw-inlinecomment-author"><a href="/wiki/Special:Contributions/127.0.0.2" class="mw-userlink mw-anonuserlink" title="Special:Contributions/127.0.0.2"><bdi>127.0.0.2</bdi></a></div></aside><aside id="mw-inlinecomment-aside-second" class="mw-inlinecomment-aside"><p>s</p><div class="mw-inlinecomment-author"><a href="/wiki/Special:Contributions/127.0.0.3" class="mw-userlink mw-anonuserlink" title="Special:Contributions/127.0.0.3"><bdi>127.0.0.3</bdi></a></div></aside></div>',
			'Overlapped comments properly nest span tags'
		];
	}
}
