locals {
  # Injected into wp-config.php by the official WordPress image. WP Offload Media
  # reads AS3CF_SETTINGS, so no keys or admin-UI configuration are needed: it uses
  # the ECS task role.
  wordpress_config_extra = <<-PHP
    // Stateless deployment: code changes only ship through the image.
    define( 'DISALLOW_FILE_MODS', true );
    define( 'AUTOMATIC_UPDATER_DISABLED', true );
    define( 'WP_AUTO_UPDATE_CORE', false );
    define( 'WP_HOME', '${var.site_url}' );
    define( 'WP_SITEURL', '${var.site_url}' );
    if ( isset( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) && 'https' === $_SERVER['HTTP_X_FORWARDED_PROTO'] ) {
      $_SERVER['HTTPS'] = 'on';
    }

    define( 'AS3CF_SETTINGS', serialize( array(
      'provider'               => 'aws',
      'use-server-roles'       => true,
      'bucket'                 => '${var.media_bucket}',
      'region'                 => '${var.region}',
      'copy-to-s3'             => true,
      'serve-from-s3'          => true,
      'remove-local-file'      => true,
      'enable-object-prefix'   => true,
      'object-prefix'          => 'wp-content/uploads/',
      'use-yearmonth-folders'  => true,
      'force-https'            => true,
      'enable-delivery-domain' => true,
      'delivery-domain'        => '${var.media_cdn_domain}',
      'block-public-access'    => true,
      'object-ownership-enforced' => true,
    ) ) );
  PHP
}
