<?php

namespace MediaWiki\Extension\RawCSS;

use MediaWiki\Config\Config;
use MediaWiki\Config\ConfigFactory;
use MediaWiki\Extension\RawCSS\Utilities\PreloadLinkGenerator;
use MediaWiki\Hook\ParserFirstCallInitHook;
use MediaWiki\Linker\LinkTargetLookup;
use MediaWiki\Output\Hook\OutputPageParserOutputHook;
use MediaWiki\Output\OutputPage;
use MediaWiki\Page\WikiPageFactory;
use MediaWiki\Parser\Parser;
use MediaWiki\Parser\ParserOutput;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Storage\EditResult;
use MediaWiki\Storage\Hook\PageSaveCompleteHook;
use MediaWiki\Title\TitleValue;
use MediaWiki\User\User;
use Wikimedia\Rdbms\IConnectionProvider;
use WikiPage;

class Hooks implements ParserFirstCallInitHook, OutputPageParserOutputHook, PageSaveCompleteHook {
	private Config $mainConfig;
	private Config $extensionConfig;
	private RawCSS $rawCss;
	private IConnectionProvider $databaseProvider;
	private LinkTargetLookup $linkTargetLookup;
	private WikiPageFactory $wikiPageFactory;

	public function __construct(
		ConfigFactory $configFactory,
		IConnectionProvider $databaseProvider,
		LinkTargetLookup $linkTargetLookup,
		WikiPageFactory $wikiPageFactory
	) {
		$this->mainConfig = $configFactory->makeConfig( 'main' );
		$this->extensionConfig = $configFactory->makeConfig( 'rawcss' );
		$this->rawCss = new RawCSS( $this->mainConfig, $this->extensionConfig, $wikiPageFactory );
		$this->databaseProvider = $databaseProvider;
		$this->linkTargetLookup = $linkTargetLookup;
		$this->wikiPageFactory = $wikiPageFactory;
	}

	/**
	 * Registers the function hook for the 'rawcss' parser function
	 * @param Parser $parser
	 * @return void
	 */
	public function onParserFirstCallInit( $parser ): void {
		$parser->setFunctionHook(
			'rawcss',
			[ new RawCSS( $this->mainConfig, $this->extensionConfig, $this->wikiPageFactory ), 'onFunctionHook' ],
			Parser::SFH_OBJECT_ARGS
		);
	}

	/**
	 * Appends the stylesheets and preload link header from the parser data
	 * @param OutputPage $outputPage
	 * @param ParserOutput $parserOutput
	 * @return void
	 */
	public function onOutputPageParserOutput( $outputPage, $parserOutput ): void {
		// add inline styles for each compiled style sheet
		foreach ( ( $parserOutput->getExtensionData( RawCSS::STYLE_SHEETS_DATA_KEY ) ?: [] ) as $styleSheet ) {
			$outputPage->addInlineStyle( $styleSheet );
		}

		// get everything requested to be preloaded
		$preloadData = $parserOutput->getExtensionData( RawCSS::PRELOAD_DATA_KEY );
		// make sure there is something to be preloaded
		if ( !empty( $preloadData ) ) {
			// make the Link directives
			$preloadLinks = array_map( [ PreloadLinkGenerator::class, 'generatePreloadLink' ], $preloadData );
			// add them
			foreach ( $preloadLinks as $preloadLink ) {
				$outputPage->addLinkHeader( $preloadLink );
			}
		}
	}

	/**
	 * Updates the template file on page edit and (optionally) purges the pages which link to the RawCSS style sheet
	 * @param WikiPage $wikiPage
	 * @param User $user
	 * @param string $summary
	 * @param int $flags
	 * @param RevisionRecord $revisionRecord
	 * @param EditResult $editResult
	 * @return void
	 */
	public function onPageSaveComplete( $wikiPage, $user, $summary, $flags, $revisionRecord, $editResult ): void {
		// make sure this is a RawCSS style sheet
		if ( $wikiPage->getNamespace() == NS_RAWCSS ) {
			$this->rawCss->templateEngine->writeLatteTemplate( $wikiPage );

			if ( $this->extensionConfig->get( 'RawCSSPurgeOnStyleSheetEdit' ) ) {
				// select backlinks of the style sheet (where the style sheet is linked)
				$replicaDatabase = $this->databaseProvider->getReplicaDatabase();
				$linkTargetId = $this->linkTargetLookup->getLinkTargetId( TitleValue::newFromPage( $wikiPage ) );
				$linkingPageIds = $replicaDatabase->newSelectQueryBuilder()
					->select( 'tl_from' )
					->from( 'templatelinks' )
					->where( [ 'tl_target_id' => $linkTargetId ] )
					->caller( __METHOD__ )
					->fetchFieldValues();
				foreach ( $linkingPageIds as $linkingPageId ) {
					// get the page linking to the style sheet
					$linkingPage = $this->wikiPageFactory->newFromID( $linkingPageId );
					// purge to forcibly refresh
					$linkingPage->doPurge();
				}
			}
		}
	}
}
