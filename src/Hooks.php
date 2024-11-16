<?php

namespace MediaWiki\Extension\RawCSS;

use Content;
use MediaWiki\Config\Config;
use MediaWiki\Config\ConfigFactory;
use MediaWiki\Context\IContextSource;
use MediaWiki\Extension\RawCSS\Parser\LinkHeaderFunction;
use MediaWiki\Extension\RawCSS\Parser\RawCssFunction;
use MediaWiki\Hook\EditFilterMergedContentHook;
use MediaWiki\Hook\ParserFirstCallInitHook;
use MediaWiki\Linker\LinkTargetLookup;
use MediaWiki\Output\Hook\OutputPageParserOutputHook;
use MediaWiki\Output\OutputPage;
use MediaWiki\Page\WikiPageFactory;
use MediaWiki\Parser\Parser;
use MediaWiki\Parser\ParserOutput;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Status\Status;
use MediaWiki\Storage\EditResult;
use MediaWiki\Storage\Hook\PageSaveCompleteHook;
use MediaWiki\Title\TitleValue;
use MediaWiki\User\User;
use Throwable;
use Wikimedia\Rdbms\IConnectionProvider;
use WikiPage;

class Hooks implements
	ParserFirstCallInitHook,
	OutputPageParserOutputHook,
	EditFilterMergedContentHook,
	PageSaveCompleteHook
{
	private Config $mainConfig;
	private Config $extensionConfig;
	private RawCssFunction $rawCssFunction;
	private LinkHeaderFunction $linkHeaderFunction;
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
		$this->rawCssFunction = new RawCssFunction( $this->mainConfig, $this->extensionConfig );
		$this->linkHeaderFunction = new LinkHeaderFunction();
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
		$parser->setFunctionHook( 'rawcss',
			[ $this->rawCssFunction, 'onFunctionHook' ],
			Parser::SFH_OBJECT_ARGS
		);
		$parser->setFunctionHook( 'linkheader',
			[ $this->linkHeaderFunction, 'onFunctionHook' ],
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
		foreach ( ( $parserOutput->getExtensionData( RawCssFunction::DATA_KEY ) ?: [] ) as $styleSheet ) {
			$outputPage->addInlineStyle( $styleSheet );
		}

		// get all the Link headers
		foreach ( ( $parserOutput->getExtensionData( LinkHeaderFunction::DATA_KEY ) ?: [] ) as $linkHeader ) {
			$outputPage->addLinkHeader( $linkHeader );
		}
	}

	/**
	 * Filter the edits for RawCSS style sheets to make sure it can compile
	 * @param IContextSource $context
	 * @param Content $content
	 * @param Status $status
	 * @param string $summary
	 * @param User $user
	 * @param bool $minoredit
	 * @return mixed
	 */
	public function onEditFilterMergedContent( IContextSource $context, Content $content, Status $status,
											   $summary, $user, $minoredit ): bool {
		if ( $context->getTitle()->getNamespace() == NS_RAWCSS ) {
			try {
				$this->rawCssFunction->templateEngine->writeLatteTemplate( $context->getTitle(), null, $content );
			} catch ( Throwable $exception ) {
				$status->fatal( 'rawcss-style-sheet-rendering-failed', $exception->getMessage() );
				return false;
			}
		}
		return true;
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
			$this->rawCssFunction->templateEngine->writeLatteTemplate(
				$wikiPage->getTitle(), $wikiPage->getRevisionRecord(), $wikiPage->getContent()
			);

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
