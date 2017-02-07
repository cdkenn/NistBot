<?php
/**
 * Copyright: Chip Wasson and Collin Kennedy
 * Date: 2/6/2017
 * Time: 2:01 PM
 */
namespace NistBot;
require_once __DIR__ . "/../vendor/autoload.php";

use GorkaLaucirica\HipchatAPIv2Client\Auth\OAuth2;
use GorkaLaucirica\HipchatAPIv2Client\Client;
use GorkaLaucirica\HipchatAPIv2Client\API\RoomAPI;

class Bot
{

    const version = "v0.1";
    /** @var  \PDO $db */
    protected $db;
    public function __construct()
    {
        global $mysql_user, $mysql_pass, $mysql_host, $mysql_db;
        $this->db = new \PDO("mysql:dbname={$mysql_db};host={$mysql_host}",$mysql_user, $mysql_pass);
    }

    /**
     * Log an install to the database given the JSON
     * @param $json
     */
    public function logInstall($json){
        $stmt = $this->db->prepare("UPDATE `install` SET `uninstalled` = 1 WHERE `roomid` = :roomId AND `groupid` = :groupId");
        $stmt->bindParam(":roomId", $json->{'roomId'});
        $stmt->bindParam(":groupId", $json->{'groupId'});
        $stmt->execute();
        $stmt = $this->db->prepare("DELETE FROM `token` WHERE `roomid` = :roomId");
        $stmt->bindParam(":roomId", $json->{'roomId'});
        $stmt->execute();
        $stmt = $this->db->prepare("INSERT INTO `install`(`oauthid`, `capabilitiesUrl`, `roomid`, `groupid`, `oauthSecret`) 
          VALUES (:oauth, :capabilitiesUrl, :roomId, :groupId, :secret)");
        $stmt->bindParam(":oauth", $json->{'oauthId'});
        $stmt->bindParam(":capabilitiesUrl", $json->{'capabilitiesUrl'});
        $stmt->bindParam(":roomId", $json->{'roomId'});
        $stmt->bindParam(":groupId", $json->{'groupId'});
        $stmt->bindParam(":secret", $json->{'oauthSecret'});
        $stmt->execute();
    }


    public function roomInstalled($roomid)
    {
        $stmt = $this->db->prepare("SELECT count(recordid) FROM `install` WHERE `roomid` = :roomid AND `uninstalled` = 0");
        $stmt->bindParam(":roomid", $roomid);
        $stmt->execute();
        return $stmt->rowCount() == 1;
    }

    public function getToken($roomid)
    {
        $reqTime = time();
        //Check DB for non-expired token
        $stmt = $this->db->prepare("SELECT * FROM `token` WHERE `roomid` = :room and `expires` > :now");
        $stmt->bindParam(":room", $roomid);
        $stmt->bindParam(":now", $reqTime);
        $stmt->execute();
        if ($stmt->rowCount() == 1){
            return $stmt->fetch()['accessToken'];
        }
        //No token found, get one
        //Get the token url from the room's capabilities URL
        $stmt = $this->db->prepare("SELECT * FROM `install` WHERE `roomid` = :roomid AND `uninstalled` = 0");
        $stmt->bindParam(":roomid", $roomid);
        $stmt->execute();
        if ($stmt->rowCount() == 0)
        {
            return false;
        }
        $roominfo = $stmt->fetch();
        //curl '<capabilities.oauth2Provider.tokenUrl>' -H 'Content-Type: application/x-www-form-urlencoded' -u <oauthid>:<oauthsecret> --data 'grant_type=client_credentials&scope=<list of scopes>'
        $tokenURL = json_decode(file_get_contents($roominfo['capabilitiesUrl']))->{'capabilities'}->{'oauth2Provider'}->{'tokenUrl'};
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $tokenURL);
        curl_setopt($curl, CURLOPT_USERAGENT,'RemindMeBot Backend');
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($curl, CURLOPT_HTTPHEADER, Array(
            "Content-Type: application/x-www-form-urlencoded",
            "Authorization: Basic " . base64_encode($roominfo['oauthid'].":".$roominfo['oauthSecret'])
        ));
        curl_setopt($curl, CURLOPT_POSTFIELDS, "grant_type=client_credentials&scope=send_notification");
        $result = curl_exec($curl);
        //var_dump($result);

        $result = json_decode($result);
        //var_dump($result);
        curl_close($curl);
        $stmt = $this->db->prepare("INSERT INTO `token`(`requesttime`, `accessToken`, `expires`, `roomid`, `groupid`, `groupname`, `scope`, `type`)
          VALUES (:time, :token, :expires, :room, :group, :name, :scope, :type)");
        $stmt->bindValue(":time",$reqTime);
        $stmt->bindValue(":expires",$reqTime+$result->{'expires_in'});
        $stmt->bindParam(":token", $result->{'access_token'});
        $stmt->bindParam(":room", $roomid);
        $stmt->bindParam(":group", $result->{'group_id'});
        $stmt->bindParam(":name", $result->{'group_name'});
        $stmt->bindParam(":scope", $result->{'scope'});
        $stmt->bindParam(":type", $result->{'token_type'});
        $stmt->execute();
        return $result->{'access_token'};
    }


    public function handleMessage($json)
    {
        $silent = false;
        $roomid = $json->{'item'}->{'room'}->{'id'};
        $message = $json->{'item'}->{'message'}->{'message'};
        $mention = $json->{'item'}->{'message'}->{'from'}->{'mention_name'};
        $message = trim(preg_replace('/^\/nist/', "", $message));
        if (strlen($message) == 0 || $message == "help"){
            $this->sendMessageToRoom($roomid, "Usage: /nist <search type> <component name>
Examples:
/nist name carbon dioxide
Will return the link to the NIST carbon dioxide page searching for carbon dioxide by name
/nist cas 124-38-9
Will return the link tot he NIST cabon dioxide by searching by CAS number", "green");
            return false;
        }
        if ($message == "version"){
            $this->sendMessageToRoom($roomid, "Nist Bot Version ".Bot::version.".","green");
            return false;
        }
        //Check for first-of-command modifiers
        $messageSplit = preg_split("/\s/", $message);
        $reqType="default";
        switch ($messageSplit[0]){
            case "cas":
                $reqType="cas";
                break;
            case "name":
                $reqType="name";
                break;
        }
        $message = preg_replace("/^(cas|formula|name){0,1}\s*/", "", $message);
        switch ($reqType){
            case "cas":
                $message = urlencode($message);
                $nistLink = "http://webbook.nist.gov/cgi/cbook.cgi?ID={$message}&Units=SI";
                break;
            case "name":
                $message = urlencode($message);
                $nistLink = "http://webbook.nist.gov/cgi/cbook.cgi?Name={$message}&Units=SI";
                break;
            default:
                $message = "Not a valid search, use /nist help for examples.";
                $this->sendMessageToRoom($roomid, $message);
                return;
        }
        preg_match('/<li><strong>Chemical\s+structure:<\/strong>\s+<img\s+src=\"([^"]+)/', file_get_contents($nistLink), $matches);
        if(sizeof($matches) > 0){
            $structureURL = "http://webbook.nist.gov".$matches[1];
            $structureURL =  preg_replace("/&amp;/", "&", $structureURL);
            $structureURL = $structureURL."&.jpg";
            $this->sendMessageToRoom($roomid, $nistLink." ".$structureURL);
        }
        else{
            $this->sendMessageToRoom($roomid, $nistLink);
        }

    }

    public function sendMessageToRoom($roomid, $messagein, $color = "yellow")
    {
        $token = $this->getToken($roomid);
        $auth = new OAuth2($token);
        $client = new Client($auth);
        $message = new \GorkaLaucirica\HipchatAPIv2Client\Model\Message();

        $message->setColor($color);
        $message->setMessageFormat("text");
        $message->setMessage($messagein);



        $roomAPI = new RoomAPI($client);
        $room = $roomAPI->sendRoomNotification($roomid, $message);
    }


    /**
     * @return \PDO
     */
    public function getDB(){
        return $this->db;
    }
}