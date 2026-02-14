<?php

use MediaWiki\Linker\LinkRenderer;
use MediaWiki\SpecialPage\SpecialPageFactory;
use Wikimedia\Rdbms\ILoadBalancer;

/**
 * A special page providing means to manually refresh special pages
 *
 * @ingroup Extensions
 * @author Bartek Łapiński <bartek@wikia-inc.com>
 * @author Jack Phoenix
 * @license GPL-2.0-or-later
 * @link https://www.mediawiki.org/wiki/Extension:RefreshSpecial Documentation
 */
class RefreshSpecial extends SpecialPage {

	/** @var int limits the number of refreshed rows */
	public const ROW_LIMIT = 1000;
	/** @var int interval between reconnects */
	public const RECONNECTION_SLEEP = 10;
	/** @var int amount of acceptable replica lag */
	public const REPLICA_LAG_LIMIT = 600;
	/** @var int interval when replica is lagged */
	public const REPLICA_LAG_SLEEP = 30;

	public function __construct(
		private readonly ILoadBalancer $dbLoadBalancer,
		private readonly LinkRenderer $linkRenderer,
		private readonly SpecialPageFactory $specialPageFactory,
	) {
		parent::__construct( 'RefreshSpecial', 'refreshspecial' );
	}

	/** @inheritDoc */
	public function doesWrites() {
		return true;
	}

	/** @inheritDoc */
	public function execute( $par ): void {
		$out = $this->getOutput();
		$request = $this->getRequest();
		$user = $this->getUser();

		// Can the user execute the action?
		$this->checkPermissions();

		// Is the database in read-only mode?
		$this->checkReadOnly();

		// Is the user blocked? If so they can't make new wikis
		if ( $user->getBlock() ) {
			throw new UserBlockedError( $user->getBlock() );
		}

		// Bump up PHP's memory and time limits a bit, the defaults aren't good
		// enough; this extension's pretty intensive
		ini_set( 'memory_limit', '512M' );
		set_time_limit( 240 );

		$out->setPageTitleMsg( $this->msg( 'refreshspecial-title' ) );

		$cSF = new RefreshSpecialForm( $this->dbLoadBalancer, $this->linkRenderer, $this->specialPageFactory );
		$cSF->setContext( $this->getContext() );

		$action = $request->getVal( 'action' );
		if ( $action === 'success' ) {
			/* do something */
		} elseif ( $action === 'failure' ) {
			$cSF->showForm( $this->msg( 'refreshspecial-fail' )->plain() );
		} elseif ( $request->wasPosted() && $action === 'submit' &&
			$user->matchEditToken( $request->getVal( 'wpEditToken' ) ) ) {
			$cSF->doSubmit();
		} else {
			$cSF->showForm( '' );
		}
	}

	/** @inheritDoc */
	protected function getGroupName() {
		return 'wiki';
	}
}
