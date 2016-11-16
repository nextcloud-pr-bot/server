<?php
/**
 * @author Björn Schießle <schiessle@owncloud.com>
 *
 * @copyright Copyright (c) 2016, ownCloud, Inc.
 * @license AGPL-3.0
 *
 * This code is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License, version 3,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License, version 3,
 * along with this program.  If not, see <http://www.gnu.org/licenses/>
 *
 */


namespace Test\Accounts;


use OC\Accounts\AccountManager;
use OC\Mail\Mailer;
use OCP\IUser;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Test\TestCase;

/**
 * Class AccountsManagerTest
 *
 * @group DB
 * @package Test\Accounts
 */
class AccountsManagerTest extends TestCase {

	/** @var  \OCP\IDBConnection */
	private $connection;

	/** @var  EventDispatcherInterface | \PHPUnit_Framework_MockObject_MockObject */
	private $eventDispatcher;

	/** @var string accounts table name */
	private $table = 'accounts';

	public function setUp() {
		parent::setUp();
		$this->eventDispatcher = $this->getMockBuilder('Symfony\Component\EventDispatcher\EventDispatcherInterface')
			->disableOriginalConstructor()->getMock();
		$this->connection = \OC::$server->getDatabaseConnection();
	}

	public function tearDown() {
		parent::tearDown();
		$query = $this->connection->getQueryBuilder();
		$query->delete($this->table)->execute();
	}

	/**
	 * get a instance of the accountManager
	 *
	 * @param array $mockedMethods list of methods which should be mocked
	 * @return \PHPUnit_Framework_MockObject_MockObject | AccountManager
	 */
	public function getInstance($mockedMethods = null) {
		return $this->getMockBuilder('OC\Accounts\AccountManager')
			->setConstructorArgs([$this->connection, $this->eventDispatcher])
			->setMethods($mockedMethods)
			->getMock();

	}

	/**
	 * @dataProvider dataTrueFalse
	 *
	 * @param bool $userAlreadyExists
	 */
	public function testUpdateUser($userAlreadyExists) {
		$accountManager = $this->getInstance(['getUser', 'insertNewUser', 'updateExistingUser']);
		$user = $this->getMockBuilder('OCP\IUser')->getMock();

		$accountManager->expects($this->once())->method('getUser')->with($user)->willReturn($userAlreadyExists);

		if ($userAlreadyExists) {
			$accountManager->expects($this->once())->method('updateExistingUser')
				->with($user, 'data');
			$accountManager->expects($this->never())->method('insertNewUser');
		} else {
			$accountManager->expects($this->once())->method('insertNewUser')
				->with($user, 'data');
			$accountManager->expects($this->never())->method('updateExistingUser');
		}

		$this->eventDispatcher->expects($this->once())->method('dispatch')
			->willReturnCallback(function($eventName, $event) use ($user) {
				$this->assertSame('OC\AccountManager::userUpdated', $eventName);
				$this->assertInstanceOf('Symfony\Component\EventDispatcher\GenericEvent', $event);
			}
			);

		$accountManager->updateUser($user, 'data');
	}

	public function dataTrueFalse() {
		return [
			[true],
			[false]
		];
	}


	/**
	 * @dataProvider dataTestGetUser
	 *
	 * @param string $setUser
	 * @param array $setData
	 * @param IUser $askUser
	 * @param array $expectedData
	 * @param book $userAlreadyExists
	 */
	public function testGetUser($setUser, $setData, $askUser, $expectedData, $userAlreadyExists) {
		$accountManager = $this->getInstance(['buildDefaultUserRecord', 'insertNewUser']);
		if (!$userAlreadyExists) {
			$accountManager->expects($this->once())->method('buildDefaultUserRecord')
				->with($askUser)->willReturn($expectedData);
			$accountManager->expects($this->once())->method('insertNewUser')
				->with($askUser, $expectedData);
		}
		$this->addDummyValuesToTable($setUser, $setData);
		$this->assertEquals($expectedData,
			$accountManager->getUser($askUser)
		);
	}

	public function dataTestGetUser() {
		$user1 = $this->getMockBuilder('OCP\IUser')->getMock();
		$user1->expects($this->any())->method('getUID')->willReturn('user1');
		$user2 = $this->getMockBuilder('OCP\IUser')->getMock();
		$user2->expects($this->any())->method('getUID')->willReturn('user2');
		return [
			['user1', ['key' => 'value'], $user1, ['key' => 'value'], true],
			['user1', ['key' => 'value'], $user2, [], false],
		];
	}

	public function testUpdateExistingUser() {
		$user = $this->getMockBuilder('OCP\IUser')->getMock();
		$user->expects($this->once())->method('getUID')->willReturn('uid');
		$oldData = ['key' => 'value'];
		$newData = ['newKey' => 'newValue'];

		$accountManager = $this->getInstance();
		$this->addDummyValuesToTable('uid', $oldData);
		$this->invokePrivate($accountManager, 'updateExistingUser', [$user, $newData]);
		$newDataFromTable = $this->getDataFromTable('uid');
		$this->assertEquals($newData, $newDataFromTable);
	}

	public function testInsertNewUser() {
		$user = $this->getMockBuilder('OCP\IUser')->getMock();
		$uid = 'uid';
		$data = ['key' => 'value'];

		$accountManager = $this->getInstance();
		$user->expects($this->once())->method('getUID')->willReturn($uid);
		$this->assertNull($this->getDataFromTable($uid));
		$this->invokePrivate($accountManager, 'insertNewUser', [$user, $data]);

		$dataFromDb = $this->getDataFromTable($uid);
		$this->assertEquals($data, $dataFromDb);
	}

	private function addDummyValuesToTable($uid, $data) {

		$query = $this->connection->getQueryBuilder();
		$query->insert($this->table)
			->values(
				[
					'uid' => $query->createNamedParameter($uid),
					'data' => $query->createNamedParameter(json_encode($data)),
				]
			)
			->execute();
	}

	private function getDataFromTable($uid) {
		$query = $this->connection->getQueryBuilder();
		$query->select('data')->from($this->table)
			->where($query->expr()->eq('uid', $query->createParameter('uid')))
			->setParameter('uid', $uid);
		$query->execute();
		$result = $query->execute()->fetchAll();

		if (!empty($result)) {
			return json_decode($result[0]['data'], true);
		}
	}

}
