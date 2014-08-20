<div class="wrap go-mailchimp" id="go-mailchimp-on-demand-sync">
	<h3>MailChimp Synchronization</h3>
	<div class="results">
		<?php echo $user_status; ?>
	</div>
	<button class="go-mailchimp-sync button-secondary sync" id="go-mailchimp-user-sync-btn" value="go_mailchimp_user_sync">Sync to MailChimp</button>
	<input type="hidden" class="user" id="go-mailchimp-user-sync-user" name="go_mailchimp_user_sync_user" value="<?php echo $user->ID; ?>" />
	<input type="hidden" class="nonce" id="go-mailchimp-user-sync-user-nonce" name="go_mailchimp_nonce" value="<?php echo $go_mailchimp_nonce; ?>" />
	<span class="feedback"></span>
</div>
