<?php

class GitSynchro {

	static function onPageContentSaveComplete( $wikiPage, $user, $content, $summary, $isMinor, $isWatch, $section, $flags, $revision, $status, $baseRevId ) {

		global $wgGitSynchroBaseGitDir;

		wfDebugLog( 'gitsynchro', $wgGitSynchroBaseGitDir );
		self::initBaseDir();

		self::createGitDirectory( $wikiPage->getTitle() );

		self::populateGitDirectory( $wikiPage->getTitle() );
	}

	static private function initBaseDir() {

		global $wgGitSynchroBaseGitDir;

		if( !is_dir( $wgGitSynchroBaseGitDir ) )
			mkdir( $wgGitSynchroBaseGitDir, 0777, true );
	}

	static private function createGitDirectory( Title $title ) {

		global $wgGitSynchroBaseGitDir;

		self::initBaseDir();

		if( is_dir( $wgGitSynchroBaseGitDir . DIRECTORY_SEPARATOR . $title->getPrefixedDBkey() ) )
			wfShellExec( [ 'rm', $wgGitSynchroBaseGitDir . DIRECTORY_SEPARATOR . $title->getPrefixedDBkey() ] );

		wfShellExec( [ 'git', '--git-dir=' . $wgGitSynchroBaseGitDir . DIRECTORY_SEPARATOR . $title->getPrefixedDBkey(), 'init', '--bare' ] );
	}

	static private function populateGitDirectory( Title $title ) {

		global $wgGitSynchroBaseGitDir, $wgServer;

		$retval = null;

		# Collect revisions
		$revisions = [];
		$lastRev = Revision::newFromTitle( $title );

		while( $lastRev ) {

			$revisions[] = $lastRev;
			$lastRev = $lastRev->getPrevious();
		}

		# Write Git commits
		mkdir( '/tmp/igIlsH5h', 0777 );
		wfShellExec( [ 'git', 'clone', $wgGitSynchroBaseGitDir . DIRECTORY_SEPARATOR . $title->getPrefixedDBkey(), '/tmp/igIlsH5h' ] );
		foreach( array_reverse( $revisions ) as $revision ) {

			$content = $revision->getContent();
			if( ! $content instanceof TextContent )
				$text = wfMessage( 'gitsynchro-no-text-content-type' )->inContentLanguage()->text();
			else
				$text = $content->getNativeData();
			
			$comment = $revision->getComment() ? $revision->getComment() : wfMessage( 'gitsynchro-no-comment' )->inContentLanguage()->text();
			$user = $revision->getUserText();
			$email = $user . '@' . preg_replace( '/^(https?:)?\/\//', '', $wgServer );
			$timestamp = wfTimestamp( TS_ISO_8601, $revision->getTimestamp() );
			$commitMsg = $comment . "\n\nID: " . $revision->getId() . "\nSHA1: " . $revision->getSha1();

			file_put_contents( '/tmp/igIlsH5h/' . $title->getPrefixedText(), $text );
			file_put_contents( '/tmp/igIlsH5h/.git/COMMIT_EDITMSG', $commitMsg );

			wfShellExec( [ 'git', '--work-tree=/tmp/igIlsH5h', '--git-dir=/tmp/igIlsH5h/.git', 'add', $title->getPrefixedText() ] );
			wfShellExec( [ 'git', '--git-dir=/tmp/igIlsH5h/.git', 'commit', '--file=/tmp/igIlsH5h/.git/COMMIT_EDITMSG' ], $retval, [ 'GIT_AUTHOR_NAME' => $user, 'GIT_COMMITTER_NAME' => $user, 'GIT_AUTHOR_EMAIL' => $email, 'GIT_COMMITTER_EMAIL' => $email, 'GIT_AUTHOR_DATE' => $timestamp, 'GIT_COMMITTER_DATE' => $timestamp ] );
		}
		wfShellExec( [ 'git', '--git-dir=/tmp/igIlsH5h/.git', 'push', 'origin', 'master' ] );
		wfShellExec( [ 'rm', '-rf', '/tmp/igIlsH5h' ] );
	}
}
