<?php

namespace Miraheze\MirahezeRequests\Specials\Viewer;

use MediaWiki\Config\Config;
use MediaWiki\Context\IContextSource;
use MediaWiki\Html\Html;
use MediaWiki\HTMLForm\HTMLForm;
use MediaWiki\Linker\Linker;
use MediaWiki\Logging\ManualLogEntry;
use MediaWiki\Message\Message;
use MediaWiki\SpecialPage\SpecialPage;
use Miraheze\MirahezeRequests\CodexHTMLFormTabs;
use Miraheze\MirahezeRequests\Requests\RequestAccountManager;
use Wikimedia\Codex\Utility\Codex;

class RequestAccountViewer extends RequestViewer {
	public function __construct(
		private readonly Config $config,
		private readonly IContextSource $context,
		private readonly RequestAccountManager $requestManager
	) {
	}

	public function getFormDescriptor(): array {
		$codex = new Codex();
		$authority = $this->context->getAuthority();

		if ( !$authority->isAllowed( 'handle-requestaccount' ) ) {
			$this->context->getOutput()->addHTML(
				Html::errorBox( $this->context->msg( 'mirahezerequests-nopermission' )->escaped() )
			);
			return [];
		}

		$formDescriptor = [
			'username' => [
				'label-message' => 'requestaccount-username',
				'type' => 'text',
				'readonly' => true,
				'section' => 'details',
				'default' => $this->requestManager->getUsername(),
			],
			'email' => [
				'label-message' => 'requestaccount-email',
				'type' => 'text',
				'readonly' => true,
				'section' => 'details',
				'default' => $this->requestManager->getEmail(),
			],
			'requester' => [
				'label-message' => 'mirahezerequests-label-requester',
				'type' => 'info',
				'section' => 'details',
				'default' => htmlspecialchars( $this->requestManager->getRequester()->getName() ) .
					Linker::userToolLinks(
						$this->requestManager->getRequester()->getId(),
						$this->requestManager->getRequester()->getName()
					),
				'raw' => true,
			],
			'requestedDate' => [
				'label-message' => 'mirahezerequests-label-requested-date',
				'type' => 'info',
				'section' => 'details',
				'default' => $this->context->getLanguage()->timeanddate(
					$this->requestManager->getTimestamp(), true
				),
			],
			'ip' => [
				'label-message' => 'mirahezerequests-label-ip',
				'type' => 'info',
				'section' => 'details',
				'default' => $this->requestManager->getIp(),
			],
			'status' => [
				'label-message' => 'mirahezerequests-label-status',
				'type' => 'text',
				'readonly' => true,
				'section' => 'details',
				'default' => $this->context->msg(
					'mirahezerequests-status-' . $this->requestManager->getStatus()
				)->text(),
			],
			'reason' => [
				'label-message' => 'requestaccount-reason',
				'type' => 'text',
				'readonly' => true,
				'section' => 'details',
				'default' => $this->context->msg(
					'requestaccount-' . $this->requestManager->getReason() . '-label'
				)->text(),
			],
			'explanation' => [
				'type' => 'textarea',
				'rows' => 6,
				'readonly' => true,
				'label-message' => 'requestaccount-explanation',
				'default' => $this->requestManager->getExplanation(),
				'raw' => true,
				'section' => 'details',
			],
			'comments' => [
				'type' => 'textarea',
				'rows' => 4,
				'readonly' => true,
				'label-message' => 'requestaccount-comments',
				'default' => $this->requestManager->getComments(),
				'raw' => true,
				'section' => 'details',
			],
		];

		$info = '';
		$invalidStatus = $this->requestManager->invalidStatus();

		$blocks = $this->requestManager->getIpBlocks();
		if ( $blocks ) {
			$info .= $codex->message()->setContentText( implode( "\n", $blocks ) )->build()->getHtml();
		}

		if ( $authority->isAllowed( 'handle-requestaccount' ) ) {
			$formDescriptor['handle-info'] = [
				'type' => 'info',
				'default' => $info,
				'raw' => true,
				'section' => 'handling',
			];

			if ( $invalidStatus ) {
				$formDescriptor['handle-resolved'] = [
					'type' => 'info',
					'default' => $this->context->msg( 'mirahezerequests-status-conflict' )->escaped(),
					'raw' => true,
					'section' => 'handling',
				];
			} else {
				$formDescriptor += [
					'submit-accept' => [
						'type' => 'submit',
						'buttonlabel-message' => 'mirahezerequests-label-accept',
						'disabled' => $this->requestManager->userExists(),
						'section' => 'handling',
					],
					'submit-decline' => [
						'type' => 'submit',
						'flags' => [ 'destructive', 'primary' ],
						'buttonlabel-message' => 'mirahezerequests-label-decline',
						'section' => 'handling',
					],
					'submit-decline-reason' => [
						'type' => 'text',
						'label-message' => 'mirahezerequests-label-decline-reason',
						'section' => 'handling',
						'validation-callback' => [ $this, 'isValidDeclineReason' ],
					],
				];
			}
		}

		return $formDescriptor;
	}

	public function getForm( int $requestId ): CodexHTMLFormTabs {
		$this->requestManager->getById( $requestId );

		$formDescriptor = $this->getFormDescriptor();
		$htmlForm = new CodexHTMLFormTabs( $formDescriptor, $this->context, 'requestaccount-section' );

		$htmlForm->setId( 'mirahezerequests-requestaccount-viewer' );
		$htmlForm->suppressDefaultSubmit();
		$htmlForm->setSubmitCallback(
			function ( array $formData, HTMLForm $form ) {
				$this->submitForm( $formData, $form );
			}
		);

		return $htmlForm;
	}

	public function isValidDeclineReason( ?string $reason, array $alldata ): Message|true {
		if ( isset( $alldata['submit-decline'] ) && ( !$reason || ctype_space( $reason ) ) ) {
			return $this->context->msg( 'htmlform-required' );
		}

		return true;
	}

	protected function submitForm(
		array $formData,
		HTMLForm $form,
	): void {
		$out = $this->context->getOutput();

		if ( $this->requestManager->invalidStatus() ) {
			$out->addHTML( Html::errorBox(
				$this->context->msg( 'mirahezerequests-status-conflict' )->escaped()
			) );
			return;
		}

		$username = $this->requestManager->getUsername();
		$requestTitle = SpecialPage::getTitleFor( 'RequestAccountQueue', (string)$this->requestManager->getId() );

		if ( isset( $formData['submit-accept'] ) ) {
			// The job verifies the outcome and sets the real final
			// status; this is just an in-flight marker so the request
			// can't be actioned again while it's being processed.
			$this->requestManager->setStatus( self::STATUS_STARTING );
			$this->requestManager->executeJob();

			$logEntry = new ManualLogEntry( 'requestaccount', 'accept' );
			$logEntry->setPerformer( $this->context->getUser() );
			$logEntry->setTarget( $requestTitle );
			$logEntry->setParameters( [ '4::requestTarget' => $username ] );
			$logEntry->publish( $logEntry->insert() );

			$out->redirect( $requestTitle->getFullURL() );
			return;
		}

		if ( isset( $formData['submit-decline'] ) ) {
			$this->requestManager->sendDeclineEmail( $formData['submit-decline-reason'] );
			$this->requestManager->setStatus( self::STATUS_DECLINED );

			$logEntry = new ManualLogEntry( 'requestaccount', 'decline' );
			$logEntry->setPerformer( $this->context->getUser() );
			$logEntry->setTarget( $requestTitle );
			$logEntry->setComment( $formData['submit-decline-reason'] );
			$logEntry->setParameters( [ '4::requestTarget' => $username ] );
			$logEntry->publish( $logEntry->insert() );

			$out->redirect( $requestTitle->getFullURL() );
		}
	}
}
