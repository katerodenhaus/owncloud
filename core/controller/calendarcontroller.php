<?php

namespace OC\Core\Controller;

use \OCP\AppFramework\Controller;
use \OCP\AppFramework\Http\JSONResponse;
use OCP\IDBConnection;
use \OCP\IRequest;

/**
 * Class CalendarController
 */
class CalendarController extends Controller
{
    /**
     * @var IDBConnection Database connection
     */
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
     * @param mixed $connection Database connection
     *
     * @return void
     */
    private function setConnection($connection)
    {
        $this->connection = $connection;
    }

    /**
     * Get a config value
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
    private function getConnection()
    {
        return $this->connection;
    }
}
