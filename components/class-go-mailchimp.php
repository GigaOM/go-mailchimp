<?php
/**
 * class-go-mailchimp.php
 *
 * @author Gigaom <support@gigaom.com>
 */
class GO_MailChimp
{
	private $api = NULL;
	private $map = NULL;
	private $admin = NULL;

	private $meta_key_base = 'go_mailchimp';
	private $config = NULL;
	private $subscribe_hooks;
	private $did_subscribe_hook = array();
	private $user_pre_update;

	/**
	 * constructor method for the GO_Mailchimp class
	 */
	public function __construct()
	{
		// most hooks registered on init
		add_action( 'init', array( $this, 'init' ) );

		// listen for triggered user action from go-syncuser
		add_action( 'go_syncuser_user', array( $this, 'go_syncuser_user' ), 10, 2 );

		// listen for the action when the user's do_not_email user profile
		// is updated (unsubscribed or re-subscribed)
		add_action( 'go_user_profile_do_not_email_updated', array( $this, 'do_not_email_updated' ), 10, 2 );

		if ( is_admin() )
		{
			$this->admin();
		}
	}//END __construct

	/**
	 * This function is triggered at 'init' to initialize all actions and
	 * hooks for the plugin to function. It makes callbacks to both internal
	 * functions as well as to the api class.
	 */
	public function init()
	{
		if ( ! $this->config() )
		{
			apply_filters( 'go_slog', 'go-mailchimp', __FUNCTION__ . ': Trying to register hooks without a valid config file.' );
		}

		// hook pre_user_login to check if the user is changing her email
		// or not. if so then we update the user's email at MC before
		// calling subscribe() on the user to avoid creating a new MC account
		// TODO: use the subscriber's list email id instead of email address
		// to identify the subscriber to update
		add_filter( 'pre_user_login', array( $this, 'pre_user_login' ) );
	}//END init

	/**
	 * @return GO_MailChimp_Admin object
	 */
	public function admin()
	{
		if ( ! $this->admin )
		{
			require_once __DIR__ . '/class-go-mailchimp-admin.php';
			$this->admin = new GO_MailChimp_Admin( $this );
		}

		return $this->admin;
	}//END admin

	/**
	 * @param string $suffix (optional) what to append to the plugin's
	 *  main meta key. an underscore (_) will be appended between the plugin's
	 *  base meta key and $suffix
	 * @return the meta key
	 */
	public function meta_key( $suffix = NULL )
	{
		if ( empty( $suffix ) )
		{
			return $this->meta_key_base;
		}

		return $this->meta_key_base . '_' . $suffix;
	}//END meta_key

	/**
	 * returns our current configuration, or a value in the configuration.
	 *
	 * @param string $key (optional) key to a configuration value
	 * @return mixed Returns the config array, or a config value if
	 *  $key is not NULL
	 */
	public function config( $key = NULL )
	{
		if ( empty( $this->config ) )
		{
			$this->config = apply_filters(
				'go_config',
				NULL,
				'go-mailchimp'
			);
		}//END if

		if ( ! empty( $key ) )
		{
			return isset( $this->config[ $key ] ) ? $this->config[ $key ] : NULL ;
		}

		return $this->config;
	}//END config

	/**
	 * function used to retrieve the currently set api, or set it if it
	 * has no yet been setup
	 *
	 * @param array $config (optional) The config array setup in file
	 * @return object Returns the Go_Mailchimp_API object
	 */
	public function api()
	{
		if ( empty( $this->api ) )
		{
			require_once __DIR__ . '/class-go-mailchimp-api.php';

			$this->api = new GO_Mailchimp_API( $this->config( 'api_key' ) );
		}//END if

		return $this->api;
	}//END api

	/**
	 * accessor function for the GO_Mailchimp_Map instance
	 *
	 * @return the GO_Mailchimp_Map object
	 */
	public function map()
	{
		if ( empty( $this->map ) )
		{
			require_once __DIR__ . '/class-go-mailchimp-map.php';

			$this->map = new GO_Mailchimp_Map();
		}//END if

		return $this->map;
	}//END map

	/**
	 * hooked to the go_user_profile_do_not_email_updated action. 
	 * when $do_not_email is FALSE we make sure to subscribe the user
	 * and invoke a sync
	 *
	 * @param $user_id int the user id
	 * @param $do_not_email bool value of the do_not_email user profile
	 */
	public function do_not_email_updated( $user_id, $do_not_email )
	{
		if ( go_syncuser()->debug() )
		{
			apply_filters( 'go_slog', 'go-mailchimp', 'do_not_email updated to ' . var_export( $do_not_email, 1 ) . ' by user ' . $user_id );
		}

		if ( 0 >= $user_id )
		{
			return;
		}

		if ( $do_not_email )
		{
			$this->api()->unsubscribe( $user_id );
		}
		else
		{
			$this->api()->subscribe( $user_id );
		}
	}//END do_not_email_updated

	/**
	 * this callback gets invoked when events configured in go-syncuser
	 * are fired.
	 *
	 * @param int $user_id ID of the user who triggered an event
	 * @param string $action the type action triggered. we're only
	 *  processing 'update' and 'delete'.
	 */
	public function go_syncuser_user( $user_id, $action )
	{
		if ( go_syncuser()->debug() )
		{
			apply_filters( 'go_slog', 'go-mailchimp', 'go_syncuser_user action invoked for user ' . $user_id );
		}

		// get all lists from mailchimp
		$lists = $this->api()->lists();

		if ( ( 'update' == $action ) || ( 'add' == $action ) )
		{
			// call subscribe() on lists the user is subscribed to to sync
			// the mailchimp merge vars
			foreach ( $lists as $list )
			{
				$membership_info = $this->api()->member( $user_id, $list['id'] );
				if ( 'subscribed' == $membership_info['status'] )
				{
					$subscribed[] = $list['id'];
				}
			}//END foreach

			$this->api()->subscribe( $user_id, $subscribed );
		}//END if
		elseif ( 'delete' == $action )
		{
			// unsubscribe from all lists
			$this->api()->unsubscribe( $user_id, $lists );
		}
	}//END go_syncuser_user

	/**
	 * captures the existing WP_User object before it is updated
	 *
	 * @param $user_login String user login name
	 */
	public function pre_user_login( $user_login )
	{
		$this->user_pre_update = get_user_by( 'login', $user_login );
		return $user_login;
	}//end pre_user_login

	/**
	 * @param WP_User $user a user object
	 * @return the MC member rating from $user's user meta, or '' if we
	 *  don't have one.
	 */
	public function get_subscriber_rating( $user )
	{
		if ( empty( $user->ID ) )
		{
			return '';
		}

		if ( ! $usermeta = get_user_meta( $user->ID, go_mailchimp()->meta_key( 'subscriber_info' ), TRUE ) )
		{
			return '';
		}

		if ( empty( $usermeta ) )
		{
			return '';
		}

		// in our set up the user is generally only subscribed to one list
		// so we just pick the rating from the first list in the user meta
		foreach ( $usermeta as $list_info )
		{
			if ( empty( $list_info['member_rating'] ) )
			{
				continue;
			}

			return $list_info['member_rating'];
		}

		return '';
	}//END get_subscriber_rating
}//END class

/**
 * singleton
 */
function go_mailchimp()
{
	global $go_mailchimp;

	if ( ! isset( $go_mailchimp ) )
	{
		$go_mailchimp = new GO_Mailchimp();
	}//END if

	return $go_mailchimp;
}//END go_mailchimp