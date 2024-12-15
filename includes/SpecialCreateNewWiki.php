<?php

namespace WikiOasis\WikiOasisMagic;

use ErrorPageError;
use MediaWiki\Html\Html;
use MediaWiki\Message\Message;
use MediaWiki\SpecialPage\FormSpecialPage;
use Miraheze\CreateWiki\ConfigNames;
use Miraheze\CreateWiki\Services\CreateWikiDatabaseUtils;
use Miraheze\CreateWiki\Services\WikiManagerFactory;
use OOUI;

class SpecialCreateNewWiki extends FormSpecialPage {

	private CreateWikiDatabaseUtils $databaseUtils;
	private WikiManagerFactory $wikiManagerFactory;

	public function __construct(
		CreateWikiDatabaseUtils $databaseUtils,
		WikiManagerFactory $wikiManagerFactory
	) {
		parent::__construct( 'CreateNewWiki', 'createnewwiki' );

		$this->databaseUtils = $databaseUtils;
		$this->wikiManagerFactory = $wikiManagerFactory;
	}

	/**
	 * @param ?string $par
	 */
	public function execute( $par ): void {
		if ( !$this->databaseUtils->isCurrentWikiCentral() ) {
			throw new ErrorPageError( 'errorpagetitle', 'createwiki-wikinotcentralwiki' );
		}

		if ( !$this->getUser()->isEmailConfirmed() ) {
			throw new ErrorPageError( 'requestwiki', 'requestwiki-error-emailnotconfirmed' );
		}

		parent::execute( $par );
	}

	/**
	 * @inheritDoc
	 */
	protected function getFormFields(): array {
		$formDescriptor = [
			'dbname' => [
				#'label-message' => 'createnewwiki-label-subdomain',
				'label' => "." . $this->getConfig()->get( 'CreateWikiSubdomain' ),
				'cssclass' => 'subdomain',
				'placeholder-message' => 'createnewwiki-placeholder-subdomain',
				'type' => 'text',
				'required' => true,
				'validation-callback' => [ $this, 'isValidDatabase' ],
			],
			'sitename' => [
				'label-message' => 'createwiki-label-sitename',
				'placeholder-message' => 'createnewwiki-placeholder-sitename',
				'type' => 'text',
				'size' => 20,
			],
			'language' => [
				'type' => 'language',
				'label-message' => 'createwiki-label-language',
				'default' => 'en',
			],
		];

		if ( $this->getConfig()->get( ConfigNames::UsePrivateWikis ) ) {
			$formDescriptor['private'] = [
				'type' => 'check',
				'label-message' => 'createwiki-label-private',
			];
		}

		if ( $this->getConfig()->get( ConfigNames::Categories ) ) {
			$formDescriptor['category'] = [
				'type' => 'select',
				'label-message' => 'createwiki-label-category',
				'options' => $this->getConfig()->get( ConfigNames::Categories ),
				'default' => 'uncategorised',
			];
		}

		$formDescriptor['reason'] = [
			'type' => 'textarea',
			'rows' => 10,
			'label-message' => 'createwiki-label-reason',
			'help-message' => 'createwiki-help-reason',
			'required' => true,
			'useeditfont' => true,
		];

		return $formDescriptor;
	}

	/**
	 * @inheritDoc
	 */
	public function onSubmit( array $formData ): bool {
		if ( $this->getConfig()->get( ConfigNames::UsePrivateWikis ) ) {
			$private = $formData['private'];
		} else {
			$private = 0;
		}

		if ( $this->getConfig()->get( ConfigNames::Categories ) ) {
			$category = $formData['category'];
		} else {
			$category = 'uncategorised';
		}

		$wikiManager = $this->wikiManagerFactory->newInstance( $formData['dbname'] . 'wiki' );
		$wikiManager->create(
			sitename: $formData['sitename'],
			language: $formData['language'],
			private: $private,
			category: $category,
			requester: $this->getContext()->getUser()->getName(),
			actor: $this->getContext()->getUser()->getName(),
			reason: $formData['reason'],
			extra: []
		);

		$this->getOutput()->redirect( "http://" . $formData['dbname'] . "." . $this->getConfig()->get( 'CreateWikiSubdomain' ) ); 

		return true;
	}

	public function isValidDatabase( ?string $dbname ): bool|string|Message {
		if ( !$dbname || ctype_space( $dbname ) ) {
			return $this->msg( 'htmlform-required' );
		}

		$wikiManager = $this->wikiManagerFactory->newInstance( $dbname . 'wiki' );
		$check = $wikiManager->checkDatabaseName( $dbname . 'wiki', forRename: false );

		if ( $check ) {
			// Will return a string — the error it received
			return $check;
		}

		return true;
	}

	/**
	 * @inheritDoc
	 */
	protected function getDisplayFormat(): string {
		return 'ooui';
	}

	/**
	 * @inheritDoc
	 */
	protected function getGroupName(): string {
		return 'wikimanage';
	}

	/**
	 * @inheritDoc
	 */
	protected function preHtml(): string {
		return $this->msg( 'createnewwiki-description' ) . "
			<style>
				.subdomain .oo-ui-fieldLayout-body {
					display: flex;
					flex-direction: row-reverse;
					justify-content: flex-end;
				}
				.subdomain .oo-ui-fieldLayout-field {
					flex: 1;
					max-width: 50em;
					margin-right: 1rem;
				}
				.oo-ui-fieldLayout-body .oo-ui-iconElement-icon {
					margin-right: .25rem;
				}
			</style>
		";
	}
}
