<?php
/**
 * base class for GO_Mailchimp_Test and related test classes
 */
abstract class GO_Mailchimp_Test_Abstract extends WP_UnitTestCase
{
	/**
	 * this is run before each test* function in this class, to set
	 * up the environment each test runs in.
	 */
	public function setUp()
	{
		parent::setUp();
		$this->clear_caches();

		// because the go_mailchimp singleton caches its config data,
		// we need to destroy it before each test to make sure each test
		// gets a fresh config object
		global $go_mailchimp;
		$go_mailchimp = NULL;
	}//END setUp

	/**
	 * clean up stuff
	 */
	public function tearDown()
	{
		parent::tearDown();
		$this->clear_caches();
	}//END tearDown

	/**
	 * clear out caches so we don't leave any test artifacts behind
	 */
	public function clear_caches()
	{
		$this->flush_cache();

		// clear cache if enabled
		$save_handler = ini_get( 'session.save_handler' );
		$save_path = ini_get( 'session.save_path' );

		try
		{
			if ( ! $save_path )
			{
				$save_path = 'tcp://127.0.0.1:11211';
			}

			$memcache = new Memcache;

			$save_path = str_replace( 'tcp://', '', $save_path );
			$save_path = explode( ':', $save_path );

			$memcache->connect( $save_path[0], $save_path[1] );
			$memcache->flush();
		}
		catch( Exception $e )
		{
			var_dump( $e );
		}//END catch
	}//END clear_caches
}// END class