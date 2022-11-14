<?php
/**
 * @copyright Copyright (c) 2022 Joas Schilling <coding@schilljs.com>
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
namespace OCA\UserRetention\Tests;

use OCA\UserRetention\BackgroundJob\ExpireUsers;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IServerContainer;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Test\TestCase;

class ExpireUsersTest extends TestCase {
	/** @var MockObject|IConfig */
	protected $config;
	/** @var MockObject|IUserManager */
	protected $userManager;
	/** @var MockObject|IGroupManager */
	protected $groupManager;
	/** @var MockObject|ITimeFactory */
	protected $timeFactory;
	/** @var MockObject|IServerContainer */
	protected $container;
	/** @var MockObject|LoggerInterface */
	protected $logger;

	protected function setUp(): void {
		parent::setUp();

		/** @var MockObject|IConfig $config */
		$this->config = $this->createMock(IConfig::class);
		/** @var MockObject|IUserManager $userManager */
		$this->userManager = $this->createMock(IUserManager::class);
		/** @var MockObject|IGroupManager $groupManager */
		$this->groupManager = $this->createMock(IGroupManager::class);
		/** @var MockObject|ITimeFactory $timeFactory */
		$this->timeFactory = $this->createMock(ITimeFactory::class);
		/** @var MockObject|IServerContainer $urlGenerator */
		$this->container = $this->createMock(IServerContainer::class);
		/** @var MockObject|LoggerInterface $dispatcher */
		$this->logger = $this->createMock(LoggerInterface::class);
	}

	protected function createJob(array $methods = []): ExpireUsers {
		if (empty($methods)) {
			return new ExpireUsers(
				$this->config,
				$this->userManager,
				$this->groupManager,
				$this->timeFactory,
				$this->container,
				$this->logger
			);
		}

		$mock = $this->getMockBuilder(ExpireUsers::class);
		$mock->setConstructorArgs([
			$this->config,
			$this->userManager,
			$this->groupManager,
			$this->timeFactory,
			$this->container,
			$this->logger,
		]);
		$mock->onlyMethods($methods);
		return $mock->getMock();
	}

	public function dataShouldPerformActionOnUser(): array {
		return [
			'No expiration configured'
				=> [0, null, null, null, false, false, false],
			'Newly discovered user'
				=> [120000000, 0, null, null, true, false, false],
			'Too new discovery'
				=> [120000000, 120000001, null, null, true, false, false],
			'No login at all'
				=> [120000000, 119999999, 0, null, true, true, false],
			'Too new login (without auth tokens)'
				=> [120000000, 119999999, 120000001, null, true, true, false],
			'Too new login (with old auth tokens)'
				=> [120000000, 119999999, 120000001, 119999999, true, true, false],
			'Too new auth token'
				=> [120000000, 119999999, 119999999, 120000001, true, true, false],
			'Performing action'
				=> [120000000, 119900000, 119900000, 119900000, true, true, true],
			'Already performed'
				=> [120000000, 119900000, 119900000, 119900000, false, true, false],
		];
	}

	/**
	 * @dataProvider dataShouldPerformActionOnUser
	 */
	public function testShouldPerformActionOnUser(int $maxLastLogin, ?int $discoveryTime, ?int $lastLogin, ?int $lastTokenActivity, bool $retryOnFollowupDays, bool $keepUsersWithoutLogin, bool $expected): void {
		/** @var ExpireUsers|MockObject $job */
		$job = $this->createJob(['getAuthTokensLastActivity', 'setCreatedAt']);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')
			->willReturn('uid');
		$user->method('getLastLogin')
			->willReturn($lastLogin);

		if ($maxLastLogin !== 0) {
			$this->config
				->expects($this->once())
				->method('getUserValue')
				->with('uid', 'user_retention', 'user_created_at', '0')
				->willReturn($discoveryTime);
		}

		if ($discoveryTime === 0) {
			$job->expects($this->once())
				->method('setCreatedAt');
		} else {
			$job->expects($this->never())
				->method('setCreatedAt');
		}

		$job->method('getAuthTokensLastActivity')
			->willReturn($lastTokenActivity);

		self::invokePrivate($job, 'keepUsersWithoutLogin', [$keepUsersWithoutLogin]);

		$this->assertSame($expected, self::invokePrivate($job, 'shouldPerformActionOnUser', [$user, $maxLastLogin, $retryOnFollowupDays]));
	}
}
