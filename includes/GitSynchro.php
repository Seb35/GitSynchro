<?php

/**
 * GitSynchro
 * @author Sébastien Beyou <seb35@seb35.fr>
 * @license LGPL-2.0+
 */

namespace MediaWiki\Extension\GitSynchro;

use MediaWiki\Config\ServiceOptions;
use MediaWiki\Content\TextContent;
use MediaWiki\MainConfigNames;
use MediaWiki\Revision\RevisionLookup;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\Shell\CommandFactory;
use MediaWiki\Title\Title;
use Shellbox\Shellbox;
use Shellbox\ShellboxError;
use Wikimedia\ScopedCallback;

class GitSynchro {

	public const CONSTRUCTOR_OPTIONS = [
		MainConfigNames::Server,
		MainConfigNames::TmpDirectory,
		'GitSynchroBaseGitDir',
		'GitSynchroMode',
	];

	const ONE_GIT_PER_PAGE = 'one-git-per-page';
	const ONE_GLOBAL_GIT = 'one-global-git';

	private ServiceOptions $config;
	private RevisionLookup $revisionLookup;
	private CommandFactory $commandFactory;

	private string $baseGitDir;
	private string $mode;

	public function __construct(
		ServiceOptions $config,
		RevisionLookup $revisionLookup,
		CommandFactory $commandFactory
	) {
		$this->config = $config;
		$this->revisionLookup = $revisionLookup;
		$this->commandFactory = $commandFactory;

		$baseGitDir = $config->get( 'GitSynchroBaseGitDir' );
		if( is_string( $baseGitDir ) ) {
			$this->baseGitDir = $baseGitDir;
		} else {
			throw new \LogicException();
		}

		$mode = $config->get( 'GitSynchroMode' );
		if( $mode === self::ONE_GIT_PER_PAGE || $mode === self::ONE_GLOBAL_GIT ) {
			$this->mode = $mode;
		} else {
			throw new \LogicException();
		}
	}

	public function initBaseDir() {

		if( !is_dir( $this->baseGitDir ) ) {
			mkdir( $this->baseGitDir, 0750, true );
		}
	}

	public function createGitDirectory( Title $title ) {

		$retval = null;
		$gitDir = $this->baseGitDir . DIRECTORY_SEPARATOR . $title->getPrefixedDBkey();

		$this->initBaseDir();

		if( is_dir( $this->baseGitDir . DIRECTORY_SEPARATOR . $title->getPrefixedDBkey() ) ) {
			
			$this->executeCommand( [ 'git', '--git-dir=' . $gitDir, 'branch' ], $retval );
			if( !$retval ) {
				return;
			}
			$this->executeCommand( [ 'rm', '-rf', $gitDir ] );
		}
		
		mkdir( $this->gitDir, true );
		$this->executeCommand( [ 'git', '--git-dir=' . $gitDir, 'init', '--bare' ] );
	}

	public function populateGitDirectory( Title $title ) {

		$retval = null;
		$gitDir = $this->baseGitDir . DIRECTORY_SEPARATOR . $title->getPrefixedDBkey();

		# Collect revisions
		$revisions = [];
		$lastRev = $this->revisionLookup->getRevisionByTitle( $title );

		# Check if an 
		$lastRecordedRevId = $this->executeCommand( [ 'git', '--git-dir=' . $gitDir, 'config', 'mediawiki.revid' ] );
		while( $lastRev && $lastRev->getId() != $lastRecordedRevId ) {

			$revisions[] = $lastRev;
			$lastRev = $this->revisionLookup->getPreviousRevision( $lastRev );
		}
		wfDebugLog( 'gitsynchro', 'new revisions = ' . count( $revisions ) );
		if( count( $revisions ) == 0 ) {
			return true;
		}

		# Write Git commits
		$tempDir = $this->createTempDir();
		if( $tempDir === null ) {
			throw new \RuntimeException();
		}
		$tempDirGit = $tempDir . DIRECTORY_SEPARATOR . '.git';
		$teardown = new ScopedCallback( function () use ( $tempDir ) {
			wfRecursiveRemoveDir( $tempDir );
		} );

		$this->executeCommand( [ 'git', 'clone', '--quiet', '--reference', $gitDir, $gitDir, $tempDir ] );
		foreach( array_reverse( $revisions ) as $revision ) {

			$content = $revision->getContent( SlotRecord::MAIN );
			if( ! $content instanceof TextContent ) {
				$text = wfMessage( 'gitsynchro-no-text-content-type' )->inContentLanguage()->text();
			} else {
				$text = $content->getText();
			}

			$comment = $revision->getComment() ? $revision->getComment()->text : wfMessage( 'gitsynchro-no-comment' )->inContentLanguage()->text();
			$user = $revision->getUser();
			$user = $user ? $user->getName() : '';
			$email = $user . '@' . preg_replace( '/^(https?:)?\/\//', '', $this->config->get( 'Server' ) );
			$timestamp = wfTimestamp( TS_ISO_8601, $revision->getTimestamp() );
			$commitMsg = $comment;
			#$commitMsg = $comment . "\n\nID: " . $revision->getId() . "\nSHA1: " . $revision->getSha1();
			wfDebugLog( 'gitsynchro', 'add revision = ' . $revision->getId() );

			if( $text ) {
				file_put_contents( $tempDir . DIRECTORY_SEPARATOR . $title->getPrefixedText(), $text );
				$this->executeCommand( [ 'git', '--work-tree=' . $tempDir, '--git-dir=' . $tempDirGit, 'add', $title->getPrefixedText() ] );
			} else {
				$this->executeCommand( [ 'git', '--work-tree=' . $tempDir, '--git-dir=' . $tempDirGit, 'rm', '--force', $title->getPrefixedText() ] );
			}
			file_put_contents( $tempDirGit . DIRECTORY_SEPARATOR . 'COMMIT_EDITMSG', $commitMsg );

			$this->executeCommand(
				[ 'git', '--git-dir=' . $tempDirGit, 'commit', '--file=' . $tempDirGit . DIRECTORY_SEPARATOR . 'COMMIT_EDITMSG', '--allow-empty-message', '--allow-empty' ],
				$retval,
				[
					'GIT_AUTHOR_NAME' => $user,
					'GIT_COMMITTER_NAME' => $user,
					'GIT_AUTHOR_EMAIL' => $email,
					'GIT_COMMITTER_EMAIL' => $email,
					'GIT_AUTHOR_DATE' => $timestamp,
					'GIT_COMMITTER_DATE' => $timestamp
				]
			);
		}
		wfDebugLog( 'gitsynchro', 'last added revid = '.$revision->getId() );
		$this->executeCommand( [ 'git', '--git-dir=' . $tempDirGit, 'push', '--quiet', 'origin', 'master' ] );
		$this->executeCommand( [ 'git', '--git-dir=' . $gitDir, 'config', 'mediawiki.revid', $revision->getId() ] );

		return true;
	}

	protected function executeCommand( array $cmd, &$retval = null, $environ = [] ) {

		$cmd = Shellbox::escape( $cmd );
		$profileMethod = wfGetCaller();

		try {
			$result = $this->commandFactory
				->create()
				->unsafeParams( $cmd )
				->environment( $environ )
				->profileMethod( $profileMethod )
				->execute();
		} catch( ShellboxError $e ) {
			$retval = -1;
			return '';
		}

		$retval = $result->getExitCode();
		return $result->getStdout();
	}

	/**
	 * Create a temporary directory
	 *
	 * @return string|null Path of the temporary directory or null in case of error
	 */
	protected function createTempDir() {

		$attempts = 5;
		while( $attempts-- ) {
			$fuzz = md5( (string)mt_rand() );
			$path = $this->config->get( MainConfigNames::TmpDirectory ) . DIRECTORY_SEPARATOR . 'GitSynchro-' . $fuzz;
			if( wfMkdirParents( $path, null, __METHOD__ ) ) {
				return $path;
			}
		}

		return null;
	}
}
