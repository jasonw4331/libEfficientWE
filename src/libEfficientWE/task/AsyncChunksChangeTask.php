<?php
declare(strict_types=1);
namespace libEfficientWE\task;

use pocketmine\level\format\Chunk;
use pocketmine\level\Level;
use pocketmine\level\SimpleChunkManager;
use pocketmine\math\Vector3;
use pocketmine\scheduler\AsyncTask;
use pocketmine\Server;
use function serialize;
use function unserialize;

abstract class AsyncChunksChangeTask extends AsyncTask {

	/** @var int */
	protected $levelId;
	/** @var int */
	protected $seed;
	/** @var int */
	protected $worldHeight;
	/** @var string|null */
	protected $chunks;
	/** @var int */
	protected $changed;
	/** @var float */
	protected $time;
	/** @var bool */
	protected $set_chunks = true;
	/** @var bool */
	private $has_callable = false;

	/**
	 * @param mixed $unserialized
	 * @return string
	 */
	protected static function serialize($unserialized) : string {
		return extension_loaded("igbinary") ? igbinary_serialize($unserialized) : serialize($unserialized);
	}

	/**
	 * @param string $serialized
	 * @return mixed
	 */
	protected static function unserialize(string $serialized) {
		return extension_loaded("igbinary") ? igbinary_unserialize($serialized) : unserialize($serialized);
	}

	public function setLevel(Level $level) : void {
		$this->levelId = $level->getId();
		$this->seed = $level->getSeed();
		$this->worldHeight = $level->getWorldHeight();
	}

	public function updateStatistics(float $time, int $changed) : void {
		$this->time = $time;
		$this->changed = $changed;
	}

	/**
	 * @param Chunk[] $chunks
	 */
	public function setChunks(array $chunks) : void {
		$serialized_chunks = [];
		foreach($chunks as $chunk) {
			$serialized_chunks[Level::chunkHash($chunk->getX(), $chunk->getZ())] = $chunk->fastSerialize();
		}

		$this->chunks = self::serialize($serialized_chunks);
	}

	public function saveChunks(SimpleChunkManager $level, Vector3 $pos1, Vector3 $pos2) : void {
		if(!$this->set_chunks) {
			$this->chunks = null;
			return;
		}

		$minChunkX = min($pos1->x, $pos2->x) >> 4;
		$maxChunkX = max($pos1->x, $pos2->x) >> 4;
		$minChunkZ = min($pos1->z, $pos2->z) >> 4;
		$maxChunkZ = max($pos1->z, $pos2->z) >> 4;

		$chunks = [];

		for($chunkX = $minChunkX; $chunkX <= $maxChunkX; ++$chunkX) {
			for($chunkZ = $minChunkZ; $chunkZ <= $maxChunkZ; ++$chunkZ) {
				$chunks[Level::chunkHash($chunkX, $chunkZ)] = $level->getChunk($chunkX, $chunkZ)->fastSerialize();
			}
		}

		$this->chunks = self::serialize($chunks);
	}

	public function onCompletion(Server $server) : void {
		if($this->set_chunks) {
			$level = $server->getLevel($this->levelId);
			foreach(self::unserialize($this->chunks) as $hash => $chunk) {
				Level::getXZ($hash, $chunkX, $chunkZ);
				$level->setChunk($chunkX, $chunkZ, Chunk::fastDeserialize($chunk));
			}
		}

		if($this->has_callable) {
			$this->fetchLocal()($this->time, $this->changed);
		}
	}

	protected function setCallable(?callable $callable) : void {
		if($callable !== null) {
			$this->storeLocal($callable);
			$this->has_callable = true;
		}
	}

	protected function getChunkManager() : SimpleChunkManager {
		$manager = new SimpleChunkManager($this->seed, $this->worldHeight);

		foreach(self::unserialize($this->chunks) as $hash => $serialized_chunk) {
			Level::getXZ($hash, $chunkX, $chunkZ);
			$manager->setChunk($chunkX, $chunkZ, Chunk::fastDeserialize($serialized_chunk));
		}

		return $manager;
	}
}