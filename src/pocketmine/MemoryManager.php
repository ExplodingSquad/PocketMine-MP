<?php

/*
 *
 *  ____            _        _   __  __ _                  __  __ ____
 * |  _ \ ___   ___| | _____| |_|  \/  (_)_ __   ___      |  \/  |  _ \
 * | |_) / _ \ / __| |/ / _ \ __| |\/| | | '_ \ / _ \_____| |\/| | |_) |
 * |  __/ (_) | (__|   <  __/ |_| |  | | | | | |  __/_____| |  | |  __/
 * |_|   \___/ \___|_|\_\___|\__|_|  |_|_|_| |_|\___|     |_|  |_|_|
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * @author PocketMine Team
 * @link http://www.pocketmine.net/
 *
 *
*/

namespace pocketmine;

use pocketmine\event\server\LowMemoryEvent;
use pocketmine\event\Timings;
use pocketmine\scheduler\GarbageCollectionTask;
use pocketmine\utils\Utils;

class MemoryManager{

	/** @var Server */
	private $server;

	private $memoryLimit;
	private $globalMemoryLimit;
	private $checkRate;
	private $checkTicker = 0;
	private $lowMemory = false;

	private $continuousTrigger = true;
	private $continuousTriggerRate;
	private $continuousTriggerCount = 0;
	private $continuousTriggerTicker = 0;

	private $garbageCollectionPeriod;
	private $garbageCollectionTicker = 0;
	private $garbageCollectionTrigger;
	private $garbageCollectionAsync;

	private $chunkRadiusOverride;
	private $chunkCollect;
	private $chunkTrigger;

	private $chunkCache;
	private $cacheTrigger;

	public function __construct(Server $server){
		$this->server = $server;

		$this->init();
	}

	private function init(){
		$this->memoryLimit = ((int) $this->server->getProperty("memory.main-limit", 0)) * 1024 * 1024;

		$defaultMemory = 1024;

		if(preg_match("/([0-9]+)([KMGkmg])/", $this->server->getConfigString("memory-limit", ""), $matches) > 0){
			$m = (int) $matches[1];
			if($m <= 0){
				$defaultMemory = 0;
			}else{
				switch(strtoupper($matches[2])){
					case "K":
						$defaultMemory = $m / 1024;
						break;
					case "M":
						$defaultMemory = $m;
						break;
					case "G":
						$defaultMemory = $m * 1024;
						break;
					default:
						$defaultMemory = $m;
						break;
				}
			}
		}

		$hardLimit = ((int) $this->server->getProperty("memory.main-hard-limit", $defaultMemory));

		if($hardLimit <= 0){
			ini_set("memory_limit", -1);
		}else{
			ini_set("memory_limit", $hardLimit . "M");
		}

		$this->globalMemoryLimit = ((int) $this->server->getProperty("memory.global-limit", 0)) * 1024 * 1024;
		$this->checkRate = (int) $this->server->getProperty("memory.check-rate", 20);
		$this->continuousTrigger = (bool) $this->server->getProperty("memory.continuous-trigger", true);
		$this->continuousTriggerRate = (int) $this->server->getProperty("memory.continuous-trigger-rate", 30);

		$this->garbageCollectionPeriod = (int) $this->server->getProperty("memory.garbage-collection.period", 36000);
		$this->garbageCollectionTrigger = (bool) $this->server->getProperty("memory.garbage-collection.low-memory-trigger", true);
		$this->garbageCollectionAsync = (bool) $this->server->getProperty("memory.garbage-collection.collect-async-worker", true);

		$this->chunkRadiusOverride = (int) $this->server->getProperty("memory.max-chunks.chunk-radius", 4);
		$this->chunkCollect = (bool) $this->server->getProperty("memory.max-chunks.trigger-chunk-collect", true);
		$this->chunkTrigger = (bool) $this->server->getProperty("memory.max-chunks.low-memory-trigger", true);

		$this->chunkCache = (bool) $this->server->getProperty("memory.world-caches.disable-chunk-cache", true);
		$this->cacheTrigger = (bool) $this->server->getProperty("memory.world-caches.low-memory-trigger", true);

		gc_enable();
	}

	public function isLowMemory(){
		return $this->lowMemory;
	}

	public function canUseChunkCache(){
		return !($this->lowMemory and $this->chunkTrigger);
	}

	/**
	 * Returns the allowed chunk radius based on the current memory usage.
	 *
	 * @param int $distance
	 *
	 * @return int
	 */
	public function getViewDistance(int $distance) : int{
		return $this->lowMemory ? min($this->chunkRadiusOverride, $distance) : $distance;
	}

	public function trigger($memory, $limit, $global = false, $triggerCount = 0){
		$this->server->getLogger()->debug(sprintf("[Memory Manager] %sLow memory triggered, limit %gMB, using %gMB",
			$global ? "Global " : "", round(($limit / 1024) / 1024, 2), round(($memory / 1024) / 1024, 2)));
		if($this->cacheTrigger){
			foreach($this->server->getLevels() as $level){
				$level->clearCache(true);
			}
		}

		if($this->chunkTrigger and $this->chunkCollect){
			foreach($this->server->getLevels() as $level){
				$level->doChunkGarbageCollection();
			}
		}

		($ev = new LowMemoryEvent($memory, $limit, $global, $triggerCount))->call();

		$cycles = 0;
		if($this->garbageCollectionTrigger){
			$cycles = $this->triggerGarbageCollector();
		}

		$this->server->getLogger()->debug(sprintf("[Memory Manager] Freed %gMB, $cycles cycles", round(($ev->getMemoryFreed() / 1024) / 1024, 2)));
	}

	public function check(){
		Timings::$memoryManagerTimer->startTiming();

		if(($this->memoryLimit > 0 or $this->globalMemoryLimit > 0) and ++$this->checkTicker >= $this->checkRate){
			$this->checkTicker = 0;
			$memory = Utils::getMemoryUsage(true);
			$trigger = false;
			if($this->memoryLimit > 0 and $memory[0] > $this->memoryLimit){
				$trigger = 0;
			}elseif($this->globalMemoryLimit > 0 and $memory[1] > $this->globalMemoryLimit){
				$trigger = 1;
			}

			if($trigger !== false){
				if($this->lowMemory and $this->continuousTrigger){
					if(++$this->continuousTriggerTicker >= $this->continuousTriggerRate){
						$this->continuousTriggerTicker = 0;
						$this->trigger($memory[$trigger], $this->memoryLimit, $trigger > 0, ++$this->continuousTriggerCount);
					}
				}else{
					$this->lowMemory = true;
					$this->continuousTriggerCount = 0;
					$this->trigger($memory[$trigger], $this->memoryLimit, $trigger > 0);
				}
			}else{
				$this->lowMemory = false;
			}
		}

		if($this->garbageCollectionPeriod > 0 and ++$this->garbageCollectionTicker >= $this->garbageCollectionPeriod){
			$this->garbageCollectionTicker = 0;
			$this->triggerGarbageCollector();
		}

		Timings::$memoryManagerTimer->stopTiming();
	}

	public function triggerGarbageCollector(){
		Timings::$garbageCollectorTimer->startTiming();

		if($this->garbageCollectionAsync){
			$size = $this->server->getScheduler()->getAsyncTaskPoolSize();
			for($i = 0; $i < $size; ++$i){
				$this->server->getScheduler()->scheduleAsyncTaskToWorker(new GarbageCollectionTask(), $i);
			}
		}

		$cycles = gc_collect_cycles();

		Timings::$garbageCollectorTimer->stopTiming();

		return $cycles;
	}

	public function dumpServerMemory($outputFolder, $maxNesting, $maxStringSize){
		$hardLimit = ini_get('memory_limit');
		ini_set('memory_limit', -1);
		gc_disable();

		if(!file_exists($outputFolder)){
			mkdir($outputFolder, 0777, true);
		}

		$this->server->getLogger()->notice("[Dump] After the memory dump is done, the server might crash");

		$obData = fopen($outputFolder . "/objects.js", "wb+");

		$staticProperties = [];

		$data = [];

		$objects = [];

		$refCounts = [];

		$instanceCounts = [];

		$staticCount = 0;
		foreach($this->server->getLoader()->getClasses() as $className){
			$reflection = new \ReflectionClass($className);
			$staticProperties[$className] = [];
			foreach($reflection->getProperties() as $property){
				if(!$property->isStatic() or $property->getDeclaringClass()->getName() !== $className){
					continue;
				}

				if(!$property->isPublic()){
					$property->setAccessible(true);
				}

				$staticCount++;
				$this->continueDump($property->getValue(), $staticProperties[$className][$property->getName()], $objects, $refCounts, 0, $maxNesting, $maxStringSize);
			}

			if(count($staticProperties[$className]) === 0){
				unset($staticProperties[$className]);
			}
		}

		echo "[Dump] Wrote $staticCount static properties\n";

		$this->continueDump($this->server, $data, $objects, $refCounts, 0, $maxNesting, $maxStringSize);

		do{
			$continue = false;
			foreach($objects as $hash => $object){
				if(!is_object($object)){
					continue;
				}
				$continue = true;

				$className = get_class($object);
				if(!isset($instanceCounts[$className])){
					$instanceCounts[$className] = 1;
				}else{
					$instanceCounts[$className]++;
				}

				$objects[$hash] = true;

				$reflection = new \ReflectionObject($object);

				$info = [
					"information" => "$hash@$className",
					"properties" => []
				];

				if($reflection->getParentClass()){
					$info["parent"] = $reflection->getParentClass()->getName();
				}

				if(count($reflection->getInterfaceNames()) > 0){
					$info["implements"] = implode(", ", $reflection->getInterfaceNames());
				}

				foreach($reflection->getProperties() as $property){
					if($property->isStatic()){
						continue;
					}

					if(!$property->isPublic()){
						$property->setAccessible(true);
					}
					$this->continueDump($property->getValue($object), $info["properties"][$property->getName()], $objects, $refCounts, 0, $maxNesting, $maxStringSize);
				}

				fwrite($obData, "$hash@$className: " . json_encode($info, JSON_UNESCAPED_SLASHES) . "\n");
			}

			echo "[Dump] Wrote " . count($objects) . " objects\n";
		}while($continue);

		fclose($obData);

		file_put_contents($outputFolder . "/staticProperties.js", json_encode($staticProperties, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
		file_put_contents($outputFolder . "/serverEntry.js", json_encode($data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
		file_put_contents($outputFolder . "/referenceCounts.js", json_encode($refCounts, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

		arsort($instanceCounts, SORT_NUMERIC);
		file_put_contents($outputFolder . "/instanceCounts.js", json_encode($instanceCounts, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

		echo "[Dump] Finished!\n";

		ini_set('memory_limit', $hardLimit);
		gc_enable();
	}

	private function continueDump($from, &$data, &$objects, &$refCounts, $recursion, $maxNesting, $maxStringSize){
		if($maxNesting <= 0){
			$data = "(error) NESTING LIMIT REACHED";
			return;
		}

		--$maxNesting;

		if(is_object($from)){
			if(!isset($objects[$hash = spl_object_hash($from)])){
				$objects[$hash] = $from;
				$refCounts[$hash] = 0;
			}

			++$refCounts[$hash];

			$data = "(object) $hash@" . get_class($from);
		}elseif(is_array($from)){
			if($recursion >= 5){
				$data = "(error) ARRAY RECURSION LIMIT REACHED";
				return;
			}
			$data = [];
			foreach($from as $key => $value){
				$this->continueDump($value, $data[$key], $objects, $refCounts, $recursion + 1, $maxNesting, $maxStringSize);
			}
		}elseif(is_string($from)){
			$data = "(string) len(". strlen($from) .") " . substr(Utils::printable($from), 0, $maxStringSize);
		}elseif(is_resource($from)){
			$data = "(resource) " . print_r($from, true);
		}else{
			$data = $from;
		}
	}
}
