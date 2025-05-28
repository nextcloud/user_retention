<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2022 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\UserRetention\Service;

use OC\Authentication\Token\Manager;
use OC\Authentication\Token\PublicKeyToken;
use OCA\Guests\UserBackend as GuestUserBackend;
use OCA\UserRetention\SkipUserException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IServerContainer;
use OCP\IUser;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use OCP\LDAP\IDeletionFlagSupport;
use OCP\LDAP\ILDAPProvider;
use OCP\Mail\IMailer;
use Psr\Log\LoggerInterface;

class RetentionService {
	protected int $userDaysDisable = 0;
	protected int $userDisableMaxLastLogin = 0;
	protected int $userDays = 0;
	protected int $userMaxLastLogin = 0;
	protected int $guestDaysDisable = 0;
	protected int $guestDisableMaxLastLogin = 0;
	protected int $guestDays = 0;
	protected int $guestMaxLastLogin = 0;
	protected array $excludedGroups = [];
	protected array $reminders = [];
	protected array $remindersPlain = [];
	protected bool $keepUsersWithoutLogin = true;

	public function __construct(
		protected IConfig $config,
		protected IUserManager $userManager,
		protected IGroupManager $groupManager,
		protected ITimeFactory $time,
		protected IServerContainer $server,
		protected IMailer $mailer,
		protected IFactory $l10nFactory,
		protected LoggerInterface $logger,
	) {
	}

	public function runCron(): void {
		$now = new \DateTimeImmutable();
		$this->userDaysDisable = (int)$this->config->getAppValue('user_retention', 'user_days_disable', '0');
		if ($this->userDaysDisable > 0) {
			$userDisableMaxLastLogin = $now->sub(new \DateInterval('P' . $this->userDaysDisable . 'D'));
			$this->userDisableMaxLastLogin = $userDisableMaxLastLogin->getTimestamp();
			$this->logger->debug('Account disabling with last login before ' . $userDisableMaxLastLogin->format(\DateTimeInterface::ATOM));
		} else {
			$this->logger->debug('Account disabling is disabled');
		}

		$this->userDays = (int)$this->config->getAppValue('user_retention', 'user_days', '0');
		if ($this->userDays > 0) {
			$userMaxLastLogin = $now->sub(new \DateInterval('P' . $this->userDays . 'D'));
			$this->userMaxLastLogin = $userMaxLastLogin->getTimestamp();
			$this->logger->debug('Account retention with last login before ' . $userMaxLastLogin->format(\DateTimeInterface::ATOM));
		} else {
			$this->logger->debug('Account retention is disabled');
		}

		$this->guestDaysDisable = (int)$this->config->getAppValue('user_retention', 'guest_days_disable', '0');
		if ($this->guestDaysDisable > 0) {
			$guestDisableMaxLastLogin = $now->sub(new \DateInterval('P' . $this->guestDaysDisable . 'D'));
			$this->guestDisableMaxLastLogin = $guestDisableMaxLastLogin->getTimestamp();
			$this->logger->debug('Guest account disabling with last login before ' . $guestDisableMaxLastLogin->format(\DateTimeInterface::ATOM));
		} else {
			$this->logger->debug('Guest account disabling is disabled');
		}

		$this->guestDays = (int)$this->config->getAppValue('user_retention', 'guest_days', '0');
		if ($this->guestDays > 0) {
			$guestMaxLastLogin = $now->sub(new \DateInterval('P' . $this->guestDays . 'D'));
			$this->guestMaxLastLogin = $guestMaxLastLogin->getTimestamp();
			$this->logger->debug('Guest account retention with last login before ' . $guestMaxLastLogin->format(\DateTimeInterface::ATOM));
		} else {
			$this->logger->debug('Guest account retention is disabled');
		}

		$reminderDaysString = $this->config->getAppValue('user_retention', 'reminder_days', '');
		$reminderDayOptions = explode(',', $reminderDaysString);
		foreach ($reminderDayOptions as $option) {
			$option = (int)trim($option);
			if ($option > 0) {
				$this->remindersPlain[] = $option;
				$this->reminders[] = $now->sub(new \DateInterval('P' . $option . 'D'))->getTimestamp();
			}
		}

		$this->keepUsersWithoutLogin = $this->config->getAppValue('user_retention', 'keep_users_without_login', 'yes') === 'yes';

		try {
			$excludedGroups = $this->config->getAppValue('user_retention', 'excluded_groups', '["admin"]');
			$excludedGroups = json_decode($excludedGroups, true, 512, JSON_THROW_ON_ERROR);
			$this->excludedGroups = \is_array($excludedGroups) ? $excludedGroups : [];
		} catch (\JsonException $e) {
			$this->logger->warning('User retention excluded groups is not a valid JSON array');
		}

		if ($this->keepUsersWithoutLogin) {
			$this->userManager->callForSeenUsers(\Closure::fromCallable([$this, 'executeRetentionPolicy']));
		} else {
			$this->userManager->callForAllUsers(\Closure::fromCallable([$this, 'executeRetentionPolicy']));
		}
	}

	public function executeRetentionPolicy(IUser $user): ?bool {
		$skipDisableIfNewerThan = $this->userDisableMaxLastLogin;
		if ($user->getBackend() instanceof GuestUserBackend) {
			$skipDisableIfNewerThan = $this->guestDisableMaxLastLogin;
		}

		$skipIfNewerThan = $this->userMaxLastLogin;
		$policyDays = $this->userDays;
		$policyDaysDisable = $this->userDaysDisable;
		if ($user->getBackend() instanceof GuestUserBackend) {
			$skipIfNewerThan = $this->guestMaxLastLogin;
			$policyDays = $this->guestDays;
			$policyDaysDisable = $this->guestDaysDisable;
		}

		if (!$skipDisableIfNewerThan && !$skipIfNewerThan) {
			$this->logger->debug('Skipping retention because not defined for user backend: {user}', [
				'user' => $user->getUID(),
			]);
			return true;
		}

		// Skip user completely when member of a protected group
		try {
			$this->skipUserBasedOnProtectedGroupMembership($user);
		} catch (SkipUserException $e) {
			$this->logger->debug($e->getMessage(), $e->getLogParameters());
			return true;
		}

		// Check if we disable the user
		if ($skipDisableIfNewerThan !== 0) {
			try {
				$this->shouldPerformActionOnUser($user, $skipDisableIfNewerThan);

				if ($user->isEnabled()) {
					$user->setEnabled(false);
					$this->logger->info('Account disabled: ' . $user->getUID());
					return true;
				}
				$this->logger->debug('Account already disabled, continuing with potential deletion: ' . $user->getUID());
			} catch (SkipUserException $e) {
				// Not disabling yet, continue with checking deletion
				$this->logger->debug("Disable: {$e->getMessage()}", $e->getLogParameters());
			}
		} else {
			$this->logger->debug('No account disabling policy enabled for account: ' . $user->getUID());
		}

		// Check if we delete the user
		if ($skipIfNewerThan !== 0) {
			try {
				$this->shouldPerformActionOnUser($user, $skipIfNewerThan);

				$this->logger->debug('Attempting to delete account: {user}', [
					'user' => $user->getUID(),
				]);
				if ($user->getBackendClassName() === 'LDAP' && !$this->prepareLDAPUser($user)) {
					$this->logger->warning('Expired LDAP account ' . $user->getUID() . ' was not deleted');
					return true;
				}

				if ($user->delete()) {
					$this->logger->info('Account deleted: ' . $user->getUID());
				} else {
					$this->logger->warning('Expired account ' . $user->getUID() . ' was not deleted');
				}
				return true;
			} catch (SkipUserException $e) {
				// Not deleting yet, continue with checking reminders
				$this->logger->debug("Delete: {$e->getMessage()}", $e->getLogParameters());
			}
		} else {
			$this->logger->debug('No account retention policy enabled for account: ' . $user->getUID());
		}

		// Check if we remind the user
		foreach ($this->reminders as $key => $reminder) {
			$reminderDays = $this->remindersPlain[$key] ?? 0;

			$this->logger->debug('Checking reminder with {reminder} day: {user}', [
				'reminder' => $reminderDays,
				'user' => $user->getUID(),
			]);

			try {
				$lastActivity = $this->shouldPerformActionOnUser($user, $reminder, $reminder - 86400);

				$this->sendReminder($user, $lastActivity, $policyDays, $policyDaysDisable);
			} catch (SkipUserException $e) {
				$this->logger->debug("Reminder: {$e->getMessage()}", $e->getLogParameters());
				continue;
			}
		}

		return true;
	}

	/**
	 * @param IUser $user
	 * @param int $skipIfNewerThan
	 * @param ?int $skipIfOlderThan
	 * @return int Return the last activity timestamp
	 * @throws SkipUserException When the user should be skipped
	 */
	protected function shouldPerformActionOnUser(IUser $user, int $skipIfNewerThan, ?int $skipIfOlderThan = null): int {
		$discoveryTimestamp = $this->skipUserBasedOnDiscovery($user);
		$lastWebLogin = $user->getLastLogin();
		$authTokensLastActivity = $this->getAuthTokensLastActivity($user);

		if ($authTokensLastActivity === null) {
			$lastAction = max($discoveryTimestamp, $lastWebLogin);
		} else {
			$lastAction = max($discoveryTimestamp, $lastWebLogin, $authTokensLastActivity);
		}

		if ($this->keepUsersWithoutLogin && $lastAction === 0) {
			throw new SkipUserException(
				'Skipping user that never logged in: {user}',
				['user' => $user->getUID()]
			);
		}

		if ($skipIfNewerThan < $lastAction) {
			throw new SkipUserException(
				'Skipping user because last action is newer: {user}',
				['user' => $user->getUID()]
			);
		}

		if ($skipIfOlderThan !== null && $skipIfOlderThan > $lastAction) {
			throw new SkipUserException(
				'Skipping user because last action is older: {user}',
				['user' => $user->getUID()]
			);
		}

		return $lastAction;
	}

	/**
	 * @param IUser $user
	 * @return int Return the discovery timestamp as last activity timestamp
	 * @throws SkipUserException When the user was just discovered
	 */
	protected function skipUserBasedOnDiscovery(IUser $user): int {
		$discoveryTimestamp = (int)$this->config->getUserValue($user->getUID(), 'user_retention', 'user_created_at', '0');
		if ($discoveryTimestamp === 0) {
			// Set "now" as created at timestamp for the user.
			$this->config->setUserValue($user->getUID(), 'user_retention', 'user_created_at', (string)$this->time->getTime());

			throw new SkipUserException(
				'New user, saving discovery time: {user}',
				['user' => $user->getUID()]
			);
		}

		return $discoveryTimestamp;
	}

	/**
	 * @param IUser $user
	 * @throws SkipUserException When the user is part of a group and should therefor be skipped
	 */
	protected function skipUserBasedOnProtectedGroupMembership(IUser $user): void {
		if (empty($this->excludedGroups)) {
			return;
		}

		$userGroups = $this->groupManager->getUserGroupIds($user);
		$excludedGroups = array_intersect($userGroups, $this->excludedGroups);
		if (!empty($excludedGroups)) {
			throw new SkipUserException(
				'Skipping user because of excluded groups ({groups}): {user}',
				[
					'user' => $user->getUID(),
					'groups' => implode(',', $excludedGroups),
				]
			);
		}
	}

	protected function getAuthTokensLastActivity(IUser $user): ?int {
		/** @var Manager $authTokenManager */
		$authTokenManager = $this->server->get(Manager::class);
		/** @var PublicKeyToken[] $tokens */
		$tokens = $authTokenManager->getTokenByUser($user->getUID());

		$lastActivities = [];
		foreach ($tokens as $token) {
			$lastActivities[] = $token->getLastActivity();
		}

		if (empty($lastActivities)) {
			return null;
		}

		if (count($lastActivities) === 1) {
			return array_pop($lastActivities);
		}

		return max(...$lastActivities);
	}

	protected function prepareLDAPUser(IUser $user): bool {
		try {
			$ldapProvider = $this->server->get(ILDAPProvider::class);
			if ($ldapProvider instanceof IDeletionFlagSupport) {
				$ldapProvider->flagRecord($user->getUID());
				$this->logger->info('Marking LDAP user as deleted: ' . $user->getUID());
			}
		} catch (\Exception $e) {
			$this->logger->warning($e->getMessage(), [
				'exception' => $e,
			]);
			return false;
		}
		return true;
	}

	protected function sendReminder(IUser $user, int $lastActivity, int $policyDays, int $policyDaysDisable): void {
		$email = $user->getEMailAddress();
		if (!$email) {
			$this->logger->warning('Could not send account retention reminder to {user} because no email address is configured.', [
				'user' => $user->getUID(),
			]);
			return;
		}

		$this->logger->debug('Send reminder to account: {user}', [
			'user' => $user->getUID(),
		]);

		$l = $this->l10nFactory->get('user_retention', $this->l10nFactory->getUserLanguage($user));

		$message = $this->mailer->createMessage();
		$template = $this->mailer->createEMailTemplate('user_retention.Reminder', [
			'user_id' => $user->getUID(),
			'user_displayname' => $user->getDisplayName(),
			'last_activity' => $l->l('date', $lastActivity),
			'policy_days' => $policyDays,
		]);
		$template->setSubject($l->t('Important information regarding your account'));

		$template->addHeader();
		$template->addHeading($l->t('Account deletion'));
		$template->addBodyText(str_replace('{date}', $l->l('date', $lastActivity), $l->t('You have not used your account since {date}.')));
		if ($policyDaysDisable) {
			$template->addBodyText($l->n(
				'Due to the configured policy for accounts, inactive accounts will be disabled after %n day.',
				'Due to the configured policy for accounts, inactive accounts will be disabled after %n days.',
				$policyDaysDisable
			));
		}
		if ($policyDays) {
			$template->addBodyText($l->n(
				'Due to the configured policy for accounts, inactive accounts will be deleted after %n day.',
				'Due to the configured policy for accounts, inactive accounts will be deleted after %n days.',
				$policyDays
			));
		}
		$template->addBodyText($l->t('To keep your account you only need to login with your browser or connect with a desktop or mobile app. Otherwise your account and all the connected data will be permanently deleted.'));
		$template->addBodyText($l->t('If you have any questions, please contact your administration.'));
		$template->addFooter();

		$message->useTemplate($template);
		$message->setTo([
			$email => $user->getDisplayName(),
		]);

		try {
			$this->mailer->send($message);
		} catch (\Exception $e) {
			$this->logger->error('Error while sending user retention reminder to {user}', [
				'user' => $user->getUID(),
				'exception' => $e,
			]);
		}
	}
}
