<?php
/**
 * class to map MailChimp subscriber merge tags to WP user meta or other
 * user data.
 */
class GO_Mailchimp_Map
{
	/**
	 * get a user meta or profile field that's mapped to a Mailchimp merge
	 * tag in our config file, or a group of fields (if $field is 'GROUPINGS')
	 *
	 * @param mixed $user user id or WP_User object
	 * @param object a mailing list object
	 * @param string field the merge tag field name to map
	 * @return 
	 */
	public function map( $user, $list, $field )
	{
		$user = is_object( $user ) ? $user : ( get_userdata( $user ) );

		if ( ! ( $config = $this->get_field_config( $field, $list['id'] ) ) )
		{
			return '';
		}

		if ( 'GROUPINGS' == $field )
		{
			return $this->map_groupings( $user, $config );
		}

		return go_syncuser_map()->map_field( $user, $config );
	}//END map

	/**
	 * maps list groupings to a collection of group functions specified in the config
	 *
	 * @param $user WP_User User being synchronized
	 * @param $config array Configuration for a "GROUPINGS" field
	 */
	public function map_groupings( $user, $config )
	{
		$groups = array();

		foreach ( $config as $group )
		{
			$field_value = go_syncuser_map()->map_field( $user, $group );

			$groups[] = array(
				'name'   => $group['name'],
				'groups' => $field_value,
			);
		}//END foreach

		return $groups;
	}//END map_groupings

	/**
	 * use the field config mapping to lookup a meta (ex. if MERGE1 maps to
	 * user_registered, look that up)
	 *
	 * @param string $field the field name. this is always uppercase
	 * @param string $list_id id of the email list to use
	 * @return the field mapping config if set, or NULL if not
	 */
	public function get_field_config( $field, $list_id )
	{
		$config = go_mailchimp()->config();

		$field_config = NULL;

		if ( isset( $config['lists'][ $list_id ]['field_map'][ strtoupper( $field ) ] ) )
		{
			$field_config = $config['lists'][ $list_id ]['field_map'][ strtoupper( $field ) ];
		}

		return $field_config;
	}//END get_field_config
}//END class
