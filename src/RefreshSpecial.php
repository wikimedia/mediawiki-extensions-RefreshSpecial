<?php

/**
 * A special page providing means to manually refresh special pages
 *
 * @file
 * @ingroup Extensions
 * @author Bartek Łapiński <bartek@wikia-inc.com>
 * @author Jack Phoenix
 * @license GPL-2.0-or-later
 * @link https://www.mediawiki.org/wiki/Extension:RefreshSpecial Documentation
 */
class RefreshSpecial extends SpecialPage {

	/* limits the number of refreshed rows */
	const ROW_LIMIT = 1000;
	/* interval between reconnects */
	const RECONNECTION_SLEEP = 10;
	/* amount of acceptable slave lag  */
	const SLAVE_LAG_LIMIT = 600;
	/* interval when slave is lagged */
	const SLAVE_LAG_SLEEP = 30;

	/**
	 * Constructor
	 */
	public function __construct() {
		parent::__construct( 'RefreshSpecial'/*class*/, 'refreshspecial'/*restriction*/ );
	}

	public function doesWrites() {
		return true;
	}

	/**
	 * Show the special page
	 *
	 * @param mixed $par Parameter passed to the page or null
	 */
	public function execute( $par ) {
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

		$out->setPageTitle( $this->msg( 'refreshspecial-title' )->escaped() );

		$cSF = new RefreshSpecialForm();
		$cSF->setContext( $this->getContext() );

		$action = $request->getVal( 'action' );
		if ( $action == 'success' ) {
			/* do something */
		} elseif ( $action == 'failure' ) {
			$cSF->showForm( $this->msg( 'refreshspecial-fail' )->plain() );
		} elseif ( $request->wasPosted() && $action == 'submit' &&
			$user->matchEditToken( $request->getVal( 'wpEditToken' ) ) ) {
			$cSF->doSubmit();
		} else {
			$cSF->showForm( '' );
		}
	}

	/**
	 * @inheritDoc
	 */
	protected function getGroupName() {
		return 'wiki';
	}
}
