<?php
/**
 * @author    Thomas Müller <thomas.mueller@tmit.eu>
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

namespace OC;

use Coduo\PHPHumanizer\DateTimeHumanizer;
use OC\Encryption\CalCrypt;
use OC\Hipchat\Messenger;
use OCP\IDBConnection;
use Sabre\DAV\Exception\BadRequest;
use Sabre\VObject\DateTimeParser;
use Sabre\VObject\Property\FlatText;
use Sabre\VObject\Reader;
use Sabre\VObject\Recur\EventIterator;

require_once __DIR__ . '/lib/base.php';
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ .
    '/3rdparty/gorkalaucirica/hipchat-v2-api-client/GorkaLaucirica/HipchatAPIv2Client/API/RoomAPI.php';
require_once __DIR__ .
    '/3rdparty/gorkalaucirica/hipchat-v2-api-client/GorkaLaucirica/HipchatAPIv2Client/API/UserAPI.php';
require_once __DIR__ .
    '/3rdparty/gorkalaucirica/hipchat-v2-api-client/GorkaLaucirica/HipchatAPIv2Client/Auth/AuthInterface.php';
require_once __DIR__ .
    '/3rdparty/gorkalaucirica/hipchat-v2-api-client/GorkaLaucirica/HipchatAPIv2Client/Auth/OAuth2.php';
require_once __DIR__ . '/3rdparty/gorkalaucirica/hipchat-v2-api-client/GorkaLaucirica/HipchatAPIv2Client/Client.php';
require_once __DIR__ .
    '/3rdparty/gorkalaucirica/hipchat-v2-api-client/GorkaLaucirica/HipchatAPIv2Client/Exception/RequestException.php';
require_once __DIR__ .
    '/3rdparty/gorkalaucirica/hipchat-v2-api-client/GorkaLaucirica/HipchatAPIv2Client/Model/Message.php';
require_once __DIR__ .
    '/3rdparty/gorkalaucirica/hipchat-v2-api-client/GorkaLaucirica/HipchatAPIv2Client/Model/Room.php';
require_once __DIR__ .
    '/3rdparty/gorkalaucirica/hipchat-v2-api-client/GorkaLaucirica/HipchatAPIv2Client/Model/User.php';
require_once __DIR__ .
    '/3rdparty/gorkalaucirica/hipchat-v2-api-client/GorkaLaucirica/HipchatAPIv2Client/Model/Webhook.php';

/**
 * Class ReminderRunner, when run, will check if any upcoming events need to have their reminders sent out
 */
class ReminderRunner
{
    /**
     * @var array $users Users who wanted reminders
     */
    private $users;
    /**
     * @var string $maxTime Date in the future to remind up until
     */
    private $maxTime;
    /**
     * @var IDBConnection $connection Database connection
     */
    private $connection;
    /**
     * @var Messenger $hipchatMessenger Hipchat messenger
     */
    private $hipchatMessenger;
    /**
     * @var array $calendars Array of calendars to consider
     */
    private $calendars;
    /**
     * @var array $events Events to consider
     */
    private $events;
    /**
     * @var array $data Compiled data to iterate over
     */
    private $data;

    /**
     * ReminderRunner constructor.
     *
     * @throws \UnexpectedValueException
     * @throws \Sabre\DAV\Exception\BadRequest
     */
    public function __construct()
    {
        date_default_timezone_set('America/New_York');
        $this->setConnection(\OC::$server->getDatabaseConnection());
        $this->setHipchatMessenger(new Messenger(\OC::$server->getConfig()->getSystemValue('hipchat_token')));
        $this->getAllEligibleUsers();
        $this->getCalendarsForUsers();
        $this->getEligibleEventsFromCalendars();
        $this->setMaxTime(date('Y-m-d', strtotime('+1 day', time())));
        $this->compileData();
    }

    /**
     * @return array
     */
    private function getAllEligibleUsers()
    {
        // Only bother looking up people who want reminders
        $userReminderQuery = $this->connection->getQueryBuilder();
        $userReminderQuery
            ->select(['userid', 'configkey', 'configvalue'])
            ->from('preferences')
            ->where($userReminderQuery->expr()->eq('appid', $userReminderQuery->createNamedParameter('calendar')))
            ->andWhere($userReminderQuery->expr()->orX(
                "configkey = 'reminderEmail' AND configvalue = 'on'",
                "configkey = 'reminderHipchat' AND configvalue = 'on'"
            ))
            ->groupBy('userid');
        $userStmt = $userReminderQuery->execute();

        $this->setUsers($userStmt->fetchAll());
    }

    /**
     * This will get calendars for the user
     *
     * @return array
     */
    private function getCalendarsForUsers()
    {
        // Get their calendars
        $allCalendars = [];
        foreach ($this->getUsers() as $user) {
            $calendarQuery = $this->connection->getQueryBuilder();
            $calendarQuery
                ->select(['id', 'principaluri', 'calendarcolor', 'displayname'])
                ->from('calendars')
                ->where(
                    $calendarQuery->expr()->eq(
                        'principaluri',
                        $calendarQuery->createNamedParameter(
                            "principals/users/$user[userid]"
                        )
                    )
                );
            $calendarStmt = $calendarQuery->execute();
            $calendars    = $calendarStmt->fetchAll();

            foreach ($calendars as $calendar) {
                $allCalendars[] = $calendar;
            }
        }

        $this->setCalendars($allCalendars);
    }

    /**
     * Gets all events to consider
     *
     * @return array
     * @throws \UnexpectedValueException
     */
    private function getEligibleEventsFromCalendars()
    {
        foreach ($this->getCalendars() as $calendar) {
            // Get events where the first and last occurence are outside of "now"
            $query  = $this->connection->getQueryBuilder();
            $fields = [
                'calendarData',
                'calendarid'
            ];

            $query->select($fields)
                ->andWhere($query->expr()->eq('calendarid', $query->createNamedParameter($calendar['id'])))
                ->from('calendarobjects');

            if (\OC::$server->getConfig()->getSystemValue('encrypt_cal', false)) {
                $decryptQuery = new CalCrypt($query);
                $decryptQuery->decryptData();
            }

            $stmt = $query->execute();
            $this->appendEvents($stmt->fetchAll());
        }
    }

    /**
     * Compiles data from the calendars and events to iterate over
     *
     * @return void
     * @throws \Sabre\DAV\Exception\BadRequest
     */
    private function compileData()
    {
        $data = [];
        foreach ($this->getCalendars() as $calendar) {
            foreach ($this->getEvents() as $event) {
                if ($calendar['id'] === $event['calendarid']) {
                    $principal          = str_replace('principals/users/', '', $calendar['principaluri']);
                    $data[$principal][] = [
                        'calendarData'        => $this->getDenormalizedData($event['calendarData']),
                        'calendarColor'       => $calendar['calendarcolor'],
                        'calendarDisplayName' => $calendar['displayname']
                    ];
                }
            }
        }
        $this->setData($data);
    }

    /**
     * @return mixed
     */
    public function getUsers()
    {
        return $this->users;
    }

    /**
     * @param array $users Users who wanted to be reminded
     *
     * @return void
     */
    public function setUsers($users)
    {
        $this->users = $users;
    }

    /**
     * @return array
     */
    public function getCalendars()
    {
        return $this->calendars;
    }

    /**
     * @param array $calendars Calendars to consider
     *
     * @return void
     */
    public function setCalendars($calendars)
    {
        $this->calendars = $calendars;
    }

    /**
     * @param array $events Events to add
     *
     * @return void
     */
    public function appendEvents($events)
    {
        foreach ($events as $event) {
            $this->events[] = $event;
        }
    }

    /**
     * @return mixed
     */
    public function getEvents()
    {
        return $this->events;
    }

    /**
     * @param array $events Events to consider
     *
     * @return void
     */
    public function setEvents($events)
    {
        $this->events = $events;
    }

    /**
     * @param $calendarData
     *
     * @return array
     * @throws \Sabre\DAV\Exception\BadRequest
     */
    private function getDenormalizedData($calendarData)
    {
        $vObject        = Reader::read($calendarData);
        $componentType  = null;
        $component      = null;
        $firstOccurence = null;
        $lastOccurence  = null;
        $uid            = null;
        $occurrences    = [];

        foreach ($vObject->getComponents() as $component) {
            if ($component->name !== 'VTIMEZONE') {
                $componentType = $component->name;
                $uid           = (string)$component->UID;
                break;
            }
        }
        if (!$componentType) {
            throw new BadRequest(
                'Calendar objects must have a VJOURNAL, VEVENT or VTODO component'
            );
        }
        if ($componentType === 'VEVENT' && $component->DTSTART) {
            $firstOccurence = $component->DTSTART->getDateTime()->getTimestamp();
            // Finding the last occurence is a bit harder
            if (!isset($component->RRULE)) {
                if (isset($component->DTEND)) {
                    $lastOccurence = $component->DTEND->getDateTime()->getTimestamp();
                } elseif (isset($component->DURATION)) {
                    $endDate = clone $component->DTSTART->getDateTime();
                    $endDate->add(DateTimeParser::parse($component->DURATION->getValue()));
                    $lastOccurence = $endDate->getTimestamp();
                } elseif (!$component->DTSTART->hasTime()) {
                    $endDate = clone $component->DTSTART->getDateTime();
                    $endDate->modify('+1 day');
                    $lastOccurence = $endDate->getTimestamp();
                } else {
                    $lastOccurence = $firstOccurence;
                }
            } else {
                $iterator = new EventIterator($vObject, (string)$component->UID);
                $maxDate  = new \DateTime($this->getMaxTime());
                $end      = $iterator->getDtEnd();
                while ($iterator->valid() && $end < $maxDate) {
                    $occurrences[] = [
                        'start'   => $iterator->getDtStart(),
                        'counter' => $iterator->key()
                    ];
                    $iterator->next();
                    $end = $iterator->getDtEnd();
                }
                $lastOccurence = $end->getTimestamp();
            }
        }

        return [
            'etag'           => md5($calendarData),
            'size'           => strlen($calendarData),
            'componentType'  => $componentType,
            'firstOccurence' => $firstOccurence === null ? null : max(0, $firstOccurence),
            'occurrences'    => $occurrences,
            'lastOccurence'  => $lastOccurence,
            'uid'            => $uid,
            'eventTitle'     => array_values($component->select('SUMMARY'))[0]
        ];
    }

    /**
     * @return mixed
     */
    public function getMaxTime()
    {
        return $this->maxTime;
    }

    /**
     * @param mixed $maxTime Maximum time to look ahead in the future
     *
     * @return void
     */
    public function setMaxTime($maxTime)
    {
        $this->maxTime = $maxTime;
    }

    /**
     * @return mixed
     */
    public function getHipchatMessenger()
    {
        return $this->hipchatMessenger;
    }

    /**
     * @param Messenger $hipchatMessenger Hipchat Messenger
     *
     * @return void
     */
    public function setHipchatMessenger($hipchatMessenger)
    {
        $this->hipchatMessenger = $hipchatMessenger;
    }

    /**
     * @return mixed
     */
    public function getConnection()
    {
        return $this->connection;
    }

    /**
     * @param IDBConnection $connection Database connection
     *
     * @return void
     */
    public function setConnection($connection)
    {
        $this->connection = $connection;
    }

    /**
     * Runs the process
     *
     * @return void
     * @throws \InvalidArgumentException
     * @throws \OC\DatabaseException
     * @throws \OCP\PreConditionNotMetException
     * @throws \Sabre\DAV\Exception\BadRequest
     */
    public function run()
    {
        $eventData        = $this->getData();
        $users            = $this->getUsers();
        $now              = new \DateTime();
        $recurring        = true;
        $reminderTime     = null;
        $start            = null;
        $timeDifference = null;

        foreach ($users as $user) {
            $reminders    = \OC::$server->getUserManager()->get($user['userid'])->getReminders();
            $reminderMins = $reminders['reminderMins'];
            foreach ($eventData[$user['userid']] as $userEventData) {
                $eventUri = $userEventData['calendarData']['uid'];
                if (count($userEventData['calendarData']['occurrences']) === 0) {
                    if (!$this->wasEventInstanceReminded($eventUri, 00)) {
                        // This is a single event
                        $recurring    = false;
                        $reminderTime = new \DateTime();
                        $start        = new \DateTime();
                        $start->setTimestamp($userEventData['calendarData']['firstOccurence']);
                        $reminderTime
                            ->setTimestamp($userEventData['calendarData']['firstOccurence'])
                            ->modify("-$reminderMins minutes");
                    }
                } else {
                    // This is a recurring event
                    foreach ($userEventData['calendarData']['occurrences'] as $occurrence) {
                        // We have to make sure this count of the master event was not already reminded
                        // Unfortunately, we have no way of knowing this ahead of time since the data is all
                        // packed away in the iCAL data
                        if (!$this->wasEventInstanceReminded($eventUri, $occurrence['counter'])) {
                            $start        = clone $occurrence['start'];
                            $reminderTime = clone $occurrence['start'];
                            $reminderTime->modify("-$reminderMins minutes");
                        }
                    }
                }

                $timeDifference = DateTimeHumanizer::difference($now, $start);
                // We are between when we should remind the user and when the event starts
                if ($reminderTime <= $now && $start > $now) {
                    // I should be reminding!
                    $this->handleNotifications($timeDifference, $userEventData['calendarData'], $user, $reminders);
                    // Log the reminder history
                    $counter = $recurring ? $occurrence['counter'] : 0;
                    if (!$this->logReminder($eventUri, $counter)) {
                        throw new DatabaseException('Could not log that a reminder was made');
                    }
                }
            }
        }
    }

    /**
     * @return mixed
     */
    public function getData()
    {
        return $this->data;
    }

    /**
     * @param mixed $data The iterator data
     *
     * @return void
     */
    public function setData($data)
    {
        $this->data = $data;
    }

    /**
     * @param string $eventUri The master event's URI
     * @param int    $instance The instance of the event (for recurring events)
     *
     * @return bool
     */
    public function wasEventInstanceReminded($eventUri, $instance)
    {
        $query = $this->connection->getQueryBuilder();
        $query
            ->select('event_uri')
            ->from('reminders')
            ->where($query->expr()->eq('event_uri', $query->createNamedParameter($eventUri)))
            ->andWhere($query->expr()->eq('instance', $query->createNamedParameter($instance)))
            ->setMaxResults(1);
        $results = $query->execute()->fetchAll();

        return count($results) > 0;
    }

    /**
     * @param int      $timeDifference Minutes remaining until event starts
     * @param FlatText $calendarData   Calendardata object
     * @param string   $user           User's email address
     * @param array    $reminders      User's reminder settings
     *
     * @return void
     * @throws \InvalidArgumentException
     */
    private function handleNotifications($timeDifference, $calendarData, $user, $reminders)
    {
        $eventTitle  = $calendarData['eventTitle']->getValue();
        $messageText = "You have an upcoming appointment, $eventTitle, starting $timeDifference";
        $email = str_replace('.css', '.com', $user['userid']);
        if ($reminders['reminderHipchat'] === 'on') {
            $this->hipchatNotification($email, $messageText);
        }
        if ($reminders['reminderEmail'] === 'on') {
            $this->emailNotification($email, $messageText);
        }
    }

    /**
     * @param string $eventUri The master event's URI
     * @param int    $instance The instance number of the event
     *
     * @return \Doctrine\DBAL\Driver\Statement|int
     */
    private function logReminder($eventUri, $instance)
    {
        $query = $this->connection->getQueryBuilder();
        $query
            ->insert('reminders')
            ->setValue('event_uri', $query->createNamedParameter($eventUri))
            ->setValue('instance', $query->createNamedParameter($instance));

        return $query->execute();
    }

    /**
     * @param array  $user        User information
     * @param string $messagetext Message to send
     *
     * @return void
     * @throws \InvalidArgumentException
     */
    private function hipchatNotification($user, $messagetext)
    {
        $messenger = new Messenger(\OC::$server->getConfig()->getSystemValue('hipchat_token'));
        $message   = [
            'message_format' => 'html',
            'color'          => 'purple',
            'notify'         => true,
            'message'        => $messagetext
        ];
        $messenger->sendUserMessage($message, $user);
    }

    /**
     * Sends an email notification to a user
     *
     * @param string $user        Email address to send to
     * @param string $messagetext Message body to send
     *
     * @return void
     */
    private function emailNotification($user, $messagetext)
    {
        $headers = 'From: ' . \OC::$server->getConfig()->getSystemValue('email_from_name');
        mail($user, 'Upcoming appointment', $messagetext, $headers);
    }

    /**
     * @param array $calendars Calendars to add
     *
     * @return void
     */
    public function appendCalendars($calendars)
    {
        foreach ($calendars as $calendar) {
            $this->calendars[] = $calendar;
        }
    }
}

$notify = new ReminderRunner();
$notify->run();
