<?php

/*

           -
         /   \
      /         \
   /   PocketMine  \
/          MP         \
|\     @shoghicp     /|
|.   \           /   .|
| ..     \   /     .. |
|    ..    |    ..    |
|       .. | ..       |
\          |          /
   \       |       /
      \    |    /
         \ | /

This program is free software: you can redistribute it and/or modify
it under the terms of the GNU Lesser General Public License as published by
the Free Software Foundation, either version 3 of the License, or
(at your option) any later version.


*/


class Player{
	private $server;
	private $queue = array();
	private $buffer = array();
	private $evid = array();
	var $timeout;
	var $connected = true;
	var $clientID;
	var $ip;
	var $port;
	var $counter = array(0, 0, 0);
	var $username;
	var $eid = false;
	var $data = array();
	var $entity = false;
	var $auth = false;
	var $CID;
	var $MTU;
	var $spawned = false;
	var $inventory;
	public $equipment;
	public $armor;
	var $loggedIn = false;
	public $gamemode;
	private $chunksLoaded = array();
	private $chunksOrder = array();
	
	function __construct(PocketMinecraftServer $server, $clientID, $ip, $port, $MTU){
		$this->MTU = $MTU;
		$this->server = $server;
		$this->clientID = $clientID;
		$this->CID = $this->server->clientID($ip, $port);
		$this->ip = $ip;
		$this->port = $port;
		$this->timeout = microtime(true) + 20;
		$this->inventory = array_fill(0, 36, array(AIR, 0, 0));
		$this->armor = array_fill(0, 4, array(AIR, 0, 0));
		$this->gamemode = $this->server->gamemode;
		if($this->gamemode === SURVIVAL or $this->gamemode === ADVENTURE){
			$this->equipment = BlockAPI::getItem(AIR);
		}else{
			$this->equipment = BlockAPI::getItem(STONE);
		}
		$this->evid[] = $this->server->event("server.tick", array($this, "onTick"));
		$this->evid[] = $this->server->event("server.close", array($this, "close"));
		console("[DEBUG] New Session started with ".$ip.":".$port.". MTU ".$this->MTU.", Client ID ".$this->clientID, true, true, 2);
	}
	
	private function orderChunks(){
		if(!($this->entity instanceof Entity)){
			return false;
		}
		$X = $this->entity->x / 16;
		$Z = $this->entity->z / 16;
		$v = new Vector2($X, $Z);
		$this->chunksOrder = array();
		for($x = 0; $x < 16; ++$x){
			for($z = 0; $z < 16; ++$z){
				$d = $x.":".$z;
				if(!isset($this->chunksLoaded[$d])){				
					$this->chunksOrder[$d] = $v->distance(new Vector2($x + 0.5, $z + 0.5));
				}
			}
		}
		asort($this->chunksOrder);
	}
	
	public function getNextChunk(){
		$this->orderChunks();
		$c = key($this->chunksOrder);
		$d = $this->chunksOrder[$c];
		if($c === null or $d > 6){
			$this->server->schedule(50, array($this, "getNextChunk"));
			return false;
		}
		array_shift($this->chunksOrder);
		$this->chunksLoaded[$c] = true;
		$id = explode(":", $c);
		$X = $id[0];
		$Z = $id[1];
		$x = $X * 16;
		$z = $Z * 16;
		/*
		$MTU = $this->MTU - 16;
		$y = $this->entity->y / 16;
		$order = array();
		
		for($Y = 0; $Y < 8; ++$Y){
			$order[$Y] = abs($y - ($Y + 0.5));
		}
		asort($order);
		foreach($order as $Y => $distance){
			$chunk = $this->server->api->level->getMiniChunk('.$X.', '.$Z.', $Y, $MTU);
			foreach($chunk as $d){
				$this->dataPacket(MC_CHUNK_DATA, array(
					"x" => '.$X.',
					"z" => '.$Z.',
					"data" => $d,
				), true);
			}
		}
		
		$tiles = $this->server->query("SELECT * FROM tileentities WHERE spawnable = 1 AND x >= '.$x.' AND x < '.($x + 16).' AND z >= '.$z.' AND z < '.($z + 16).';");
		if($tiles !== false and $tiles !== true){
			while(($tile = $tiles->fetchArray(SQLITE3_ASSOC)) !== false){
				$this->server->api->tileentity->spawnTo($tile["ID"], "'.$this->username.'", true);
			}
		}
		$this->actionQueue(\'$this->getNextChunk();\');
		*/
		$this->actionQueue('$MTU = $this->MTU - 16;$y = $this->entity->y / 16;$order = array();for($Y = 0; $Y < 8; ++$Y){$order[$Y] = abs($y - ($Y + 0.5));}asort($order);foreach($order as $Y => $distance){$chunk = $this->server->api->level->getMiniChunk('.$X.', '.$Z.', $Y, $MTU);foreach($chunk as $d){$this->dataPacket(MC_CHUNK_DATA, array("x" => '.$X.',"z" => '.$Z.',"data" => $d,), true);}}$tiles = $this->server->query("SELECT * FROM tileentities WHERE spawnable = 1 AND x >= '.$x.' AND x < '.($x + 16).' AND z >= '.$z.' AND z < '.($z + 16).';");if($tiles !== false and $tiles !== true){while(($tile = $tiles->fetchArray(SQLITE3_ASSOC)) !== false){$this->server->api->tileentity->spawnTo($tile["ID"], "'.$this->username.'", true);}}$this->actionQueue(\'$this->getNextChunk();\');');

	}

	public function onTick($time, $event){
		if($event !== "server.tick"){ //WTF??
			return;
		}
		if($time > $this->timeout){
			$this->close("timeout");
		}else{
			if(!empty($this->queue)){
				$cnt = 0;
				while($cnt < 4){
					$p = array_shift($this->queue);
					if($p === null){
						break;
					}
					switch($p[0]){
						case 0:
							$this->dataPacket($p[1], $p[2], false, $p[3]);
							break;
						case 1:
							eval($p[1]);
							break;
					}
					++$cnt;
				}
			}
		}
	}

	public function save(){
		if($this->entity instanceof Entity){
			$this->data["spawn"] = array(
				"x" => $this->entity->x,
				"y" => $this->entity->y,
				"z" => $this->entity->z,
			);
		}
	}

	public function close($reason = "", $msg = true){
		if($this->connected === true){
			foreach($this->evid as $ev){
				$this->server->deleteEvent($ev);
			}
			$this->server->api->dhandle("player.quit", $this);
			$reason = $reason == "" ? "server stop":$reason;
			$this->save();
			$this->eventHandler(new Container("You have been kicked. Reason: ".$reason), "server.chat");
			$this->dataPacket(MC_LOGIN_STATUS, array(
				"status" => 1,
			));
			$this->dataPacket(MC_DISCONNECT);
			$this->buffer = null;
			unset($this->buffer);
			$this->queue = null;
			unset($this->queue);
			$this->connected = false;
			if($msg === true){
				$this->server->api->chat->broadcast($this->username." left the game");
			}
			console("[INFO] Session with \x1b[36m".$this->ip.":".$this->port."\x1b[0m Client ID ".$this->clientID." closed due to ".$reason);
			$this->server->api->player->remove($this->CID);
		}
	}

	public function addItem($type, $damage, $count){
		while($count > 0){
			$add = 0;
			foreach($this->inventory as $s => $data){
				if($data[0] === AIR){
					$add = min(64, $count);
					$this->inventory[$s] = array($type, $damage, $add);
					break;
				}elseif($data[0] === $type and $data[1] === $damage){
					$add = min(64 - $data[2], $count);
					if($add <= 0){
						continue;
					}
					$this->inventory[$s] = array($type, $damage, $data[2] + $add);
					break;
				}
			}
			if($add === 0){
				return false;
			}
			$count -= $add;
		}
		return true;
	}

	public function removeItem($type, $damage, $count){
		while($count > 0){
			$remove = 0;
			foreach($this->inventory as $s => $data){
				if($data[0] === $type and $data[1] === $damage){
					$remove = min($count, $data[2]);
					if($remove < $data[2]){
						$this->inventory[$s][2] -= $remove;
					}else{
						$this->inventory[$s] = array(0, 0, 0);
					}
					break;
				}
			}
			if($remove === 0){
				return false;
			}
			$count -= $remove;
		}
		return true;
	}
	
	public function hasItem($type, $damage = false){
		if($type === AIR){
			return true;
		}
		foreach($this->inventory as $s => $data){
			if($data[0] === $type and ($data[1] === $damage or $damage === false) and $data[2] > 0){
				return true;
			}
		}
		return false;
	}
	
	public function eventHandler($data, $event){
		switch($event){
			case "player.armor":
				if($data["eid"] === $this->eid){
					$data["eid"] = 0;
					$this->armor = array();
					for($i = 0; $i < 4; ++$i){
						if($data["slot".$i] > 0){
							$this->armor[$i] = array($data["slot".$i] + 256, 0, 1);
						}else{
							$this->armor[$i] = array(AIR, 0, 0);
						}
					}
					$this->dataPacket(MC_PLAYER_ARMOR_EQUIPMENT, $data);
				}else{
					$this->dataPacket(MC_PLAYER_ARMOR_EQUIPMENT, $data);
				}
				break;
			case "player.block.place":
				if($data["eid"] === $this->eid and ($this->gamemode === SURVIVAL or $this->gamemode === ADVENTURE)){
					$this->removeItem($data["original"]->getID(), $data["original"]->getMetadata(), 1);
				}
				break;
			case "player.pickup":
				if($data["eid"] === $this->eid){
					$data["eid"] = 0;
					if(($this->gamemode === SURVIVAL or $this->gamemode === ADVENTURE)){
						$this->addItem($data["entity"]->type, $data["entity"]->meta, $data["entity"]->stack);
					}
				}
				$this->dataPacket(MC_TAKE_ITEM_ENTITY, $data);
				break;
			case "player.equipment.change":
				if($data["eid"] === $this->eid){
					break;
				}
				$this->dataPacket(MC_PLAYER_EQUIPMENT, $data);
				break;
			case "block.change":
				$this->dataPacket(MC_UPDATE_BLOCK, $data);
				break;
			case "entity.move":
				if($data->eid === $this->eid){
					break;
				}
				$this->dataPacket(MC_MOVE_ENTITY_POSROT, array(
					"eid" => $data->eid,
					"x" => $data->x,
					"y" => $data->y,
					"z" => $data->z,
					"yaw" => $data->yaw,
					"pitch" => $data->pitch,
				));
				break;
			case "entity.motion":
				/*if($data->eid === $this->eid){
					break;
				}
				$this->dataPacket(MC_SET_ENTITY_MOTION, array(
					"eid" => $data->eid,
					"speedX" => (int) ($data->speedX * 32000),
					"speedY" => (int) ($data->speedY * 32000),
					"speedZ" => (int) ($data->speedZ * 32000),
				));
				break;*/
			case "entity.remove":
				if($data->eid === $this->eid){
					break;
				}
				$this->dataPacket(MC_REMOVE_ENTITY, array(
					"eid" => $data->eid,
				));
				break;
			case "server.time":
				$this->dataPacket(MC_SET_TIME, array(
					"time" => $data,
				));
				break;
			case "entity.animate":
				if($data["eid"] === $this->eid){
					break;
				}
				$this->dataPacket(MC_ANIMATE, array(
					"eid" => $data["eid"],
					"action" => $data["action"],
				));
				break;
			case "entity.metadata":
				if($data->eid === $this->eid){
					$eid = 0;
				}else{
					$eid = $data->eid;
				}
				$this->dataPacket(MC_SET_ENTITY_DATA, array(
					"eid" => $eid,
					"metadata" => $data->getMetadata(),
				));
				break;
			case "entity.event":
				if($data["entity"]->eid === $this->eid){
					$eid = 0;
				}else{
					$eid = $data["entity"]->eid;
				}
				$this->dataPacket(MC_ENTITY_EVENT, array(
					"eid" => $eid,
					"event" => $data["event"],
				));
				break;
			case "server.chat":
				if(($data instanceof Container) === true){
					if(!$data->check($this->username)){
						return;
					}else{
						$message = $data->get();
					}
				}else{
					$message = (string) $data;
				}
				$this->sendChat($message);
				break;
		}
	}
	
	public function sendChat($message){
		$mes = explode("\n", $message);
		foreach($mes as $m){
			$this->dataPacket(MC_CHAT, array(
				"message" => str_replace("@username", $this->username, $m),
			));	
		}
	}

	public function handle($pid, $data){
		if($this->connected === true){
			$this->timeout = microtime(true) + 20;
			switch($pid){
				case 0xa0: //NACK
					if(isset($this->buffer[$data[2]])){
						array_unshift($this->queue, array(0, $this->buffer[$data[2]][0], $this->buffer[$data[2]][1], $data[2]));
					}
					if(isset($data[3])){
						if(isset($this->buffer[$data[3]])){
							array_unshift($this->queue, array(0, $this->buffer[$data[3]][0], $this->buffer[$data[3]][1], $data[3]));
						}
					}
					break;
				case 0xc0: //ACK
					$diff = $data[2] - $this->counter[2];
					if($diff > 8){ //Packet recovery
						array_unshift($this->queue, array(0, $this->buffer[$data[2]][0], $this->buffer[$data[2]][1], $data[2]));
					}
					$this->counter[2] = $data[2];
					$this->buffer[$data[2]] = null;
					unset($this->buffer[$data[2]]);

					if(isset($data[3])){
						$diff = $data[3] - $this->counter[2];
						if($diff > 8){ //Packet recovery
							array_unshift($this->queue, array(0, $this->buffer[$data[3]][0], $this->buffer[$data[3]][1], $data[3]));
						}
						$this->counter[2] = $data[3];
						$this->buffer[$data[3]] = null;
						unset($this->buffer[$data[3]]);
					}
					break;
				case 0x07:
					if($this->loggedIn === true){
						break;
					}
					$this->send(0x08, array(
						MAGIC,
						$this->server->serverID,
						$this->port,
						$data[3],
						0,
					));
					break;
				case 0x80:
				case 0x81:
				case 0x82:
				case 0x83:
				case 0x84:
				case 0x85:
				case 0x86:
				case 0x87:
				case 0x88:
				case 0x89:
				case 0x8a:
				case 0x8b:
				case 0x8c:
				case 0x8d:
				case 0x8e:
				case 0x8f:
					if(isset($data[0])){
						$diff = $data[0] - $this->counter[1];
						if($diff > 1){ //Packet recovery
							for($i = $this->counter[1]; $i < $data[0]; ++$i){
								$this->send(0xa0, array(1, true, $i));
							}
							$this->counter[1] = $data[0];
						}elseif($diff === 1){
							$this->counter[1] = $data[0];
						}
						$this->send(0xc0, array(1, true, $data[0]));
					}
					
					if(!isset($data["id"])){
						break;
					}
					switch($data["id"]){

						case MC_KEEP_ALIVE:

							break;
						case 0x03:

							break;
						case MC_DISCONNECT:
							$this->close("client disconnect");
							break;
						case MC_CLIENT_CONNECT:
							if($this->loggedIn === true){
								break;
							}
							$this->dataPacket(MC_SERVER_HANDSHAKE, array(
								"port" => $this->port,
								"session" => $data["session"],
								"session2" => Utils::readLong("\x00\x00\x00\x00\x04\x44\x0b\xa9"),
							));
							break;
						case MC_CLIENT_HANDSHAKE:
							if($this->loggedIn === true){
								break;
							}
							break;
						case MC_LOGIN:
							if($this->loggedIn === true){
								break;
							}
							if($data["protocol1"] !== CURRENT_PROTOCOL){
								$this->close("protocol", false);
								break;
							}
							$this->loggedIn = true;
							$this->username = str_replace(array("\x00", "/", " ", "\r", "\n", '"', "'"), array("", "-", "_", "", "", "", ""), $data["username"]);
							if($this->username == ""){
								$this->close("bad username", false);
								break;
							}
							$o = $this->server->api->player->getOffline($this->username);
							if($this->server->whitelist === true and !$this->server->api->ban->inWhitelist($this->username)){
								$this->close("\"\x1b[33m".$this->username."\x1b[0m\" not being on white-list", false);
								break;
							}elseif($this->server->api->ban->isBanned($this->username) or $this->server->api->ban->isIPBanned($this->ip)){
								$this->close("\"\x1b[33m".$this->username."\x1b[0m\" is banned!", false);
							}
							$u = $this->server->api->player->get($this->username);
							$c = $this->server->api->player->getByClientID($this->clientID);
							if($u !== false){
								$u->close("logged in from another location");
							}
							if($c !== false){
								$c->close("logged in from another location");
							}
							if($this->server->api->dhandle("player.join", $this) === false){
								$this->close();
								return;
							}
							$this->server->api->player->add($this->CID);
							$this->auth = true;
							if(!isset($this->data["inventory"]) or $this->gamemode === CREATIVE){
								$this->data["inventory"] = $this->inventory;
							}
							$this->inventory = &$this->data["inventory"];
							$this->armor = &$this->data["armor"];
							
							$this->data["lastIP"] = $this->ip;
							$this->data["lastID"] = $this->clientID;
							$this->server->api->player->saveOffline($this->username, $this->data);
							$this->dataPacket(MC_LOGIN_STATUS, array(
								"status" => 0,
							));
							$this->dataPacket(MC_START_GAME, array(
								"seed" => $this->server->seed,
								"x" => $this->data["spawn"]["x"],
								"y" => $this->data["spawn"]["y"],
								"z" => $this->data["spawn"]["z"],
								"unknown1" => 0,
								"gamemode" => $this->gamemode,
								"eid" => 0,
							));
							break;
						case MC_READY:
							if($this->loggedIn === false){
								break;
							}
							switch($data["status"]){
								case 1: //Spawn!!
									if($this->spawned !== false){
										break;
									}
									$this->spawned = true;
									$this->entity = $this->server->api->entity->add(ENTITY_PLAYER, 0, array("player" => $this));
									$this->eid = $this->entity->eid;
									$this->server->query("UPDATE players SET EID = ".$this->eid." WHERE clientID = ".$this->clientID.";");
									$this->entity->x = $this->data["spawn"]["x"];
									$this->entity->y = $this->data["spawn"]["y"];
									$this->entity->z = $this->data["spawn"]["z"];
									$this->entity->setName($this->username);
									$this->entity->data["clientID"] = $this->clientID;
									$this->server->api->entity->spawnAll($this);
									$this->server->api->entity->spawnToAll($this->eid);
									$this->evid[] = $this->server->event("server.time", array($this, "eventHandler"));  
									$this->evid[] = $this->server->event("server.chat", array($this, "eventHandler"));
									$this->evid[] = $this->server->event("entity.remove", array($this, "eventHandler"));
									$this->evid[] = $this->server->event("entity.move", array($this, "eventHandler"));
									$this->evid[] = $this->server->event("entity.motion", array($this, "eventHandler"));
									$this->evid[] = $this->server->event("entity.animate", array($this, "eventHandler"));
									$this->evid[] = $this->server->event("entity.event", array($this, "eventHandler"));
									$this->evid[] = $this->server->event("entity.metadata", array($this, "eventHandler"));
									$this->evid[] = $this->server->event("player.equipment.change", array($this, "eventHandler"));
									$this->evid[] = $this->server->event("player.armor", array($this, "eventHandler"));
									$this->evid[] = $this->server->event("player.pickup", array($this, "eventHandler"));
									$this->evid[] = $this->server->event("block.change", array($this, "eventHandler"));
									$this->evid[] = $this->server->event("player.block.place", array($this, "eventHandler"));
									$this->server->api->dhandle("player.armor", array("eid" => $this->eid, "slot0" => ($this->armor[0][0] > 0 ? ($this->armor[0][0] - 256):AIR), "slot1" => ($this->armor[1][0] > 0 ? ($this->armor[1][0] - 256):AIR), "slot2" => ($this->armor[2][0] > 0 ? ($this->armor[2][0] - 256):AIR), "slot3" => ($this->armor[3][0] > 0 ? ($this->armor[3][0] - 256):AIR)));
									console("[DEBUG] Player \"".$this->username."\" EID ".$this->eid." spawned at X ".$this->entity->x." Y ".$this->entity->y." Z ".$this->entity->z, true, true, 2);
									$this->eventHandler(new Container($this->server->motd), "server.chat");
									if($this->MTU <= 548){
										$this->eventHandler("Your connection is bad, you may experience lag and slow map loading.", "server.chat");
									}
									$this->sendInventory();
									$this->entity->setPosition($this->entity->x, $this->entity->y, $this->entity->z, 0, 0);
									/*
									0x01 world_inmutable
									0x02 ?
									0x04 ?
									0x08 ?
									0x10 ?
									0x20 nametags_visible
									0x40 ?
									0x80 ?
									*/
									$flags = 0;
									if($this->gamemode === ADVENTURE){
										$flags |= 0x01; //Not allow placing/breaking blocks
									}
									$flags |= 0x20; //Nametags
									$this->dataPacket(MC_ADVENTURE_SETTINGS, array(
										"flags" => $flags,
									));
									$this->getNextChunk();
									break;
								case 2://Chunk loaded?
									break;
							}
							break;
						case MC_MOVE_PLAYER:
							if($this->loggedIn === false){
								break;
							}
							if($this->entity instanceof Entity){
								$this->entity->setPosition($data["x"], $data["y"], $data["z"], $data["yaw"], $data["pitch"]);
								$this->server->api->dhandle("player.move", $this->entity);
							}
							break;
						case MC_PLAYER_EQUIPMENT:
							if($this->loggedIn === false){
								break;
							}
							$data["eid"] = $this->eid;
							if($this->server->handle("player.equipment.change", $data) !== false){
								$this->equipment = BlockAPI::getItem($data["block"], $data["meta"]);
								console("[DEBUG] Player ".$this->username." has now ".$this->equipment->getName()." (".$this->equipment->getID().":".$this->equipment->getMetadata().") in their hands!", true, true, 2);
							}
							break;
						case MC_REQUEST_CHUNK:
							if($this->loggedIn === false){
								break;
							}
							break;
						case MC_USE_ITEM:
							if($this->loggedIn === false){
								break;
							}
							$data["eid"] = $this->eid;
							if(Utils::distance($this->entity->position, $data) > 10){
								break;
							}elseif(($this->gamemode === SURVIVAL or $this->gamemode === ADVENTURE) and !$this->hasItem($data["block"], $data["meta"])){
								console("[DEBUG] Player \"".$this->username."\" tried to place not got block (or crafted block)", true, true, 2);
								//break;
							}
							$this->server->api->block->playerBlockAction($this, new Vector3($data["x"], $data["y"], $data["z"]), $data["face"], $data["fx"], $data["fy"], $data["fz"]);
							break;
						case MC_REMOVE_BLOCK:
							if($this->loggedIn === false){
								break;
							}
							if(Utils::distance($this->entity->position, $data) > 8){
								break;
							}
							$this->server->api->block->playerBlockBreak($this, new Vector3($data["x"], $data["y"], $data["z"]));
							break;
						case MC_PLAYER_ARMOR_EQUIPMENT:
							if($this->loggedIn === false){
								break;
							}
							$data["eid"] = $this->eid;
							$this->server->handle("player.armor", $data);
							break;
						case MC_INTERACT:
							if($this->loggedIn === false){
								break;
							}
							if(isset($this->server->entities[$data["target"]]) and Utils::distance($this->entity->position, $this->server->entities[$data["target"]]->position) <= 8){
								if($this->handle("player.interact", $data) !== false){
									console("[DEBUG] EID ".$this->eid." attacked EID ".$data["target"], true, true, 2);
									if($this->server->difficulty > 0){
										$this->server->api->entity->harm($data["target"], $this->server->difficulty, $this->eid);
									}
								}
							}
							break;
						case MC_ANIMATE:
							if($this->loggedIn === false){
								break;
							}
							$this->server->api->dhandle("entity.animate", array("eid" => $this->eid, "action" => $data["action"]));
							break;
						case MC_RESPAWN:
							if($this->loggedIn === false){
								break;
							}
							if($this->entity->dead === false){
								break;
							}
							$this->entity->fire = 0;
							$this->entity->air = 300;
							$this->entity->setPosition($data["x"], $data["y"], $data["z"], 0, 0);
							$this->entity->setHealth(20, "respawn");
							$this->entity->updateMetadata();
							break;
						case MC_SET_HEALTH:
							if($this->loggedIn === false){
								break;
							}
							if($this->gamemode === CREATIVE){
								break;
							}
							//$this->entity->setHealth($data["health"], "client");
							break;
						case MC_ENTITY_EVENT:
							if($this->loggedIn === false){
								break;
							}
							$data["eid"] = $this->eid;
							switch($data["event"]){
								case 9: //Eating
									$items = array(
										APPLE => 2, //Apples
										282 => 10, //Stew
										BREAD => 5, //Bread
										319 => 3,
										320 => 8,
										363 => 3,
										364 => 8,
									);
									if(isset($items[$this->equipment->getID()])){
										$this->removeItem($this->equipment->getID(), $this->equipment->getMetadata(), 1);
										$this->dataPacket(MC_ENTITY_EVENT, array(
											"eid" => 0,
											"event" => 9,
										));
										$this->entity->heal($items[$this->equipment->getID()], "eating");
									}
									break;
							}
							break;
						case MC_DROP_ITEM:
							if($this->loggedIn === false){
								break;
							}
							if($this->server->handle("player.drop", $data) !== false){
								$this->server->api->block->drop($this->entity->x, $this->entity->y, $this->entity->z, $data["block"], $data["meta"], $data["stack"]);
							}
							break;
						case MC_SIGN_UPDATE:
							if($this->loggedIn === false){
								break;
							}
							$t = $this->server->api->tileentity->get($data["x"], $data["y"], $data["z"]);
							if(($t[0] instanceof TileEntity) and $t[0]->class === TILE_SIGN){
								$t = $t[0];
								if($t->data["creator"] !== $this->username){
									$t->spawn($this);
								}else{
									$t->data["Text1"] = $data["line0"];
									$t->data["Text2"] = $data["line1"];
									$t->data["Text3"] = $data["line2"];
									$t->data["Text4"] = $data["line3"];
									$this->server->handle("tile.update", $t);
									$this->server->api->tileentity->spawnToAll($t);
								}
							}
							break;
						case MC_CHAT:
							if($this->loggedIn === false){
								break;
							}
							$message = $data["message"];
							if($message{0} === "/"){ //Command
								$this->server->api->console->run(substr($message, 1), $this);
							}else{
								if($this->server->api->dhandle("player.chat", array("player" => $this, "message" => $message)) !== false){
									$this->server->api->send($this, $message);
								}
							}
							break;
						default:
							console("[DEBUG] Unhandled 0x".dechex($data["id"])." Data Packet for Client ID ".$this->clientID.": ".print_r($data, true), true, true, 2);
							break;
					}
					break;
			}
		}
	}
	
	public function sendInventory(){
		foreach($this->inventory as $s => $data){
			if($data[0] > 0 and $data[2] >= 0){
				$e = $this->server->api->entity->add(ENTITY_ITEM, $data[0], array(
					"x" => $this->entity->x + 0.5,
					"y" => $this->entity->y + 0.19,
					"z" => $this->entity->z + 0.5,
					"meta" => $data[1],
					"stack" => $data[2],
				));
				$this->server->api->entity->spawnTo($e->eid, $this);
			}
			$this->inventory[$s] = array(AIR, 0, 0);
		}	
		/*
		//Future
		$inv = array();
		foreach($this->inventory as $s => $data){
			if($data[0] > 0 and $data[2] >= 0){
				$inv[] = BlockAPI::getItem($data[0], $data[1], $data[2]);
			}else{
				$inv[] = BlockAPI::getItem(AIR, 0, 0);
				$this->inventory[$s] = array(AIR, 0, 0);
			}
		}
		$this->dataPacket(MC_SEND_INVENTORY, array(
			"eid" => 0,
			"windowid" => 0,
			"slots" => $inv,
			"armor" => array(
				0 => BlockAPI::getItem($this->armor[0][0], $this->armor[0][1], $this->armor[0][2], $this->armor[0][3]),
				1 => BlockAPI::getItem($this->armor[1][0], $this->armor[1][1], $this->armor[1][2], $this->armor[1][3]),
				2 => BlockAPI::getItem($this->armor[2][0], $this->armor[2][1], $this->armor[2][2], $this->armor[2][3]),
				3 => BlockAPI::getItem($this->armor[3][0], $this->armor[3][1], $this->armor[3][2], $this->armor[3][3]),
			),	
		));
		*/
	}

	public function send($pid, $data = array(), $raw = false){
		if($this->connected === true){
			$this->server->send($pid, $data, $raw, $this->ip, $this->port);
		}
	}

	public function actionQueue($code){
		$this->queue[] = array(1, $code);
	}

	public function dataPacket($id, $data = array(), $queue = false, $count = false){
		if($queue === true){
			$this->queue[] = array(0, $id, $data, $count);
		}else{
			if($count === false){
				$count = $this->counter[0];
				++$this->counter[0];
				if(count($this->buffer) >= 512){
					array_shift($this->buffer);
				}
				$this->buffer[$count] = array($id, $data);
			}
			$data["id"] = $id;
			$this->send(0x80, array(
				$count,
				0x00,
				$data,
			));
		}
	}
	
	function __toString(){
		if($this->username != ""){
			return $this->username;
		}
		return $this->clientID;
	}

}