<?php
/**
 * class-go-mailchimp-api.php
 *
 * @description Acts as a layer between WordPress and the API to handle
 *              changes to both systems.
 * @version 1.0.0
 * @author Gigaom <support@gigaom.com>
 */
class GO_Mailchimp_API
{
	private $config = NULL;
	private $mc     = NULL;

	public $debug = FALSE;

	/**
	 * @description Creates a new instance of GO_Mailchimp_API
	 * @constructor
	 * @param string $api_key the Mailchimp API key
	 */
	public function __construct( $api_key )
	{
		if ( class_exists( 'Mailchimp', FALSE ) )
		{
			apply_filters( 'go_slog', 'go-mailchimp', __FUNCTION__ . ': Warning: Mailchimp class already registered by another plugin. Attempting to use that, but watch for errors if the external version is incompatible.' );
		}
		else
		{
			require_once __DIR__ . '/external/Mailchimp.php';
		}

		$this->mc = new Mailchimp( $api_key );
	}//END __construct

	/**
	 * Retrieve information about a list, and can target
	 * MailChimp, WP, or a merge of the two. Default is a merged listing
	 *
	 * @param string $identifier list id or list name
	 * @param string $source Where to pull the list data from. can be 'config',
	 *  'mc', 'user' or 'merged. default is 'merged'.
	 * @return array Information about the list id provided, or NULL if the
	 *  list cannot be found
	 */
	public function list_data( $identifier, $source = 'merged' )
	{
		$lists = $this->lists( $source );

		if ( isset( $lists[ $identifier ] ) )
		{
			return $lists[ $identifier ];
		}

		// also check if user passed in the list name instead of id
		foreach ( $lists as $list_id => $list )
		{
			if ( $list[ 'name' ] == $identifier || $list[ 'id' ] == $identifier )
			{
				return $list;
			}
		}//END foreach

		apply_filters( 'go_slog', 'go-mailchimp', __FUNCTION__ . ': No lists found with an id or name of "' . $identifier . '".' );
		return NULL;
	}//END list_data

	/**
	 * list_merge_vars
	 *
	 * Return the MailChimp merge vars for a list
	 *
	 * @param string $list_id id of the list to get the merge vars from
	 * @return array the merge variables, or FALSE if encounter an error
	 */
	public function list_merge_vars( $list_id )
	{
		try
		{
			$merge_vars = $this->mc->lists->mergeVars( array( $list_id ) );
		}
		catch ( Exception $e )
		{
			apply_filters( 'go_slog', 'go-mailchimp', __FUNCTION__ . ': An Exception was thrown: ' . $e->getMessage() );
			return FALSE;
		}//END catch

		if ( ! isset( $merge_vars['data'][0]['merge_vars'] ) )
		{
			apply_filters( 'go_slog', 'go-mailchimp', __FUNCTION__ . ': Merge vars not found' );
			return FALSE;
		}

		return $merge_vars['data'][0]['merge_vars'];
	}//END list_merge_vars

	/**
	 * Return the lists and associated data that's defined in the config file,
	 * from MailChimp, subscribed by a user, or a merged list from the config
	 * file and MailChimp.
	 *
	 * @param string $source (Optional) Where to pull the list data from.
	 * @param mixed $user (Optional) A WP user to get lists for. This is
	 *  required when $source is set to 'user'.
	 */
	public function lists( $source = 'merged', $user = NULL )
	{
		switch ( $source )
		{
			case 'config':
				return go_mailchimp()->config( 'lists' );
				break;

			case 'mc':
				try
				{
					$lists = $this->mc->lists->getList();
				}
				catch ( Exception $e )
				{
					apply_filters( 'go_slog', 'go-mailchimp', __FUNCTION__ . ': An Exception was thrown: ' . $e->getMessage() );
					return FALSE;
				}//END catch

				$return_lists = array();

				// handle error conditions with some feedback
				if ( ! is_array( $lists ) )
				{
					echo '<h2>Ack, we got errors and stuff!</h2><pre>';
					print_r( $this->mc );
					print_r( go_mailchimp()->config() );
					var_dump( $lists );
					return FALSE;
				}//END if

				foreach ( $lists['data'] as $list )
				{
					$return_lists[ $list['id'] ] = $list;
				}//END foreach

				return $return_lists;
				break;

			case 'merged':
				return array_intersect_key( $this->lists( 'config' ), $this->lists( 'mc' ) );
				break;

			case 'user':
				$return_lists = array();

				foreach ( $this->subscriptions( $user ) as $list_id )
				{
					$return_lists[ $list_id ] = $this->list( $list_id );
				}//END foreach

				return $return_lists;
				break;
		}//END switch

		apply_filters( 'go_slog', 'go-mailchimp', __FUNCTION__ . ': No lists found from "' . $source . '".' );
		return FALSE;
	}//END lists

	/**
	 * Gets the membership information for an email address in a
	 * particular list.
	 *
	 * @param object $user WP_User object
	 * @param string $list id of the list.
	 * @return mixed Returns an array of member info or FALSE
	 */
	public function member( $user, $list )
	{
		if ( empty( $user->user_email ) || empty( $list ) )
		{
			apply_filters( 'go_slog', 'go-mailchimp', __FUNCTION__ . ': Empty email or list id passed.' );
			return FALSE;
		}

		try
		{
			$email_struct = array( array( 'email' => $user->user_email ) );
			$member = $this->mc->lists->memberInfo( $list, $email_struct );
		}//END try
		catch ( Exception $e )
		{
			apply_filters( 'go_slog', 'go-mailchimp', __FUNCTION__ . ': An Exception was thrown: ' . $e->getMessage() );
			return FALSE;
		}//END catch

		if ( empty( $member ) || empty( $member['data'] ) )
		{
			apply_filters( 'go_slog', 'go-mailchimp', __FUNCTION__ . ": No membership info found for email address '{$user->user_email}' in list: $list" );
			return FALSE;
		}//END if

		return $member['data'][0];
	}//END member

	/**
	 * get the value of the merge vars for a user and list
	 *
	 * @param object $user The WP user to get data from
	 * @param mixed $list The list to get the merge vars from
	 * @return array The merge variables for the list and user
	 */
	public function merge_vars( $user, $list )
	{
		require_once __DIR__ . '/class-go-mailchimp-map.php';

		$merge_vars = array();
		$list = is_array( $list ) ? $list : $this->list_data( $list );

		foreach ( $this->list_merge_vars( $list['id'] ) as $mv )
		{
			$merge_vars[ $mv['tag'] ] = go_mailchimp()->map()->map( $user, $list, $mv['tag'] );
		}// END foreach

		$merge_vars[ 'GROUPINGS' ] = go_mailchimp()->map()->map( $user, $list, 'GROUPINGS' );

		return $merge_vars;
	}//END merge_vars

	/**
	 * Save the users current subscription status in user meta
	 *
	 * @param object $user The WP_User to save status for
	 * @param string $action The last action performed on the user
	 * @param string $function (Optional) The function calling this one
	 * @return boolean The success of the user meta save
	 */
	public function save_status( $user, $action, $function = 'subscribe' )
	{
		$inserting = array(
			'activity_date' => time(),
			'last_action_performed' => $action,
			'source_function' => $function,
		);

		if ( $subscriptions = $this->subscriptions( $user ) )
		{
			$inserting[ 'subscriptions' ] = $subscriptions;
		}

		if ( $unsubscribed = $this->unsubscribed( $user ) )
		{
			$inserting[ 'unsubscribed' ] = $unsubscribed;
			// if someone has unsubscribed from any of our lists, let's flag
			// them as do not email
			do_action( 'go_user_profile_do_not_email', $user->ID, TRUE );
		}//END if

		// suspend user update triggers
		go_syncuser()->suspend_triggers( TRUE );

		$return = update_user_meta( $user->ID, go_mailchimp()->meta_key(), $inserting );

		// turn triggers back on
		go_syncuser()->suspend_triggers( FALSE );

		return $return;
	}//END save_status

	/**
	 * Turn a user ID, email address, or object into a proper user object
	 *
	 * @param $user mixed an user id (int), email address (string) or a
	 *  WP_User (object)
	 * @return mixed a WP_User if $user maps to a user, or FALSE if not.
	 */
	public function sanitize_user( $user )
	{
		if ( is_object( $user ) )
		{
			if ( isset( $user->ID ) )
			{
				return get_userdata( (int) $user->ID );
			}
			return FALSE;  // not a WP_User object for an existing user
		}
		elseif ( is_numeric( $user ) )
		{
			return get_userdata( absint( $user ) );
		}
		elseif ( is_string( $user ) )
		{
			return get_user_by( 'email', $user );
		}

		return FALSE;
	}//END sanitize_user

	/**
	 * Subscribe a user to a list
	 *
	 * @param object $user A WP_User object.
	 * @param string $list (optional) The id of the list to subscribe the user to.
	 * @param array $merge_vars (optional) The merge vars to merge the user into MC.
	 * @param boolean $wait (optional) Cron action or execute now
	 * @param string $action The action being performed (may be update)
	 * @return boolean Returns the success or failure of the subscription.
	 */
	public function subscribe( $user, $list = NULL, $merge_vars = NULL, $wait = FALSE, $action = 'subscribe' )
	{
		// validate the user input
		$user_in = $user; // save this in case of error
		$user = $this->sanitize_user( $user_in );

		// make sure we found a user
		if ( empty( $user ) || is_wp_error( $user ) )
		{
			apply_filters( 'go_slog', 'go-mailchimp', __FUNCTION__ . ': No user found for input value, got ' . var_export( $user_in, TRUE ) );
			return FALSE;
		}//END if

		$list = $list ? $list : $this->lists();

		if ( is_array( $list ) )
		{
			/**
			 * We want to subscribe the user to multiple lists. Call this
			 * method recursively for each list that was passed in accounting
			 * for ones the user is unsubscribed from.
			 */
			$success = TRUE;
			foreach ( $list as $list_id => $list )
			{
				// success = FALSE if any of the subscribe() calls fail
				$success &= (bool) $this->subscribe( $user, $list_id, $merge_vars, $wait );
			}//END foreach

			return $success;
		}//END if

		if ( ! isset( $merge_vars ) )
		{
			if ( ! ( $merge_vars = $this->merge_vars( $user, $list ) ) )
			{
				apply_filters( 'go_slog', 'go-mailchimp', __FUNCTION__ . ': No merge variables were passed in.' );
				return FALSE;
			}
		}//END if

		if ( $this->do_not_email( $user->ID ) )
		{
			return FALSE;
		}

		if ( $wait )
		{
			return $this->cronify( $user->ID );
		}

		try
		{
			$email_struct = array( 'email' => $user->user_email );
			$api_result = $this->mc->lists->subscribe( $list, $email_struct, $merge_vars, 'html', FALSE, TRUE );
		}//END try
		catch ( Exception $e )
		{
			apply_filters( 'go_slog', 'go-mailchimp', __FUNCTION__ . ': An Exception was thrown: ' . $e->getMessage() );
			return FALSE;
		}//END catch

		if ( ! $api_result )
		{
			apply_filters( 'go_slog', 'go-mailchimp', __FUNCTION__ . ': Unable to subscribe user to list ' . $list );
			return FALSE;
		}

		$this->save_status( $user, $action, __FUNCTION__ );

		// sync some subscriber info from MC
		$this->sync_subscriber_info( $user, $list );

		do_action( 'go_mailchimp_sync', $user, $list );

		return $api_result;
	}//END subscribe

	/**
	 * check if the user is subscribed to the list id provided.
	 *
	 * @param object $user A WP_User object.
	 * @param string $list_id The id of the list we are checking subscription
	 * @return mixed Returns FALSE on failure, or member info on success
	 */
	public function subscribed( $user, $list_id )
	{
		if ( empty( $user->user_email ) || empty( $list_id ) )
		{
			apply_filters( 'go_slog', 'go-mailchimp', __FUNCTION__ . ': Invalid email or list id passed in.' );
			return FALSE;
		}

		if ( $this->do_not_email( $user->ID ) )
		{
			return FALSE;
		}

		$member = $this->member( $user, $list_id );
		if ( ! $member )
		{
			return FALSE;
		}

		if ( 'subscribed' != $member['status'] )
		{
			return FALSE;
		}

		return $member;
	}//END subscribed

	/**
	 * Get the subscriptions for a user
	 *
	 * @param object $user The user to get subscriptions for
	 * @return array Array of the users list subscriptions
	 */
	public function subscriptions( $user )
	{
		$subscriptions = array();

		foreach ( $this->lists() as $list_id => $list )
		{
			if ( $this->subscribed( $user, $list_id ) )
			{
				$subscriptions[ $list_id ] = $list_id;
			}
		}//END foreach

		return $subscriptions;
	}//END subscriptions

	/**
	 * check if the user is flagged for not being emailed
	 *
	 * @param $user_id ID of the user to check
	 */
	public function do_not_email( $user_id )
	{
		return apply_filters( 'go_user_profile_do_not_email_check', FALSE, $user_id );
	}//END do_not_email

	/**
	 * Unsubscribe a user from the list(s) passed in. Optionally, this can
	 * be delayed into the cron as well as trigger a full delete of the
	 * user in MailChimp.
	 *
	 * @param object $user A WP_User object.
	 * @param mixed Either a string list id, or an array of list id's
	 * @param boolean $delete Optional delete flag set to FALSE
	 * @param boolean $wait Optional flag to cron action
	 * @param string $action What is calling the unsub
	 * @return boolean Returns the success or failure
	 */
	public function unsubscribe( $user, $list = NULL, $delete = FALSE, $wait = FALSE, $action = 'unsubscribe' )
	{
		// validate the user input
		$user_in = $user;
		$user = $this->sanitize_user( $user );

		// make sure we found a user
		if ( empty( $user ) || is_wp_error( $user ) )
		{
			apply_filters( 'go_slog', 'go-mailchimp', __FUNCTION__ . ': No user found for input value ' . var_export( $user_in, TRUE ) );
			return FALSE;
		}

		$list = $list ? $list : $this->lists();

		if ( is_array( $list ) )
		{
			/**
			 * We want to unsubscribe the user from multiple lists. Call this
			 * method recursively for each list that was passed in accounting
			 * for ones the user is unsubscribed from.
			 */
			$success = FALSE;
			foreach ( $list as $list_id => $list )
			{
				// success = FALSE if any of the subscribe() calls fail
				$success &= $this->unsubscribe( $user, $list_id, $delete, $wait );
			}//END foreach

			return $success;
		}//END if

		if ( $wait )
		{
			return $this->cronify( $user->ID );
		}

		if ( $this->unsubscribed( $user, $list ) )
		{
			if ( $this->debug )
			{
				apply_filters( 'go_slog', 'go-mailchimp', __FUNCTION__ . ": The user's email appears to be already unsubscribed from the list '$list'" );
			}

			$this->save_status( $user, $action, __FUNCTION__ );
			return TRUE;
		}//END if

		try
		{
			$email_struct = array( 'email' => $user->user_email );
			$api_result = $this->mc->lists->unsubscribe( $list, $email_struct, $delete );
		}//end try
		catch ( Exception $e )
		{
			apply_filters( 'go_slog', 'go-mailchimp', __FUNCTION__ . ': An Exception was thrown: ' . $e->getMessage() );
			return FALSE;
		}//END catch

		if ( ! $api_result )
		{
			apply_filters( 'go_slog', 'go-mailchimp', __FUNCTION__ . ': Unable to call $list->unsubscribe' );
			return FALSE;
		}

		$this->save_status( $user, $action, __FUNCTION__ );

		// sync some subscriber info from MC
		$this->sync_subscriber_info( $user, $list );

		do_action( 'go_mailchimp_sync', $user, $list );

		return $api_result;
	}//END unsubscribe

	/**
	 * If a list is passed, return whether or not they are unsubscribed
	 * from it, otherwise return an array of lists that they are
	 * unsubscribed from.
	 *
	 * @param object A wp_user object.
	 * @param string $list Optional list to check
	 * @return mixed Return array of lists or boolean for list
	 */
	public function unsubscribed( $user, $list = NULL )
	{
		if ( is_string( $list ) )
		{
			return ! $this->subscribed( $user, $list );
		}

		$unsubscribed = array();

		foreach ( $this->lists() as $list_id => $list_data )
		{
			if ( $this->unsubscribed( $user, $list_id ) )
			{
				$unsubscribed[] = $list_id;
			}
		}//END foreach

		return $unsubscribed;
	}//END unsubscribed

	/**
	 * Update a user in Mailchimp. Really just calls subscribe with the
	 * update variable set to true
	 *
	 * @param object $user The WP user to update
	 * @param boolean $wait Cron action or execute. Default to wait
	 * @return boolean Success of update action
	 */
	public function update( $user, $wait = TRUE )
	{
		$user = $this->sanitize_user( $user );

		if ( $user->user_email != $_POST[ 'email' ] )
		{
			return $this->update_email( $user, wp_filter_nohtml_kses( $_POST[ 'email' ] ) );
		}

		return $this->subscribe( $user, NULL, NULL, $wait, 'update' );
	}//END update

	/**
	 * Update a user's email in MailChimp. note that since we use the current
	 * email of a user to look up the subscriber on MailChimp, the caller
	 * should make sure the user's email is updated after this call
	 * succeeds or we might not be able to find the same user by the (old)
	 * email after this update.
	 *
	 * @param object $user WP user to update email for
	 * @param string $new_email The new email address
	 * @return boolean The success of the update
	 */
	public function update_email( $user, $new_email )
	{
		if ( ! ( $user = $this->sanitize_user( $user ) ) )
		{
			return FALSE;
		}

		$success = TRUE;
		foreach ( $this->lists() as $list_id => $list )
		{
			$merge_vars = $this->merge_vars( $user, $list_id );
			$merge_vars[ 'EMAIL' ] = $new_email;

			$api_result = FALSE;
			try
			{
				$email_struct = array( 'email' => $user->user_email );
				$api_result = $this->mc->lists->updateMember( $list_id, $email_struct, $merge_vars );
			}//END try
			catch ( Exception $e )
			{
				apply_filters( 'go_slog', 'go-mailchimp', __FUNCTION__ . ': An Exception was thrown: ' . $e->getMessage() );
				$success = FALSE;
			}//END catch

			if ( ! $api_result )
			{
				apply_filters( 'go_slog', 'go-mailchimp', __FUNCTION__ . ": Failed to update the user email to ($new_email) from ({$user->user_email}) in list '$list_id'." );
				$success = FALSE;
			}
		}//END foreach

		return $success;
	}//END update_email

	/**
	 * sync some of $user's MailChimp subscriber info into our user meta.
	 * the information include the user's MC web_id, member_rating, and
	 * geo latitude/longitude.
	 *
	 * @param WP_User $user a user object
	 * @param string $list_id id of the list user is on
	 * @return bool TRUE if successful, FALSE if not
	 */
	public function sync_subscriber_info( $user, $list_id )
	{
		$saved_subscriber_info = get_user_meta( $user->ID, go_mailchimp()->meta_key( 'subscriber_info' ), TRUE );
		if ( empty( $saved_subscriber_info ) && ! is_array( $saved_subscriber_info ) )
		{
			$saved_subscriber_info = array();
		}

		$membership_info = $this->member( $user, $list_id );
		if ( empty( $membership_info ) )
		{
			// user is not subscribed to the list any more
			unset( $saved_subscriber_info[ $list_id ] );
		}
		else
		{
			$saved_subscriber_info[ $list_id ] = array(
				'web_id' => $membership_info['web_id'],
				'member_rating' => $membership_info['member_rating'],
			);

			// assume if geo->latitude is set then so is geo->longitude
			if ( isset( $membership_info['geo']['latitude'] ) )
			{
				$saved_subscriber_info[ $list_id ]['geo'] = array(
					'lat' => $membership_info['geo']['latitude'],
					'lon' => $membership_info['geo']['longitude'],
				);
			}//END if
		}//END else

		return update_user_meta( $user->ID, go_mailchimp()->meta_key( 'subscriber_info' ), $saved_subscriber_info );
	}//END sync_subscriber_info

	/**
	 * get a list of campaigns and their details matching the specified
	 * filters. see http://apidocs.mailchimp.com/api/2.0/campaigns/list.php
	 *
	 * @param array $filters criteria to match the campaigns with
	 * @param int $offset first campaign to retrieve
	 * @param int $limit maxiumum number of campaigns to retrieve
	 * @param string $sort_field optional what field to sort the campaigns on
	 * @param string $sort_dir sorting direction, DESC or ASC
	 * @return array list of campaign info matching $filters
	 */
	public function get_campaigns_list( $filters, $offset = 0, $limit = 25, $sort_field = 'create_time', $sort_dir = 'DESC' )
	{
		try
		{
			return $this->mc->campaigns->getList( $filters, $offset, $limit, $sort_field, $sort_dir );
		}
		catch ( Exception $e )
		{
			apply_filters( 'go_slog', 'go-mailchimp', __FUNCTION__ . ': An Exception was thrown: ' . $e->getMessage() );
			return FALSE;
		}//END catch
	}//END get_campaigns_list

	/**
	 * delete a MailChimp campaign by Id
	 *
	 * @param string $cid Id of the campaign to delete
	 * @return TRUE if the deletion succeeded, FALSE if not
	 */
	public function delete_campaign( $cid )
	{
		try
		{
			return $this->mc->campaigns->delete( $cid );
		}
		catch ( Exception $e )
		{
			apply_filters( 'go_slog', 'go-mailchimp', __FUNCTION__ . ': An Exception was thrown: ' . $e->getMessage() );
			return FALSE;
		}//END catch
	}//END delete_campaign

	/**
	 * get the open and click history of MC members on a specific campaign
	 * see http://apidocs.mailchimp.com/api/2.0/reports/member-activity.php
	 *
	 * @param string $lid Id of the list the subscriber is on
	 * @param array $emails list of emails to get activitity for. the
	 *  maximum number we can handle is 50.
	 * @return array a list containing member activity and associated metadata
	 */
	public function member_activity( $lid, $emails )
	{
		if ( is_array( $emails ) && 50 < count( $emails ) )
		{
			apply_filters( 'go_slog', 'go-mailchimp', __FUNCTION__ . ': Can only get member activity for up to 50 emails at a time.' );
			return FALSE;
		}

		if ( ! is_array( $emails ) )
		{
			$ids = array( array( 'email' => $emails ) );
		}
		else
		{
			$ids = array();
			foreach ( $emails as $email )
			{
				$ids[] = array( 'email' => $email );
			}
		}//END else

		try
		{
			return $this->mc->lists->memberActivity( $lid, $ids );
		}
		catch ( Exception $e )
		{
			apply_filters( 'go_slog', 'go-mailchimp', __FUNCTION__ . ': An Exception was thrown: ' . $e->getMessage() );
			return FALSE;
		}//END catch
	}//END member_activity
}//END class