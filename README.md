go-mailchimp
============

Features for synchronizing WP data with MailChimp

This plugin depends on [go-syncuser](https://github.com/GigaOM/go-syncuser) for the "go_syncuser_user" action callback and for the mapping functions in GO_Sync_User_Map.

Our config file expects the MailChimp list name and id to be defined by the GO_MAILCHIMP_LIST_ID and GO_MAILCHIMP_LIST_NAME constants respectively, or else they would default to the test subscribers list. The MailChimp API key is expected to be defined in our config file.

