<?php
/**
 * The base configuration for WordPress
 *
 * The wp-config.php creation script uses this file during the installation.
 * You don't have to use the website, you can copy this file to "wp-config.php"
 * and fill in the values.
 *
 * This file contains the following configurations:
 *
 * * Database settings
 * * Secret keys
 * * Database table prefix
 * * ABSPATH
 *
 * @link https://developer.wordpress.org/advanced-administration/wordpress/wp-config/
 *
 * @package WordPress
 */

// ** Database settings - You can get this info from your web host ** //
/** The name of the database for WordPress */
define( 'DB_NAME', 'WordPress' );

/** Database username */
define( 'DB_USER', 'WordPressUser' );

/** Database password */
define( 'DB_PASSWORD', 'wordpress' );

/** Database hostname */
define( 'DB_HOST', 'localhost' );

/** Database charset to use in creating database tables. */
define( 'DB_CHARSET', 'utf8mb4' );

/** The database collate type. Don't change this if in doubt. */
define( 'DB_COLLATE', '' );

/**#@+
 * Authentication unique keys and salts.
 *
 * Change these to different unique phrases! You can generate these using
 * the {@link https://api.wordpress.org/secret-key/1.1/salt/ WordPress.org secret-key service}.
 *
 * You can change these at any point in time to invalidate all existing cookies.
 * This will force all users to have to log in again.
 *
 * @since 2.6.0
 */
define( 'AUTH_KEY',         'M50,Hlh`pkc#S%Vou=Zz=;=D,78fppbRp&8=*_gdm(UPEL{z,#~@:%u#:>>S|uoG' );
define( 'SECURE_AUTH_KEY',  ')},CmV75afujt-!KN51*.%c,:9AkNk~B(8+hF X~rS]i+e ~hv}m,T8Sb_A0foo0' );
define( 'LOGGED_IN_KEY',    'n-exkY<Yw/9v&a8d+tJn~O<O%zF.ebc;pUmqUA?lbqM,80tL)EJrEYu@`n`JX8c_' );
define( 'NONCE_KEY',        ':6:wuv+_qf~h2A,Kgt1gPYS/ )n6LQpgV`E|4rx~2: U%l]x0L^j.tj,59:<P*GE' );
define( 'AUTH_SALT',        'E17cP{K+%y)g[)fmi&:Zp]_w=!2z*n{Q_dAb#e86a(Ti4l[`I3?<gB3PSRycsL<)' );
define( 'SECURE_AUTH_SALT', '8&Ni; H tjl;4=!`=A6|o[RO|^(Nn8niPA}`C;7iPhfluf@u2jKBKm5MT.?b w}F' );
define( 'LOGGED_IN_SALT',   '+H/[gwpk@.@h]@c_?<a`7k?CQ>!.I*AOzB3u1HGb{01.VV)5srPX,DenYaF)!w}d' );
define( 'NONCE_SALT',       'w+)|_XO*Jt?Va[j3pN~1eSV$2Zb.JW#U^)amb4wE@30R:#5r!IgMczl~NTu[#sOK' );

/**#@-*/

/**
 * WordPress database table prefix.
 *
 * You can have multiple installations in one database if you give each
 * a unique prefix. Only numbers, letters, and underscores please!
 *
 * At the installation time, database tables are created with the specified prefix.
 * Changing this value after WordPress is installed will make your site think
 * it has not been installed.
 *
 * @link https://developer.wordpress.org/advanced-administration/wordpress/wp-config/#table-prefix
 */
$table_prefix = 'wp_';

/**
 * For developers: WordPress debugging mode.
 *
 * Change this to true to enable the display of notices during development.
 * It is strongly recommended that plugin and theme developers use WP_DEBUG
 * in their development environments.
 *
 * For information on other constants that can be used for debugging,
 * visit the documentation.
 *
 * @link https://developer.wordpress.org/advanced-administration/debug/debug-wordpress/
 */
define( 'WP_DEBUG', false );

/* Add any custom values between this line and the "stop editing" line. */



/* That's all, stop editing! Happy publishing. */

/** Absolute path to the WordPress directory. */
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

/** Sets up WordPress vars and included files. */
require_once ABSPATH . 'wp-settings.php';
