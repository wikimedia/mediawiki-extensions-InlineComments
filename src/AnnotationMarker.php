<?php
namespace MediaWiki\Extension\InlineComments;

// Namespace got renamed to be prefixed with Wikimedia!
use Config;
use Language;
use MediaWiki\Permissions\PermissionManager;
use MediaWiki\Title\Title;
use MediaWiki\User\ActorStore;
use MediaWiki\User\UserFactory;
use User;
use Wikimedia\Rdbms\LBFactory;
use Wikimedia\RemexHtml\HTMLData;
use Wikimedia\RemexHtml\Serializer\Serializer;
use Wikimedia\RemexHtml\Tokenizer\Tokenizer;
use Wikimedia\RemexHtml\TreeBuilder\Dispatcher;
use Wikimedia\RemexHtml\TreeBuilder\TreeBuilder;

// Based on MediaWiki\Html\HtmlHelper

class AnnotationMarker {
	public const SERVICE_NAME = 'InlineComments:AnnotationMarker';

	/** @var Config */
	private $config;
	/** @var AnnotationUtils */
	private $utils;
	/** @var UserFactory */
	private $userFactory;

	/** @var PermissionManager */
	private $permissionManager;
	/** @var ActorStore */
	private $actorStore;
	/** @var LBFactory */
	private $dbLoadBalancerFactory;

	/**
	 * @param Config $config
	 * @param UserFactory $userFactory
	 * @param PermissionManager $permissionManager
	 * @param LBFactory $dbLoadBalancerFactory
	 * @param ActorStore $actorStore
	 */
	public function __construct(
		Config $config,
		UserFactory $userFactory,
		PermissionManager $permissionManager,
		LBFactory $dbLoadBalancerFactory,
		ActorStore $actorStore
	) {
		$this->config = $config;
		$this->utils = new AnnotationUtils( $userFactory, $dbLoadBalancerFactory, $actorStore );
		$this->userFactory = $userFactory;
		$this->permissionManager = $permissionManager;
	}

	/**
	 * Add annotations to html
	 *
	 * @param string $html Input html
	 * @param AnnotationContent $annotationContent The annotations to add
	 * @param Language $reqLanguage for formatting timestamps
	 * @param User $reqUser for formatting timestamps
	 * @param Title $reqTitle for checking admin permissions
	 * @return string HTML with annotations and asides added
	 */
	public function markUp(
		string $html,
		AnnotationContent $annotationContent,
		Language $reqLanguage,
		User $reqUser,
		Title $reqTitle
	) {
		return $this->markUpAndGetUnused(
			$html,
			$annotationContent,
			$reqLanguage,
			$reqUser,
			$reqTitle
		)[0];
	}

	/**
	 * Add annotations to html and get unused arrays.
	 *
	 * @param string $html Input html
	 * @param AnnotationContent $annotationsContent The annotations to add
	 * @param Language $reqLanguage for formatting timestamps
	 * @param User $reqUser for formatting timestamps
	 * @return array HTML with annotations and asides added, plus array of annotations not used.
	 */
	public function markUpAndGetUnused(
		string $html,
		AnnotationContent $annotationsContent,
		Language $reqLanguage,
		User $reqUser,
		Title $reqTitle
	) {
		$annotations = $annotationsContent->getData()->getValue();
		// TODO: We may want to set the performance optimisation options.

		$alist = null;
		if ( $this->config->get( 'InlineCommentsAutoDeleteComments' ) ) {
			$getUnusedAnnotations = static function () use ( &$alist ) {
				// @phan-suppress-next-line PhanNonClassMethodCall
				return $alist->getUnusedAnnotations();
			};
		} else {
			$getUnusedAnnotations = static function () {
				return [];
			};
		}

		$annotationFormatter = new AnnotationFormatter(
			[],
			$annotations,
			$getUnusedAnnotations,
			$reqLanguage,
			$reqUser,
			$this->utils,
			$this->permissionManager,
			$reqTitle
		);
		$serializer = new Serializer( $annotationFormatter );
		$alist = new AnnotationTreeHandler( $serializer, $annotations );
		$treeBuilder = new TreeBuilder( $alist );
		$dispatcher = new Dispatcher( $treeBuilder );
		$tokenizer = new Tokenizer( $dispatcher, $html );
		$tokenizer->execute( [
			'fragmentNamespace' => HTMLData::NS_HTML,
			'fragmentName' => 'body'
		] );
		return [ $serializer->getResult(), $alist->getUnusedAnnotations() ];
	}
}
