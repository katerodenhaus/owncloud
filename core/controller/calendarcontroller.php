<?php
/**
 * @author    Lukas Reschke <lukas@owncloud.com>
 * @author    Morris Jobke <hey@morrisjobke.de>
 *
 * @copyright Copyright (c) 2016, ownCloud, Inc.
 * @license   AGPL-3.0
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

namespace OC\Core\Controller;

use OC\DB\Connection;
use \OCP\AppFramework\Controller;
use \OCP\AppFramework\Http\JSONResponse;
use OCP\IConfig;
use OCP\IDBConnection;
use \OCP\IRequest;

class CalendarController extends Controller
{
    /**
     * @var IConfig
     */
    private $config;
    private $connection;

    /**
     * @param string   $appName Application name
     * @param IRequest $request an instance of the request
     *
     * @internal param IUserSession $userSession
     * @internal param IConfig $config
     */
    public function __construct($appName, IRequest $request)
    {
        parent::__construct($appName, $request);
        $this->setConnection(\OC::$server->getDatabaseConnection());
    }

    /**
     * get a config value
     *
     * @return JSONResponse
     *
     * @PublicPage
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function getCalendarUsers()
    {
        // Only bother looking up people who want reminders
        $userReminderQuery = $this->getConnection()->getQueryBuilder();
        $userReminderQuery
            ->select(['uid', 'displayname'])
            ->from('users')
            ->where($userReminderQuery->expr()->neq('uid', $userReminderQuery->createNamedParameter('admin')))
            ->groupBy('uid');
        $userStmt = $userReminderQuery->execute();

        return new JSONResponse([
                                    $userStmt->fetchAll()
                                ]);
    }

    /**
     * @return IDBConnection
     */
    public function getConnection()
    {
        return $this->connection;
    }

    /**
     * @param mixed $connection
     */
    public function setConnection($connection)
    {
        $this->connection = $connection;
    }
}
