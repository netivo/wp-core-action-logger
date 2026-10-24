<?php
/**
 * Created by Netivo for wp-core-action-logger
 *
 * @package Netivo\Module\ActionLogger
 */

namespace Netivo\Module\ActionLogger\Listeners;

use Plugin_Upgrader;
use Theme_Upgrader;
use WP_Theme;
use WP_Upgrader;

if ( ! defined( 'ABSPATH' ) ) {
	header( 'HTTP/1.0 403 Forbidden' );
	exit;
}

/**
 * Class SystemListener
 *
 * Plugin and theme activation, deactivation, installation, updates and deletion,
 * and WordPress core updates (with old and new versions).
 */
class SystemListener extends AbstractListener {

	/**
	 * Core version when the request started (the update replaces the global).
	 *
	 * @var string
	 */
	private string $core_version = '';

	/**
	 * Versions before an update, keyed by "plugin:<file>" or "theme:<stylesheet>".
	 *
	 * @var array<string, string>
	 */
	private array $versions_before = [];

	/**
	 * Plugin details captured right before deletion, keyed by plugin file.
	 *
	 * @var array<string, array{name: string, version: string}>
	 */
	private array $deleting_plugins = [];

	/**
	 * Hooks the listener into WordPress.
	 *
	 * @return void
	 */
	public function register(): void {
		global $wp_version;
		$this->core_version = is_string( $wp_version ) ? $wp_version : '';

		$this->on( 'activated_plugin', 'plugin_activated', 10, 2 );
		$this->on( 'deactivated_plugin', 'plugin_deactivated', 10, 2 );
		$this->on( 'delete_plugin', 'plugin_before_delete' );
		$this->on( 'deleted_plugin', 'plugin_deleted', 10, 2 );
		$this->on( 'switch_theme', 'theme_switched', 10, 3 );
		$this->on( 'deleted_theme', 'theme_deleted', 10, 2 );
		$this->observe( 'upgrader_pre_install', 'before_install', 10, 2 );
		$this->on( 'upgrader_process_complete', 'upgrade_completed', 10, 2 );
		$this->on( '_core_updated_successfully', 'core_updated' );
	}

	/**
	 * Plugin activated.
	 *
	 * @param string $plugin       Plugin file relative to the plugins directory.
	 * @param bool   $network_wide Whether activated network-wide.
	 *
	 * @return void
	 */
	public function plugin_activated( string $plugin, bool $network_wide = false ): void {
		$this->log( 'system', 'plugin_activated', $this->plugin_entity( $plugin ), null, $this->plugin_metadata( $plugin, $network_wide ), 'warning' );
	}

	/**
	 * Plugin deactivated.
	 *
	 * @param string $plugin       Plugin file relative to the plugins directory.
	 * @param bool   $network_wide Whether deactivated network-wide.
	 *
	 * @return void
	 */
	public function plugin_deactivated( string $plugin, bool $network_wide = false ): void {
		$this->log( 'system', 'plugin_deactivated', $this->plugin_entity( $plugin ), null, $this->plugin_metadata( $plugin, $network_wide ), 'warning' );
	}

	/**
	 * Captures plugin details while its files still exist.
	 *
	 * @param string $plugin Plugin file.
	 *
	 * @return void
	 */
	public function plugin_before_delete( string $plugin ): void {
		$data                              = $this->plugin_data( $plugin );
		$this->deleting_plugins[ $plugin ] = [
			'name'    => (string) ( $data['Name'] ?? '' ),
			'version' => (string) ( $data['Version'] ?? '' ),
		];
	}

	/**
	 * Plugin deleted.
	 *
	 * @param string $plugin  Plugin file.
	 * @param bool   $deleted Whether the files were removed.
	 *
	 * @return void
	 */
	public function plugin_deleted( string $plugin, bool $deleted ): void {
		$data = $this->deleting_plugins[ $plugin ] ?? [
			'name'    => '',
			'version' => '',
		];
		unset( $this->deleting_plugins[ $plugin ] );
		if ( ! $deleted ) {
			return;
		}

		$this->log(
			'system',
			'plugin_deleted',
			[
				'type'  => 'plugin',
				'title' => '' === $data['name'] ? $this->plugin_slug( $plugin ) : $data['name'],
				'slug'  => $plugin,
			],
			null,
			'' === $data['version'] ? [] : [ 'version' => $data['version'] ],
			'warning'
		);
	}

	/**
	 * Active theme changed.
	 *
	 * @param string        $new_name  New theme name.
	 * @param WP_Theme      $new_theme New theme.
	 * @param WP_Theme|null $old_theme Previous theme.
	 *
	 * @return void
	 */
	public function theme_switched( string $new_name, WP_Theme $new_theme, ?WP_Theme $old_theme = null ): void {
		$changes = [
			'stylesheet' => [
				'old' => $old_theme instanceof WP_Theme ? $old_theme->get_stylesheet() : null,
				'new' => $new_theme->get_stylesheet(),
			],
		];

		$this->log( 'system', 'theme_switched', $this->theme_entity( $new_theme ), $changes, [], 'warning' );
	}

	/**
	 * Theme deleted.
	 *
	 * @param string $stylesheet Theme directory name.
	 * @param bool   $deleted    Whether the files were removed.
	 *
	 * @return void
	 */
	public function theme_deleted( string $stylesheet, bool $deleted ): void {
		if ( $deleted ) {
			$this->log(
				'system',
				'theme_deleted',
				[
					'type'  => 'theme',
					'title' => $stylesheet,
					'slug'  => $stylesheet,
				],
				null,
				[],
				'warning'
			);
		}
	}

	/**
	 * Records the installed version of a plugin or theme before it is replaced.
	 *
	 * @param mixed                $response   Filter value (unchanged).
	 * @param array<string, mixed> $hook_extra Upgrade context.
	 *
	 * @return void
	 */
	public function before_install( mixed $response, array $hook_extra = [] ): void {
		if ( isset( $hook_extra['plugin'] ) && is_string( $hook_extra['plugin'] ) ) {
			$this->versions_before[ 'plugin:' . $hook_extra['plugin'] ] = (string) ( $this->plugin_data( $hook_extra['plugin'] )['Version'] ?? '' );
		}
		if ( isset( $hook_extra['theme'] ) && is_string( $hook_extra['theme'] ) ) {
			$this->versions_before[ 'theme:' . $hook_extra['theme'] ] = (string) wp_get_theme( $hook_extra['theme'] )->get( 'Version' );
		}
	}

	/**
	 * Plugin or theme installed or updated (single and bulk).
	 *
	 * @param WP_Upgrader          $upgrader   Upgrader instance.
	 * @param array<string, mixed> $hook_extra Upgrade context (`type`, `action`, `plugins`, `themes`, ...).
	 *
	 * @return void
	 */
	public function upgrade_completed( WP_Upgrader $upgrader, array $hook_extra ): void {
		$type   = $hook_extra['type'] ?? '';
		$action = $hook_extra['action'] ?? '';

		if ( 'install' === $action && ( 'plugin' === $type || 'theme' === $type ) ) {
			$this->installed( $upgrader, $type );

			return;
		}
		if ( 'update' !== $action ) {
			return;
		}

		if ( 'plugin' === $type ) {
			foreach ( $this->items( $hook_extra, 'plugin' ) as $plugin ) {
				$this->updated( 'plugin', $plugin, (string) ( $this->plugin_data( $plugin, true )['Version'] ?? '' ), $this->plugin_entity( $plugin ) );
			}
		} elseif ( 'theme' === $type ) {
			foreach ( $this->items( $hook_extra, 'theme' ) as $stylesheet ) {
				$theme = wp_get_theme( $stylesheet );
				$this->updated( 'theme', $stylesheet, (string) $theme->get( 'Version' ), $this->theme_entity( $theme ) );
			}
		}
	}

	/**
	 * WordPress core updated.
	 *
	 * @param string $new_version Installed version.
	 *
	 * @return void
	 */
	public function core_updated( string $new_version ): void {
		$this->log(
			'system',
			'core_updated',
			[
				'type'  => 'core',
				'title' => 'WordPress',
			],
			[
				'version' => [
					'old' => '' === $this->core_version ? null : $this->core_version,
					'new' => $new_version,
				],
			],
			[],
			'warning'
		);
	}

	/**
	 * Logs an update of one plugin or theme.
	 *
	 * @param string               $type        `plugin` or `theme`.
	 * @param string               $item        Plugin file or theme stylesheet.
	 * @param string               $new_version Version after the update.
	 * @param array<string, mixed> $entity      Entity to log.
	 *
	 * @return void
	 */
	private function updated( string $type, string $item, string $new_version, array $entity ): void {
		$old = $this->versions_before[ $type . ':' . $item ] ?? '';
		unset( $this->versions_before[ $type . ':' . $item ] );

		$changes = [
			'version' => [
				'old' => '' === $old ? null : $old,
				'new' => '' === $new_version ? null : $new_version,
			],
		];
		$this->log( 'system', $type . '_updated', $entity, $changes );
	}

	/**
	 * Logs a newly installed plugin or theme.
	 *
	 * @param WP_Upgrader $upgrader Upgrader instance.
	 * @param string      $type     `plugin` or `theme`.
	 *
	 * @return void
	 */
	private function installed( WP_Upgrader $upgrader, string $type ): void {
		$data = [];
		if ( $upgrader instanceof Plugin_Upgrader ) {
			$data = $upgrader->new_plugin_data;
		} elseif ( $upgrader instanceof Theme_Upgrader ) {
			$data = $upgrader->new_theme_data;
		}
		$slug = is_array( $upgrader->result ) ? (string) $upgrader->result['destination_name'] : '';

		$this->log(
			'system',
			$type . '_installed',
			[
				'type'  => $type,
				'title' => (string) ( $data['Name'] ?? $slug ),
				'slug'  => $slug,
			],
			null,
			isset( $data['Version'] ) ? [ 'version' => (string) $data['Version'] ] : [],
			'warning'
		);
	}

	/**
	 * Items affected by a single or bulk upgrade.
	 *
	 * @param array<string, mixed> $hook_extra Upgrade context.
	 * @param string               $type       `plugin` or `theme`.
	 *
	 * @return string[]
	 */
	private function items( array $hook_extra, string $type ): array {
		$items = $hook_extra[ $type . 's' ] ?? ( isset( $hook_extra[ $type ] ) ? [ $hook_extra[ $type ] ] : [] );

		return is_array( $items ) ? array_values( array_filter( $items, 'is_string' ) ) : [];
	}

	/**
	 * Header data of a plugin.
	 *
	 * @param string $plugin Plugin file.
	 * @param bool   $fresh  Read the file instead of the cached plugin list (after updates).
	 *
	 * @return array<string, mixed>
	 */
	private function plugin_data( string $plugin, bool $fresh = false ): array {
		if ( ! function_exists( 'get_plugins' ) || ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		if ( $fresh && defined( 'WP_PLUGIN_DIR' ) && is_readable( WP_PLUGIN_DIR . '/' . $plugin ) ) {
			return get_plugin_data( WP_PLUGIN_DIR . '/' . $plugin, false, false );
		}

		return get_plugins()[ $plugin ] ?? [];
	}

	/**
	 * Entity describing a plugin.
	 *
	 * @param string $plugin Plugin file.
	 *
	 * @return array<string, mixed>
	 */
	private function plugin_entity( string $plugin ): array {
		$name = (string) ( $this->plugin_data( $plugin )['Name'] ?? '' );

		return [
			'type'  => 'plugin',
			'title' => '' === $name ? $this->plugin_slug( $plugin ) : $name,
			'slug'  => $plugin,
		];
	}

	/**
	 * Metadata for plugin (de)activation.
	 *
	 * @param string $plugin       Plugin file.
	 * @param bool   $network_wide Whether the change is network-wide.
	 *
	 * @return array<string, mixed>
	 */
	private function plugin_metadata( string $plugin, bool $network_wide ): array {
		$metadata = [ 'network_wide' => $network_wide ];
		$version  = (string) ( $this->plugin_data( $plugin )['Version'] ?? '' );
		if ( '' !== $version ) {
			$metadata['version'] = $version;
		}

		return $metadata;
	}

	/**
	 * Directory (or file) name of a plugin.
	 *
	 * @param string $plugin Plugin file.
	 *
	 * @return string
	 */
	private function plugin_slug( string $plugin ): string {
		$dir = dirname( $plugin );

		return '.' === $dir ? basename( $plugin, '.php' ) : $dir;
	}

	/**
	 * Entity describing a theme.
	 *
	 * @param WP_Theme $theme Theme.
	 *
	 * @return array<string, mixed>
	 */
	private function theme_entity( WP_Theme $theme ): array {
		return [
			'type'  => 'theme',
			'title' => (string) $theme->get( 'Name' ),
			'slug'  => $theme->get_stylesheet(),
		];
	}
}
