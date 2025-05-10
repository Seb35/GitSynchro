<?php

/**
 * GitSynchro
 * @author Sébastien Beyou <seb35@seb35.fr>
 * @license LGPL-2.0+
 */

use MediaWiki\MediaWikiServices;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\Title\Title;

class GitSynchro {

	static function onArticlePurge( &$article ) {

		global $wgGitSynchroBaseGitDir;

		wfDebugLog( 'gitsynchro', $wgGitSynchroBaseGitDir );
		self::initBaseDir();

		self::createGitDirectory( $article->getTitle() );

		self::populateGitDirectory( $article->getTitle() );
	}

	static function onPageSaveComplete( $wikiPage, $user, $summary, $flags, $revisionRecord, $editResult ) {

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
		$retval = null;

		self::initBaseDir();

		if( is_dir( $wgGitSynchroBaseGitDir . DIRECTORY_SEPARATOR . $title->getPrefixedDBkey() ) ) {
			
			wfShellExec( [ 'git', '--git-dir=' . $wgGitSynchroBaseGitDir . DIRECTORY_SEPARATOR . $title->getPrefixedDBkey(), 'branch' ], $retval );
			if( !$retval ) return;
			wfShellExec( [ 'rm', '-rf', $wgGitSynchroBaseGitDir . DIRECTORY_SEPARATOR . $title->getPrefixedDBkey() ] );
		}
		
		mkdir( $wgGitSynchroBaseGitDir . DIRECTORY_SEPARATOR . $title->getPrefixedDBkey(), 0777, true );
		wfShellExec( [ 'git', '--git-dir=' . $wgGitSynchroBaseGitDir . DIRECTORY_SEPARATOR . $title->getPrefixedDBkey(), 'init', '--bare' ] );
	}

	static private function populateGitDirectory( Title $title ) {

		global $wgGitSynchroBaseGitDir, $wgServer;

		$services = MediaWikiServices::getInstance();
		$revisionLookup = $services->getRevisionLookup();

		$retval = null;

		# Collect revisions
		$revisions = [];
		$lastRev = $revisionLookup->getRevisionByTitle( $title );

		# Check if an 
		$lastRecordedRevId = wfShellExec( [ 'git', '--git-dir=' . $wgGitSynchroBaseGitDir . DIRECTORY_SEPARATOR . $title->getPrefixedDBkey(), 'config', 'mediawiki.revid' ] );
		while( $lastRev && $lastRev->getId() != $lastRecordedRevId ) {

			$revisions[] = $lastRev;
			$lastRev = $revisionLookup->getPreviousRevision( $lastRev );
		}
		wfDebugLog( 'gitsynchro', 'new revisions = '.count( $revisions ) );
		if( count( $revisions ) == 0 ) {
			return true;
		}

		# Write Git commits
		mkdir( '/tmp/igIlsH5h', 0777 );
		wfShellExec( [ 'git', 'clone', '--quiet', $wgGitSynchroBaseGitDir . DIRECTORY_SEPARATOR . $title->getPrefixedDBkey(), '/tmp/igIlsH5h' ] );
		foreach( array_reverse( $revisions ) as $revision ) {

			$content = $revision->getContent( SlotRecord::MAIN );
			if( ! $content instanceof TextContent )
				$text = wfMessage( 'gitsynchro-no-text-content-type' )->inContentLanguage()->text();
			else
				$text = $content->getNativeData();
			
			$comment = $revision->getComment() ? $revision->getComment()->text : wfMessage( 'gitsynchro-no-comment' )->inContentLanguage()->text();
			$user = $revision->getUser();
			$user = $user ? $user->getName() : '';
			$email = $user . '@' . preg_replace( '/^(https?:)?\/\//', '', $wgServer );
			$timestamp = wfTimestamp( TS_ISO_8601, $revision->getTimestamp() );
			$commitMsg = $comment;
			#$commitMsg = $comment . "\n\nID: " . $revision->getId() . "\nSHA1: " . $revision->getSha1();
			wfDebugLog( 'gitsynchro', 'add revision = '.$revision->getId() );

			if( $text ) {
				file_put_contents( '/tmp/igIlsH5h/' . $title->getPrefixedText(), $text );
				wfShellExec( [ 'git', '--work-tree=/tmp/igIlsH5h', '--git-dir=/tmp/igIlsH5h/.git', 'add', $title->getPrefixedText() ] );
			} else {
				wfShellExec( [ 'git', '--work-tree=/tmp/igIlsH5h', '--git-dir=/tmp/igIlsH5h/.git', 'rm', $title->getPrefixedText() ] );
			}
			file_put_contents( '/tmp/igIlsH5h/.git/COMMIT_EDITMSG', $commitMsg );

			wfShellExec( [ 'git', '--git-dir=/tmp/igIlsH5h/.git', 'commit', '--file=/tmp/igIlsH5h/.git/COMMIT_EDITMSG', '--allow-empty-message', '--allow-empty' ], $retval, [ 'GIT_AUTHOR_NAME' => $user, 'GIT_COMMITTER_NAME' => $user, 'GIT_AUTHOR_EMAIL' => $email, 'GIT_COMMITTER_EMAIL' => $email, 'GIT_AUTHOR_DATE' => $timestamp, 'GIT_COMMITTER_DATE' => $timestamp ] );
		}
		wfDebugLog( 'gitsynchro', 'last added revid = '.$revision->getId() );
		wfShellExec( [ 'git', '--git-dir=/tmp/igIlsH5h/.git', 'gc' ], $retval, [], [ 'memory' => 614400 ] );
		wfShellExec( [ 'git', '--git-dir=/tmp/igIlsH5h/.git', 'push', '--quiet', 'origin', 'master' ] );
		wfShellExec( [ 'git', '--git-dir=' . $wgGitSynchroBaseGitDir . DIRECTORY_SEPARATOR . $title->getPrefixedDBkey(), 'config', 'mediawiki.revid', $revision->getId() ] );
		wfShellExec( [ 'rm', '-rf', '/tmp/igIlsH5h' ] );
		return true;
	}
}
