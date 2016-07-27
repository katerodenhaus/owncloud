<?php

namespace OC\Core\Controller;

use OC\User\Manager;
use OCA\DAV\CalDAV\CalDavBackend;
use OCA\DAV\Connector\Sabre\Principal;
use \OCP\AppFramework\Controller;
use \OCP\AppFramework\Http\JSONResponse;
use OCP\IDBConnection;
use \OCP\IRequest;
use OCP\Security\ISecureRandom;
use Sabre\DAV\Exception;
use Sabre\VObject\Component\VCalendar;

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
     * @var CalDavBackend The CalDAV Backend
     */
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
        $this->setCalDavBackend(
            new CalDavBackend(
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
     * @param mixed $calDavBackend The CalDAV Backend from Owncloud
     *
     * @return void
     */
    public function setCalDavBackend($calDavBackend)
    {
        $this->calDavBackend = $calDavBackend;
    }

    /**
     * Gets all calendars that a user has
     *
     * @param string $user The user to get calendars for, "foo@bar.com"
     * @param string $name The calendar name
     *
     * @PublicPage
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse
     */
    public function getUserCalendarByName($user, $name)
    {
        try {
            $return['calendars'] = $this->getCalDavBackend()->getCalendarByName($user, $name);
            $return['success']   = true;
        } catch (Exception $e) {
            $return['success'] = false;
        }

        return new JSONResponse($return);
    }

    /**
     * Gets all calendars that a user has
     *
     * @param string $user The user to get calendars for, "foo@bar.com"
     * @param string $name The calendar name
     *
     * @PublicPage
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse
     */
    public function getCalendarEventObjectByUid($uid)
    {
        try {
            $return['event']   = $this->getCalDavBackend()->getCalendarObjectByUri($uid);
            $return['success'] = true;
        } catch (\UnexpectedValueException $e) {
            $return['success'] = false;
        }

        return new JSONResponse($return);
    }

    /**
     * Creates a calendar object on a user's calendar with a specific link
     *
     * @param string $user     The user, "foo@bar.com"
     * @param string $calendar The calendar's exact display name
     * @param int    $start    Timestamp of event's start
     * @param int    $end      Timestamp of event's end
     *
     * @PublicPage
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse
     * @throws \UnexpectedValueException
     * @throws \Sabre\DAV\Exception\BadRequest
     * @throws \InvalidArgumentException
     */
    public function bookUserEvent($user, $calendar, $start, $end)
    {
        $return = [];
        $link   = $this->request->getParam('link');

        $calendarObject = $this->getCalDavBackend()->getCalendarByName($user, $calendar);
        $calendarData   = $this->generateCalendarData($start, $end, $link);
        if ($calendarObject === null) {
            // Calendar was not found.  Attempt to create it first.
            try {
                $calendarObject['id'] = $this->getCalDavBackend()->createCalendar($user, $calendar, []);
            } catch (\Exception $e) {
                $return['error']   = "Could not create calendar for user: $user, " . $e->getMessage();
                $return['success'] = false;
            }
        }

        // Calendar was found/made, add the event
        try {
            $eventObjectID     = $this->getUniqueID();
            $return['etag']    = $this->getCalDavBackend()->createCalendarObject(
                $calendarObject['id'],
                $eventObjectID,
                $calendarData,
                $link
            );
            $return['uri']     = $eventObjectID;
            $return['success'] = true;
        } catch (\InvalidArgumentException $e) {
            $return['error']   = 'Could not create event: ' . $e->getMessage();
            $return['success'] = false;
        } catch (\UnexpectedValueException $e) {
            $return['error']   = 'Could not create event: ' . $e->getMessage();
            $return['success'] = false;
        } catch (Exception\BadRequest $e) {
            $return['error']   = 'Could not create event: ' . $e->getMessage();
            $return['success'] = false;
        }

        return new JSONResponse($return);
    }

    /**
     * Generates the iCAL Calendar Data
     *
     * @param int    $start Timestamp of start
     * @param int    $end   Timestamp of end
     * @param string $link  Link URL
     *
     * @return string
     */
    private function generateCalendarData($start, $end, $link)
    {
        // Get DateTimes sorted
        $startDate = new \DateTime();
        $endDate   = new \DateTime();
        $startDate->setTimestamp($start);
        $endDate->setTimestamp($end);

        // Get VCalendar made, then use it to create an event
        $vCal          = new VCalendar();
        $vCal->VERSION = '2.0';
        $vEvent        = $vCal->createComponent('VEVENT');

        // Event start
        $vEvent->add('DTSTART');
        $vEvent->DTSTART->setDateTime(
            $startDate
        );
        $vEvent->DTSTART['VALUE'] = 'DATE-TIME';

        // Event end
        $vEvent->add('DTEND');
        $vEvent->DTEND->setDateTime(
            $endDate
        );
        $vEvent->DTEND['VALUE'] = 'DATE-TIME';

        // Other required fields
        $vEvent->{'UID'}     = $this->getUniqueID();
        $vEvent->{'SUMMARY'} = 'Scheduled CMR';
        $vEvent->{'TRANSP'}  = 'TRANSPARENT';
        $vEvent->{'URL'}     = $link;
        $vCal->add($vEvent);

        return $vCal->serialize();
    }

    /**
     * @param int $length Length of string to generate
     *
     * @return string
     */
    private function getUniqueID($length = 20)
    {
        return \OC::$server->getConfig()->getAppValue('calendar', 'uri_prefix') .
        \OC::$server->getSecureRandom()->generate(
            $length,
            // Do not use dots and slashes as we use the value for file names
            ISecureRandom::CHAR_DIGITS . ISecureRandom::CHAR_LOWER . ISecureRandom::CHAR_UPPER
        );
    }
}


