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

use OC\BackgroundJob\TimedJob;
use OCA\Guests\UserBackend;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;

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

	protected $userMaxLastLogin = 0;
	protected $guestMaxLastLogin = 0;
	protected $excludedGroups = [];

	public function __construct(IConfig $config,
								IUserManager $userManager,
								IGroupManager $groupManager) {
		$this->config = $config;
		$this->userManager = $userManager;
		$this->groupManager = $groupManager;

		// Every day
		$this->setInterval(1);//60 * 60 * 24);
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

		if (empty($this->excludedGroups)) {
			return true;
		}

		$userGroups = $this->groupManager->getUserGroupIds($user);
		return empty(array_intersect($userGroups, $this->excludedGroups));
	}
}
