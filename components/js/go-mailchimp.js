jQuery(function($) {

	// function to run when the user clicks on the "Sync to MailChimp" push
	// button on a user's admin dashboard page
	$( '.go-mailchimp .sync' ).click(function( e ) {
		"use strict";
		e.preventDefault();

		var $el       = $(this);
		var $parent   = $el.closest('.go-mailchimp');
		var $feedback = $parent.find('.feedback');
		var $results  = $parent.find('.results');

		$feedback.html( 'Synchronizing...' );

		var data = {
			action: 'go_mailchimp_user_sync',
			go_mailchimp_user_sync_user: $( '.go-mailchimp .user' ).val()
		};

		$.post(ajaxurl, data, function(response) {
			$results.html( response );
			$feedback.html( 'Synchronization complete.' );
		});
	});//END submit function
});
