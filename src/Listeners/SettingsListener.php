<?php
/**
 * Created by Netivo for wp-core-action-logger
 *
 * @package Netivo\Module\ActionLogger
 */

namespace Netivo\Module\ActionLogger\Listeners;

if ( ! defined( 'ABSPATH' ) ) {
	header( 'HTTP/1.0 403 Forbidden' );
	exit;
}

/**
 * Class SettingsListener
 *
 * Changes to an allowlist of security- and SEO-relevant WordPress options. Using an
 * allowlist keeps transients, cron timestamps and plugin caches out of the audit log.
 */
class SettingsListener extends AbstractListener {

	/**
	 * Tracked options => severity.
	 */
	private const TRACKED_OPTIONS = [
		'siteurl'                => 'critical',
		'home'                   => 'critical',
		'admin_email'            => 'warning',
		'new_admin_email'        => 'warning',
		'users_can_register'     => 'warning',
		'default_role'           => 'warning',
		'blog_public'            => 'warning',
		'permalink_structure'    => 'warning',
		'category_base'          => 'info',
		'tag_base'               => 'info',
		'blogname'               => 'info',
		'blogdescription'        => 'info',
		'WPLANG'                 => 'info',
		'timezone_string'        => 'info',
		'gmt_offset'             => 'info',
		'show_on_front'          => 'info',
		'page_on_front'          => 'info',
		'page_for_posts'         => 'info',
		'default_comment_status' => 'info',
		'comment_registration'   => 'info',
		'comment_moderation'     => 'info',
		'wp_page_for_privacy_policy' => 'info',
	];

	/**
	 * Hooks the listener into WordPress.
	 *
	 * @return void
	 */
	public function register(): void {
		$this->on( 'updated_option', 'option_updated', 10, 3 );
	}

	/**
	 * Option value changed.
	 *
	 * @param string $option    Option name.
	 * @param mixed  $old_value Previous value.
	 * @param mixed  $value     New value.
	 *
	 * @return void
	 */
	public function option_updated( string $option, mixed $old_value, mixed $value ): void {
		/**
		 * Filters the options tracked by the Action Logger, as option name => severity.
		 *
		 * @param array<string, string> $options Tracked options.
		 */
		$tracked = apply_filters( 'netivo_action_logger_tracked_options', self::TRACKED_OPTIONS );
		if ( ! isset( $tracked[ $option ] ) ) {
			return;
		}

		$severity = $tracked[ $option ];
		// Making new accounts administrators by default is a privilege escalation.
		if ( 'default_role' === $option && 'administrator' === $value ) {
			$severity = 'critical';
		}

		$this->log(
			'settings',
			'permalink_structure' === $option ? 'permalink_structure_changed' : 'option_updated',
			[
				'type'  => 'option',
				'title' => $option,
				'slug'  => $option,
			],
			[
				$option => [
					'old' => $old_value,
					'new' => $value,
				],
			],
			[],
			$severity
		);
	}
}
