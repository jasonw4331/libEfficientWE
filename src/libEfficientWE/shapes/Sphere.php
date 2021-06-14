<?php
declare(strict_types=1);

namespace libEfficientWE\shapes;

use libEfficientWE\task\AsyncSphereTask;
use libEfficientWE\utils\BlockIterator;
use libEfficientWE\utils\Clipboard;
use libEfficientWE\utils\Utils;
use pocketmine\block\Block;
use pocketmine\level\ChunkManager;
use pocketmine\level\Level;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Vector2;
use pocketmine\math\Vector3;

class Sphere extends Shape {

	protected Vector3 $relativeCenter;
	protected int $radius;

	private function __construct(Vector3 $relativeCenter, int $radius, ?Clipboard $clipboard = null) {
		$this->relativeCenter = $relativeCenter;
		$this->radius = abs($radius);
		parent::__construct($clipboard);
	}

	public static function fromVector3(Vector3 $min, Vector3 $max) : Shape {
		$minX = (int)min($min->x, $max->x);
		$minY = (int)min($min->y, $max->y);
		$minZ = (int)min($min->z, $max->z);
		$maxX = (int)max($min->x, $max->x);
		$maxY = (int)max($min->y, $max->y);
		$maxZ = (int)max($min->z, $max->z);

		$center = new Vector3(($maxX + $minX) / 2, ($maxY + $minY) / 2, ($maxZ + $minZ) / 2);
		$radius = ($maxX - $minX) / 2;
		return new self($center, $radius);
	}

	public static function fromAABB(AxisAlignedBB $alignedBB) : Shape {
		$minX = (int)min($alignedBB->minX, $alignedBB->maxX);
		$minY = (int)min($alignedBB->minY, $alignedBB->maxY);
		$minZ = (int)min($alignedBB->minZ, $alignedBB->maxZ);
		$maxX = (int)max($alignedBB->minX, $alignedBB->maxX);
		$maxY = (int)max($alignedBB->minY, $alignedBB->maxY);
		$maxZ = (int)max($alignedBB->minZ, $alignedBB->maxZ);

		$center = new Vector3(($maxX + $minX) / 2, ($maxY + $minY) / 2, ($maxZ + $minZ) / 2);
		$radius = ($maxX - $minX) / 2;
		return new self($center, $radius);
	}

	public function syncCopy(ChunkManager $level, Vector3 $worldBasePos, ?callable $callable = null) : void {
		$time = microtime(true);

		$absoluteBasePos = $this->relativeCenter->subtract($worldBasePos->floor());

		$relativeMaximums = $this->relativeCenter->add($this->radius, $this->radius, $this->radius);
		$xCap = $relativeMaximums->x;
		$yCap = $relativeMaximums->y;
		$zCap = $relativeMaximums->z;

		$relativeMinimums = $this->relativeCenter->subtract($this->radius, $this->radius, $this->radius);
		$minX = $relativeMinimums->x;
		$minY = $relativeMinimums->y;
		$minZ = $relativeMinimums->z;

		$blocks = [];
		$iterator = new BlockIterator($level);

		for($x = 0; $x <= $xCap; ++$x) {
			$ax = $minX + $x;
			for($z = 0; $z <= $zCap; ++$z) {
				$az = $minZ + $z;
				for($y = 0; $y <= $yCap; ++$y) {
					$ay = $minY + $y;
					$iterator->moveTo($ax, $ay, $az);
					if($this->relativeCenter->distance(new Vector3($x, $y, $z)) > $this->radius) {
						continue;
					}
					$blocks[Level::blockHash($x, $y, $z)] = $iterator->currentSubChunk->getFullBlock($ax & 0x0f, $ay & 0x0f, $az & 0x0f);
				}
			}
		}

		$this->clipboard->setFullBlocks($blocks)->setRelativePos($absoluteBasePos)->setCapVector($relativeMaximums);

		$time = microtime(true) - $time;
		if($callable !== null) {
			$callable($time, ($xCap + 1) * ($yCap + 1) * ($zCap + 1)); // TODO: update for better accuracy
		}
	}

	public function asyncPaste(Level $level, Vector3 $relative_pos, bool $replace_air = true, ?callable $callable = null) : void {
		$chunks = [];

		$caps = $this->clipboard->getCapVector();
		$minChunkX = $relative_pos->x >> 4;
		$minChunkZ = $relative_pos->z >> 4;
		$maxChunkX = ($relative_pos->x + $caps->x) >> 4;
		$maxChunkZ = ($relative_pos->z + $caps->z) >> 4;

		for($chunkX = $minChunkX; $chunkX <= $maxChunkX; ++$chunkX) {
			for($chunkZ = $minChunkZ; $chunkZ <= $maxChunkZ; ++$chunkZ) {
				$chunks[] = $level->getChunk($chunkX, $chunkZ, true);
			}
		}

		$task = new AsyncSphereTask($this, $level, $chunks, AsyncSphereTask::PASTE, $callable);
		$task->setRelativePos($relative_pos);
		$task->replaceAir($replace_air);
		$level->getServer()->getAsyncPool()->submitTask($task);
	}

	public function syncPaste(ChunkManager $level, Vector3 $worldBasePos, bool $replace_air = true, ?callable $callable = null) : void {
		$changed = 0;
		$time = microtime(true);

		$clipboard = $this->clipboard;

		$relativeMaximums = $clipboard->getCapVector();
		$xCap = $relativeMaximums->x;
		$yCap = $relativeMaximums->y;
		$zCap = $relativeMaximums->z;

		$absoluteBasePos = $worldBasePos->floor()->add($clipboard->getRelativePos());
		$minX = $absoluteBasePos->x;
		$minY = $absoluteBasePos->y;
		$minZ = $absoluteBasePos->z;

		$iterator = new BlockIterator($level);

		for($x = 0; $x <= $xCap; ++$x) {
			$ax = $minX + $x;
			for($z = 0; $z <= $zCap; ++$z) {
				$az = $minZ + $z;
				for($y = 0; $y <= $yCap; ++$y) {
					$fullBlock = $clipboard->getFullBlocks()[Level::blockHash($x, $y, $z)] ?? null;
					if($fullBlock !== null) {
						if($replace_air || ($fullBlock >> 4) !== Block::AIR) {
							$ay = $minY + $y;
							$iterator->moveTo($ax, $ay, $az);
							// if($absoluteBasePos->distance(new Vector3($x, $y, $z)) > $this->radius)
							// 	continue;
							$iterator->currentSubChunk->setBlock($ax & 0x0f, $ay & 0x0f, $az & 0x0f, $fullBlock >> 4, $fullBlock & 0xf);
							++$changed;
						}
					}
				}
			}
		}

		if($level instanceof Level) {
			Utils::updateChunks($level, $minX >> 4, ($minX + $xCap) >> 4, $minZ >> 4, ($minZ + $zCap) >> 4);
		}

		$time = microtime(true) - $time;
		if($callable !== null) {
			$callable($time, $changed);
		}
	}

	public function asyncSet(Level $level, Block $block, ?callable $callable = null) : void {
		$task = new AsyncSphereTask($this, $level, $this->getChunks($level), AsyncSphereTask::SET, $callable);
		$task->setBlock($block);
		$level->getServer()->getAsyncPool()->submitTask($task);
	}

	public function syncSet(ChunkManager $level, Block $block, ?callable $callable = null) : void {
		$time = microtime(true);

		$relativeMaximums = $this->relativeCenter->add($this->radius, $this->radius, $this->radius);
		$maxX = $relativeMaximums->x;
		$maxY = $relativeMaximums->y;
		$maxZ = $relativeMaximums->z;

		$relativeMinimums = $this->relativeCenter->subtract($this->radius, $this->radius, $this->radius);
		$minX = $relativeMinimums->x;
		$minY = $relativeMinimums->y;
		$minZ = $relativeMinimums->z;

		$blockId = $block->getId();
		$blockMeta = $block->getDamage();

		$iterator = new BlockIterator($level);

		for($x = $minX; $x <= $maxX; ++$x) {
			for($z = $minZ; $z <= $maxZ; ++$z) {
				for($y = $minY; $y <= $maxY; ++$y) {
					$iterator->moveTo($x, $y, $z);
					if($this->relativeCenter->distance(new Vector3($x, $y, $z)) > $this->radius) {
						continue;
					}
					$iterator->currentSubChunk->setBlock($x & 0x0f, $y & 0x0f, $z & 0x0f, $blockId, $blockMeta);
				}
			}
		}

		if($level instanceof Level) {
			Utils::updateChunks($level, $minX >> 4, $maxX >> 4, $minZ >> 4, $maxZ >> 4);
		}

		$time = microtime(true) - $time;
		if($callable !== null) {
			$callable($time, (1 + $maxX - $minX) * (1 + $maxY - $minY) * (1 + $maxZ - $minZ));
		}
	}

	public function asyncReplace(Level $level, Block $find, Block $replace, ?callable $callable = null) : void {
		$task = new AsyncSphereTask($this, $level, $this->getChunks($level), AsyncSphereTask::REPLACE, $callable);
		$task->find($find);
		$task->replace($replace);
		$level->getServer()->getAsyncPool()->submitTask($task);
	}

	public function syncReplace(ChunkManager $level, Block $find, Block $replace, ?callable $callable = null) : void {
		$time = microtime(true);

		$relativeMaximums = $this->relativeCenter->add($this->radius, $this->radius, $this->radius);
		$maxX = $relativeMaximums->x;
		$maxY = $relativeMaximums->y;
		$maxZ = $relativeMaximums->z;

		$relativeMinimums = $this->relativeCenter->subtract($this->radius, $this->radius, $this->radius);
		$minX = $relativeMinimums->x;
		$minY = $relativeMinimums->y;
		$minZ = $relativeMinimums->z;

		$find = ($find->getId() << 4) | $find->getDamage(); //fullBlock

		$replaceId = $replace->getId();
		$replaceMeta = $replace->getDamage();

		$iterator = new BlockIterator($level);

		for($x = $minX; $x <= $maxX; ++$x) {
			for($z = $minZ; $z <= $maxZ; ++$z) {
				for($y = $minY; $y <= $maxY; ++$y) {
					$iterator->moveTo($x, $y, $z);
					if((new Vector2($this->relativeCenter->x, $this->relativeCenter->z))->distance(new Vector2($x, $z)) > $this->radius) {
						continue;
					}
					if($iterator->currentSubChunk->getFullBlock($x & 0x0f, $y & 0x0f, $z & 0x0f) === $find) {
						$iterator->currentSubChunk->setBlock($x & 0x0f, $y & 0x0f, $z & 0x0f, $replaceId, $replaceMeta);
					}
				}
			}
		}

		if($level instanceof Level) {
			Utils::updateChunks($level, $minX >> 4, $maxX >> 4, $minZ >> 4, $maxZ >> 4);
		}

		$time = microtime(true) - $time;
		if($callable !== null) {
			$callable($time, (1 + $maxX - $minX) * (1 + $maxY - $minY) * (1 + $maxZ - $minZ));
		}
	}

	public function asyncRotate(Level $level, int $direction, ?callable $callable = null) : void {
		// TODO: Implement asyncRotate() method.
	}

	public function syncRotate(ChunkManager $level, int $direction, ?callable $callable = null) : void {
		// TODO: Implement syncRotate() method.
	}

	protected function getChunks(ChunkManager $level) : array {
		$chunks = [];

		$relativeMaximums = $this->relativeCenter->add($this->radius, $this->radius, $this->radius);
		$xCap = $relativeMaximums->x;
		$zCap = $relativeMaximums->z;

		$relativeMinimums = $this->relativeCenter->subtract($this->radius, 0, $this->radius);
		$minX = $relativeMinimums->x;
		$minZ = $relativeMinimums->z;

		$minChunkX = $minX >> 4;
		$maxChunkX = $xCap >> 4;
		$minChunkZ = $minZ >> 4;
		$maxChunkZ = $zCap >> 4;

		for($chunkX = $minChunkX; $chunkX <= $maxChunkX; ++$chunkX) {
			for($chunkZ = $minChunkZ; $chunkZ <= $maxChunkZ; ++$chunkZ) {
				$chunks[] = $level->getChunk($chunkX, $chunkZ);
			}
		}

		return $chunks;
	}

	public function getRadius() : int {
		return $this->radius;
	}
}