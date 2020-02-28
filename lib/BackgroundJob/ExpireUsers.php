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

use OC\Authentication\Token\DefaultToken;
use OC\Authentication\Token\Manager;
use OC\BackgroundJob\TimedJob;
use OCA\Guests\UserBackend;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\ILogger;
use OCP\IServerContainer;
use OCP\IUser;
use OCP\IUserManager;
use OCP\LDAP\IDeletionFlagSupport;

/**
 * Class ExpireUsers
 *
 * @package OCA\UserRetention\BackgroundJob
 */
class ExpireUsers extends TimedJob {

	/** @var IConfig */
	protected $config;
	/** @var IUserManager */
	protected $userManager;
	/** @var IGroupManager */
	protected $groupManager;
	/** @var ITimeFactory */
	protected $timeFactory;

	protected $userMaxLastLogin = 0;
	protected $guestMaxLastLogin = 0;
	protected $excludedGroups = [];

	/** @var IServerContainer */
	private $server;

	public function __construct(
		IConfig $config,
		IUserManager $userManager,
		IGroupManager $groupManager,
		ITimeFactory $timeFactory,
		IServerContainer $server
	) {
		$this->config = $config;
		$this->userManager = $userManager;
		$this->groupManager = $groupManager;
		$this->timeFactory = $timeFactory;
		$this->server = $server;

		// Every day
		$this->setInterval(60 * 60 * 24);
	}

	protected function run($argument): void {
		$now = new \DateTimeImmutable();
		$userDays = (int) $this->config->getAppValue('user_retention', 'user_days', 0);
		if ($userDays > 0) {
			$this->userMaxLastLogin = $now->sub(new \DateInterval('P' . $userDays . 'D'))->getTimestamp();
		}

		$guestDays = (int) $this->config->getAppValue('user_retention', 'guest_days', 0);
		if ($guestDays === $userDays) {
			$this->guestMaxLastLogin = $this->userMaxLastLogin;
		} else if ($guestDays > 0) {
			$this->guestMaxLastLogin = $now->sub(new \DateInterval('P' . $guestDays . 'D'))->getTimestamp();
		}

		$excludedGroups = $this->config->getAppValue('user_retention', 'excluded_groups', '["admin"]');
		$excludedGroups = json_decode($excludedGroups, true);
		$this->excludedGroups = \is_array($excludedGroups) ? $excludedGroups : [];

		$this->userManager->callForAllUsers(function(IUser $user) {
			$maxLastLogin = $this->userMaxLastLogin;
			if ($user->getBackend() instanceof UserBackend) {
				$maxLastLogin = $this->guestMaxLastLogin;
			}

			if ($this->shouldExpireUser($user, $maxLastLogin)) {
				if($user->getBackendClassName() === 'LDAP' && !$this->prepareLDAPUser($user)) {
					return;
				}
				$user->delete();
			}
		});
	}

	protected function shouldExpireUser(IUser $user, int $maxLastLogin): bool {
		if (!$maxLastLogin) {
			return false;
		}

		if ($maxLastLogin < $user->getLastLogin()) {
			return false;
		}

		if (!$this->allAuthTokensInactive($user, $maxLastLogin)) {
			return false;
		}

		$createdAt = $this->getCreatedAt($user);
		if ($createdAt === 0) {
			// Set "now" as created at timestamp for the user.
			$this->setCreatedAt($user, $this->timeFactory->getTime());
			return false;
		}

		if ($maxLastLogin < $createdAt) {
			return false;
		}

		if (empty($this->excludedGroups)) {
			return true;
		}

		$userGroups = $this->groupManager->getUserGroupIds($user);
		return empty(array_intersect($userGroups, $this->excludedGroups));
	}

	protected function allAuthTokensInactive(IUser $user, int $maxLastActivity): bool {
		/** @var Manager $authTokenManager */
		$authTokenManager = \OC::$server->query(Manager::class);
		/** @var DefaultToken[] $tokens */
		$tokens = $authTokenManager->getTokenByUser($user->getUID());

		foreach ($tokens as $token) {
			if ($maxLastActivity < $token->getLastActivity()) {
				return false;
			}
		}

		return true;
	}

	protected function getCreatedAt(IUser $user): int {
		return (int) $this->config->getUserValue(
			$user->getUID(),
			'user_retention',
			'user_created_at',
			0
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
			$ldapProvider = $this->server->query('LDAPProvider');
			if($ldapProvider instanceof IDeletionFlagSupport) {
				$ldapProvider->flagRecord($user->getUID());
			}
		} catch (\Exception $e) {
			$this->server->getLogger()->logException($e, [
				'app' => 'user_retention',
				'level' => ILogger::WARN,
			]);
			return false;
		}
		return true;
	}
}
