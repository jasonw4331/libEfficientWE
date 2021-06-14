<?php
declare(strict_types=1);
namespace libEfficientWE\task;

use libEfficientWE\utils\Clipboard;
use pocketmine\block\Block;
use pocketmine\level\format\Chunk;
use pocketmine\level\Level;
use pocketmine\level\SimpleChunkManager;
use pocketmine\math\Vector3;
use pocketmine\scheduler\AsyncTask;
use pocketmine\Server;
use function serialize;
use function unserialize;

abstract class AsyncChunksChangeTask extends AsyncTask {

	public const PASTE = 0;
	public const REPLACE = 0;
	public const SET = 0;

	protected int $levelId;
	protected int $seed;
	protected int $worldHeight;
	protected string $clipboard;
	protected ?string $chunks;
	protected int $changed;
	protected float $time;
	protected bool $set_chunks = true;
	protected bool $has_callable = false;
	protected Vector3 $relativePos;
	protected bool $replaceAir;
	protected Block $find;
	protected Block $replace;
	protected Block $block;

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

	public function getClipboard() : Clipboard {
		return self::unserialize($this->clipboard);
	}

	public function setClipboard(Clipboard $clipboard) : self {
		$this->clipboard = self::serialize($clipboard);
		return $this;
	}

	public function setRelativePos(Vector3 $relativePos) : self {
		$this->relativePos = $relativePos;
		return $this;
	}

	public function replaceAir(bool $replace_air) : self {
		$this->replaceAir = $replace_air;
		return $this;
	}

	public function find(Block $block) : self {
		$this->find = $block;
		return $this;
	}

	public function replace(Block $block) : self {
		$this->replace = $block;
		return $this;
	}

	public function setBlock(Block $block) : self {
		$this->block = $block;
		return $this;
	}

	public function setLevel(Level $level) : self {
		$this->levelId = $level->getId();
		$this->seed = $level->getSeed();
		$this->worldHeight = $level->getWorldHeight();
		return $this;
	}

	public function updateStatistics(float $time, int $changed) : self {
		$this->time = $time;
		$this->changed = $changed;
		return $this;
	}

	/**
	 * @param Chunk[] $chunks
	 */
	public function setChunks(array $chunks) : self {
		$serialized_chunks = [];
		foreach($chunks as $chunk) {
			$serialized_chunks[Level::chunkHash($chunk->getX(), $chunk->getZ())] = $chunk->fastSerialize();
		}

		$this->chunks = self::serialize($serialized_chunks);
		return $this;
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