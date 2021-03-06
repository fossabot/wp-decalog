<?php
/**
 * The admin-specific functionality of the plugin.
 *
 * @package    Plugin
 * @author  Pierre Lannoy <https://pierre.lannoy.fr/>.
 * @since   1.0.0
 */

namespace Decalog\Plugin;

use Decalog\Plugin\Feature\Log;
use Decalog\Plugin\Feature\EventViewer;
use Decalog\Plugin\Feature\HandlerTypes;
use Decalog\Plugin\Feature\ProcessorTypes;
use Decalog\Plugin\Feature\LoggerFactory;
use Decalog\Plugin\Feature\Events;
use Decalog\Plugin\Feature\InlineHelp;
use Decalog\Listener\ListenerFactory;
use Decalog\System\Assets;
use Decalog\System\UUID;
use Decalog\System\Option;
use Decalog\System\Form;
use Decalog\System\Role;
use Decalog\System\GeoIP;
use Monolog\Logger;

/**
 * The admin-specific functionality of the plugin.
 *
 * @package    Plugin
 * @author  Pierre Lannoy <https://pierre.lannoy.fr/>.
 * @since   1.0.0
 */
class Decalog_Admin {

	/**
	 * The assets manager that's responsible for handling all assets of the plugin.
	 *
	 * @since  1.0.0
	 * @var    Assets    $assets    The plugin assets manager.
	 */
	protected $assets;

	/**
	 * The internal logger.
	 *
	 * @since  1.0.0
	 * @var    DLogger    $logger    The plugin admin logger.
	 */
	protected $logger;

	/**
	 * The current logger.
	 *
	 * @since  1.0.0
	 * @var    array    $current_logger    The current logger.
	 */
	protected $current_logger;

	/**
	 * The current handler.
	 *
	 * @since  1.0.0
	 * @var    array    $current_handler    The current handler.
	 */
	protected $current_handler;

	/**
	 * The current view.
	 *
	 * @since  1.0.0
	 * @var    object    $current_view    The current view.
	 */
	protected $current_view = null;

	/**
	 * Initialize the class and set its properties.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->assets = new Assets();
		$this->logger = Log::bootstrap( 'plugin', DECALOG_PRODUCT_SHORTNAME, DECALOG_VERSION );
	}

	/**
	 * Register the stylesheets for the admin area.
	 *
	 * @since 1.0.0
	 */
	public function register_styles() {
		$this->assets->register_style( DECALOG_ASSETS_ID, DECALOG_ADMIN_URL, 'css/decalog.min.css' );
	}

	/**
	 * Register the JavaScript for the admin area.
	 *
	 * @since 1.0.0
	 */
	public function register_scripts() {
		$this->assets->register_script( DECALOG_ASSETS_ID, DECALOG_ADMIN_URL, 'js/decalog.min.js', [ 'jquery' ] );
	}

	/**
	 * Set the items in the settings menu.
	 *
	 * @since 1.0.0
	 */
	public function init_admin_menus() {
		if ( 'decalog-settings' === filter_input( INPUT_GET, 'page', FILTER_SANITIZE_STRING ) ) {
			remove_action( 'admin_print_scripts', 'print_emoji_detection_script' );
			remove_action( 'admin_print_styles', 'print_emoji_styles' );
		}
		$this->current_view = null;
		if ( Role::SUPER_ADMIN === Role::admin_type() || Role::SINGLE_ADMIN === Role::admin_type() ) {
			/* translators: as in the sentence "DecaLog Settings" or "WordPress Settings" */
			$settings = add_submenu_page( 'options-general.php', sprintf( esc_html__( '%s Settings', 'decalog' ), DECALOG_PRODUCT_NAME ), DECALOG_PRODUCT_NAME, 'manage_options', 'decalog-settings', [ $this, 'get_settings_page' ] );
			add_action( 'load-' . $settings, [ new InlineHelp(), 'set_contextual_settings' ] );
		}
		if ( Role::SUPER_ADMIN === Role::admin_type() || Role::SINGLE_ADMIN === Role::admin_type() || Role::LOCAL_ADMIN === Role::admin_type() ) {
			if ( Events::loggers_count() > 0 ) {
				$name = add_submenu_page(
					'tools.php',
					/* translators: as in the sentence "DecaLog Viewer" */
					sprintf( esc_html__( '%s Viewer', 'decalog' ), DECALOG_PRODUCT_NAME ),
					DECALOG_PRODUCT_NAME,
					'manage_options',
					'decalog-viewer',
					[ $this, 'get_tools_page' ]
				);
				add_action( 'load-' . $name, [ new InlineHelp(), 'set_contextual_viewer' ] );
				$logid   = filter_input( INPUT_GET, 'logid', FILTER_SANITIZE_STRING );
				$eventid = filter_input( INPUT_GET, 'eventid', FILTER_SANITIZE_NUMBER_INT );
				if ( 'decalog-viewer' === filter_input( INPUT_GET, 'page', FILTER_SANITIZE_STRING ) ) {
					if ( isset( $logid ) && isset( $eventid ) && 0 !== $eventid ) {
						$this->current_view = new EventViewer( $logid, $eventid, $this->logger );
						add_action( 'load-' . $name, [ $this->current_view, 'add_metaboxes_options' ] );
						add_action( 'admin_footer-' . $name, [ $this->current_view, 'add_footer' ] );
						add_filter( 'screen_settings', [ $this->current_view, 'display_screen_settings' ], 10, 2 );
					} else {
						add_action( 'load-' . $name, [ 'Decalog\Plugin\Feature\Events', 'add_column_options' ] );
						add_filter(
							'screen_settings',
							[
								'Decalog\Plugin\Feature\Events',
								'display_screen_settings',
							],
							10,
							2
						);
					}
				}
			}
		}
	}

	/**
	 * Get actions links for myblogs_blog_actions hook.
	 *
	 * @param string $actions   The HTML site link markup.
	 * @param object $user_blog An object containing the site data.
	 * @return string   The action string.
	 * @since 1.2.0
	 */
	public function blog_action( $actions, $user_blog ) {
		if ( Role::SUPER_ADMIN === Role::admin_type() || Role::LOCAL_ADMIN === Role::admin_type() && Events::loggers_count() > 0 ) {
			$actions .= " | <a href='" . esc_url( admin_url( 'tools.php?page=decalog-viewer&site_id=' . $user_blog->userblog_id ) ) . "'>" . __( 'Events log', 'decalog' ) . '</a>';
		}
		return $actions;
	}

	/**
	 * Get actions for manage_sites_action_links hook.
	 *
	 * @param string[] $actions  An array of action links to be displayed.
	 * @param int      $blog_id  The site ID.
	 * @param string   $blogname Site path, formatted depending on whether it is a sub-domain
	 *                           or subdirectory multisite installation.
	 * @return array   The actions.
	 * @since 1.2.0
	 */
	public function site_action( $actions, $blog_id, $blogname ) {
		if ( Role::SUPER_ADMIN === Role::admin_type() || Role::LOCAL_ADMIN === Role::admin_type() && Events::loggers_count() > 0 ) {
			$actions['events_log'] = "<a href='" . esc_url( admin_url( 'tools.php?page=decalog-viewer&site_id=' . $blog_id ) ) . "' rel='bookmark'>" . __( 'Events log', 'decalog' ) . '</a>';
		}
		return $actions;
	}

	/**
	 * Initializes settings sections.
	 *
	 * @since 1.0.0
	 */
	public function init_settings_sections() {
		add_settings_section( 'decalog_loggers_options_section', esc_html__( 'Loggers options', 'decalog' ), [ $this, 'loggers_options_section_callback' ], 'decalog_loggers_options_section' );
		add_settings_section( 'decalog_plugin_options_section', esc_html__( 'Plugin options', 'decalog' ), [ $this, 'plugin_options_section_callback' ], 'decalog_plugin_options_section' );
		add_settings_section( 'decalog_listeners_options_section', null, [ $this, 'listeners_options_section_callback' ], 'decalog_listeners_options_section' );
		add_settings_section( 'decalog_listeners_settings_section', null, [ $this, 'listeners_settings_section_callback' ], 'decalog_listeners_settings_section' );
		add_settings_section( 'decalog_logger_misc_section', null, [ $this, 'logger_misc_section_callback' ], 'decalog_logger_misc_section' );
		add_settings_section( 'decalog_logger_delete_section', null, [ $this, 'logger_delete_section_callback' ], 'decalog_logger_delete_section' );
		add_settings_section( 'decalog_logger_specific_section', null, [ $this, 'logger_specific_section_callback' ], 'decalog_logger_specific_section' );
		add_settings_section( 'decalog_logger_privacy_section', esc_html__( 'Privacy options', 'decalog' ), [ $this, 'logger_privacy_section_callback' ], 'decalog_logger_privacy_section' );
		add_settings_section( 'decalog_logger_details_section', esc_html__( 'Reported details', 'decalog' ), [ $this, 'logger_details_section_callback' ], 'decalog_logger_details_section' );
	}

	/**
	 * Add links in the "Actions" column on the plugins view page.
	 *
	 * @param string[] $actions     An array of plugin action links. By default this can include 'activate',
	 *                              'deactivate', and 'delete'.
	 * @param string   $plugin_file Path to the plugin file relative to the plugins directory.
	 * @param array    $plugin_data An array of plugin data. See `get_plugin_data()`.
	 * @param string   $context     The plugin context. By default this can include 'all', 'active', 'inactive',
	 *                              'recently_activated', 'upgrade', 'mustuse', 'dropins', and 'search'.
	 * @return array Extended list of links to print in the "Actions" column on the Plugins page.
	 * @since 1.0.0
	 */
	public function add_actions_links( $actions, $plugin_file, $plugin_data, $context ) {
		$actions[] = sprintf( '<a href="%s">%s</a>', admin_url( 'options-general.php?page=decalog-settings' ), esc_html__( 'Settings', 'decalog' ) );
		if ( Events::loggers_count() > 0 ) {
			$actions[] = sprintf( '<a href="%s">%s</a>', admin_url( 'tools.php?page=decalog-viewer' ), esc_html__( 'Events Logs', 'decalog' ) );
		}
		return $actions;
	}

	/**
	 * Add links in the "Description" column on the Plugins page.
	 *
	 * @param array  $links List of links to print in the "Description" column on the Plugins page.
	 * @param string $file Path to the plugin file relative to the plugins directory.
	 * @return array Extended list of links to print in the "Description" column on the Plugins page.
	 * @since 1.3.0
	 */
	public function add_row_meta( $links, $file ) {
		if ( 0 === strpos( $file, DECALOG_SLUG . '/' ) ) {
			$links[] = '<a href="https://wordpress.org/support/plugin/' . DECALOG_SLUG . '/">' . __( 'Support', 'decalog' ) . '</a>';
			$links[] = '<a href="https://decalog.io">' . __( 'Site', 'decalog' ) . '</a>';
			$links[] = '<a href="https://github.com/Pierre-Lannoy/wp-decalog">' . __( 'GitHub repository', 'decalog' ) . '</a>';
		}
		return $links;
	}

	/**
	 * Get the content of the tools page.
	 *
	 * @since 1.0.0
	 */
	public function get_tools_page() {
		if ( isset( $this->current_view ) ) {
			$this->current_view->get();
		} else {
			include DECALOG_ADMIN_DIR . 'partials/decalog-admin-view-events.php';
		}
	}

	/**
	 * Get the content of the settings page.
	 *
	 * @since 1.0.0
	 */
	public function get_settings_page() {
		$this->current_handler = null;
		$this->current_logger  = null;
		if ( ! ( $action = filter_input( INPUT_GET, 'action' ) ) ) {
			$action = filter_input( INPUT_POST, 'action' );
		}
		if ( ! ( $tab = filter_input( INPUT_GET, 'tab' ) ) ) {
			$tab = filter_input( INPUT_POST, 'tab' );
		}
		if ( ! ( $handler = filter_input( INPUT_GET, 'handler' ) ) ) {
			$handler = filter_input( INPUT_POST, 'handler' );
		}
		if ( ! ( $uuid = filter_input( INPUT_GET, 'uuid' ) ) ) {
			$uuid = filter_input( INPUT_POST, 'uuid' );
		}
		$nonce = filter_input( INPUT_GET, 'nonce' );
		if ( $uuid ) {
			$loggers = Option::network_get( 'loggers' );
			if ( array_key_exists( $uuid, $loggers ) ) {
				$this->current_logger         = $loggers[ $uuid ];
				$this->current_logger['uuid'] = $uuid;
			}
		}
		if ( $handler ) {
			$handlers              = new HandlerTypes();
			$this->current_handler = $handlers->get( $handler );
		} elseif ( $this->current_logger ) {
			$handlers              = new HandlerTypes();
			$this->current_handler = $handlers->get( $this->current_logger['handler'] );
		}
		if ( $this->current_handler && ! $this->current_logger ) {
			$this->current_logger = [
				'uuid'    => $uuid = UUID::generate_v4(),
				'name'    => esc_html__( 'New logger', 'decalog' ),
				'handler' => $this->current_handler['id'],
				'running' => Option::network_get( 'logger_autostart' ),
			];
		}
		if ( $this->current_logger ) {
			$factory              = new LoggerFactory();
			$this->current_logger = $factory->check( $this->current_logger );
		}
		$view = 'decalog-admin-settings-main';
		if ( $action && $tab ) {
			switch ( $tab ) {
				case 'loggers':
					switch ( $action ) {
						case 'form-edit':
							if ( Role::SUPER_ADMIN === Role::admin_type() || Role::SINGLE_ADMIN === Role::admin_type() ) {
								$current_logger  = $this->current_logger;
								$current_handler = $this->current_handler;
								$args            = compact( 'current_logger', 'current_handler' );
								$view            = 'decalog-admin-settings-logger-edit';
							}
							break;
						case 'form-delete':
							if ( Role::SUPER_ADMIN === Role::admin_type() || Role::SINGLE_ADMIN === Role::admin_type() ) {
								$current_logger  = $this->current_logger;
								$current_handler = $this->current_handler;
								$args            = compact( 'current_logger', 'current_handler' );
								$view            = 'decalog-admin-settings-logger-delete';
							}
							break;
						case 'do-edit':
							if ( Role::SUPER_ADMIN === Role::admin_type() || Role::SINGLE_ADMIN === Role::admin_type() ) {
								$this->save_current();
							}
							break;
						case 'do-delete':
							if ( Role::SUPER_ADMIN === Role::admin_type() || Role::SINGLE_ADMIN === Role::admin_type() ) {
								$this->delete_current();
							}
							break;
						case 'start':
							if ( Role::SUPER_ADMIN === Role::admin_type() || Role::SINGLE_ADMIN === Role::admin_type() ) {
								if ( $nonce && $uuid && wp_verify_nonce( $nonce, 'decalog-logger-start-' . $uuid ) ) {
									$loggers = Option::network_get( 'loggers' );
									if ( array_key_exists( $uuid, $loggers ) ) {
										$loggers[ $uuid ]['running'] = true;
										Option::network_set( 'loggers', $loggers );
										$this->logger = Log::bootstrap( 'plugin', DECALOG_PRODUCT_SHORTNAME, DECALOG_VERSION );
										$message      = sprintf( esc_html__( 'Logger %s has started.', 'decalog' ), '<em>' . $loggers[ $uuid ]['name'] . '</em>' );
										$code         = 0;
										add_settings_error( 'decalog_no_error', $code, $message, 'updated' );
										$this->logger->info( sprintf( 'Logger "%s" has started.', $loggers[ $uuid ]['name'] ), $code );
									}
								}
							}
							break;
						case 'pause':
							if ( Role::SUPER_ADMIN === Role::admin_type() || Role::SINGLE_ADMIN === Role::admin_type() ) {
								if ( $nonce && $uuid && wp_verify_nonce( $nonce, 'decalog-logger-pause-' . $uuid ) ) {
									$loggers = Option::network_get( 'loggers' );
									if ( array_key_exists( $uuid, $loggers ) ) {
										$message = sprintf( esc_html__( 'Logger %s has been paused.', 'decalog' ), '<em>' . $loggers[ $uuid ]['name'] . '</em>' );
										$code    = 0;
										$this->logger->notice( sprintf( 'Logger "%s" has been paused.', $loggers[ $uuid ]['name'] ), $code );
										$loggers[ $uuid ]['running'] = false;
										Option::network_set( 'loggers', $loggers );
										$this->logger = Log::bootstrap( 'plugin', DECALOG_PRODUCT_SHORTNAME, DECALOG_VERSION );
										add_settings_error( 'decalog_no_error', $code, $message, 'updated' );
									}
								}
							}
						case 'test':
							if ( Role::SUPER_ADMIN === Role::admin_type() || Role::SINGLE_ADMIN === Role::admin_type() ) {
								if ( $nonce && $uuid && wp_verify_nonce( $nonce, 'decalog-logger-test-' . $uuid ) ) {
									$loggers = Option::network_get( 'loggers' );
									if ( array_key_exists( $uuid, $loggers ) ) {
										$test = Log::bootstrap( 'plugin', DECALOG_PRODUCT_SHORTNAME, DECALOG_VERSION, $uuid );
										$done = true;
										$done = $done & $test->debug( 'Debug test message.', 210871 );
										$done = $done & $test->info( 'Info test message.', 210871 );
										$done = $done & $test->notice( 'Notice test message.', 210871 );
										$done = $done & $test->warning( 'Warning test message.', 210871 );
										$done = $done & $test->error( 'Error test message.', 210871 );
										$done = $done & $test->critical( 'Critical test message.', 210871 );
										$done = $done & $test->alert( 'Alert test message.', 210871 );
										$done = $done & $test->emergency( 'Emergency test message.', 210871 );
										if ( $done ) {
											$message = sprintf( esc_html__( 'Test messages have been sent to logger %s.', 'decalog' ), '<em>' . $loggers[ $uuid ]['name'] . '</em>' );
											$code    = 0;
											$this->logger->info( sprintf( 'Logger "%s" has been tested.', $loggers[ $uuid ]['name'] ), $code );
											add_settings_error( 'decalog_no_error', $code, $message, 'updated' );
										} else {
											$message = sprintf( esc_html__( 'Test messages have not been sent to logger %s. Please check the logger\'s settings.', 'decalog' ), '<em>' . $loggers[ $uuid ]['name'] . '</em>' );
											$code    = 1;
											$this->logger->warning( sprintf( 'Logger "%s" has been unsuccessfully tested.', $loggers[ $uuid ]['name'] ), $code );
											add_settings_error( 'decalog_error', $code, $message, 'error' );
										}
									}
								}
							}
					}
					break;
				case 'misc':
					switch ( $action ) {
						case 'do-save':
							if ( Role::SUPER_ADMIN === Role::admin_type() || Role::SINGLE_ADMIN === Role::admin_type() ) {
								if ( ! empty( $_POST ) && array_key_exists( 'submit', $_POST ) ) {
									$this->save_options();
								} elseif ( ! empty( $_POST ) && array_key_exists( 'reset-to-defaults', $_POST ) ) {
									$this->reset_options();
								}
							}
							break;
					}
					break;
				case 'listeners':
					switch ( $action ) {
						case 'do-save':
							if ( Role::SUPER_ADMIN === Role::admin_type() || Role::SINGLE_ADMIN === Role::admin_type() ) {
								if ( ! empty( $_POST ) && array_key_exists( 'submit', $_POST ) ) {
									$this->save_listeners();
								} elseif ( ! empty( $_POST ) && array_key_exists( 'reset-to-defaults', $_POST ) ) {
									$this->reset_listeners();
								}
							}
							break;
					}
					break;
			}
		}
		include DECALOG_ADMIN_DIR . 'partials/' . $view . '.php';
	}

	/**
	 * Save the listeners options.
	 *
	 * @since 1.0.0
	 */
	private function save_listeners() {
		if ( ! empty( $_POST ) ) {
			if ( array_key_exists( '_wpnonce', $_POST ) && wp_verify_nonce( $_POST['_wpnonce'], 'decalog-listeners-options' ) ) {
				Option::network_set( 'autolisteners', 'auto' === filter_input( INPUT_POST, 'decalog_listeners_options_auto',FILTER_SANITIZE_STRING ) );
				$list      = [];
				$listeners = ListenerFactory::$infos;
				foreach ( $listeners as $listener ) {
					if ( array_key_exists( 'decalog_listeners_settings_' . $listener['id'], $_POST ) ) {
						$list[] = $listener['id'];
					}
				}
				Option::network_set( 'listeners', $list );
				$message = esc_html__( 'Listeners settings have been saved.', 'decalog' );
				$code    = 0;
				add_settings_error( 'decalog_no_error', $code, $message, 'updated' );
				$this->logger->info( 'Listeners settings updated.', $code );
			} else {
				$message = esc_html__( 'Listeners settings have not been saved. Please try again.', 'decalog' );
				$code    = 2;
				add_settings_error( 'decalog_nonce_error', $code, $message, 'error' );
				$this->logger->warning( 'Listeners settings not updated.', $code );
			}
		}
	}

	/**
	 * Reset the listeners options.
	 *
	 * @since 1.0.0
	 */
	private function reset_listeners() {
		if ( ! empty( $_POST ) ) {
			if ( array_key_exists( '_wpnonce', $_POST ) && wp_verify_nonce( $_POST['_wpnonce'], 'decalog-listeners-options' ) ) {
				Option::network_set( 'autolisteners', true );
				$message = esc_html__( 'Listeners settings have been reset to defaults.', 'decalog' );
				$code    = 0;
				add_settings_error( 'decalog_no_error', $code, $message, 'updated' );
				$this->logger->info( 'Listeners settings reset to defaults.', $code );
			} else {
				$message = esc_html__( 'Listeners settings have not been reset to defaults. Please try again.', 'decalog' );
				$code    = 2;
				add_settings_error( 'decalog_nonce_error', $code, $message, 'error' );
				$this->logger->warning( 'Listeners settings not reset to defaults.', $code );
			}
		}
	}

	/**
	 * Save the plugin options.
	 *
	 * @since 1.0.0
	 */
	private function save_options() {
		if ( ! empty( $_POST ) ) {
			if ( array_key_exists( '_wpnonce', $_POST ) && wp_verify_nonce( $_POST['_wpnonce'], 'decalog-plugin-options' ) ) {
				Option::network_set( 'use_cdn', array_key_exists( 'decalog_plugin_options_usecdn', $_POST ) );
				Option::network_set( 'display_nag', array_key_exists( 'decalog_plugin_options_nag', $_POST ) );
				Option::network_set( 'download_favicons', array_key_exists( 'decalog_plugin_options_favicons', $_POST ) ? (bool) filter_input( INPUT_POST, 'decalog_plugin_options_favicons' ) : false );
				Option::network_set( 'logger_autostart', array_key_exists( 'decalog_loggers_options_autostart', $_POST ) );
				Option::network_set( 'pseudonymization', array_key_exists( 'decalog_loggers_options_pseudonymization', $_POST ) );
				Option::network_set( 'respect_wp_debug', array_key_exists( 'decalog_loggers_options_wpdebug', $_POST ) );
				$message = esc_html__( 'Plugin settings have been saved.', 'decalog' );
				$code    = 0;
				add_settings_error( 'decalog_no_error', $code, $message, 'updated' );
				$this->logger->info( 'Plugin settings updated.', $code );
			} else {
				$message = esc_html__( 'Plugin settings have not been saved. Please try again.', 'decalog' );
				$code    = 2;
				add_settings_error( 'decalog_nonce_error', $code, $message, 'error' );
				$this->logger->warning( 'Plugin settings not updated.', $code );
			}
		}
	}

	/**
	 * Reset the plugin options.
	 *
	 * @since 1.0.0
	 */
	private function reset_options() {
		if ( ! empty( $_POST ) ) {
			if ( array_key_exists( '_wpnonce', $_POST ) && wp_verify_nonce( $_POST['_wpnonce'], 'decalog-plugin-options' ) ) {
				Option::reset_to_defaults();
				$message = esc_html__( 'Plugin settings have been reset to defaults.', 'decalog' );
				$code    = 0;
				add_settings_error( 'decalog_no_error', $code, $message, 'updated' );
				$this->logger->info( 'Plugin settings reset to defaults.', $code );
			} else {
				$message = esc_html__( 'Plugin settings have not been reset to defaults. Please try again.', 'decalog' );
				$code    = 2;
				add_settings_error( 'decalog_nonce_error', $code, $message, 'error' );
				$this->logger->warning( 'Plugin settings not reset to defaults.', $code );
			}
		}
	}

	/**
	 * Save the current logger as new or modified logger.
	 *
	 * @since 1.0.0
	 */
	private function save_current() {
		if ( ! empty( $_POST ) ) {
			if ( array_key_exists( '_wpnonce', $_POST ) && wp_verify_nonce( $_POST['_wpnonce'], 'decalog-logger-edit' ) ) {
				if ( array_key_exists( 'submit', $_POST ) ) {
					$this->current_logger['name']                        = ( array_key_exists( 'decalog_logger_misc_name', $_POST ) ? filter_input( INPUT_POST, 'decalog_logger_misc_name', FILTER_SANITIZE_STRING ) : $this->current_logger['name'] );
					$this->current_logger['level']                       = ( array_key_exists( 'decalog_logger_misc_level', $_POST ) ? filter_input( INPUT_POST, 'decalog_logger_misc_level', FILTER_SANITIZE_NUMBER_INT ) : $this->current_logger['level'] );
					$this->current_logger['privacy']['obfuscation']      = ( array_key_exists( 'decalog_logger_privacy_ip', $_POST ) ? true : false );
					$this->current_logger['privacy']['pseudonymization'] = ( array_key_exists( 'decalog_logger_privacy_name', $_POST ) ? true : false );
					$this->current_logger['processors']                  = [];
					$proc = new ProcessorTypes();
					foreach ( array_reverse( $proc->get_all() ) as $processor ) {
						if ( array_key_exists( 'decalog_logger_details_' . strtolower( $processor['id'] ), $_POST ) ) {
							$this->current_logger['processors'][] = $processor['id'];
						}
					}
					foreach ( $this->current_handler['configuration'] as $key => $configuration ) {
						$id = 'decalog_logger_details_' . strtolower( $key );
						if ( 'boolean' === $configuration['control']['cast'] ) {
							$this->current_logger['configuration'][ $key ] = ( array_key_exists( $id, $_POST ) ? true : false );
						}
						if ( 'integer' === $configuration['control']['cast'] ) {
							$this->current_logger['configuration'][ $key ] = ( array_key_exists( $id, $_POST ) ? filter_input( INPUT_POST, $id, FILTER_SANITIZE_NUMBER_INT ) : $this->current_logger['configuration'][ $key ] );
						}
						if ( 'string' === $configuration['control']['cast'] ) {
							$this->current_logger['configuration'][ $key ] = ( array_key_exists( $id, $_POST ) ? filter_input( INPUT_POST, $id, FILTER_SANITIZE_STRING ) : $this->current_logger['configuration'][ $key ] );
						}
						if ( 'password' === $configuration['control']['cast'] ) {
							$this->current_logger['configuration'][ $key ] = ( array_key_exists( $id, $_POST ) ? filter_input( INPUT_POST, $id, FILTER_UNSAFE_RAW ) : $this->current_logger['configuration'][ $key ] );
						}
					}
					$uuid             = $this->current_logger['uuid'];
					$loggers          = Option::network_get( 'loggers' );
					$factory          = new LoggerFactory();
					$loggers[ $uuid ] = $factory->check( $this->current_logger, true );
					if ( array_key_exists( 'uuid', $loggers[ $uuid ] ) ) {
						unset( $loggers[ $uuid ]['uuid'] );
					}
					Option::network_set( 'loggers', $loggers );
					$this->logger = Log::bootstrap( 'plugin', DECALOG_PRODUCT_SHORTNAME, DECALOG_VERSION );
					$message      = sprintf( esc_html__( 'Logger %s has been saved.', 'decalog' ), '<em>' . $this->current_logger['name'] . '</em>' );
					$code         = 0;
					add_settings_error( 'decalog_no_error', $code, $message, 'updated' );
					$this->logger->info( sprintf( 'Logger "%s" has been saved.', $this->current_logger['name'] ), $code );
				}
			} else {
				$message = sprintf( esc_html__( 'Logger %s has not been saved. Please try again.', 'decalog' ), '<em>' . $this->current_logger['name'] . '</em>' );
				$code    = 2;
				add_settings_error( 'decalog_nonce_error', $code, $message, 'error' );
				$this->logger->warning( sprintf( 'Logger "%s" has not been saved.', $this->current_logger['name'] ), $code );
			}
		}
	}

	/**
	 * Delete the current logger.
	 *
	 * @since 1.0.0
	 */
	private function delete_current() {
		if ( ! empty( $_POST ) ) {
			if ( array_key_exists( '_wpnonce', $_POST ) && wp_verify_nonce( $_POST['_wpnonce'], 'decalog-logger-delete' ) ) {
				if ( array_key_exists( 'submit', $_POST ) ) {
					$uuid    = $this->current_logger['uuid'];
					$loggers = Option::network_get( 'loggers' );
					$factory = new LoggerFactory();
					$factory->clean( $this->current_logger );
					unset( $loggers[ $uuid ] );
					Option::network_set( 'loggers', $loggers );
					$this->logger = Log::bootstrap( 'plugin', DECALOG_PRODUCT_SHORTNAME, DECALOG_VERSION );
					$message      = sprintf( esc_html__( 'Logger %s has been removed.', 'decalog' ), '<em>' . $this->current_logger['name'] . '</em>' );
					$code         = 0;
					add_settings_error( 'decalog_no_error', $code, $message, 'updated' );
					$this->logger->notice( sprintf( 'Logger "%s" has been removed.', $this->current_logger['name'] ), $code );
				}
			} else {
				$message = sprintf( esc_html__( 'Logger %s has not been removed. Please try again.', 'decalog' ), '<em>' . $this->current_logger['name'] . '</em>' );
				$code    = 2;
				add_settings_error( 'decalog_nonce_error', $code, $message, 'error' );
				$this->logger->warning( sprintf( 'Logger "%s" has not been removed.', $this->current_logger['name'] ), $code );
			}
		}
	}

	/**
	 * Callback for listeners options section.
	 *
	 * @since 1.0.0
	 */
	public function listeners_options_section_callback() {
		$form = new Form();
		add_settings_field(
			'decalog_listeners_options_auto',
			__( 'Activate', 'decalog' ),
			[ $form, 'echo_field_select' ],
			'decalog_listeners_options_section',
			'decalog_listeners_options_section',
			[
				'list'        => [
					0 => [ 'manual', esc_html__( 'Selected listeners', 'decalog' ) ],
					1 => [ 'auto', esc_html__( 'All available listeners (recommended)', 'decalog' ) ],
				],
				'id'          => 'decalog_listeners_options_auto',
				'value'       => Option::network_get( 'autolisteners' ) ? 'auto' : 'manual',
				'description' => esc_html__( 'Automatically or selectively choose which sources to listen.', 'decalog' ),
				'full_width'  => true,
				'enabled'     => true,
			]
		);
		register_setting( 'decalog_listeners_options_section', 'decalog_listeners_options_autostart' );
	}

	/**
	 * Callback for listeners settings section.
	 *
	 * @since 1.0.0
	 */
	public function listeners_settings_section_callback() {
		$standard  = [];
		$plugin    = [];
		$theme     = [];
		$listeners = ListenerFactory::$infos;
		usort(
			$listeners,
			function( $a, $b ) {
				return strcmp( strtolower( $a['name'] ), strtolower( $b['name'] ) );
			}
		);
		foreach ( $listeners as $listener ) {
			if ( 'plugin' === $listener['class'] && $listener['available'] ) {
				$plugin[] = $listener;
			} elseif ( 'theme' === $listener['class'] && $listener['available'] ) {
				$theme[] = $listener;
			} elseif ( $listener['available'] ) {
				$standard[] = $listener;
			}
		}
		$main = [
			esc_html__( 'Standard listeners', 'decalog' ) => $standard,
			esc_html__( 'Plugin listeners', 'decalog' )   => $plugin,
			esc_html__( 'Theme listeners', 'decalog' )    => $theme,
		];
		$form = new Form();
		foreach ( $main as $name => $items ) {
			$title = true;
			foreach ( $items as $item ) {
				add_settings_field(
					'decalog_listeners_settings_' . $item['id'],
					$title ? $name : null,
					[ $form, 'echo_field_checkbox' ],
					'decalog_listeners_settings_section',
					'decalog_listeners_settings_section',
					[
						'text'        => sprintf( '%s (%s %s)', $item['name'], $item['product'], $item['version'] ),
						'id'          => 'decalog_listeners_settings_' . $item['id'],
						'checked'     => in_array( $item['id'], Option::network_get( 'listeners' ), true ),
						'description' => null,
						'full_width'  => true,
						'enabled'     => true,
					]
				);
				register_setting( 'decalog_listeners_settings_section', 'decalog_listeners_settings_' . $item['id'] );
				$title = false;
			}
		}
	}

	/**
	 * Callback for loggers options section.
	 *
	 * @since 1.0.0
	 */
	public function loggers_options_section_callback() {
		$form = new Form();
		add_settings_field(
			'decalog_loggers_options_autostart',
			__( 'New logger', 'decalog' ),
			[ $form, 'echo_field_checkbox' ],
			'decalog_loggers_options_section',
			'decalog_loggers_options_section',
			[
				'text'        => esc_html__( 'Auto-start', 'decalog' ),
				'id'          => 'decalog_loggers_options_autostart',
				'checked'     => Option::network_get( 'logger_autostart' ),
				'description' => esc_html__( 'If checked, when a new logger is added it automatically starts.', 'decalog' ),
				'full_width'  => true,
				'enabled'     => true,
			]
		);
		register_setting( 'decalog_loggers_options_section', 'decalog_loggers_options_autostart' );
		add_settings_field(
			'decalog_loggers_options_pseudonymization',
			__( 'Events messages', 'decalog' ),
			[ $form, 'echo_field_checkbox' ],
			'decalog_loggers_options_section',
			'decalog_loggers_options_section',
			[
				'text'        => esc_html__( 'Respect privacy', 'decalog' ),
				'id'          => 'decalog_loggers_options_pseudonymization',
				'checked'     => Option::network_get( 'pseudonymization' ),
				'description' => esc_html__( 'If checked, DecaLog will try to obfuscate personal information in events messages.', 'decalog' ),
				'full_width'  => true,
				'enabled'     => true,
			]
		);
		register_setting( 'decalog_loggers_options_section', 'decalog_loggers_options_pseudonymization' );
		add_settings_field(
			'decalog_loggers_options_wpdebug',
			__( 'Rules', 'decalog' ),
			[ $form, 'echo_field_checkbox' ],
			'decalog_loggers_options_section',
			'decalog_loggers_options_section',
			[
				'text'        => esc_html__( 'Respect WP_DEBUG', 'decalog' ),
				'id'          => 'decalog_loggers_options_wpdebug',
				'checked'     => Option::network_get( 'respect_wp_debug' ),
				'description' => esc_html__( 'If checked, the value of WP_DEBUG will override each logger\'s settings for minimal level of logging.', 'decalog' ),
				'full_width'  => true,
				'enabled'     => true,
			]
		);
		register_setting( 'decalog_loggers_options_section', 'decalog_loggers_options_wpdebug' );
	}

	/**
	 * Callback for plugin options section.
	 *
	 * @since 1.0.0
	 */
	public function plugin_options_section_callback() {
		$form = new Form();
		add_settings_field(
			'decalog_plugin_options_favicons',
			__( 'Favicons', 'decalog' ),
			[ $form, 'echo_field_checkbox' ],
			'decalog_plugin_options_section',
			'decalog_plugin_options_section',
			[
				'text'        => esc_html__( 'Download and display', 'decalog' ),
				'id'          => 'decalog_plugin_options_favicons',
				'checked'     => Option::network_get( 'download_favicons' ),
				'description' => esc_html__( 'If checked, DecaLog will download favicons of websites to display them in reports.', 'decalog' ) . '<br/>' . esc_html__( 'Note: This feature uses the (free) Google Favicon Service.', 'decalog' ),
				'full_width'  => true,
				'enabled'     => true,
			]
		);
		register_setting( 'decalog_plugin_options_section', 'decalog_plugin_options_favicons' );
		if ( class_exists( 'PODeviceDetector\API\Device' ) ) {
			$help  = '<img style="width:16px;vertical-align:text-bottom;" src="' . \Feather\Icons::get_base64( 'thumbs-up', 'none', '#00C800' ) . '" />&nbsp;';
			$help .= sprintf( esc_html__('Your site is currently using %s.', 'decalog' ), '<em>Device Detector v' . PODD_VERSION .'</em>' );
		} else {
			$help  = '<img style="width:16px;vertical-align:text-bottom;" src="' . \Feather\Icons::get_base64( 'alert-triangle', 'none', '#FF8C00' ) . '" />&nbsp;';
			$help .= sprintf( esc_html__('Your site does not use any device detection mechanism. To handle user-agents and callers reporting in DecaLog, I recommend you to install the excellent (and free) %s. But it is not mandatory.', 'decalog' ), '<a href="https://wordpress.org/plugins/device-detector/">Device Detector</a>' );
		}
		add_settings_field(
			'decalog_plugin_options_podd',
			__( 'Device Detection', 'decalog' ),
			[ $form, 'echo_field_simple_text' ],
			'decalog_plugin_options_section',
			'decalog_plugin_options_section',
			[
				'text' => $help
			]
		);
		register_setting( 'decalog_plugin_options_section', 'decalog_plugin_options_podd' );
		$geo_ip = new GeoIP();
		if ( $geo_ip->is_installed() ) {
			$help  = '<img style="width:16px;vertical-align:text-bottom;" src="' . \Feather\Icons::get_base64( 'thumbs-up', 'none', '#00C800' ) . '" />&nbsp;';
			$help .= sprintf( esc_html__('Your site is currently using %s.', 'decalog' ), '<em>' . $geo_ip->get_full_name() .'</em>' );
		} else {
			$help  = '<img style="width:16px;vertical-align:text-bottom;" src="' . \Feather\Icons::get_base64( 'alert-triangle', 'none', '#FF8C00' ) . '" />&nbsp;';
			$help .= sprintf( esc_html__('Your site does not use any IP geographic information plugin. To display callers geographical details in DecaLog, I recommend you to install the excellent (and free) %s. But it is not mandatory.', 'decalog' ), '<a href="https://wordpress.org/plugins/geoip-detect/">GeoIP Detection</a>' );
		}
		add_settings_field(
			'decalog_plugin_options_geoip',
			__( 'IP information', 'decalog' ),
			[ $form, 'echo_field_simple_text' ],
			'decalog_plugin_options_section',
			'decalog_plugin_options_section',
			[
				'text' => $help
			]
		);
		register_setting( 'decalog_plugin_options_section', 'decalog_plugin_options_geoip' );
		add_settings_field(
			'decalog_plugin_options_usecdn',
			__( 'Resources', 'decalog' ),
			[ $form, 'echo_field_checkbox' ],
			'decalog_plugin_options_section',
			'decalog_plugin_options_section',
			[
				'text'        => esc_html__( 'Use public CDN', 'decalog' ),
				'id'          => 'decalog_plugin_options_usecdn',
				'checked'     => Option::network_get( 'use_cdn' ),
				'description' => esc_html__( 'Use CDN (jsDelivr) to serve DecaLog scripts and stylesheets.', 'decalog' ),
				'full_width'  => true,
				'enabled'     => true,
			]
		);
		register_setting( 'decalog_plugin_options_section', 'decalog_plugin_options_usecdn' );
		add_settings_field(
			'decalog_plugin_options_nag',
			__( 'Admin notices', 'decalog' ),
			[ $form, 'echo_field_checkbox' ],
			'decalog_plugin_options_section',
			'decalog_plugin_options_section',
			[
				'text'        => esc_html__( 'Display', 'decalog' ),
				'id'          => 'decalog_plugin_options_nag',
				'checked'     => Option::network_get( 'display_nag' ),
				'description' => esc_html__( 'Allows DecaLog to display admin notices throughout the admin dashboard.', 'decalog' ) . '<br/>' . esc_html__( 'Note: DecaLog respects DISABLE_NAG_NOTICES flag.', 'decalog' ),
				'full_width'  => true,
				'enabled'     => true,
			]
		);
		register_setting( 'decalog_plugin_options_section', 'decalog_plugin_options_nag' );
	}

	/**
	 * Callback for logger misc section.
	 *
	 * @since 1.0.0
	 */
	public function logger_misc_section_callback() {
		$icon  = '<img style="vertical-align:middle;width:34px;margin-top: -2px;padding-right:6px;" src="' . $this->current_handler['icon'] . '" />';
		$title = $this->current_handler['name'];
		echo '<h2>' . $icon . '&nbsp;' . $title . '</h2>';
		echo '<p style="margin-top: -10px;margin-left: 6px;">' . $this->current_handler['help'] . '</p>';
		$form = new Form();
		add_settings_field(
			'decalog_logger_misc_name',
			__( 'Name', 'decalog' ),
			[ $form, 'echo_field_input_text' ],
			'decalog_logger_misc_section',
			'decalog_logger_misc_section',
			[
				'id'          => 'decalog_logger_misc_name',
				'value'       => $this->current_logger['name'],
				'description' => esc_html__( 'Used only in admin dashboard.', 'decalog' ),
				'full_width'  => true,
				'enabled'     => true,
			]
		);
		register_setting( 'decalog_logger_misc_section', 'decalog_logger_misc_name' );
		add_settings_field(
			'decalog_logger_misc_level',
			__( 'Minimal level', 'decalog' ),
			[ $form, 'echo_field_select' ],
			'decalog_logger_misc_section',
			'decalog_logger_misc_section',
			[
				'list'        => Log::get_levels( $this->current_handler['minimal'] ),
				'id'          => 'decalog_logger_misc_level',
				'value'       => $this->current_logger['level'],
				'description' => esc_html__( 'Minimal reported level. May be overridden by the "respect WP_DEBUG directive" option.', 'decalog' ),
				'full_width'  => true,
				'enabled'     => true,
			]
		);
		register_setting( 'decalog_logger_misc_section', 'decalog_logger_misc_level' );
	}

	/**
	 * Callback for logger delete section.
	 *
	 * @since 1.0.0
	 */
	public function logger_delete_section_callback() {
		$icon  = '<img style="vertical-align:middle;width:34px;margin-top: -2px;padding-right:6px;" src="' . $this->current_handler['icon'] . '" />';
		$title = $this->current_handler['name'];
		echo '<h2>' . $icon . '&nbsp;' . $title . '</h2>';
		echo '<p style="margin-top: -10px;margin-left: 6px;">' . $this->current_handler['help'] . '</p>';
		$form = new Form();
		add_settings_field(
			'decalog_logger_delete_name',
			__( 'Name', 'decalog' ),
			[ $form, 'echo_field_input_text' ],
			'decalog_logger_delete_section',
			'decalog_logger_delete_section',
			[
				'id'          => 'decalog_logger_delete_name',
				'value'       => $this->current_logger['name'],
				'description' => null,
				'full_width'  => true,
				'enabled'     => false,
			]
		);
		register_setting( 'decalog_logger_delete_section', 'decalog_logger_delete_name' );
		add_settings_field(
			'decalog_logger_delete_level',
			__( 'Minimal level', 'decalog' ),
			[ $form, 'echo_field_select' ],
			'decalog_logger_delete_section',
			'decalog_logger_delete_section',
			[
				'list'        => Log::get_levels( $this->current_handler['minimal'] ),
				'id'          => 'decalog_logger_delete_level',
				'value'       => $this->current_logger['level'],
				'description' => null,
				'full_width'  => true,
				'enabled'     => false,
			]
		);
		register_setting( 'decalog_logger_delete_section', 'decalog_logger_delete_level' );
	}

	/**
	 * Callback for logger specific section.
	 *
	 * @since 1.0.0
	 */
	public function logger_specific_section_callback() {
		$form = new Form();
		if ( 'ErrorLogHandler' === $this->current_logger['handler'] ) {
			add_settings_field(
				'decalog_logger_specific_dummy',
				__( 'Log file', 'decalog' ),
				[ $form, 'echo_field_input_text' ],
				'decalog_logger_specific_section',
				'decalog_logger_specific_section',
				[
					'id'          => 'decalog_logger_specific_dummy',
					'value'       => ini_get( 'error_log' ),
					'description' => esc_html__( 'Value set in php.ini file.', 'decalog' ),
					'full_width'  => true,
					'enabled'     => false,
				]
			);
			register_setting( 'decalog_logger_specific_section', 'decalog_logger_specific_dummy' );
		}
		foreach ( $this->current_handler['configuration'] as $key => $configuration ) {
			if ( ! $configuration['show'] ) {
				continue;
			}
			$id   = 'decalog_logger_details_' . strtolower( $key );
			$args = [
				'id'          => $id,
				'text'        => esc_html__( 'Enabled', 'decalog' ),
				'checked'     => (bool) $this->current_logger['configuration'][ $key ],
				'value'       => $this->current_logger['configuration'][ $key ],
				'description' => $configuration['help'],
				'full_width'  => true,
				'enabled'     => $configuration['control']['enabled'],
				'list'        => ( array_key_exists( 'list', $configuration['control'] ) ? $configuration['control']['list'] : [] ),
			];
			foreach ( $configuration['control'] as $index => $control ) {
				if ( 'type' !== $index && 'cast' !== $index ) {
					$args[ $index ] = $control;
				}
			}
			add_settings_field(
				$id,
				$configuration['name'],
				[ $form, 'echo_' . $configuration['control']['type'] ],
				'decalog_logger_specific_section',
				'decalog_logger_specific_section',
				$args
			);
			register_setting( 'decalog_logger_specific_section', $id );
		}
	}

	/**
	 * Callback for logger privacy section.
	 *
	 * @since 1.0.0
	 */
	public function logger_privacy_section_callback() {
		$form = new Form();
		add_settings_field(
			'decalog_logger_privacy_ip',
			__( 'Remote IPs', 'decalog' ),
			[ $form, 'echo_field_checkbox' ],
			'decalog_logger_privacy_section',
			'decalog_logger_privacy_section',
			[
				'text'        => esc_html__( 'Obfuscation', 'decalog' ),
				'id'          => 'decalog_logger_privacy_ip',
				'checked'     => $this->current_logger['privacy']['obfuscation'],
				'description' => esc_html__( 'If checked, log fields will contain hashes instead of real IPs.', 'decalog' ) . '<br/>' . esc_html__( 'Note: it concerns all fields except events messages.', 'decalog' ),
				'full_width'  => true,
				'enabled'     => true,
			]
		);
		register_setting( 'decalog_logger_privacy_section', 'decalog_logger_privacy_ip' );
		add_settings_field(
			'decalog_logger_privacy_name',
			__( 'Users', 'decalog' ),
			[ $form, 'echo_field_checkbox' ],
			'decalog_logger_privacy_section',
			'decalog_logger_privacy_section',
			[
				'text'        => esc_html__( 'Pseudonymisation', 'decalog' ),
				'id'          => 'decalog_logger_privacy_name',
				'checked'     => $this->current_logger['privacy']['pseudonymization'],
				'description' => esc_html__( 'If checked, log fields will contain hashes instead of user IDs & names.', 'decalog' ) . '<br/>' . esc_html__( 'Note: it concerns all fields except events messages.', 'decalog' ),
				'full_width'  => true,
				'enabled'     => true,
			]
		);
		register_setting( 'decalog_logger_privacy_section', 'decalog_logger_privacy_name' );
	}

	/**
	 * Callback for logger privacy section.
	 *
	 * @since 1.0.0
	 */
	public function logger_details_section_callback() {
		$form = new Form();
		$id   = 'decalog_logger_details_dummy';
		add_settings_field(
			$id,
			__( 'Standard', 'decalog' ),
			[ $form, 'echo_field_checkbox' ],
			'decalog_logger_details_section',
			'decalog_logger_details_section',
			[
				'text'        => esc_html__( 'Included', 'decalog' ),
				'id'          => $id,
				'checked'     => true,
				'description' => esc_html__( 'Allows to log standard DecaLog information.', 'decalog' ),
				'full_width'  => true,
				'enabled'     => false,
			]
		);
		register_setting( 'decalog_logger_details_section', $id );
		$proc = new ProcessorTypes();
		foreach ( array_reverse( $proc->get_all() ) as $processor ) {
			$id = 'decalog_logger_details_' . strtolower( $processor['id'] );
			add_settings_field(
				$id,
				$processor['name'],
				[ $form, 'echo_field_checkbox' ],
				'decalog_logger_details_section',
				'decalog_logger_details_section',
				[
					'text'        => esc_html__( 'Included', 'decalog' ),
					'id'          => $id,
					'checked'     => in_array( $processor['id'], $this->current_logger['processors'], true ),
					'description' => $processor['help'],
					'full_width'  => true,
					'enabled'     => ( 'WordpressHandler' !== $this->current_logger['handler'] || 'BacktraceProcessor' === $processor['id'] ) && ( 'PushoverHandler' !== $this->current_logger['handler'] ),
				]
			);
			register_setting( 'decalog_logger_details_section', $id );
		}
	}

}
