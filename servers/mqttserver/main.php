<?php

/*
	TODO: Brake this down into classes and logical components.
	TODO: Maybe add a buffer for failed broadcasts so that they can be sent later on again.
*/
chdir (__DIR__);
require 'frameworks/MQTT/phpMQTT.php';
require '../../frameworks/wsclient/Client.php';

use \Lora\Server\Command as Command;
$loop = true;
$dserver = DataServer::instance ();
$dserver->start ($loop);
exit ();
/*
$data = json_decode ('{ "_id" : ObjectId("58f5f1747f3a9d09b0e183db"), "app_id" : "not_an_application", "dev_id" : "lorawan_asd", "hardware_serial" : "0039ABFB7C0F69F5", "port" : 1, "counter" : 31, "payload_raw" : "aGVsbG8gamlza2E=", "metadata" : { "time" : "2017-03-28T12:37:41.695112344Z", "frequency" : 868.1, "modulation" : "LORA", "data_rate" : "SF7BW125", "coding_rate" : "4/5", "gateways" : [ { "gtw_id" : "eui-000000000000beef", "timestamp" : 2169582635, "time" : "", "channel" : 0, "rssi" : -119, "snr" : -6.5, "rf_chain" : 1 } ] }, "topic" : "not_an_application/devices/lorawan_asd/up", "time" : 1490704662, "payload" : "hello jiska\u0000" }');

while (true) {
	procmsg ('waa', $data);
}
exit ();
*/

function procmsg ($topic, $msg) {
	global $dserver;
	msg_print ("Msg Received: ".date ("r")."\nTopic:${topic}\n${msg}\n");
	DataServer::instance ()->process ($topic, $msg);
}

class DataServer
{

	private static $instance;
	private $mongo				= null,
			$mqtt_client 		= null,
			$confs 				= null,
			$rt_client 			= null,
			$rt_address 		= '',
			$allowPrint			= false;

	private function __construct () {
		$this->confs = \Lora\Config::instance ();
		$this->rt_address = "ws://localhost:".$this->confs->get ('server', 'intern_port');
	}

	public static function instance() {
		return self::$instance ?? (self::$instance = new self);
	}
	public function start ($loop = true) {
		$topics ['#'] = [ "qos" => 0, "function" => "procmsg" ];
		$this->print ("Establishing TTN connection.");
		if (!$this->MQTTConnect ()) {
			$this->print ("Could not establish connection to TTN.");
			return false;
		}
		$this->print ("Connection to TTN server established. Subscribing to channels...");
		$this->mqtt_client->subscribe ($topics);
		// $this->mqtt_client->debug = true;
		$this->print ("Subscribing complete. Listening for messages.".PHP_EOL);
		if ($loop) {
			while ($this->mqtt_client->proc ());
			$this->mqtt_client->close ();
			$this->rt_client->close ();
		}
	}

	public function process (string $topic, string $msg) {
		$topic = $this->parseTopic ($topic);
		if (($data = $this->parseData ($msg)) !== null) {
			$measurements = $this->parsePayload ($data ['payload']);
			$this->print ("Msg Received: ".date ("r")."\nTopic:".print_r ($topic, true)."\n".print_r ($data, true).PHP_EOL);
			$data ['topic'] = $topic;
			$this->insert ($data, $measurements);
			$this->broadcast ($data, $measurements);
		}
	}

	# TODO: Internal message processing (terminate).
	private function broadcast ($data) {
		$this->print ("Broadcasting data.");
		if (!$this->rt_client) {
			$this->print ("Establishing realtime server connection; {$this->rt_address}");
			if (!$this->rtConnect ()) {
				$this->print ("Could not establish realtime connection.");
				return false;
				
			}
			$this->print ("Realtime connection established.");
		}
		$msg = null;
		$data = $this->craftRtPackage (Command::DATA, $data);
		if (!$this->rt_client->send ($data)) {
			if (!$this->rt_client->isConnected ()) {
				$this->print ($this->rt_client->lastError ());
			}
			return false;
		}
		$this->rt_client->receive ($msg);
		if ($msg === 'terminate') {
			$this->rt_client->close ();
			return false;
		}
		$this->print ($msg);
		return true;
	}

	private function rtConnect () {
		if ($this->rt_client) {
			return true;
		}
		$this->rt_client = new \WebSocket\Client ($this->rt_address);
		return $this->rt_client->connect ();
	}

	private function craftRtPackage (int $type, array $data) : string {
		switch ($type) {
			case Command::DATA:
					return "{$type}:".json_encode ($data);
				break;
			case Command::COMMAND:
					return "{$type}:".json_encode ($data);
				break;
		}
	}

	private function parseTopic ($topic) {
		$topic = explode ('/', $topic);
		$keys = [
			'application',
			'source',
			'device',
			'message',
			'event'
		];
		foreach ($topic as $key => $value) {
			$topic [$keys [$key]] = $value;
			unset ($topic [$key]);
		}
		return $topic;
	}

	private function parseData ($data) {
		# TODO: Timezone fuckery.
		$required = [
			'metadata',
			'dev_id',
			'hardware_serial',
			'payload_raw'
		];
		$requiredMeta = [
			'time'
		];
		$data = json_decode ($data, true);
		if ($data === null) {
			return null;
		}
		foreach ($required as $r) {
			if (!isset ($data [$r])) {
				return null;
			}
		}
		$datetime = DateTime::createFromFormat('Y-m-d\TH:i:s+', $data ['metadata']['time']);
		if ($datetime === false) {
			return null;
		}
		$data ['time'] = $datetime->getTimestamp ();
		$data ['device_id'] = $data ['hardware_serial'];
		$data ['payload'] = base64_decode ($data ['payload_raw']);
		$data = [
			'dev' => [
				'_id' => $data ['hardware_serial'],
				'dev_id' => $data ['dev_id'],
				'hardware_serial' => $data ['hardware_serial']
			],
			'msg' => $data
		];
		unset ($data ['msg']['hardware_serial'], $data ['msg']['dev_id']);
		return $data;
	}

	private function parsePayload ($payload) {
		$data = explode ('|', $payload);
		foreach ($data as $key => $entry) {
			if (empty ($entry)) {
				unset ($data [$key]);
				continue;
			}
			$type = substr ($entry, 0, 3);
			$value = substr ($entry, 3);
			$data [$key] = [ $type, floatval ($value) ];
		}
		return $data;
	}

	private function MQTTConnect () : bool {
		if ($this->mqtt_client) {
			return $this->mqtt_client;
		}
		$configs = parse_ini_file ('configs/ttn.ini');
		$this->mqtt_client = new phpMQTT ($configs ['address'], 1883, 'nasty'.rand ());
		return $this->mqtt_client->connect (true, null, $configs ['app_id'], $configs ['access_key']);
	}

	private function insert (array $data, $measurements) : bool {
		try {
			if (!$this->mongo) {
				$this->print ("Establishing database connection...");
				$this->mongo = new \MongoDB\Driver\Manager ("mongodb://localhost:27017");
				$this->print ("Database connection established.");
			}
			# Insert device information or update existing.
			$writer = new MongoDB\Driver\BulkWrite ([ 'ordered' => true ]);
			$writer->update ([ '_id' => $data ['dev']['_id'] ], $data ['dev'], [ 'upsert' => true ]);
			$result = $this->mongo->executeBulkWrite ('lorawan.devices', $writer);

			# Insert parsed measurement data for actual use.
			$writer = new MongoDB\Driver\BulkWrite ([ 'ordered' => true ]);
			$writer->insert ([ '_id' => $data ['dev']['_id'], $measurements ]);
			$result = $this->mongo->executeBulkWrite ('lorawan.data', $writer);

			# Insert whole data blob for archiving purposes
			$writer = new MongoDB\Driver\BulkWrite ([ 'ordered' => true ]);
			$writer->insert ($data ['msg']);
			$result = $this->mongo->executeBulkWrite ('lorawan.raw_data', $writer);
		} catch (Exception $e) {
			$this->print ('Failed to store data;'.$e->getMessage ());
			return false;
		} return true;
	}

	private function print ($msg, $eol = true) {
		if ($this->allowPrint) {
			msg_print ($msg);
		}
	}
}

function msg_print ($msg) {
	echo "MQTT Says: ${msg}".PHP_EOL;
}

/*
# Example data.
{
	"app_id":"not_an_application",
	"dev_id":"lorawan_asd",
	"hardware_serial":"0039ABFB7C0F69F5",
	"port":1,"
	counter":31,
	"payload_raw":"aGVsbG8gamlza2E=",
	"metadata":{
		"time":"2017-03-28T12:37:41.695112344Z",
		"frequency":868.1,
		"modulation":"LORA",
		"data_rate":"SF7BW125",
		"coding_rate":"4/5",
		"gateways":[{
			"gtw_id":"eui-000000000000beef",
			"timestamp":2169582635,
			"time":"",
			"channel":0,
			"rssi":-119,
			"snr":-6.5,
			"rf_chain":1
		}]
	}
}
*/