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
			$this->log( 'Trying to register hooks without a valid config file.', __FUNCTION__ );
		}

		add_action( 'show_user_profile', array( $this, 'show_user_profile' ) );
		add_action( 'edit_user_profile', array( $this, 'show_user_profile' ) );

		// Hooking admin_menu here because it's too late to do it in admin_init
		if ( is_admin() )
		{
			add_action( 'admin_init', array( $this, 'admin_init' ) );
			$this->test();
		}// END if

		// hook pre_user_login to check if the user is changing her email
		// or not. if so then we update the user's email at MC before
		// calling subscribe() on the user to avoid creating a new MC account
		// TODO: use the subscriber's list email id instead of email address
		// to identify the subscriber to update
		add_filter( 'pre_user_login', array( $this, 'pre_user_login' ) );
	}//END init

	/**
	 * admin_init
	 *
	 * Register all necessary aspects for the visual aspects of the plugin
	 * to appear throughout the WP Dashboard specific to MailChimp
	 * administration.
	 */
	public function admin_init()
	{
		// Custom columns in the user listing
		add_filter( 'manage_users_columns', array( $this, 'manage_users_columns' ) );
		add_filter( 'manage_users_custom_column', array( $this, 'manage_users_custom_column' ), 15, 3 );

		// Ajax handlers
		add_action( 'wp_ajax_go_mailchimp_user_sync', array( $this, 'user_sync_ajax' ) );

		// Webhook Action (for when MailChimp updates us)
		add_action( 'wp_ajax_go-mailchimp-webhook', array( $this, 'webhook_handler' ) );
		add_action( 'wp_ajax_nopriv_go-mailchimp-webhook', array( $this, 'webhook_handler' ) );

		// Our settings page, um, settings
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_enqueue_scripts' ) );
	}//END admin_init

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
	 * Function used to catch hooks being fired from MailChimp
	 * Goes through on a case basis and assigns to necessary actions
	 */
	public function webhook_handler()
	{
		// Mailchimp's documentation: http://apidocs.mailchimp.com/webhooks/

		// example URL: http://site.org/wp-admin/admin-ajax.php?action=go-mailchimp-webhook&mailchimpwhs=$webhook_secret
		// $webhook_secret is set in the config for each list independently

		$list_id = preg_replace( '/[^a-zA-Z0-9\-_]/', '', $_POST[ 'data' ][ 'list_id' ] );

		// get the info we need to check the secret
		// from MC: "our best suggestion is to simply include a secret key in the URL your provide and check that GET parameter in your scripts"
		$lists = $this->config( 'lists' );

		if ( ! isset( $lists[ $list_id ]['webhook_secret'] ) || ! $_GET['mailchimpwhs'] == $lists[ $list_id ]['webhook_secret'] )
		{
			die;
		}

		switch ( $_POST[ 'type' ] )
		{
			case 'unsubscribe':
				//The api will fail as the user is not subscribed, but will
				//update the user's status in WP accordingly
				if ( $user = get_user_by( 'email', sanitize_email( $_POST[ 'data' ][ 'email' ] ) ) )
				{
					$this->api()->unsubscribe( $user->ID, $list_id );
				}
				break;

			/*
			Commenting these out for now, because they not immediately required and I don't want to test them.
			We'll revisit them in time.

			case 'subscribe':
				//The api will fail as the user is  subscribed, but will
				//update the user's status in WP accordingly
				$this->api()->subscribe( sanitize_email( $_POST[ 'data' ][ 'new_email' ] ), $list_id );
				break;

			case 'upemail':
				if ( $user = get_user_by( 'email', sanitize_email( $_POST[ 'data' ][ 'old_email' ] ) ) )
				{
					wp_update_user( array( 'ID' => $user->ID, 'user_email' => sanitize_email( $_POST[ 'data' ][ 'new_email' ] ) ) );
				}//end if
				break;
			*/
		}//END switch

		die;
	}//END webhook_handler

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
		// we can only act if $do_not_email is FALSE and we have a valid $user_id
		if ( $do_not_email || 0 >= $user_id )
		{
			return;
		}

		$this->api()->subscribe( $user_id );
	}//END do_not_email_updated

	/**
	 * register custom plugin js file
	 *
	 * @param string $hook The page hook that this should be enqueued for
	 * @return NULL
	 */
	public function admin_enqueue_scripts( $hook )
	{
		if ( 'profile.php' == $hook || 'user-edit.php' == $hook || 'settings_page_mailchimp' == $hook )
		{
			wp_enqueue_script( 'go_mailchimp_js', plugins_url( '/js/go-mailchimp.js', __FILE__ ), array( 'jquery' ) );
		}
	}//END admin_enqueue_scripts

	/**
	 * @return GO_Mailchimp_Test singleton
	 */
	public function test()
	{
		if ( ! isset( $this->test ) )
		{
			require_once __DIR__ . '/class-go-mailchimp-test.php';
			$this->test = new GO_Mailchimp_Test;
		}

		return $this->test;
	}//END test

	/**
	 * add a custom MailChimp column to the user's administration table
	 *
	 * @param array $columns An array of the columns already setup
	 * @return array The new columns array
	 */
	public function manage_users_columns( $columns )
	{
		$columns[ 'go_mailchimp_user_status' ] = 'MailChimp';
		return $columns;
	}//END manage_users_columns

	/**
	 * The value to actually place in the table cell for each user in the
	 * newly added MailChimp column
	 *
	 * @param string $output the custom column output. defaults to ''
	 * @param string $column_name The name of the column to put val in
	 * @param object $user The user who we are displaying the val for
	 * @return string Return the date of last sync or Not Synced
	 *
	 * @NOTE: The first parameter is required by WordPress :/
	 */
	public function manage_users_custom_column( $output, $column_name, $user )
	{
		if ( 'go_mailchimp_user_status' == $column_name )
		{
			$status = get_user_meta( $user, $this->meta_key(), TRUE );
			return ( isset( $status[ 'activity_date' ] ) ) ? ( 'Last Synced: ' . date( 'Y-m-d H:i:s', $status[ 'activity_date' ] ) ) : 'Not Synced';
		}

		return $output;
	}//END manage_users_custom_column

	/**
	 * Function to display the user's Mailchimp status within the user
	 * profile admin section
	 *
	 * @param object $user The WP_User being viewed
	 */
	public function show_user_profile( $user )
	{
		ob_start();

		$this->display_user_profile_status_section( $user );
		$user_status = ob_get_clean();

		include_once __DIR__ . '/templates/user-profile.php';
	}//END show_user_profile

	/**
	 * Display the user's Mailchimp subscription status information
	 *
	 * @param object $user The WP user being viewed
	 */
	public function display_user_profile_status_section( $user )
	{
		if ( $user_status = get_user_meta( $user->ID, $this->meta_key(), TRUE ) )
		{
			?>
			<p>Last synchronized on: <span class="description"><?php echo date( 'Y-m-d H:i:s', $user_status[ 'activity_date' ] ); ?></span></p>
			<p>Last action performed: <span class="description"><?php echo esc_html( ucfirst( $user_status[ 'last_action_performed' ] ) ); ?></span></p>
			<?php

			if ( isset ( $user_status['subscriptions'] ) && is_array( $user_status['subscriptions'] ) )
			{
				$subscriber_info = get_user_meta( $user->ID, $this->meta_key( 'subscriber_info' ), TRUE );
				?>
				<p>User is a member of the following email lists:</p>
				<ul>
					<?php
					foreach ( $user_status['subscriptions'] as $lid )
					{
						echo $this->get_subscribed_list_html( $lid, $subscriber_info );
					}//END foreach
					?>
				</ul>
				<?php
			}//END if
			else
			{
				?><p>User is not a member of any lists.</p><?php
			}

			if ( isset( $user_status['unsubscribed'] ) && is_array( $user_status['unsubscribed'] ) )
			{
				?>
				<p>User is unsubscribed from the following email lists:</p>
				<ul>
					<?php
					foreach ( $user_status[ 'unsubscribed' ] as $lid )
					{
						$list = $this->api()->list_data( $lid );
						echo '<li><p class="description">' . esc_html( $list['name'] ) . '</p></li>';
					}//END foreach
					?>
				</ul>
				<?php
			}//END if
		}//END if
		else
		{
			echo '<p class="description">User is not registered in MailChimp</p>';
		}
	}//END display_user_profile_status_section

	/**
	 * build the html to render an email list the user is subscribed to.
	 * we link to the user's MailChimp profile page for the list and
	 * also render their member rating for that list next to the list name.
	 *
	 * @param int $lid the list's MailChimp Id
	 * @param array $subscriber_info the user's MailChimp subscriber info
	 * @return string the html for a MailChimp list subscribed to by a user
	 */
	public function get_subscribed_list_html( $lid, $subscriber_info )
	{
		$list = $this->api()->list_data( $lid );

		// build links to the the list in MC and show the user's ratings for each list
		$anchor_before = $anchor_after = $ratings_html = '';
		if ( $subscriber_info && isset( $subscriber_info[ $lid ] ) )
		{
			// link to the member's MC profile for $list
			if ( isset( $subscriber_info[ $lid ]['web_id'] ) )
			{
				$anchor_before = '<a href="https://us1.admin.mailchimp.com/lists/members/view?id=' . esc_attr( $subscriber_info[ $lid ]['web_id'] ) . '" target="_blank">';
				$anchor_after = '</a>';
			}//END if

			if ( isset( $subscriber_info[ $lid ]['member_rating'] ) )
			{
				// MC users have a maximum rating of 5. indicate them with filled stars
				$ratings = (int) $subscriber_info[ $lid ]['member_rating'];
				$ratings_html = ' ';
				$i = 0;
				while ( ++$i <= 5 )
				{
					$ratings_html .= ( $i <= $ratings ? ' &#x2605;' : ' &#x2606;' );
				}//END while
			}//END if
		}//END if

		return '<li><p>' . $anchor_before . esc_html( $list['name'] ) . $anchor_after . $ratings_html . '</p></li>';
	}//END get_subscribed_list_html

	/**
	 * ajax callback for the Sync button on the user profile page to
	 * avoid saving the user in the WP database
	 */
	public function user_sync_ajax()
	{
		// only allowed for people who can edit users
		if ( ! current_user_can( 'edit_users' ) )
		{
			die;
		}

		if ( ! ( $user = get_userdata( wp_filter_nohtml_kses( $_REQUEST[ 'go_mailchimp_user_sync_user' ] ) ) ) )
		{
			echo '<p class="error">Couldn&apos;t read user data</p>';
			die;
		}

		$subscribe = $unsubscribe = array();

		// get lists from mailchimp
		$lists = $this->api()->lists();

		foreach ( $lists as $list )
		{
			$membership_info = $this->api()->member( $user, $list['id'] );

			switch ( $membership_info['status'] )
			{
				case 'unsubscribed':
					$unsubscribe[] = $list['id'];
					break;
				default:
					$subscribe[] = $list['id'];
					break;
			}//END switch
		}//END foreach

		// if there are lists to subscribe to, subscribe the user to them
		if ( $subscribe )
		{
			$this->api()->subscribe( $user, $subscribe );
		}

		// if there are lists to unsubscribe from, unsubscribe the user from them
		if ( $unsubscribe )
		{
			$this->api()->unsubscribe( $user, $unsubscribe );
		}

		$this->display_user_profile_status_section( $user );
		die;
	}//END user_sync_ajax

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
		// get all lists from mailchimp
		$lists = $this->api()->lists();

		if ( ( 'update' == $action ) || ( 'add' == $action ) )
		{
			// call subscribe() on lists the user is subscribed to to sync
			// the mailchimp merge vars
			foreach ( $lists as $list )
			{
				$membership_info = $this->api()->member( $user, $list['id'] );
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
	 * Instead of bolting onto api log function, keeping this local for
	 * better source and error tracing
	 *
	 * @param string $message The message to be output
	 * @param string $origin The function that called log
	 * @param string $line (optional) Line number of log call
	 */
	public function log( $message, $origin, $line = '' )
	{
		if ( ! isset( $message ) )
		{
			$error = '[GO_Mailchimp::Log::error] => Error function was called without a message.';
			apply_filters( 'go_slog', 'go-mailchimp', $error, '' );
			error_log( $error );
			return;
		}//END if

		if ( ! isset( $origin ) )
		{
			$error = '[GO_Mailchimp::Log::error] => Error function was called without an origin.';
			apply_filters( 'go_slog', 'go-mailchimp', $error, '' );
			error_log( $error );
			return;
		}//END if

		$error = '[ GO_Mailchimp::' . $origin . ',' . $line  . '] => ' . $message;
		apply_filters( 'go_slog', 'go-mailchimp', $error, '' );
		error_log( $error );
	}//END log

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