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

use GorkaLaucirica\HipchatAPIv2Client\Model\Message;
use OC\Encryption\CalCrypt;
use OC\Hipchat\Messenger;
use OCA\DAV\CalDAV\CalDavBackend;
use Sabre\VObject\DateTimeParser;
use Sabre\VObject\Reader;
use Sabre\VObject\RecurrenceIterator;

require_once '/var/www/html/owncloud/lib/base.php';
require_once '/var/www/html/owncloud/3rdparty/gorkalaucirica/hipchat-v2-api-client/GorkaLaucirica/HipchatAPIv2Client/API/RoomAPI.php';
require_once '/var/www/html/owncloud/3rdparty/gorkalaucirica/hipchat-v2-api-client/GorkaLaucirica/HipchatAPIv2Client/API/UserAPI.php';
require_once '/var/www/html/owncloud/3rdparty/gorkalaucirica/hipchat-v2-api-client/GorkaLaucirica/HipchatAPIv2Client/Auth/AuthInterface.php';
require_once '/var/www/html/owncloud/3rdparty/gorkalaucirica/hipchat-v2-api-client/GorkaLaucirica/HipchatAPIv2Client/Auth/OAuth2.php';
require_once '/var/www/html/owncloud/3rdparty/gorkalaucirica/hipchat-v2-api-client/GorkaLaucirica/HipchatAPIv2Client/Client.php';
require_once '/var/www/html/owncloud/3rdparty/gorkalaucirica/hipchat-v2-api-client/GorkaLaucirica/HipchatAPIv2Client/Exception/RequestException.php';
require_once '/var/www/html/owncloud/3rdparty/gorkalaucirica/hipchat-v2-api-client/GorkaLaucirica/HipchatAPIv2Client/Model/Message.php';
require_once '/var/www/html/owncloud/3rdparty/gorkalaucirica/hipchat-v2-api-client/GorkaLaucirica/HipchatAPIv2Client/Model/Room.php';
require_once '/var/www/html/owncloud/3rdparty/gorkalaucirica/hipchat-v2-api-client/GorkaLaucirica/HipchatAPIv2Client/Model/User.php';
require_once '/var/www/html/owncloud/3rdparty/gorkalaucirica/hipchat-v2-api-client/GorkaLaucirica/HipchatAPIv2Client/Model/Webhook.php';

class HipchatNotify
{

    /**
     * @var int $interval How often to run
     */
    private $interval;

    /**
     * @return mixed
     */
    public function getInterval()
    {
        return $this->interval;
    }

    /**
     * @param mixed $interval
     */
    public function setInterval($interval)
    {
        $this->interval = $interval;
    }

    public function run()
    {
        $messenger = new Messenger(\OC::$server->getConfig()->getSystemValue('hipchat_token'));
        $this->setInterval(30);

        $connection = \OC::$server->getDatabaseConnection();
        $query = $connection->getQueryBuilder();
        $fields = [
            'calendarData',
            'calendarid'
        ];
        $query->select($fields)
            ->where($query->expr()->eq('id',  $query->createNamedParameter('428')))
            ->from('calendarobjects')
            ->setMaxResults(1);
        if (\OC::$server->getConfig()->getSystemValue('encrypt_cal', false)) {
            $decryptQuery = new CalCrypt($query);
            $decryptQuery->decryptData();
        }
        $stmt = $query->execute();
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        $start = $this->getDenormalizedData($row['calendarData'])['firstOccurence'];

        // Time to notify?
        if (($start - time()) < $this->getInterval() * 60) {
            $message = [
                'from' => 'Ownpathfinder',
                'message_format' => 'html',
                'color' => 'purple',
                'notify' => true,
                'message' => 'You have an appointment coming up!'
            ];

            // Making fields a comma-delimited list
            $query = $connection->getQueryBuilder();
            $query->select(['principaluri'])->from('calendars')
                ->where($query->expr()->eq('id', $query->createNamedParameter($row['calendarid'])))
                ->setMaxResults(1);
            $stmt = $query->execute();
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            $principal = explode('principals/users/', $row['principaluri']);

            $messenger->sendUserMessage($message, 'pminkler@csshealth.com');
        }
    }

    private function getDenormalizedData($calendarData)
    {
        $vObject = Reader::read($calendarData);
        $componentType = null;
        $component = null;
        $firstOccurence = null;
        $lastOccurence = null;
        $uid = null;
        foreach ($vObject->getComponents() as $component) {
            if ($component->name !== 'VTIMEZONE') {
                $componentType = $component->name;
                $uid = (string)$component->UID;
                break;
            }
        }
        if (!$componentType) {
            throw new \Sabre\DAV\Exception\BadRequest('Calendar objects must have a VJOURNAL, VEVENT or VTODO component');
        }
        if ($componentType === 'VEVENT' && $component->DTSTART) {
            $firstOccurence = $component->DTSTART->getDateTime()->getTimeStamp();
            // Finding the last occurence is a bit harder
            if (!isset($component->RRULE)) {
                if (isset($component->DTEND)) {
                    $lastOccurence = $component->DTEND->getDateTime()->getTimeStamp();
                } elseif (isset($component->DURATION)) {
                    $endDate = clone $component->DTSTART->getDateTime();
                    $endDate->add(DateTimeParser::parse($component->DURATION->getValue()));
                    $lastOccurence = $endDate->getTimeStamp();
                } elseif (!$component->DTSTART->hasTime()) {
                    $endDate = clone $component->DTSTART->getDateTime();
                    $endDate->modify('+1 day');
                    $lastOccurence = $endDate->getTimeStamp();
                } else {
                    $lastOccurence = $firstOccurence;
                }
            } else {
                $it = new RecurrenceIterator($vObject, (string)$component->UID);
                $maxDate = new \DateTime(self::MAX_DATE);
                if ($it->isInfinite()) {
                    $lastOccurence = $maxDate->getTimeStamp();
                } else {
                    $end = $it->getDtEnd();
                    while ($it->valid() && $end < $maxDate) {
                        $end = $it->getDtEnd();
                        $it->next();

                    }
                    $lastOccurence = $end->getTimeStamp();
                }

            }
        }

        return [
            'etag' => md5($calendarData),
            'size' => strlen($calendarData),
            'componentType' => $componentType,
            'firstOccurence' => is_null($firstOccurence) ? null : max(0, $firstOccurence),
            'lastOccurence' => $lastOccurence,
            'uid' => $uid,
        ];

    }
}

$notify = new HipchatNotify();
$notify->run();
