<?php
/**
 * class for admin dashboard-related functionalities
 *
 * @author Gigaom <support@gigaom.com>
 */
class GO_MailChimp_Admin
{
	private $core = NULL;

	/**
	 * constructor
	 *
	 * @param GO_MailChimp the GO_MailChimp singleton object
	 */
	public function __construct( $core )
	{
		$this->core = $core;
		add_action( 'admin_init', array( $this, 'admin_init' ) );
	}//END __construct

	/**
	 * Register all necessary aspects for the visual aspects of the plugin
	 * to appear throughout the WP Dashboard specific to MailChimp
	 * administration.
	 */
	public function admin_init()
	{
		add_action( 'show_user_profile', array( $this, 'show_user_profile' ) );
		add_action( 'edit_user_profile', array( $this, 'show_user_profile' ) );

		// Ajax handlers
		add_action( 'wp_ajax_go_mailchimp_user_sync', array( $this, 'user_sync_ajax' ) );

		// Webhook Action (for when MailChimp updates us)
		add_action( 'wp_ajax_go-mailchimp-webhook', array( $this, 'webhook_ajax' ) );
		add_action( 'wp_ajax_nopriv_go-mailchimp-webhook', array( $this, 'webhook_ajax' ) );

		// Our settings page, um, settings
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_enqueue_scripts' ) );
	}//END admin_init

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
		if ( $user_status = get_user_meta( $user->ID, $this->core->meta_key(), TRUE ) )
		{
			?>
			<p>Last synchronized on: <span class="description"><?php echo date( 'Y-m-d H:i:s', $user_status[ 'activity_date' ] ); ?></span></p>
			<p>Last action performed: <span class="description"><?php echo esc_html( ucfirst( $user_status[ 'last_action_performed' ] ) ); ?></span></p>
			<?php

			if ( isset ( $user_status['subscriptions'] ) && is_array( $user_status['subscriptions'] ) )
			{
				$subscriber_info = get_user_meta( $user->ID, $this->core->meta_key( 'subscriber_info' ), TRUE );
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
						$list = $this->core->api()->list_data( $lid );
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
		$list = $this->core->api()->list_data( $lid );

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
		$lists = $this->core->api()->lists();

		foreach ( $lists as $list )
		{
			$membership_info = $this->core->api()->member( $user, $list['id'] );

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
			$this->core->api()->subscribe( $user, $subscribe );
		}

		// if there are lists to unsubscribe from, unsubscribe the user from them
		if ( $unsubscribe )
		{
			$this->core->api()->unsubscribe( $user, $unsubscribe );
		}

		$this->display_user_profile_status_section( $user );
		die;
	}//END user_sync_ajax

	/**
	 * Function used to catch hooks being fired from MailChimp
	 * Goes through on a case basis and assigns to necessary actions
	 */
	public function webhook_ajax()
	{
		// Mailchimp's documentation: http://apidocs.mailchimp.com/webhooks/

		// example URL: http://site.org/wp-admin/admin-ajax.php?action=go-mailchimp-webhook&mailchimpwhs=$webhook_secret
		// $webhook_secret is set in the config for each list independently

		$list_id = preg_replace( '/[^a-zA-Z0-9\-_]/', '', $_POST[ 'data' ][ 'list_id' ] );

		// get the info we need to check the secret
		// from MC: "our best suggestion is to simply include a secret key in the URL your provide and check that GET parameter in your scripts"
		$lists = $this->core->config( 'lists' );

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
					$this->core->api()->unsubscribe( $user->ID, $list_id );
				}
				break;

			/*
			Commenting these out for now, because they not immediately required and I don't want to test them.
			We'll revisit them in time.

			case 'subscribe':
				//The api will fail as the user is  subscribed, but will
				//update the user's status in WP accordingly
				$this->core->api()->subscribe( sanitize_email( $_POST[ 'data' ][ 'new_email' ] ), $list_id );
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
	}//END webhook_ajax

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
}//END class