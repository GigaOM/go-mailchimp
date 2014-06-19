<?php

class GO_Mailchimp_Test
{
	// @TODO: rename the ajax hook and find a way to only activate this class when we need to do testing
	public function __construct()
	{
		add_action( 'wp_ajax_go_mailchimp_trigger', array( $this, 'trigger_ajax' ) );
		add_action( 'wp_ajax_go_mailchimp_api_test', array( $this, 'api_test_ajax' ) );
	}//end __construct

	public function go_mailchimp_trigger()
	{
		$args = func_get_args();
		echo '<h3>Found user info and configuration</h3><pre>'. print_r( $args, TRUE ) .'</pre>';
	}//END go_mailchimp_trigger

	public function trigger_ajax()
	{
		// permissions check
		if ( ! current_user_can( 'activate_plugins' ) )
		{
			die;
		}

		// track the result of these triggers
		add_action( 'go_mailchimp_trigger', array( $this, 'go_mailchimp_trigger' ), 10, 5 );

		// get the current user
		$user = wp_get_current_user();

		// uncomment the below if you want to confirm the processed value matches the source
		//echo '<pre>'. print_r( $user, TRUE ) .'</pre>';

		// display errors
		$old_reporting_level = error_reporting( E_ALL );
		$old_display_setting = ini_set( 'display_errors', TRUE );

		// do wp_login
		echo "<h2>do_action( 'wp_login', \$user->user_login, \$user )</h2>";
		do_action( 'wp_login', $user->user_login, $user );

		// do updated_user_meta
		echo "<h2>do_action( 'updated_user_meta', 0, \$user->ID, 'some_key', 'some_value' )</h2>";
		do_action( 'updated_user_meta', 0, $user->ID, 'some_key', 'some_value' );

		// reset error display
		ini_set( 'display_errors', $old_display_setting );
		error_reporting( $old_reporting_level );

		die;
	}//END trigger_ajax

	public function api_test_ajax()
	{
		// permissions check
		if ( ! current_user_can( 'activate_plugins' ) )
		{
			die;
		}

		error_reporting( E_ALL ^ E_STRICT );
		ini_set( 'display_errors', TRUE );

		go_mailchimp()->api()->debug = TRUE;

		echo '<h2>Starting!</h2>';
		echo '<hr/>';

		// NOTE: these tests would only work if these constants are valid
		// in your environment
		$list_id = 'a3e91a3095'; // id for the Test General Users list
		$email = 'zbtirrell+1234@gigaom.com';
		$new_email = 'zbtirrell+1234@gmail.com';

		$user = get_user_by( 'email', $email );

		echo '<h3>Testing list retrieval</h3>';
		$lists = go_mailchimp()->api()->lists( 'mc' );
		if ( 0 < count( $lists ) )
		{
			echo 'OK!';
		}
		else
		{
			echo 'error: ' . var_export( $lists, TRUE );
		}
		echo '<hr/>';

		echo '<h3>Testing list merge vars</h3>';
		$merge_vars = go_mailchimp()->api()->list_merge_vars( $list_id );
		if ( 0 < count( $merge_vars ) )
		{
			echo 'OK!';
		}
		else
		{
			echo 'error: ' . var_export( $merge_vars, TRUE );
		}
		echo '<hr/>';

		echo '<h3>Testing subscribe</h3>';
		$member = go_mailchimp()->api()->subscribe( $user, $list_id );
		if ( $member && isset( $member['euid'] ) )
		{
			echo 'OK!';
		}
		else
		{
			echo 'error: ' . var_export( $member, TRUE );
		}
		echo '<hr/>';

		echo '<h3>Testing subscribed check</h3>';
		$member = go_mailchimp()->api()->subscribed( $user, $list_id );
		if ( $member && isset( $member['euid'] ) )
		{
			echo 'OK!';
		}
		else
		{
			echo 'error: ' . var_export( $member, TRUE );
		}
		echo '<hr/>';

		echo '<h3>Testing unsubscribed check</h3>';
		$unsubscribed = go_mailchimp()->api()->unsubscribed( $user, $list_id );
		if ( ! $unsubscribed )
		{
			echo 'OK, subscribed!';
		}
		else
		{
			echo 'error: ' . var_export( $unsubscribed, TRUE );
		}
		echo '<hr/>';

		echo '<h3>Testing getting member details</h3>';
		$member = go_mailchimp()->api()->member( $user, $list_id );
		if ( $member && isset( $member['id'] ) )
		{
			echo 'OK!';
		}
		else
		{
			echo 'error: ' . var_export( $member, TRUE );
		}
		echo '<hr/>';

		echo '<h3>Testing update email</h3>';
		$test = go_mailchimp()->api()->update_email( $email, $new_email );
		if ( $test )
		{
			echo 'OK - changed!';
			$test = go_mailchimp()->api()->update_email( $user, $email );
			if ( $test )
			{
				echo '  OK, changed back!';
			}
			else
			{
				echo 'Error changing back';
			}
		}//end if
		else
		{
			echo 'Error changing email';
		}
		echo '<hr/>';

		echo '<h3>Testing unsubscribe</h3>';
		$test = go_mailchimp()->api()->unsubscribe( $user, $list_id );
		if ( $test && isset( $test['complete'] ) )
		{
			echo 'OK!';
		}
		else
		{
			echo 'error: ' . var_export( $member, TRUE );
		}
		echo '<hr/>';

		echo '<h3>Testing subscribed check</h3>';
		$member = go_mailchimp()->api()->subscribed( $user, $list_id );
		if ( ! $member )
		{
			echo 'OK, not subscribed!';
		}
		else
		{
			echo 'error: ' . var_export( $member, TRUE );
		}
		echo '<hr/>';

		echo '<h3>Testing unsubscribed check</h3>';
		$unsubscribed = go_mailchimp()->api()->unsubscribed( $user, $list_id );
		if ( $unsubscribed )
		{
			echo 'OK, not subscribed!';
		}
		else
		{
			echo 'error: ' . var_export( $unsubscribed, TRUE );
		}
		echo '<hr/>';

		echo '<h2>All Done!</h2>';

		die;
	}//END api_test_ajax
}//END class