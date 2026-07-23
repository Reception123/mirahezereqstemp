<?php

namespace Miraheze\MirahezeRequests\Jobs;

use MediaWiki\Auth\AuthManager;
use MediaWiki\Auth\TemporaryPasswordAuthenticationRequest;
use MediaWiki\JobQueue\Job;
use MediaWiki\Logging\ManualLogEntry;
use MediaWiki\Mail\MailAddress;
use MediaWiki\Mail\UserMailer;
use MediaWiki\User\User;
use MediaWiki\User\UserFactory;
use Miraheze\MirahezeRequests\MirahezeRequestsStatus;
use Miraheze\MirahezeRequests\Requests\RequestAccountManager;

class CreateAccountJob extends Job implements MirahezeRequestsStatus {

	public const string JOB_NAME = 'MirahezeRequestsCreateAccountJob';

	private readonly int $id;

	public function __construct(
		array $params,
		private readonly UserFactory $userFactory,
		private readonly AuthManager $authManager,
		private readonly RequestAccountManager $requestManager,
	) {
		parent::__construct( self::JOB_NAME, $params );
		$this->id = $params['id'];
	}

	public function run(): bool {
		$this->requestManager->getById( $this->id );

		$username = $this->requestManager->getUsername();
		$email = $this->requestManager->getEmail();

		$user = $this->userFactory->newFromName( $username );

		if ( !$user ) {
			$this->requestManager->setStatus( self::STATUS_FAILED );
			return false;
		}

		if ( $user->isRegistered() ) {
			// The account already exists, the desired end state is
			// already satisfied; nothing further to do.
			$this->requestManager->setStatus( self::STATUS_COMPLETE );
			return true;
		}

		$user->setEmail( $email );

		$status = $user->addToDatabase();
		if ( !$status->isGood() ) {
			$this->requestManager->setStatus( self::STATUS_FAILED );
			return false;
		}

		$req = TemporaryPasswordAuthenticationRequest::newRandom();
		$newTempPassword = $req->password;
		$sysUser = User::newSystemUser( 'MirahezeRequests', [ 'steal' => true ] );

		$req->action = AuthManager::ACTION_CHANGE;
		$req->username = $username;
		$req->mailpassword = false; // send our own custom email
		$req->caller = $sysUser->getName();

		$status = $this->authManager->allowsAuthenticationDataChange( $req, false );
		if ( !$status->isGood() ) {
			$this->requestManager->setStatus( self::STATUS_FAILED );
			return false;
		}

		$this->authManager->changeAuthenticationData( $req );

		$subjectMessage = wfMessage( 'requestaccount-created-email-title' );
		$bodyMessage = wfMessage( 'requestaccount-created-email-text', $username, $newTempPassword );

		$from = MailAddress::newFromUser( $sysUser );
		UserMailer::send(
			new MailAddress( $email, $username ),
			$from,
			$subjectMessage->text(),
			$bodyMessage->text()
		);

		$ccEmail = $this->requestManager->getRequesterCcEmail();
		if ( $ccEmail && $ccEmail !== $email ) {
			UserMailer::send(
				new MailAddress( $ccEmail ),
				$from,
				$subjectMessage->text(),
				$bodyMessage->text()
			);
		}

		$logEntry = new ManualLogEntry( 'newusers', 'byemail' );
		$logEntry->setPerformer( $sysUser );
		$logEntry->setTarget( $user->getUserPage() );
		$logEntry->setComment( '' );
		$logEntry->setParameters( [ '4::userid' => $user->getId() ] );
		$logEntry->publish( $logEntry->insert() );

		$this->requestManager->setStatus( self::STATUS_COMPLETE );

		return true;
	}

	public function allowRetries(): false {
		return false;
	}
}
