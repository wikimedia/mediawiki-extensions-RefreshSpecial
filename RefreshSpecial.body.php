<?php

use MediaWiki\MediaWikiServices;

/**
 * A special page providing means to manually refresh special pages
 *
 * @file
 * @ingroup Extensions
 * @author Bartek Łapiński <bartek@wikia-inc.com>
 * @author Jack Phoenix
 * @license http://www.gnu.org/copyleft/gpl.html GNU General Public License 2.0 or later
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
		if ( $user->isBlocked() ) {
			throw new UserBlockedError( $user->getBlock() );
		}

		// Bump up PHP's memory and time limits a bit, the defaults aren't good
		// enough; this extension's pretty intensive
		ini_set( 'memory_limit', '512M' );
		set_time_limit( 240 );

		$out->setPageTitle( $this->msg( 'refreshspecial-title' )->plain() );

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

	protected function getGroupName() {
		return 'wiki';
	}
}

/**
 * RefreshSpecialForm class
 * Constructs and displays the form
 */
class RefreshSpecialForm extends ContextSource {
	public $mLink;

	/**
	 * Show the actual form
	 *
	 * @param string $err Error message if there was an error, otherwise empty
	 */
	function showForm( $err ) {
		$out = $this->getOutput();

		$token = htmlspecialchars( $this->getUser()->getEditToken() );
		$titleObj = SpecialPage::getTitleFor( 'RefreshSpecial' );
		$action = htmlspecialchars( $titleObj->getLocalURL( 'action=submit' ) );

		if ( $err != '' ) {
			$out->setSubtitle( $this->msg( 'formerror' )->escaped() );
			$out->addHTML( "<p class='error'>{$err}</p>\n" );
		}

		$out->addWikiMsg( 'refreshspecial-help' );

		// Add the JavaScript via ResourceLoader
		$out->addModules( 'ext.refreshspecial' );

		$out->addHTML(
			"<form name=\"RefreshSpecial\" method=\"post\" action=\"{$action}\">
				<ul>\n"
		);

		/**
		 * List pages right here
		 *
		 * @todo Display a time estimate or a raw factor
		 * I guess it's not that important, since we have a 1000 rows limit on refresh?
		 * that brings up an interesting question - do we need that limit or not?
		 */
		foreach ( QueryPage::getPages() as $page ) {
			list( $class, $special ) = $page;

			/** @var QueryPage $specialObj */
			$specialObj = MediaWikiServices::getInstance()->getSpecialPageFactory()->getPage( $special );
			if ( !$specialObj ) {
		  		$out->addWikiTextAsInterface( $this->msg( 'refreshspecial-no-page' )->plain() . " $special\n" );
				exit;
			}

			/** @var QueryPage $queryPage */
			$queryPage = new $class;

			if ( $queryPage->isExpensive() ) {
				$checked = 'checked="checked"';
				$specialEsc = htmlspecialchars( $special );
				$out->addHTML(
					"\t\t\t\t\t<li>
						<input type=\"checkbox\" name=\"wpSpecial[]\" value=\"$specialEsc\" $checked />
						<b>"  . htmlspecialchars( $specialObj->getDescription() ) . "</b>
					</li>\n"
				);
			}
		}

		$out->addHTML(
			"\t\t\t\t\t" . '<li>
						<input type="checkbox" name="check_all" id="refreshSpecialCheckAll" checked="checked" />
						<label for="refreshSpecialCheckAll">&#160;' . $this->msg( 'refreshspecial-select-all-pages' )->plain() . '
							<noscript>' . $this->msg( 'refreshspecial-js-disabled' )->parse() . '</noscript>
						</label>
					</li>
				</ul>
				<input tabindex="5" name="wpRefreshSpecialSubmit" type="submit" value="' . $this->msg( 'refreshspecial-button' )->plain() . '" />
				<input type="hidden" name="wpEditToken" value="' . $token . '" />
			</form>' . "\n"
		);
	}

	/**
	 * Take amount of elapsed time, produce hours (hopefully never needed...), minutes, seconds
	 *
	 * @param int $amount
	 * @return array Amount of elapsed time
	 */
	function computeTime( $amount ) {
		$return_array = array();
		$return_array['hours'] = intval( $amount / 3600 );
		$return_array['minutes'] = intval( $amount % 3600 / 60 );
		$return_array['seconds'] = $amount - $return_array['hours'] * 3600 - $return_array['minutes'] * 60;
		return $return_array;
	}

	/**
	 * Format the time message
	 *
	 * @param mixed $time Amount of time, with h, m or s appended to it
	 * @param mixed $message Message displayed to the user containing the elapsed time
	 * @return bool
	 */
	function formatTimeMessage( $time, &$message ) {
		if ( $time['hours'] ) {
			$message .= $time['hours'] . 'h ';
		}
		if ( $time['minutes'] ) {
			$message .= $time['minutes'] . 'm ';
		}
		$message .= $time['seconds'] . 's';
		return true;
	}

	/**
	 * This actually refreshes the special pages
	 * Will need to be modified further
	 */
	function refreshSpecial() {
		$out = $this->getOutput();

		$to_refresh = $this->getRequest()->getArray( 'wpSpecial' );
		$total = [
			'pages' => 0,
			'rows' => 0,
			'elapsed' => 0,
			'total_elapsed' => 0
		];

		foreach ( QueryPage::getPages() as $page ) {
			list( $class, $special ) = $page;
			$limit = isset( $page[2] ) ? $page[2] : null;
			if ( !in_array( $special, $to_refresh ) ) {
				continue;
			}

			/** @var QueryPage $specialObj */
			$specialObj = MediaWikiServices::getInstance()->getSpecialPageFactory()->getPage( $special );
			if ( !$specialObj ) {
			 	$out->addWikiTextAsInterface( $this->msg( 'refreshspecial-no-page' )->plain() . ": $special\n" );
				exit;
			}

			/** @var QueryPage $queryPage */
			$queryPage = new $class;

			if( !( isset( $options['only'] ) ) || ( $options['only'] == $queryPage->getName() ) ) {
				$out->addHTML( "<b>$special</b>: " );

				if ( $queryPage->isExpensive() ) {
					$t1 = explode( ' ', microtime() );
					# Do the query
					$num = $queryPage->recache( $limit === null ? RefreshSpecial::ROW_LIMIT : $limit );
					$t2 = explode( ' ', microtime() );

			  		if ( $num === false ) {
						$out->addHTML( $this->msg( 'refreshspecial-db-error' )->plain() . '<br />' );
					} else {
			  			$message = $this->msg(
							'refreshspecial-page-result',
							$num
						)->parse() . '&#160;';
						$elapsed = ( $t2[0] - $t1[0] ) + ( $t2[1] - $t1[1] );
						$total['elapsed'] += $elapsed;
						$total['rows'] += $num;
						$total['pages']++;
						$ftime = $this->computeTime( $elapsed );
						$this->formatTimeMessage( $ftime, $message );
						$out->addHTML( "$message<br />" );
					}

					$t1 = explode( ' ', microtime() );

					# Reopen any connections that have closed
					$lb = MediaWikiServices::getInstance()->getDBLoadBalancer();
					if ( !$lb->pingAll() ) {
						$out->addHTML( '<br />' );
						do {
							$out->addHTML( $this->msg( 'refreshspecial-reconnecting' )->plain() . '<br />' );
							sleep( RefreshSpecial::RECONNECTION_SLEEP );
						} while ( !$lb->pingAll() );
						$out->addHTML( $this->msg( 'refreshspecial-reconnected' )->plain() . '<br /><br />' );
					}

					# Wait for the slave to catch up
					$slaveDB = $lb->getConnection( DB_REPLICA, [ 'QueryPage::recache', 'vslow' ] );
					while ( $lb->safeGetLag( $slaveDB ) > RefreshSpecial::SLAVE_LAG_LIMIT ) {
						$out->addHTML( $this->msg( 'refreshspecial-slave-lagged' )->plain() . '<br />' );
						sleep( RefreshSpecial::SLAVE_LAG_SLEEP );
					}

					$t2 = explode( ' ', microtime() );
					$elapsed_total = ( $t2[0] - $t1[0] ) + ( $t2[1] - $t1[1] );
					$total['total_elapsed'] += $elapsed + $elapsed_total;
				} else {
					$out->addHTML( $this->msg( 'refreshspecial-skipped' )->plain() . '<br />' );
				}
			}
		}

		/* display all stats */
		$elapsed_message = '';
		$total_elapsed_message = '';
		$this->formatTimeMessage( $this->computeTime( $total['elapsed'] ), $elapsed_message );
		$this->formatTimeMessage( $this->computeTime( $total['total_elapsed'] ), $total_elapsed_message );
		$out->addHTML( '<br />' .
			$this->msg(
				'refreshspecial-total-display',
				$total['pages'],
				$total['rows'],
				$elapsed_message,
				$total_elapsed_message
			)->parse()
		);
		$out->addHTML( '</ul></form>' );
	}

	/**
	 * On submit
	 * Check that we weren't passed an empty array of special pages to refresh,
	 * and if we were, inform the user about that.
	 * Otherwise set the correct subtitle, perform the refreshing and render a
	 * link that points back to the special page.
	 */
	function doSubmit() {
		/* guard against an empty array */
		$array = $this->getRequest()->getArray( 'wpSpecial' );
		if ( !is_array( $array ) || empty( $array ) || is_null( $array ) ) {
			$this->showForm( $this->msg( 'refreshspecial-none-selected' )->plain() );
			return;
		}

		$this->getOutput()->setSubtitle(
			$this->msg( 'refreshspecial-choice',
				$this->msg( 'refreshspecial-refreshing' )->plain()
			)->plain()
		);
		$this->refreshSpecial();

		$titleObj = SpecialPage::getTitleFor( 'RefreshSpecial' );
		$link_back = MediaWikiServices::getInstance()->getLinkRenderer()->makeKnownLink(
			$titleObj,
			$this->msg( 'refreshspecial-link-back' )->plain()
		);
		$this->getOutput()->addHTML( '<br /><b>' . $link_back . '</b>' );
	}

}
