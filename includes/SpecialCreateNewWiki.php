<?php

namespace WikiOasis\WikiOasisMagic;

use ErrorPageError;
use MediaWiki\Html\Html;
use MediaWiki\Message\Message;
use MediaWiki\SpecialPage\FormSpecialPage;
use Miraheze\CreateWiki\ConfigNames;
use Miraheze\CreateWiki\Services\CreateWikiDatabaseUtils;
use Miraheze\CreateWiki\Services\WikiManagerFactory;
use Miraheze\CreateWiki\Services\CreateWikiValidator;
use OOUI;
use MediaWiki\WikiMap\WikiMap;

class SpecialCreateNewWiki extends FormSpecialPage {

	private CreateWikiDatabaseUtils $databaseUtils;
	private WikiManagerFactory $wikiManagerFactory;
	private CreateWikiValidator $validator;

	public function __construct(
		CreateWikiDatabaseUtils $databaseUtils,
		WikiManagerFactory $wikiManagerFactory,
		CreateWikiValidator $validator,
	) {
		parent::__construct( 'CreateNewWiki', 'createnewwiki' );

		$this->databaseUtils = $databaseUtils;
		$this->wikiManagerFactory = $wikiManagerFactory;
		$this->validator = $validator;
	}

	/**
	 * @param ?string $par
	 */
	public function execute( $par ): void {
		if ( !$this->databaseUtils->isCurrentWikiCentral() ) {
			throw new ErrorPageError( 'errorpagetitle', 'createwiki-wikinotcentralwiki' );
		}

		if ( !$this->getUser()->isEmailConfirmed() ) {
			throw new ErrorPageError( 'createnewwiki', 'createnewwiki-emailnotconfirmed' );
		}

		parent::execute( $par );
	}

	/**
	 * @inheritDoc
	 */
	protected function getFormFields(): array {
                $this->getOutput()->enableOOUI();
                $this->getOutput()->addModuleStyles( [ 'oojs-ui.styles.icons-content' ] );
		$formDescriptor = [
			'dbname' => [
				'label-message' => 'createnewwiki-label-subdomain',
				'section' => 'placeholder-subdomain',
				#'label' => "." . $this->getConfig()->get( 'CreateWikiSubdomain' ),
				'cssclass' => 'subdomain',
				'placeholder-message' => 'createnewwiki-placeholder-subdomain',
				'type' => 'text',
				'required' => true,
			],
			'info' => [
                                'section' => 'placeholder-subdomain',
                                'type' => 'info',
                                'default' => "." . $this->getConfig()->get( 'CreateWikiSubdomain' ),
                                'raw' => true,
                        ],
			'sitename' => [
				'section' => 'createnewwiki-placeholder-sitename',
				'label-message' => 'createwiki-label-sitename',
				'placeholder-message' => 'createnewwiki-placeholder-sitename',
				'type' => 'text',
				'size' => 20,
			],
			'language' => [
				'section' => 'language',
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
                                'section' => 'category',
                                'type' => 'select',
                                'label-message' => 'createwiki-label-category',
                                'options' => $this->getConfig()->get( ConfigNames::Categories ),
                                'default' => 'uncategorised',
			];
			$formDescriptor['categoryIcon'] = [
                                'section' => 'category',
                                'type' => 'info',
                                'default' => new OOUI\IconWidget(
                                        [
                                                'icon' => 'folderPlaceholder',
                                                'title' => 'Folder Placeholder',
                                        ]
                                )
                        ];
		}

		$formDescriptor['reason'] = [
			'type' => 'textarea',
			'rows' => 10,
			'label-message' => 'createwiki-label-reason',
			'help-message' => 'createwiki-help-reason',
			'required' => true,
			'useeditfont' => true,
                        'validation-callback' => [ $this, 'isAcceptableDescription' ],
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
		$check = $this->validator->validateDatabaseName(
			dbname: $dbname . 'wiki',
			exists: false
		);

		if ( $check ) {
			// Will return a string — the error it received
			return $check;
		}

		return true;
	}

	public function isAcceptableDescription( ?string $reason ): bool|string|Message {
                if ( !$reason || ctype_space( $reason ) ) {
                        return $this->msg( 'htmlform-required' );
		}
		$reason = trim( $reason );
		if ( strlen( $reason ) < 150 ) {
			return $this->msg( 'createnewwiki-too-short' )->numParams( 150 );
		}
		// Prevent repeating string bypasses
		$reason = preg_replace( '/(.+?)\1{2,}/', '', $reason );
		if ( strlen( $reason ) < 150 ) {
			return $this->msg( 'createnewwiki-needs-more-information' );
		}
		// Prevent gibberish requests
		$vowelFilter = preg_replace( '/[bcdfghjklmnpqrstvwxzбвгджзклмнпрстфхцчшщ0123456789!@#$%^&*()_+{}|\"<>?\-=[\]\\;\',\.\/]/iu', '', $reason );
                if ( strlen( $vowelFilter ) / strlen( $reason ) < 0.453 ) {
                        return $this->msg( 'createnewwiki-needs-more-information' );
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
				#mw-htmlform-placeholder-subdomain .mw-htmlform-field-HTMLInfoField {
					margin-top: 1em;
				}
				#mw-input-wpcategory .oo-ui-dropdownWidget-handle {
					border-top-right-radius: 0;
					border-bottom-right-radius: 0;
				}
				.oo-ui-fieldLayout-body .oo-ui-iconElement-icon {
					margin-right: .25rem;
				}
				.oo-ui-panelLayout {
                                        margin: 0;
                                        padding: 0;
                                        border: none;
                                        width: 100%;
                                }
                                #mw-content-text .oo-ui-fieldsetLayout-header {
                                        display: none;
                                }
                                .oo-ui-fieldsetLayout-group > .oo-ui-widget > div {
                                        display: flex;
                                        align-items: center;
                                }
                                .oo-ui-fieldsetLayout-group > .oo-ui-widget > div > .mw-htmlform-field-HTMLInfoField:has(#mw-input-wpcategoryIcon) {
                                        background: #2b52c6;
                                        border-radius: 0 0.5em 0.5em 0;
                                        width: 24px;
                                        padding: 0.3em 0em 0em 0.15em;
                                        box-sizing: border-box;
                                        color: white;
					align-self: end;
					height: 32px;
                                }
                                .oo-ui-fieldsetLayout-group > .oo-ui-widget > div > .mw-htmlform-field-HTMLInfoField:has(#mw-input-wpcategoryIcon):has(.fa-solid) {
                                        display: flex;
                                        align-items: center;
                                        justify-content: center;
                                        padding: 0rem 0.3rem 0rem 0.23rem;
                                        font-size: 0.9em;
                                }
                                 .mw-htmlform-field-HTMLInfoField .oo-ui-iconWidget {
                                        filter: invert(100%);
                                        scale: 0.625;
                                }
                                .oo-ui-fieldsetLayout-group > .oo-ui-widget > div > * {
                                        margin: 0;
				}
                                #mw-content-text .mw-htmlform-field-HTMLTextField {
                                        width: 100%;
                                        max-width: 50em;
                                        margin-right: 1rem;
                                }
			</style>
                        <script>
                                const icons = {
                                        'Art & Architecture': 'palette',
                                        'Automotive': 'car',
                                        'Business & Finance': 'coins',
                                        'Community': 'comments',
                                        'Education': 'school',
                                        'Electronics': 'computer',
                                        'Entertainment': 'film',
                                        'Fandom': 'heart',
                                        'Fantasy': 'dragon',
                                        'Gaming': 'gamepad',
                                        'Geography': 'map',
                                        'History': 'landmark-dome',
                                        'Humour/Satire': 'face-laugh',
                                        'Language/Linguistics': 'language',
                                        'Leisure': 'mountain-city',
                                        'Literature/Writing': 'book',
                                        'Media/Journalism': 'newspaper',
                                        'Medicine/Medical': 'pills',
                                        'Military/War': 'bomb',
                                        'Music': 'music',
                                        'Podcast': 'podcast',
                                        'Politics': 'bullhorn',
                                        'Private': 'lock',
                                        'Religion': 'person-praying',
                                        'Science': 'flask',
                                        'Software/Computing': 'database',
                                        'Song Contest': 'guitar',
                                        'Sports': 'baseball',
                                        'Uncategorised': 'folder',
				};
				 addEventListener('load', () => {
					const observer = new MutationObserver(() => {
document.querySelector('textarea.oo-ui-inputWidget-input').setAttribute('minlength', 150);
document.querySelector('textarea.oo-ui-inputWidget-input').setAttribute('pattern', '.{150,}')
mw.loader.using( ['oojs-ui-core', 'ext.fontawesome', 'ext.fontawesome.far', 'ext.fontawesome.fas', 'ext.fontawesome.fab'], () => {
						observer.disconnect();
						console.log('ready!')
                                                document.querySelector('#mw-input-wpcategoryIcon').innerHTML = `<i class=\"fa-solid fa-\${icons[document.querySelector('#mw-input-wpcategory .oo-ui-dropdownWidget .oo-ui-labelElement-label').textContent.trim()] ?? 'question'}\"></i>`//'&#xf53f;'
						const mutationObserver = new MutationObserver(() => {
							console.log('OK IT CHANGED')
                                                        document.querySelector('#mw-input-wpcategoryIcon').innerHTML = `<i class=\"fa-solid fa-\${icons[document.querySelector('#mw-input-wpcategory .oo-ui-dropdownWidget .oo-ui-labelElement-label').textContent.trim()] ?? 'question'}\"></i>`//'&#xf53f;'

                                                });
console.log(document.querySelector('#mw-input-wpcategory .oo-ui-dropdownWidget .oo-ui-labelElement-label'))
                                                mutationObserver.observe(document.querySelector('#mw-input-wpcategory .oo-ui-dropdownWidget .oo-ui-labelElement-label'), {
                                                        characterData: true,
                                                        childList: true,
                                                        subtree: true
                                                })
})
                                        });
                                        observer.observe(document.querySelector('#mw-input-wpcategory'), {
                                                childList: true,
                                                subtree: true,
					});
                                })
                        </script>
		";
	}
}
