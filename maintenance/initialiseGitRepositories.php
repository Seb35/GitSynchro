<?php

namespace MediaWiki\Extension\GitSynchro\Maintenance;

use MediaWiki\Config\ServiceOptions;
use MediaWiki\Extension\GitSynchro\GitSynchro;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\Page\PageStore;
use Wikimedia\Rdbms\Expression;
use MediaWiki\Maintenance\Maintenance;

require_once getenv( 'MW_INSTALL_PATH' ) !== false
	? getenv( 'MW_INSTALL_PATH' ) . '/maintenance/Maintenance.php'
	: __DIR__ . '/../../../maintenance/Maintenance.php';

/**
 * Maintenance script to initialise Git repositories
 *
 * @ingroup Maintenance
 */
class InitialiseGitRepositories extends Maintenance {

	public function __construct() {
		parent::__construct();

		$this->addDescription( 'Initialise Git repositories.' );
		$this->requireExtension( 'GitSynchro' );
		$this->setBatchSize( 500 );
	}

	public function execute() {

		$pageStore = $this->getServiceContainer()->getPageStore();
		$gitSynchro = new GitSynchro(
			new ServiceOptions(
				GitSynchro::CONSTRUCTOR_OPTIONS,
				$this->getConfig()
			),
			LoggerFactory::getInstance( 'gitsynchro' ),
			$this->getServiceContainer()->getRevisionLookup(),
			$this->getServiceContainer()->getShellCommandFactory()
		);

		$pageId = -1;
		$oldPageid = -1;
		$batchId = 1;
		do {
			$this->output( "Batch $batchId...\n" );
			$oldPageId = $pageId;
			$conds = [
				new Expression( 'page_id', '>', $pageId ),
			];

			$res = $pageStore->newSelectQueryBuilder()
				->where( $conds )
				->caller( __METHOD__ )
				->orderBy( 'page_title' )
				->limit( $this->getBatchSize() )
				->useIndex( 'page_name_title' )
				->fetchPageRecords();

			foreach( $res as $page ) {
				$gitSynchro->createGitDirectory( $page );
				$gitSynchro->populateGitDirectory( $page );
			}
			$pageId = $page->getId();
			$batchId++;
		} while( $oldPageId !== $pageId );
	}
}

$maintClass = InitialiseGitRepositories::class;
require_once RUN_MAINTENANCE_IF_MAIN;
