<?php

use MediaWiki\Extension\InlineComments\AnnotationContent;
use MediaWiki\Extension\InlineComments\AnnotationFormatter;
use MediaWiki\Extension\InlineComments\AnnotationMarker;
use MediaWiki\Extension\InlineComments\AnnotationUtils;
use MediaWiki\MainConfigNames;
use MediaWiki\MediaWikiServices;
use MediaWiki\Permissions\PermissionManager;
use MediaWiki\User\ActorStore;
use MediaWiki\User\UserFactory;
use MediaWiki\User\UserNameUtils;
use Wikimedia\Rdbms\LBFactory;
use Wikimedia\TestingAccessWrapper;

/**
 * @group Database
 */
class AnnotationMarkerTest extends MediaWikiIntegrationTestCase {

	public function setUp(): void {
		parent::setUp();
		$this->overrideConfigValues( [
			MainConfigNames::ArticlePath => '/wiki/$1',
			MainConfigNames::ScriptPath => '/w/',
		] );
	}

	/**
	 * @covers MediaWiki\Extension\InlineComments\AnnotationMarker::markUp
	 * @dataProvider provideMarkingUp
	 */
	public function testMarkUp( string $inputHtml, array $annotations, string $expectedOutput, string $info ) {
		$content = $this->getAC( $annotations );
		$config = new HashConfig( [ 'InlineCommentsAutoDeleteComments' => true ] );
		$services = MediaWikiServices::getInstance();
		$user = $services->getUserFactory()->newFromName( '127.0.0.1', UserNameUtils::RIGOR_NONE );
		$title = $services->getTitleFactory()->newFromText( 'sample' );
		$mockUserFactory = $this->getMockBuilder( UserFactory::class )
			->disableOriginalConstructor()
			->getMock();
		$mockLBFactory = $this->getMockBuilder( LBFactory::class )
			->disableOriginalConstructor()
			->getMock();
		$mockActorStore = $this->getMockBuilder( ActorStore::class )
			->disableOriginalConstructor()
			->getMock();
		$mockPermissionManager = $this->getMockBuilder( PermissionManager::class )
			->disableOriginalConstructor()
			->getMock();
		$utils = new AnnotationUtils( $mockUserFactory, $mockLBFactory, $mockActorStore );
		$mockUserFactory->method( 'newFromName' )->willReturn( $user );
		$mockPermissionManager->method( 'userCan' )->willReturn( true );
		$mockActorStore->method( 'acquireActorId' )->willReturn( 1 );
		$marker = new AnnotationMarker(
			$config,
			$mockUserFactory,
			$mockPermissionManager,
			$mockLBFactory,
			$mockActorStore
		);
		$lang = $services->getLanguageFactory()->getLanguage( 'en' );
		$res = $marker->markUp( $inputHtml, $content, $lang, $user, $title );
		$this->assertEquals( $expectedOutput, $res, $info );
	}

	/**
	 * @covers MediaWiki\Extension\InlineComments\AnnotationFormatter::getAsides
	 * @dataProvider provideUserMention
	 */
	public function testUserMention( array $annotations, string $expectedOutput, string $info ) {
		$lang = MediaWikiServices::getInstance()->getLanguageFactory()->getLanguage( 'en' );
		$services = MediaWikiServices::getInstance();
		$user = $services->getUserFactory()->newFromName( '127.0.0.1', UserNameUtils::RIGOR_NONE );
		$title = $services->getTitleFactory()->newFromText( 'sample' );
		if ( $info == 'real user mention' ) {
			$testUser = new TestUser( 'TestUser' );
			$user = $testUser->getUser();
		}
		$mockUserFactory = $this->getMockBuilder( UserFactory::class )
			->disableOriginalConstructor()
			->getMock();
		$mockPermissionManager = $this->getMockBuilder( PermissionManager::class )
			->disableOriginalConstructor()
			->getMock();
		$mockLBFactory = $this->getMockBuilder( LBFactory::class )
			->disableOriginalConstructor()
			->getMock();
		$mockActorStore = $this->getMockBuilder( ActorStore::class )
			->disableOriginalConstructor()
			->getMock();
		$mockPermissionManager->method( 'userCan' )->willReturn( true );
		$mockUserFactory->method( 'newFromName' )->willReturn( $user );
		$mockActorStore->method( 'acquireActorId' )->willReturn( 1 );
		$utils = new AnnotationUtils( $mockUserFactory, $mockLBFactory, $mockActorStore );
		$formatter = TestingAccessWrapper::newFromObject(
			new AnnotationFormatter(
				[],
				$annotations,
				static function () {
				},
				$lang,
				$user,
				$utils,
				$mockPermissionManager,
				$title
			)
		);
		$res = $formatter->getAsides();
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
			'<div class="mw-parser-output"><div id="foo">b<span class="mw-annotation-highlight mw-annotation-abc" title="Hello" data-mw-highlight-id="abc">a</span>r</div></div><div id="mw-inlinecomment-annotations"><aside id="mw-inlinecomment-aside-abc" class="mw-inlinecomment-aside"><div class="mw-inlinecomment-text"><div class="mw-inlinecomment-comment"><div><p>Hello</p><div class="mw-inlinecomment-author"><a href="/wiki/Special:Contributions/127.0.0.1" class="mw-userlink mw-anonuserlink" title="Special:Contributions/127.0.0.1"><bdi>127.0.0.1</bdi></a></div></div></div></div></aside></div>',
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
			'<div class="mw-parser-output"><div>b<span class="mw-annotation-highlight mw-annotation-abc" title="Hello" data-mw-highlight-id="abc">a</span>r</div></div><div id="mw-inlinecomment-annotations"><aside id="mw-inlinecomment-aside-abc" class="mw-inlinecomment-aside"><div class="mw-inlinecomment-text"><div class="mw-inlinecomment-comment"><div><p>Hello</p><div class="mw-inlinecomment-author"><a href="/wiki/Special:Contributions/127.0.0.1" class="mw-userlink mw-anonuserlink" title="Special:Contributions/127.0.0.1"><bdi>127.0.0.1</bdi></a></div></div></div></div></aside></div>',
			'inside div'
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
						'comment' => 'Hello <img src=x onerror=alert(1)>',
						'userId' => 0,
						'actorId' => 1,
						'username' => '127.0.0.1'
					] ],
				]
			],
			'<div class="mw-parser-output"><div>b<span class="mw-annotation-highlight mw-annotation-abc" title="Hello &lt;img src=x onerror=alert(1)&gt;" data-mw-highlight-id="abc">a</span>r</div></div><div id="mw-inlinecomment-annotations"><aside id="mw-inlinecomment-aside-abc" class="mw-inlinecomment-aside"><div class="mw-inlinecomment-text"><div class="mw-inlinecomment-comment"><div><p>Hello &lt;img src=x onerror=alert(1)&gt;</p><div class="mw-inlinecomment-author"><a href="/wiki/Special:Contributions/127.0.0.1" class="mw-userlink mw-anonuserlink" title="Special:Contributions/127.0.0.1"><bdi>127.0.0.1</bdi></a></div></div></div></div></aside></div>',
			'xss'
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
			'<div class="mw-parser-output"><div>More text. b<span class="mw-annotation-highlight mw-annotation-abc" title="Hello" data-mw-highlight-id="abc">a</span>r</div></div><div id="mw-inlinecomment-annotations"><aside id="mw-inlinecomment-aside-abc" class="mw-inlinecomment-aside"><div class="mw-inlinecomment-text"><div class="mw-inlinecomment-comment"><div><p>Hello</p><div class="mw-inlinecomment-author"><a href="/wiki/Special:Contributions/127.0.0.1" class="mw-userlink mw-anonuserlink" title="Special:Contributions/127.0.0.1"><bdi>127.0.0.1</bdi></a></div></div></div></div></aside></div>',
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
			'<div class="mw-parser-output"><p>This is <span class="mw-annotation-highlight mw-annotation-abc" title="Hello Paragraph" data-mw-highlight-id="abc">first paragraph</span>.' . "\n" . '</p><p>This is the second.' . "\n" . '</p></div><div id="mw-inlinecomment-annotations"><aside id="mw-inlinecomment-aside-abc" class="mw-inlinecomment-aside"><div class="mw-inlinecomment-text"><div class="mw-inlinecomment-comment"><div><p>Hello Paragraph</p><div class="mw-inlinecomment-author"><a href="/wiki/Special:Contributions/127.0.0.2" class="mw-userlink mw-anonuserlink" title="Special:Contributions/127.0.0.2"><bdi>127.0.0.2</bdi></a></div></div></div></div></aside></div>',
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
			'<div class="mw-parser-output"><p>This is <span class="mw-annotation-highlight mw-annotation-abc" title="Hello Paragraph" data-mw-highlight-id="abc">first p<b>a</b>ragraph</span>.' . "\n" . '</p><p>This is the second.' . "\n" . '</p></div><div id="mw-inlinecomment-annotations"><aside id="mw-inlinecomment-aside-abc" class="mw-inlinecomment-aside"><div class="mw-inlinecomment-text"><div class="mw-inlinecomment-comment"><div><p>Hello Paragraph</p><div class="mw-inlinecomment-author"><a href="/wiki/Special:Contributions/127.0.0.2" class="mw-userlink mw-anonuserlink" title="Special:Contributions/127.0.0.2"><bdi>127.0.0.2</bdi></a></div></div></div></div></aside></div>',
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
			'<div class="mw-parser-output"><p>This is <span class="mw-annotation-highlight mw-annotation-abc" title="Hello Paragraph" data-mw-highlight-id="abc">first paragraph.' . "\n" . '</span></p><p><span class="mw-annotation-highlight mw-annotation-abc" title="Hello Paragraph" data-mw-highlight-id="abc">This</span> is the second.' . "\n" . '</p></div><div id="mw-inlinecomment-annotations"><aside id="mw-inlinecomment-aside-abc" class="mw-inlinecomment-aside"><div class="mw-inlinecomment-text"><div class="mw-inlinecomment-comment"><div><p>Hello Paragraph</p><div class="mw-inlinecomment-author"><a href="/wiki/Special:Contributions/127.0.0.2" class="mw-userlink mw-anonuserlink" title="Special:Contributions/127.0.0.2"><bdi>127.0.0.2</bdi></a></div></div></div></div></aside></div>',
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
			'<div class="mw-parser-output"><p>This is first paragraph.' . "\n" . '</p><p>Th<span class="mw-annotation-highlight mw-annotation-abc" title="Hello Paragraph" data-mw-highlight-id="abc">is is the sec</span>ond.' . "\n" . '</p></div><div id="mw-inlinecomment-annotations"><aside id="mw-inlinecomment-aside-abc" class="mw-inlinecomment-aside"><div class="mw-inlinecomment-text"><div class="mw-inlinecomment-comment"><div><p>Hello Paragraph</p><div class="mw-inlinecomment-author"><a href="/wiki/Special:Contributions/127.0.0.2" class="mw-userlink mw-anonuserlink" title="Special:Contributions/127.0.0.2"><bdi>127.0.0.2</bdi></a></div></div></div></div></aside></div>',
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
			'<div class="mw-parser-output"><ul><li>Foo</li><li>F<span class="mw-annotation-highlight mw-annotation-abc" title="Hello" data-mw-highlight-id="abc">oo</span></li><li>Foo</li></ul></div><div id="mw-inlinecomment-annotations"><aside id="mw-inlinecomment-aside-abc" class="mw-inlinecomment-aside"><div class="mw-inlinecomment-text"><div class="mw-inlinecomment-comment"><div><p>Hello</p><div class="mw-inlinecomment-author"><a href="/wiki/Special:Contributions/127.0.0.1" class="mw-userlink mw-anonuserlink" title="Special:Contributions/127.0.0.1"><bdi>127.0.0.1</bdi></a></div></div></div></div></aside></div>',
			'Skip first'
		];
		yield [
			'<div class="mw-parser-output"><p>This is first paragraph.' . "\n" . '</p><p>This <i>i</i><b>s</b> <i>t</i>he second.' . "\n" . '</p></div>',
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
			'<div class="mw-parser-output"><p>This is first paragraph.' . "\n" . '</p><p>Th<span class="mw-annotation-highlight mw-annotation-abc" title="Hello Paragraph" data-mw-highlight-id="abc">is <i>i</i><b>s</b> <i>t</i>he sec</span>ond.' . "\n" . '</p></div><div id="mw-inlinecomment-annotations"><aside id="mw-inlinecomment-aside-abc" class="mw-inlinecomment-aside"><div class="mw-inlinecomment-text"><div class="mw-inlinecomment-comment"><div><p>Hello Paragraph</p><div class="mw-inlinecomment-author"><a href="/wiki/Special:Contributions/127.0.0.2" class="mw-userlink mw-anonuserlink" title="Special:Contributions/127.0.0.2"><bdi>127.0.0.2</bdi></a></div></div></div></div></aside></div>',
			'Highlight spans multiple child elements'
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
			'<div class="mw-parser-output"><ul><li>Foo</li><li><span class="mw-annotation-highlight mw-annotation-abc" title="Hello" data-mw-highlight-id="abc">Foo</span></li><li>Foo</li></ul></div><div id="mw-inlinecomment-annotations"><aside id="mw-inlinecomment-aside-abc" class="mw-inlinecomment-aside"><div class="mw-inlinecomment-text"><div class="mw-inlinecomment-comment"><div><p>Hello</p><div class="mw-inlinecomment-author"><a href="/wiki/Special:Contributions/127.0.0.1" class="mw-userlink mw-anonuserlink" title="Special:Contributions/127.0.0.1"><bdi>127.0.0.1</bdi></a></div></div></div></div></aside></div>',
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
			'<div class="mw-parser-output"><p><i>This is</i> first paragraph.' . "\n" . '</p><p>Th<span class="mw-annotation-highlight mw-annotation-abc" title="Hello Paragraph" data-mw-highlight-id="abc">is is the sec</span>ond.' . "\n" . '</p></div><div id="mw-inlinecomment-annotations"><aside id="mw-inlinecomment-aside-abc" class="mw-inlinecomment-aside"><div class="mw-inlinecomment-text"><div class="mw-inlinecomment-comment"><div><p>Hello Paragraph</p><div class="mw-inlinecomment-author"><a href="/wiki/Special:Contributions/127.0.0.2" class="mw-userlink mw-anonuserlink" title="Special:Contributions/127.0.0.2"><bdi>127.0.0.2</bdi></a></div></div></div></div></aside></div>',
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
			'<div class="mw-parser-output"><p>Th<span class="mw-annotation-highlight mw-annotation-abc" title="Hello Paragraph" data-mw-highlight-id="abc">is is </span><i><span class="mw-annotation-highlight mw-annotation-abc" title="Hello Paragraph" data-mw-highlight-id="abc">first</span></i> paragraph.' . "\n" . '</p><p>This is the second.' . "\n" . '</p></div><div id="mw-inlinecomment-annotations"><aside id="mw-inlinecomment-aside-abc" class="mw-inlinecomment-aside"><div class="mw-inlinecomment-text"><div class="mw-inlinecomment-comment"><div><p>Hello Paragraph</p><div class="mw-inlinecomment-author"><a href="/wiki/Special:Contributions/127.0.0.2" class="mw-userlink mw-anonuserlink" title="Special:Contributions/127.0.0.2"><bdi>127.0.0.2</bdi></a></div></div></div></div></aside></div>',
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
			'<div class="mw-parser-output"><p>Begin. <span class="mw-annotation-highlight mw-annotation-first" title="f" data-mw-highlight-id="first">First <span class="mw-annotation-highlight mw-annotation-second" title="s" data-mw-highlight-id="second">overlap</span></span><span class="mw-annotation-highlight mw-annotation-second" title="s" data-mw-highlight-id="second"> second.</span> End</p></div><div id="mw-inlinecomment-annotations"><aside id="mw-inlinecomment-aside-first" class="mw-inlinecomment-aside"><div class="mw-inlinecomment-text"><div class="mw-inlinecomment-comment"><div><p>f</p><div class="mw-inlinecomment-author"><a href="/wiki/Special:Contributions/127.0.0.2" class="mw-userlink mw-anonuserlink" title="Special:Contributions/127.0.0.2"><bdi>127.0.0.2</bdi></a></div></div></div></div></aside><aside id="mw-inlinecomment-aside-second" class="mw-inlinecomment-aside"><div class="mw-inlinecomment-text"><div class="mw-inlinecomment-comment"><div><p>s</p><div class="mw-inlinecomment-author"><a href="/wiki/Special:Contributions/127.0.0.3" class="mw-userlink mw-anonuserlink" title="Special:Contributions/127.0.0.3"><bdi>127.0.0.3</bdi></a></div></div></div></div></aside></div>',
			'Overlapped comments properly nest span tags'
		];
		yield [
			'<div class="mw-parser-output"><p>Begin. First o<i>v</i><b><ins>e</ins></b>r<i>l</i>ap second. End</p></div>',
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
			'<div class="mw-parser-output"><p>Begin. <span class="mw-annotation-highlight mw-annotation-first" title="f" data-mw-highlight-id="first">First <span class="mw-annotation-highlight mw-annotation-second" title="s" data-mw-highlight-id="second">o<i>v</i><b><ins>e</ins></b>r<i>l</i>ap</span></span><span class="mw-annotation-highlight mw-annotation-second" title="s" data-mw-highlight-id="second"> second.</span> End</p></div><div id="mw-inlinecomment-annotations"><aside id="mw-inlinecomment-aside-first" class="mw-inlinecomment-aside"><div class="mw-inlinecomment-text"><div class="mw-inlinecomment-comment"><div><p>f</p><div class="mw-inlinecomment-author"><a href="/wiki/Special:Contributions/127.0.0.2" class="mw-userlink mw-anonuserlink" title="Special:Contributions/127.0.0.2"><bdi>127.0.0.2</bdi></a></div></div></div></div></aside><aside id="mw-inlinecomment-aside-second" class="mw-inlinecomment-aside"><div class="mw-inlinecomment-text"><div class="mw-inlinecomment-comment"><div><p>s</p><div class="mw-inlinecomment-author"><a href="/wiki/Special:Contributions/127.0.0.3" class="mw-userlink mw-anonuserlink" title="Special:Contributions/127.0.0.3"><bdi>127.0.0.3</bdi></a></div></div></div></div></aside></div>',
			'Overlapped comments properly nest span tags with children'
		];
	}

	// phpcs:disable Generic.Files.LineLength.TooLong
	public function provideUserMention() {
		yield [
			[
				[
					'id' => 'abc',
					'comments' => [ [
						'comment' => '@TestUser is the one testing this',
						'userId' => 1,
						'actorId' => 1,
						'username' => 'TestUser',
						'timestamp' => '2024-05-01T00:00:00+00:00'
					] ],
				]
			],
			'<div id="mw-inlinecomment-annotations"><aside id="mw-inlinecomment-aside-abc" class="mw-inlinecomment-aside"><div class="mw-inlinecomment-text"><div class="mw-inlinecomment-comment"><div><p>@<a href="/index.php?title=User:TestUser&amp;action=edit&amp;redlink=1" class="new mw-userlink" title="User:TestUser (page does not exist)"><bdi>TestUser</bdi></a> is the one testing this</p><div class="mw-inlinecomment-author"><a href="/index.php?title=User:TestUser&amp;action=edit&amp;redlink=1" class="new mw-userlink" title="User:TestUser (page does not exist)"><bdi>TestUser</bdi></a> 00:00, 1 May 2024</div></div><button class="mw-inlinecomment-editlink" title="Edit" type="submit">🖉</button></div></div></aside></div>',
			'real user mention'
		];
		yield [
			[
				[
					'id' => 'abc',
					'comments' => [ [
						'comment' => '@127.0.0.1 is an invalid user testing this',
						'userId' => 1,
						'actorId' => 1,
						'username' => '127.0.0.1',
						'timestamp' => '2024-05-01T00:00:00+00:00'
					] ],
				]
			],
			'<div id="mw-inlinecomment-annotations"><aside id="mw-inlinecomment-aside-abc" class="mw-inlinecomment-aside"><div class="mw-inlinecomment-text"><div class="mw-inlinecomment-comment"><div><p>@127.0.0.1 is an invalid user testing this</p><div class="mw-inlinecomment-author"><a href="/index.php?title=User:127.0.0.1&amp;action=edit&amp;redlink=1" class="new mw-userlink" title="User:127.0.0.1 (page does not exist)"><bdi>127.0.0.1</bdi></a> 00:00, 1 May 2024</div></div></div></div></aside></div>',
			'invalid user mention'
		];
	}
}
