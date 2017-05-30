# Host server for Lora server cluster.

Hosts all the servers and provides common scripts used among all the hosted servers. Main.php found in this folder is used to start the hosted servers by running it and giving it a server name as the first argument. Hosted servers may define additional arguments.

Current list of hosted servers and their names are as followed.
 - MQTT server, mqtt
 - RT server, rt
 - CAC server, cac

Here are short descriptions of each listed servers. More detailed information can be found from their own readmes.
MQTT
	MQTT server is responsible for receiving data from The Things Network backend, to which the all the sensor nodes send their data. MQTT server is capable of storing and forwarding the received data. The data is forwarded to the realtime server using sockets.

RT
	RT server is a realtime server managing all the realtime client connections to the webserver. It also takes care of catering the clients with data they are interested in. The data is received from the MQTT server through sockets.

CAC
	CAC is a command & control server used to manage and test the server cluster. For now it is only capable of sending messages to RT server due to MQTT server being a bit challenging to make play nice in this kind of manner due to its architecture. It is possible to send fake data all the way to the browser clients for testing purposes. Sending control commands is also possible. So far the only supported command is terminate. More commands for administration and testing can be easily added one the architecture gets more consistent.
