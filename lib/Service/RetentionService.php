<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2022 Joas Schilling <coding@schilljs.com>
 *
 * @author Joas Schilling <coding@schilljs.com>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\UserRetention\Service;

use OC\Authentication\Token\Manager;
use OC\Authentication\Token\PublicKeyToken;
use OCA\Guests\UserBackend;
use OCA\UserRetention\SkipUserException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IServerContainer;
use OCP\IUser;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use OCP\LDAP\IDeletionFlagSupport;
use OCP\LDAP\ILDAPProvider;
use OCP\Mail\IMailer;
use Psr\Log\LoggerInterface;

class RetentionService {
	protected IConfig $config;
	protected IUserManager $userManager;
	protected IGroupManager $groupManager;
	protected ITimeFactory $time;
	protected IServerContainer $server;
	protected IMailer $mailer;
	protected IFactory $l10nFactory;
	protected LoggerInterface $logger;

	protected int $userDays = 0;
	protected int $userMaxLastLogin = 0;
	protected int $guestDays = 0;
	protected int $guestMaxLastLogin = 0;
	protected array $excludedGroups = [];
	protected array $reminders = [];
	protected array $remindersPlain = [];
	protected bool $keepUsersWithoutLogin = true;

	public function __construct(
		IConfig $config,
		IUserManager $userManager,
		IGroupManager $groupManager,
		ITimeFactory $time,
		IServerContainer $server,
		IMailer $mailer,
		IFactory $l10nFactory,
		LoggerInterface $logger
	) {
		$this->config = $config;
		$this->userManager = $userManager;
		$this->groupManager = $groupManager;
		$this->time = $time;
		$this->server = $server;
		$this->mailer = $mailer;
		$this->l10nFactory = $l10nFactory;
		$this->logger = $logger;
	}

	public function runCron(): void {
		$now = new \DateTimeImmutable();
		$this->userDays = (int) $this->config->getAppValue('user_retention', 'user_days', '0');
		if ($this->userDays > 0) {
			$userMaxLastLogin = $now->sub(new \DateInterval('P' . $this->userDays . 'D'));
			$this->userMaxLastLogin = $userMaxLastLogin->getTimestamp();
			$this->logger->debug('Account retention with last login before ' . $userMaxLastLogin->format(\DateTimeInterface::ATOM));
		} else {
			$this->logger->debug('Account retention is disabled');
		}

		$this->guestDays = (int) $this->config->getAppValue('user_retention', 'guest_days', '0');
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
			$option = (int) trim($option);
			if ($option !== 0) {
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
		}

		if ($this->keepUsersWithoutLogin) {
			$this->userManager->callForSeenUsers(\Closure::fromCallable([$this, 'executeRetentionPolicy']));
		} else {
			$this->userManager->callForAllUsers(\Closure::fromCallable([$this, 'executeRetentionPolicy']));
		}
	}

	public function executeRetentionPolicy(IUser $user): ?bool {
		$this->logger->warning($user->getUID());
		$skipIfNewerThan = $this->userMaxLastLogin;
		$policyDays = $this->userDays;
		if ($user->getBackend() instanceof UserBackend) {
			$skipIfNewerThan = $this->guestMaxLastLogin;
			$policyDays = $this->guestDays;
		}

		if (!$skipIfNewerThan) {
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

		// Check if we delete the user
		try {
			$this->shouldPerformActionOnUser($user, $skipIfNewerThan);

			$this->logger->debug('Attempting to delete account: {user}', [
				'user' => $user->getUID(),
			]);
			if($user->getBackendClassName() === 'LDAP' && !$this->prepareLDAPUser($user)) {
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

				// FIXME send notification
				$this->sendReminder($user, $lastActivity, $policyDays);
			} catch (SkipUserException $e) {
				$this->logger->debug($e->getMessage(), $e->getLogParameters());
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

		$lastAction = max($discoveryTimestamp, $lastWebLogin, $authTokensLastActivity);

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
		$discoveryTimestamp = (int) $this->config->getUserValue($user->getUID(), 'user_retention', 'user_created_at', '0');
		if ($discoveryTimestamp === 0) {
			// Set "now" as created at timestamp for the user.
			$this->config->setUserValue($user->getUID(), 'user_retention', 'user_created_at', (string) $this->time->getTime());

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

		return max(...$lastActivities);
	}

	protected function prepareLDAPUser(IUser $user): bool {
		try {
			$ldapProvider = $this->server->get(ILDAPProvider::class);
			if($ldapProvider instanceof IDeletionFlagSupport) {
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

	protected function sendReminder(IUser $user, int $lastActivity, int $policyDays): void {
		$this->logger->debug('Send reminder to account: {user}', [
			'user' => $user->getUID(),
		]);

		$l = $this->l10nFactory->get('user_retention', $this->l10nFactory->getUserLanguage($user));

		$message = $this->mailer->createMessage();
		$template = $this->mailer->createEMailTemplate('user_retention.Reminder');
		$template->setSubject($l->t('Important information regarding your account'));

		$template->addHeader();
		$template->addHeading($l->t('Account deletion'));
		$template->addBodyText(str_replace('{date}', $l->l('date', $lastActivity), $l->t('You have used your account since {date}.')));
		$template->addBodyText($l->n(
			'Due to the configured policy for accounts, inactive accounts will be deleted after %n day.',
			'Due to the configured policy for accounts, inactive accounts will be deleted after %n days.',
			$policyDays
		));
		$template->addBodyText($l->t('To keep your account you only need to login or connect with a desktop or mobile app. Otherwise your account and all the connected data will be permanently deleted.'));
		$template->addBodyText($l->t('If you have any questions, please contact your administration.'));
		$template->addFooter();

		$message->useTemplate($template);
		$message->setTo([
			$user->getEMailAddress() => $user->getDisplayName(),
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
