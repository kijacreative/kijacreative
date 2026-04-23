<?php
# Database Configuration
define( 'DB_NAME', 'wp_kijacreativstg' );
define( 'DB_USER', 'kijacreativstg' );
define( 'DB_PASSWORD', 'NS-aQWddKG5awtztP-xV' );
define( 'DB_HOST', '127.0.0.1:3306' );
define( 'DB_HOST_SLAVE', '127.0.0.1:3306' );
define('DB_CHARSET', 'utf8');
define('DB_COLLATE', 'utf8_unicode_ci');
$table_prefix = 'wp_';

# Security Salts, Keys, Etc
define('AUTH_KEY',         '_cazAC=7@jh0#Nr8a(J.1~N)noZ3GkBJ^&GAtUgY66K,Luy9vCuvs.O^MtDW7UTf');
define('SECURE_AUTH_KEY',  'J$TsK=8hjToFX)E!LuGUrYb,DOzQaVnqan.OnWgX)-=J-nA9I=7=vRT*X.h!t2=Q');
define('LOGGED_IN_KEY',    '+7N7bIz5JQEe*)(APhaG7Wep_N%(?BFG1c_qmv)278?mSkK!gC!@@h=@S#3cr21D');
define('NONCE_KEY',        'Cg+L%$,InI.^lpl@1JqkK9uMRioBmD5Z9giES.3)oiW~K*I842IH?NyFpz9sC4+X');
define('AUTH_SALT',        '$fkn~%17^4-nash7(FUPaG0Y~sOb*bS*0Ian%Bjjfq&5~y_$Gjce@HjA_adX?pvY');
define('SECURE_AUTH_SALT', '-$D@mpXZG~TGhVXhV#=9Df=W9BCZQdXaqaE_sX$wj3ayRIsvMoSQp,87=V9_cCag');
define('LOGGED_IN_SALT',   'YKvYb1pYQ&.MLzQ*zyua0gOe!UOU5IsT23?3)=V@,#+GsB0XcG=HnjUbwj?&2I++');
define('NONCE_SALT',       'y,n$ZK#D^FQs7^$Oq3rOb~T&=@YJQ(Yop$P0A@s@1ikTJjqKMD0vKJ^Aa3$yNGHO');


# Localized Language Stuff

define( 'WP_CACHE', TRUE );

define( 'WP_AUTO_UPDATE_CORE', false );

define( 'PWP_NAME', 'kijacreativstg' );

define( 'FS_METHOD', 'direct' );

define( 'FS_CHMOD_DIR', 0775 );

define( 'FS_CHMOD_FILE', 0664 );

define( 'WPE_APIKEY', '551ea282894056faba191ef2acc00d2218e0f970' );

define( 'WPE_CLUSTER_ID', '400745' );

define( 'WPE_CLUSTER_TYPE', 'pod' );

define( 'WPE_ISP', true );

define( 'WPE_BPOD', false );

define( 'WPE_RO_FILESYSTEM', false );

define( 'WPE_LARGEFS_BUCKET', 'largefs.wpengine' );

define( 'WPE_SFTP_PORT', 2222 );

define( 'WPE_SFTP_ENDPOINT', '34.44.227.159' );

define( 'WPE_LBMASTER_IP', '' );

define( 'WPE_CDN_DISABLE_ALLOWED', true );

define( 'DISALLOW_FILE_MODS', FALSE );

define( 'DISALLOW_FILE_EDIT', FALSE );

define( 'DISABLE_WP_CRON', false );

define( 'WPE_FORCE_SSL_LOGIN', false );

define( 'FORCE_SSL_LOGIN', false );

/*SSLSTART*/ if ( isset($_SERVER['HTTP_X_WPE_SSL']) && $_SERVER['HTTP_X_WPE_SSL'] ) $_SERVER['HTTPS'] = 'on'; /*SSLEND*/

define( 'WPE_EXTERNAL_URL', false );

define( 'WP_POST_REVISIONS', FALSE );

define( 'WPE_WHITELABEL', 'wpengine' );

define( 'WP_TURN_OFF_ADMIN_BAR', false );

define( 'WPE_BETA_TESTER', false );

umask(0002);

$wpe_cdn_uris=array ( );

$wpe_no_cdn_uris=array ( );

$wpe_content_regexs=array ( );

$wpe_all_domains=array ( 0 => 'kijacreativstg.wpengine.com', 1 => 'kijacreativstg.wpenginepowered.com', );

$wpe_varnish_servers=array ( 0 => '127.0.0.1', );

$wpe_special_ips=array ( 0 => '104.198.231.166', 1 => 'pod-400745-utility.pod-400745.svc.cluster.local', );

$wpe_netdna_domains=array ( );

$wpe_netdna_domains_secure=array ( );

$wpe_netdna_push_domains=array ( );

$wpe_domain_mappings=array ( );

$memcached_servers=array ( 'default' =>  array ( 0 => 'unix:///tmp/memcached.sock', ), );
define('WPLANG','');

# WP Engine ID


# WP Engine Settings






# That's It. Pencils down
if ( !defined('ABSPATH') )
	define('ABSPATH', __DIR__ . '/');
require_once(ABSPATH . 'wp-settings.php');
