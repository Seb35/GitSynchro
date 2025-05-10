<?php

namespace MediaWiki\Extension\GitSynchro;

use MediaWiki\Config\Config;
use MediaWiki\Config\ServiceOptions;
use MediaWiki\Page\Hook\ArticlePurgeHook;
use MediaWiki\Storage\Hook\PageSaveCompleteHook;
use MediaWiki\Page\WikiPage;
use MediaWiki\Revision\RevisionLookup;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Shell\CommandFactory;
use MediaWiki\Storage\EditResult;
use MediaWiki\User\UserIdentity;

class Hooks implements ArticlePurgeHook, PageSaveCompleteHook {

	private GitSynchro $gitSynchro;

	public function __construct(
		Config $config,
		RevisionLookup $revisionLookup,
		CommandFactory $commandFactory
	) {
		$this->gitSynchro = new GitSynchro(
			new ServiceOptions(
				GitSynchro::CONSTRUCTOR_OPTIONS,
				$config
			),
			$revisionLookup,
			$commandFactory
		);
	}

	/**
	 * This hook is called after an article has been updated.
	 *
	 * @note since MediaWiki 1.35
	 *
	 * @param WikiPage $wikiPage WikiPage modified
	 * @param UserIdentity $user User performing the modification
	 * @param string $summary Edit summary/comment
	 * @param int $flags Flags passed to WikiPage::doUserEditContent()
	 * @param RevisionRecord $revisionRecord New RevisionRecord of the article
	 * @param EditResult $editResult Object storing information about the effects of this edit,
	 *   including which edits were reverted and which edit is this based on (for reverts and null
	 *   edits).
	 * @return bool|void True or no return value to continue or false to stop other hook handlers
	 *    from being called; save cannot be aborted
	 */
	public function onPageSaveComplete(
		$wikiPage,
		$user,
		$summary,
		$flags,
		$revisionRecord,
		$editResult
	) {
		$this->gitSynchro->initBaseDir();
		$this->gitSynchro->createGitDirectory( $wikiPage->getTitle() );
		$this->gitSynchro->populateGitDirectory( $wikiPage->getTitle() );
		return true;
	}

	/**
	 * This hook is called before executing "&action=purge".
	 *
	 * @note since MediaWiki 1.35
	 *
	 * @param WikiPage $wikiPage WikiPage to purge
	 * @return bool|void True or no return value to continue or false to abort
	 */
	public function onArticlePurge( $wikiPage ) {
		$this->gitSynchro->initBaseDir();
		$this->gitSynchro->createGitDirectory( $wikiPage->getTitle() );
		$this->gitSynchro->populateGitDirectory( $wikiPage->getTitle() );
		return true;
	}
}
