<?php
/**
 * GO_Mailchimp unit tests
 */

require_once dirname( __DIR__ ) . '/go-mailchimp.php';

class GO_Mailchimp_Test extends GO_Mailchimp_Test_Abstract
{
	/**
	 * set up our test environment
	 */
	public function setUp()
	{
		parent::setUp();
		remove_filter( 'go_config', array( go_config(), 'go_config_filter' ), 10, 2 );
		add_filter( 'go_config', array( $this, 'go_config_filter' ), 10, 2 );
	}//END setUp

	/**
	 * make sure we can get an instance of our plugin
	 */
	public function test_singleton()
	{
		$this->assertTrue( function_exists( 'go_mailchimp' ) );
		$this->assertTrue( is_object( go_mailchimp() ) );
	}//END test_singleton

	/**
	 * test the api functions
	 */
	public function test_api()
	{
		$list_id = 'a3e91a3095'; // id for the Test General Users list
		$email = 'zbtirrell+t_orig@gigaom.com';
		$new_email = 'zbtirrell+t_new@gigaom.com';

		$new_user_id = wp_insert_user( array( 'user_login' => 'user1', 'user_email' => $email ) );
		$user = get_user_by( 'email', $email );
		$this->assertFalse( is_wp_error( $user ) );

		$lists = go_mailchimp()->api()->lists( 'mc' );
		$this->assertGreaterThan( 0, count( $lists ) );

		$merge_vars = go_mailchimp()->api()->list_merge_vars( $list_id );
		$this->assertGreaterThan( 0, count( $merge_vars ) );

		$member = go_mailchimp()->api()->subscribe( $user, $list_id );
		$this->assertTrue( is_array( $member ) );
		$this->assertFalse( empty( $member['euid'] ) );

		$member = go_mailchimp()->api()->subscribed( $user, $list_id );
		$this->assertTrue( is_array( $member ) );
		$this->assertFalse( empty( $member['euid'] ) );

		$unsubscribed = go_mailchimp()->api()->unsubscribed( $user, $list_id );
		$this->assertTrue( empty( $unsubscribed ) );

		$member = go_mailchimp()->api()->member( $user, $list_id );
		$this->assertTrue( is_array( $member ) );
		$this->assertFalse( empty( $member['id'] ) );

		// update the email
		$result = go_mailchimp()->api()->update_email( $user, $new_email );
		$this->assertTrue( $result );

		// change it back. make sure the email address in the user is
		// consistent with what we just updated (to $new_email)
		$user->user_email = $new_email;
		$result = go_mailchimp()->api()->update_email( $user, $email );
		$this->assertTrue( $result );

		// make sure user's email is consistent with what was updated
		$user->user_email = $email;
		$result = go_mailchimp()->api()->unsubscribe( $user, $list_id );
		$this->assertTrue( $result['complete'] );
	}//END test_api

	/**
	 * return custom config data for our tests
	 */
	public function go_config_filter( $config, $which )
	{
		if ( 'go-mailchimp' == $which )
		{
			$config = array(
				'api_key' => '11d906093832805923d53aaf9f56a9de-us1',

				'lists' => array(
					'a3e91a3095' => array(
						'id' => 'a3e91a3095',
						'name' => 'Test General Users',
						'slug' => 'general_users',
						'webhook_secret'  => '1234zyxw',
						'field_map' => array(
							'EMAIL' => array(
								'function' => array( go_syncuser_map(), 'user_meta' ),
								'args' => array(
									'user_email',
								),
							),
						),
					),
				),
			);
		}//END if

		return $config;
	}//END go_config_filter
}// END class