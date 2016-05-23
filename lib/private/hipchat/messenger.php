<?php
/**
 * messenger.php
 * User: PMinkler
 * Date: 5/18/2016
 * Time: 6:22 PM
 */
namespace OC\Hipchat;

use GorkaLaucirica\HipchatAPIv2Client\API\RoomAPI;
use GorkaLaucirica\HipchatAPIv2Client\API\UserAPI;
use GorkaLaucirica\HipchatAPIv2Client\Auth\OAuth2;
use GorkaLaucirica\HipchatAPIv2Client\Client;
use GorkaLaucirica\HipchatAPIv2Client\Model\Message;
use GorkaLaucirica\HipchatAPIv2Client\Model\Room;

/**
 * Class Messenger is the entrance class to the HipchatAPIClient
 */
class Messenger
{
    /**
     * @var RoomAPI The Room API
     */
    private $roomAPI;

    /**
     * @var UserAPI The User API
     */
    private $userAPI;

    /**
     * @var OAuth2 Authetication object
     */
    private $auth;

    /**
     * @var Client Client object
     */
    private $client;

    /**
     * @var string Token used to authenticate
     */
    private $token;

    /**
     * HipchatNotifier constructor.
     *
     * @param string $token Token used to authenticate
     */
    public function __construct($token)
    {
        $this->setToken($token);
        $this->setAuth(new OAuth2($this->getToken()));
        $this->setClient(new Client($this->getAuth()));
        $this->setRoomAPI(new RoomAPI($this->getClient()));
        $this->setUserAPI(new UserAPI($this->getClient()));
    }

    /**
     * @return mixed
     */
    public function getToken()
    {
        return $this->token;
    }

    /**
     * Sets the token
     *
     * @param string $token Authentication token
     *
     * @return void
     */
    public function setToken($token)
    {
        $this->token = $token;
    }

    /**
     * @return mixed
     */
    public function getAuth()
    {
        return $this->auth;
    }

    /**
     * Sets the authentication object
     *
     * @param OAuth2 $auth Authentication object
     *
     * @return void
     */
    public function setAuth($auth)
    {
        $this->auth = $auth;
    }

    /**
     * Returns the Client object
     *
     * @return Client The client object
     */
    public function getClient()
    {
        return $this->client;
    }

    /**
     * Sets the Client object
     *
     * @param Client $client The client object
     *
     * @return void
     */
    public function setClient($client)
    {
        $this->client = $client;
    }

    /**
     * Sends a message to a given room
     *
     * @param string $message The message to send
     * @param string $room    The room to send it to (ID or string)
     *
     * @throws \InvalidArgumentException
     * @return void
     */
    public function sendRoomMessage($message = null, $room = null)
    {
        if ($room === null || $message === null) {
            throw new \InvalidArgumentException('Must provide a room and a message');
        } else {
            $this->getRoomAPI()->sendRoomNotification($this->getRoomID($room), new Message($message));
        }
    }

    /**
     * Gets the Room API
     *
     * @return RoomAPI The Room API
     */
    public function getRoomAPI()
    {
        return $this->roomAPI;
    }

    /**
     * Sets the Room API
     *
     * @param RoomAPI $roomAPI The Room API
     *
     * @return void
     */
    public function setRoomAPI($roomAPI)
    {
        $this->roomAPI = $roomAPI;
    }

    /**
     * @param string $room Room name
     *
     * @return int
     * @throws \InvalidArgumentException
     */
    private function getRoomID($room = null)
    {
        if (null === $room) {
            throw new \InvalidArgumentException('Room must be a string');
        } else {
            $room = $this->getRoomAPI()->getRoom(rawurlencode($room));
            return $room->getId();
        }
    }

    /**
     * Sends a user a message
     *
     * @param string $message Text of the message
     * @param string $user    The user's email address, ID or @...
     *
     * @return void
     * @throws \InvalidArgumentException
     */
    public function sendUserMessage($message = null, $user = null)
    {
        if ($user === null || $message === null) {
            throw new \InvalidArgumentException('Must provide a room and a message');
        } else {
            $this->getUserAPI()->privateMessageUser($user, new Message($message));
        }
    }

    /**
     * Gets the User API
     *
     * @return UserAPI The User API
     */
    public function getUserAPI()
    {
        return $this->userAPI;
    }

    /**
     * Sets the User API
     *
     * @param UserAPI $userAPI The User API object
     *
     * @return void
     */
    public function setUserAPI($userAPI)
    {
        $this->userAPI = $userAPI;
    }
}
