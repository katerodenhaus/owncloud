<?php

namespace OC\Core\Controller;

use OC\User\Manager;
use OCA\DAV\CalDAV\CalDavBackend;
use OCA\DAV\Connector\Sabre\Principal;
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
    private $calDavBackend;

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
        $this->setCalDavBackend(new CalDavBackend(
                                    $this->getConnection(),
                                    new Principal(
                                        new Manager(),
                                        new \OC\Group\Manager(
                                            new Manager()
                                        ),
                                        'principals'
                                    )
                                )
        );
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
     * @return IDBConnection
     */
    private function getConnection()
    {
        return $this->connection;
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
        $query = $this->getConnection()->getQueryBuilder();
        $query
            ->select(['uid', 'displayname'])
            ->from('users')
            ->where($query->expr()->neq('uid', $query->createNamedParameter('admin')))
            ->andWhere($query->expr()->neq('uid', $query->createNamedParameter('css_admin')))
            ->groupBy('uid');
        $userStmt          = $query->execute();
        $result['users']   = $userStmt->fetchAll();
        $result['success'] = true;

        return new JSONResponse($result);
    }

    /**
     * Gets events for a user
     *
     * @param string $user User to search for
     * @param bool   $past Whether or not to include past events
     *
     * @PublicPage
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse
     * @throws \UnexpectedValueException
     * @throws \Sabre\DAV\Exception
     * @throws \Sabre\DAV\Exception\BadRequest
     */
    public function getUserEvents($user, $past = false)
    {
        // First, we have to get all calendars
        $calendars = $this->getCalDavBackend()->getCalendarsForUser($user);

        // Then we have to get all events.  Sadly, we can't go by first occurence and last occurence because
        // if there are recurring events, those timestamps could be way outside the range but still have
        // events inside the range.  The only thing we can do is filter out events whose last occurence has already
        // passed
        $return_events = [];
        foreach ($calendars as $calendar) {
            $events = $this->getCalDavBackend()->getCalendarObjects($calendar['id'], $past);

            // Finally we can iterate over the events and see which ones are in the range!
            foreach ($events as $event) {
                $event = $this->getCalDavBackend()->getCalendarObject($calendar['id'], $event['uri']);

                // For this event object to be even slightly useful, we need to decypher the calendarData iCAL object
                $event = array_merge($event, $this->getCalDavBackend()->getAllEventData($event['calendardata']));

                // Is this recurring or not?
                if (count($event['occurrences']) === 0) {
                    $return_events[] = [
                        'start'    => $event['firstOccurence'],
                        'end'      => $event['lastOccurence'],
                        'timezone' => $event['timezone']
                    ];
                } else {
                    foreach ($event['occurrences'] as $occurrence) {
                        $return_events[] = [
                            'start'    => $occurrence['start']->getTimestamp(),
                            'end'      => $occurrence['end']->getTimestamp(),
                            'timezone' => $occurrence['start']->getTimezone()->getName()
                        ];
                    }
                }
            }
        }

        $return = [
            'events'  => $return_events,
            'success' => true
        ];

        return new JSONResponse($return);
    }

    /**
     * @return CalDavBackend
     */
    public function getCalDavBackend()
    {
        return $this->calDavBackend;
    }

    /**
     * @param mixed $calDavBackend
     */
    public function setCalDavBackend($calDavBackend)
    {
        $this->calDavBackend = $calDavBackend;
    }
}
