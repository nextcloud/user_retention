<?php
declare(strict_types=1);
/**
 * @copyright Copyright (c) 2019 Joas Schilling <coding@schilljs.com>
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

namespace OCA\UserRetention\BackgroundJob;

use OC\Authentication\Token\Manager;
use OC\Authentication\Token\PublicKeyToken;
use OCA\Guests\UserBackend;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IServerContainer;
use OCP\IUser;
use OCP\IUserManager;
use OCP\LDAP\IDeletionFlagSupport;
use OCP\LDAP\ILDAPProvider;
use Psr\Log\LoggerInterface;

/**
 * Class ExpireUsers
 *
 * @package OCA\UserRetention\BackgroundJob
 */
class ExpireUsers extends TimedJob {
	protected IConfig $config;
	protected IUserManager $userManager;
	protected IGroupManager $groupManager;
	protected LoggerInterface $logger;
	protected IServerContainer $server;

	protected int $userMaxLastLogin = 0;
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
		LoggerInterface $logger
	) {
		parent::__construct($time);

		$this->config = $config;
		$this->userManager = $userManager;
		$this->groupManager = $groupManager;
		$this->server = $server;
		$this->logger = $logger;

		// Every day
		$this->setInterval(60 * 60 * 24);
	}

	protected function run($argument): void {
		$now = new \DateTimeImmutable();
		$userDays = (int) $this->config->getAppValue('user_retention', 'user_days', '0');
		if ($userDays > 0) {
			$userMaxLastLogin = $now->sub(new \DateInterval('P' . $userDays . 'D'));
			$this->userMaxLastLogin = $userMaxLastLogin->getTimestamp();
			$this->logger->debug('User retention with last login before ' . $userMaxLastLogin->format(\DateTimeInterface::ATOM));
		} else {
			$this->logger->debug('User retention is disabled');
		}

		$guestDays = (int) $this->config->getAppValue('user_retention', 'guest_days', '0');
		if ($guestDays > 0) {
			$guestMaxLastLogin = $now->sub(new \DateInterval('P' . $guestDays . 'D'));
			$this->guestMaxLastLogin = $guestMaxLastLogin->getTimestamp();
			$this->logger->debug('Guest retention with last login before ' . $guestMaxLastLogin->format(\DateTimeInterface::ATOM));
		} else {
			$this->logger->debug('Guest retention is disabled');
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

		$handler = function(IUser $user) {
			$maxLastLogin = $this->userMaxLastLogin;
			if ($user->getBackend() instanceof UserBackend) {
				$maxLastLogin = $this->guestMaxLastLogin;
			}

			if ($this->shouldPerformActionOnUser($user, $maxLastLogin)) {
				$this->logger->debug('Attempting to delete account: {user}', [
					'user' => $user->getUID(),
				]);
				if($user->getBackendClassName() === 'LDAP' && !$this->prepareLDAPUser($user)) {
					$this->logger->warning('Expired LDAP user ' . $user->getUID() . ' was not deleted');
					return;
				}

				if ($user->delete()) {
					$this->logger->info('User deleted: ' . $user->getUID());
				} else {
					$this->logger->warning('Expired user ' . $user->getUID() . ' was not deleted');
				}
				return;
			}

			foreach ($this->reminders as $key => $reminder) {
				$this->logger->debug('Checking reminder with {reminder} day: {user}', [
					'reminder' => $this->remindersPlain[$key] ?? 0,
					'user' => $user->getUID(),
				]);
				if ($this->shouldPerformActionOnUser($user, $reminder, false)) {
					$this->logger->debug('Send reminder to account: {user}', [
						'user' => $user->getUID(),
					]);

					// FIXME send notification
				}
			}
		};

		if ($this->keepUsersWithoutLogin) {
			$this->userManager->callForSeenUsers($handler);
		} else {
			$this->userManager->callForAllUsers($handler);
		}
	}


	protected function shouldPerformActionOnUser(IUser $user, int $maxLastLogin, bool $retryOnFollowupDays = true): bool {
		if (!$maxLastLogin) {
			return false;
		}

		$createdAt = $this->getCreatedAt($user);
		if ($createdAt === 0) {
			// Set "now" as created at timestamp for the user.
			$this->setCreatedAt($user, $this->time->getTime());
			$this->logger->debug('New user, saving discovery time: {user}', [
				'user' => $user->getUID(),
			]);
			return false;
		}

		if (!$this->keepUsersWithoutLogin && $maxLastLogin < $createdAt) {
			$this->logger->debug('Skipping user because of discovery time: {user}', [
				'user' => $user->getUID(),
			]);
			return false;
		}

		if ($this->keepUsersWithoutLogin && $user->getLastLogin() === 0) {
			// no need for deletion when no user dir was initialized
			$this->logger->debug('Skipping user that never logged in: {user}', [
				'user' => $user->getUID(),
			]);
			return false;
		}

		$authTokensLastActivity = $this->getAuthTokensLastActivity($user);
		if ($authTokensLastActivity === null) {
			$lastAuthentication = $user->getLastLogin();
		} else {
			$lastAuthentication = max($user->getLastLogin(), $authTokensLastActivity);
		}

		if ($maxLastLogin < $lastAuthentication) {
			$this->logger->debug('Skipping user because of login or auth token time: {user}', [
				'user' => $user->getUID(),
			]);
			return false;
		}

		if (!$retryOnFollowupDays && ($maxLastLogin - 86400) > $lastAuthentication) {
			$this->logger->debug('Skipping user because of login or auth token time is not in retry window: {user}', [
				'user' => $user->getUID(),
			]);
			return false;
		}

		if (empty($this->excludedGroups)) {
			return true;
		}

		$userGroups = $this->groupManager->getUserGroupIds($user);
		$excludedGroups = array_intersect($userGroups, $this->excludedGroups);
		if (!empty($excludedGroups)) {
			$this->logger->debug('Skipping user because of excluded groups ({groups}): {user}', [
				'user' => $user->getUID(),
				'groups' => implode(',', $excludedGroups),
			]);
			return false;
		}

		return true;
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

	protected function getCreatedAt(IUser $user): int {
		return (int) $this->config->getUserValue(
			$user->getUID(),
			'user_retention',
			'user_created_at',
			'0'
		);
	}

	protected function setCreatedAt(IUser $user, int $time): void {
		$this->config->setUserValue(
			$user->getUID(),
			'user_retention',
			'user_created_at',
			$time
		);
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
}
