<?php
/**
 * A special page providing means to manually refresh special pages
 *
 * @file
 * @ingroup Extensions
 * @author Bartek Łapiński <bartek@wikia-inc.com>
 * @author Jack Phoenix <jack@countervandalism.net>
 * @license http://www.gnu.org/copyleft/gpl.html GNU General Public License 2.0 or later
 * @link https://www.mediawiki.org/wiki/Extension:RefreshSpecial Documentation
 */

if ( !defined( 'MEDIAWIKI' ) ){
	die( "This is not a valid entry point.\n" );
}

// Extension credits that will be shown on Special:Version
$wgExtensionCredits['specialpage'][] = array(
	'path' => __FILE__,
	'name' => 'Refresh Special',
	'author' => array( 'Bartek Łapiński', 'Jack Phoenix' ),
	'version' => '1.4.0',
	'url' => 'https://www.mediawiki.org/wiki/Extension:RefreshSpecial',
	'descriptionmsg' => 'refreshspecial-desc',
);

// ResourceLoader support for MediaWiki 1.17+
$wgResourceModules['ext.refreshspecial'] = array(
	'scripts' => 'RefreshSpecial.js',
	'localBasePath' => __DIR__,
	'remoteExtPath' => 'RefreshSpecial'
);

// New user right, required to use Special:RefreshSpecial
$wgAvailableRights[] = 'refreshspecial';
$wgGroupPermissions['bureaucrat']['refreshspecial'] = true;

// Set up the new special page
$wgMessagesDirs['RefreshSpecial'] = __DIR__ . '/i18n';
$wgExtensionMessagesFiles['RefreshSpecial'] = __DIR__ . '/RefreshSpecial.i18n.php';
$wgExtensionMessagesFiles['RefreshSpecialAlias'] = __DIR__ . '/RefreshSpecial.alias.php';
$wgAutoloadClasses['RefreshSpecial'] = __DIR__ . '/RefreshSpecial.body.php';
$wgAutoloadClasses['RefreshSpecialForm'] = __DIR__ . '/RefreshSpecial.body.php';
$wgSpecialPages['RefreshSpecial'] = 'RefreshSpecial';

/* limits the number of refreshed rows */
define( 'REFRESHSPECIAL_ROW_LIMIT', 1000 );
/* interval between reconnects */
define( 'REFRESHSPECIAL_RECONNECTION_SLEEP', 10 );
/* amount of acceptable slave lag  */
define( 'REFRESHSPECIAL_SLAVE_LAG_LIMIT', 600 );
/* interval when slave is lagged */
define( 'REFRESHSPECIAL_SLAVE_LAG_SLEEP', 30 );
