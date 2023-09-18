( function () {
	// Force VE to reload instead of modifying dom after save, in order to pick up annotations.
	if ( ve && ve.init && ve.init.articleTarget && ve.init.articleTarget.teardownUnloadHandlers ) {
		ve.init.articleTarget.teardownUnloadHandlers();
		location.replace(location.href);
	}
} )()
