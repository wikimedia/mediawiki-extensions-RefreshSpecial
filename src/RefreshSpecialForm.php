<?php

use MediaWiki\Html\Html;
use MediaWiki\Linker\LinkRenderer;
use MediaWiki\SpecialPage\SpecialPageFactory;
use Wikimedia\Rdbms\ILoadBalancer;

/**
 * RefreshSpecialForm class
 * Constructs and displays the form
 */
class RefreshSpecialForm extends ContextSource {

	public function __construct(
		private readonly ILoadBalancer $dbLoadBalancer,
		private readonly LinkRenderer $linkRenderer,
		private readonly SpecialPageFactory $specialPageFactory,
	) {
	}

	/**
	 * Show the actual form
	 *
	 * @param string $err Error message if there was an error, otherwise empty
	 */
	public function showForm( string $err ): void {
		$out = $this->getOutput();

		$token = htmlspecialchars( $this->getUser()->getEditToken() );
		$titleObj = SpecialPage::getTitleFor( 'RefreshSpecial' );
		$action = htmlspecialchars( $titleObj->getLocalURL( 'action=submit' ) );

		if ( $err != '' ) {
			$out->setSubtitle( $this->msg( 'formerror' )->escaped() );
			$out->addHTML( Html::element( 'p', [
				'class' => 'error',
			], $err ) . "\n" );
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
			[ , $special ] = $page;

			/** @var QueryPage $queryPage */
			$queryPage = $this->specialPageFactory->getPage( $special );
			if ( !$queryPage ) {
				$out->addWikiTextAsInterface( $this->msg( 'refreshspecial-no-page' )->plain() . " $special\n" );
				exit;
			}

			if ( $queryPage->isExpensive() ) {
				$checked = 'checked="checked"';
				$specialEsc = htmlspecialchars( $special );
				$out->addHTML(
					"\t\t\t\t\t<li>
						<input type=\"checkbox\" name=\"wpSpecial[]\" value=\"$specialEsc\" $checked />
						<b>" . htmlspecialchars( $queryPage->getDescription() ) . "</b>
					</li>\n"
				);
			}
		}

		$out->addHTML(
			"\t\t\t\t\t" . '<li>
						<input type="checkbox" name="check_all" id="refreshSpecialCheckAll" checked="checked" />
						<label for="refreshSpecialCheckAll">&#160;' . $this->msg( 'refreshspecial-select-all-pages' )->escaped() . '
							<noscript>' . $this->msg( 'refreshspecial-js-disabled' )->parse() . '</noscript>
						</label>
					</li>
				</ul>
				<input tabindex="5" name="wpRefreshSpecialSubmit" type="submit" value="' . $this->msg( 'refreshspecial-button' )->escaped() . '" />
				<input type="hidden" name="wpEditToken" value="' . $token . '" />
			</form>' . "\n"
		);
	}

	/**
	 * Take amount of elapsed time, produce hours (hopefully never needed...), minutes, seconds
	 *
	 * @param int $amount
	 * @return int[] Amount of elapsed time
	 */
	private function computeTime( $amount ): array {
		$hours = intval( $amount / 3600 );
		$minutes = intval( (int)$amount % 3600 / 60 );
		return [
			'hours' => $hours,
			'minutes' => $minutes,
			'seconds' => $amount - $hours * 3600 - $minutes * 60
		];
	}

	/**
	 * Format the time message
	 *
	 * @param mixed $time Amount of time, with h, m or s appended to it
	 * @param mixed &$message Message displayed to the user containing the elapsed time
	 */
	private function formatTimeMessage( $time, &$message ): void {
		if ( $time['hours'] ) {
			$message .= $time['hours'] . 'h ';
		}
		if ( $time['minutes'] ) {
			$message .= $time['minutes'] . 'm ';
		}
		$message .= $time['seconds'] . 's';
	}

	/**
	 * This actually refreshes the special pages
	 * Will need to be modified further
	 */
	private function refreshSpecial(): void {
		$out = $this->getOutput();

		$to_refresh = $this->getRequest()->getArray( 'wpSpecial' );
		$total = [
			'pages' => 0,
			'rows' => 0,
			'elapsed' => 0,
			'total_elapsed' => 0
		];

		foreach ( QueryPage::getPages() as $page ) {
			[ , $special ] = $page;
			$limit = $page[2] ?? null;
			if ( !in_array( $special, $to_refresh ) ) {
				continue;
			}

			/** @var QueryPage $queryPage */
			$queryPage = $this->specialPageFactory->getPage( $special );
			if ( !$queryPage ) {
				$out->addWikiTextAsInterface( $this->msg( 'refreshspecial-no-page' )->plain() . ": $special\n" );
				exit;
			}

			if ( !( isset( $options['only'] ) ) || ( $options['only'] == $queryPage->getName() ) ) {
				$out->addHTML( Html::element( 'b', [], $special ) . ': ' );

				if ( $queryPage->isExpensive() ) {
					$t1 = microtime( true );
					# Do the query
					$num = $queryPage->recache( $limit === null ? RefreshSpecial::ROW_LIMIT : $limit );
					$t2 = microtime( true );

					if ( $num === false ) {
						$out->addHTML( $this->msg( 'refreshspecial-db-error' )->escaped() . '<br />' );
					} else {
						$message = $this->msg(
							'refreshspecial-page-result',
							$num
						)->parse() . '&#160;';
						$elapsed = $t2 - $t1;
						$total['elapsed'] += $elapsed;
						$total['rows'] += $num;
						$total['pages']++;
						$ftime = $this->computeTime( $elapsed );
						$this->formatTimeMessage( $ftime, $message );
						$out->addHTML( "$message<br />" );
					}

					$t1 = microtime( true );

					# Reopen any connections that have closed
					if ( !$this->dbLoadBalancer->pingAll() ) {
						$out->addHTML( '<br />' );
						do {
							$out->addHTML( $this->msg( 'refreshspecial-reconnecting' )->escaped() . '<br />' );
							sleep( RefreshSpecial::RECONNECTION_SLEEP );
						} while ( !$this->dbLoadBalancer->pingAll() );
						$out->addHTML( $this->msg( 'refreshspecial-reconnected' )->escaped() . '<br /><br />' );
					}

					# Wait for the replica to catch up
					$replicaDB = $this->dbLoadBalancer->getConnection( DB_REPLICA, [ 'QueryPage::recache', 'vslow' ] );
					while ( $replicaDB->getLag() > RefreshSpecial::REPLICA_LAG_LIMIT ) {
						$out->addHTML( $this->msg( 'refreshspecial-replica-lagged' )->escaped() . '<br />' );
						sleep( RefreshSpecial::REPLICA_LAG_SLEEP );
					}

					$elapsed_total = microtime( true ) - $t1;
					$total['total_elapsed'] += $elapsed + $elapsed_total;
				} else {
					$out->addHTML( $this->msg( 'refreshspecial-skipped' )->escaped() . '<br />' );
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
	public function doSubmit(): void {
		/* guard against an empty array */
		$array = $this->getRequest()->getArray( 'wpSpecial' );
		if ( !$array ) {
			$this->showForm( $this->msg( 'refreshspecial-none-selected' )->plain() );
			return;
		}

		$this->getOutput()->setSubtitle(
			$this->msg( 'refreshspecial-choice',
				$this->msg( 'refreshspecial-refreshing' )->plain()
			)->escaped()
		);
		$this->refreshSpecial();

		$titleObj = SpecialPage::getTitleFor( 'RefreshSpecial' );
		$link_back = $this->linkRenderer->makeKnownLink(
			$titleObj,
			$this->msg( 'refreshspecial-link-back' )->plain()
		);
		$this->getOutput()->addHTML( '<br /><b>' . $link_back . '</b>' );
	}

}
