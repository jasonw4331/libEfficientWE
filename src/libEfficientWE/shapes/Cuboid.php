<?php
declare(strict_types=1);
namespace libEfficientWE\shapes;

use libEfficientWE\task\AsyncCuboidTask;
use libEfficientWE\utils\BlockIterator;
use libEfficientWE\utils\Clipboard;
use libEfficientWE\utils\Utils;
use pocketmine\block\Block;
use pocketmine\level\ChunkManager;
use pocketmine\level\format\Chunk;
use pocketmine\level\Level;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Vector3;

class Cuboid {

	/** @var Vector3 */
	private $lowCorner;
	/** @var Vector3 */
	private $highCorner;
	/** @var Clipboard */
	private $clipboard;

	private function __construct(Vector3 $pos1, Vector3 $pos2, ?Clipboard $clipboard = null) {
		$this->lowCorner = $pos1;
		$this->highCorner = $pos2;
		$this->clipboard = $clipboard ?? new Clipboard();
	}

	public static function fromVector3(Vector3 $min, Vector3 $max) : self {
		$minX = (int)min($min->x, $max->x);
		$minY = (int)min($min->y, $max->y);
		$minZ = (int)min($min->z, $max->z);
		$maxX = (int)max($min->x, $max->x);
		$maxY = (int)max($min->y, $max->y);
		$maxZ = (int)max($min->z, $max->z);
		return new self(new Vector3($minX, $minY, $minZ), new Vector3($maxX, $maxY, $maxZ));
	}

	public static function fromAABB(AxisAlignedBB $alignedBB) : self {
		$minX = (int)min($alignedBB->minX, $alignedBB->maxX);
		$minY = (int)min($alignedBB->minY, $alignedBB->maxY);
		$minZ = (int)min($alignedBB->minZ, $alignedBB->maxZ);
		$maxX = (int)max($alignedBB->minX, $alignedBB->maxX);
		$maxY = (int)max($alignedBB->minY, $alignedBB->maxY);
		$maxZ = (int)max($alignedBB->minZ, $alignedBB->maxZ);
		return new self(new Vector3($minX, $minY, $minZ), new Vector3($maxX, $maxY, $maxZ));
	}

	public function syncCopy(ChunkManager $level, Vector3 $relative_pos, ?callable $callable = null) : void {
		$time = microtime(true);

		$s_pos = $this->lowCorner->subtract($relative_pos->floor());

		$cap = $this->highCorner->subtract($this->lowCorner);
		$xCap = $cap->x;
		$yCap = $cap->y;
		$zCap = $cap->z;

		$minX = $this->lowCorner->x;
		$minY = $this->lowCorner->y;
		$minZ = $this->lowCorner->z;

		$blocks = [];
		$iterator = new BlockIterator($level);

		for($x = 0; $x <= $xCap; ++$x) {
			$ax = $minX + $x;
			for($z = 0; $z <= $zCap; ++$z) {
				$az = $minZ + $z;
				for($y = 0; $y <= $yCap; ++$y) {
					$ay = $minY + $y;
					$iterator->moveTo($ax, $ay, $az);
					$blocks[Level::blockHash($x, $y, $z)] = $iterator->currentSubChunk->getFullBlock($ax & 0x0f, $ay & 0x0f, $az & 0x0f);
				}
			}
		}

		$this->clipboard->setFullBlocks($blocks)->setRelativePos($s_pos)->setCapVector($cap);

		$time = microtime(true) - $time;
		if($callable !== null) {
			$callable($time, ($xCap + 1) * ($yCap + 1) * ($zCap + 1));
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

		$task = new AsyncCuboidTask($this, $level, $chunks, AsyncCuboidTask::PASTE, $callable);
		$task->setRelativePos($relative_pos);
		$task->replaceAir($replace_air);
		$level->getServer()->getAsyncPool()->submitTask($task);
	}

	public function syncPaste(ChunkManager $level, Vector3 $relative_pos, bool $replace_air = true, ?callable $callable = null) : void {
		$changed = 0;
		$time = microtime(true);

		$relative_pos = $relative_pos->floor()->add($this->clipboard->getRelativePos());
		$relx = $relative_pos->x;
		$rely = $relative_pos->y;
		$relz = $relative_pos->z;

		$clipboard = $this->clipboard;

		$caps = $this->clipboard->getCapVector();
		$xCap = $caps->x;
		$yCap = $caps->y;
		$zCap = $caps->z;

		$iterator = new BlockIterator($level);

		for($x = 0; $x <= $xCap; ++$x) {
			$xPos = $relx + $x;
			for($z = 0; $z <= $zCap; ++$z) {
				$zPos = $relz + $z;
				for($y = 0; $y <= $yCap; ++$y) {
					$fullBlock = $clipboard->getFullBlocks()[Level::blockHash($x, $y, $z)] ?? null;
					if($fullBlock !== null) {
						if($replace_air || ($fullBlock >> 4) !== Block::AIR) {
							$yPos = $rely + $y;
							$iterator->moveTo($xPos, $yPos, $zPos);
							$iterator->currentSubChunk->setBlock($xPos & 0x0f, $yPos & 0x0f, $zPos & 0x0f, $fullBlock >> 4, $fullBlock & 0xf);
							++$changed;
						}
					}
				}
			}
		}

		if($level instanceof Level) {
			Utils::updateChunks($level, $relx >> 4, ($relx + $xCap) >> 4, $relz >> 4, ($relz + $zCap) >> 4);
		}

		$time = microtime(true) - $time;
		if($callable !== null) {
			$callable($time, $changed);
		}
	}

	public function asyncSet(Level $level, Block $block, ?callable $callable = null) : void {
		$task = new AsyncCuboidTask($this, $level, $this->getChunks($level), AsyncCuboidTask::SET, $callable);
		$task->setBlock($block);
		$level->getServer()->getAsyncPool()->submitTask($task);
	}

	public function syncSet(ChunkManager $level, Block $block, ?callable $callable = null) : void {
		$time = microtime(true);

		$minX = $this->lowCorner->x;
		$maxX = $this->highCorner->x;
		$minY = $this->lowCorner->y;
		$maxY = $this->highCorner->y;
		$minZ = $this->lowCorner->z;
		$maxZ = $this->highCorner->z;

		$blockId = $block->getId();
		$blockMeta = $block->getDamage();

		$iterator = new BlockIterator($level);

		for($x = $minX; $x <= $maxX; ++$x) {
			for($z = $minZ; $z <= $maxZ; ++$z) {
				for($y = $minY; $y <= $maxY; ++$y) {
					$iterator->moveTo($x, $y, $z);
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
		$task = new AsyncCuboidTask($this, $level, $this->getChunks($level), AsyncCuboidTask::REPLACE, $callable);
		$task->find($find);
		$task->replace($replace);
		$level->getServer()->getAsyncPool()->submitTask($task);
	}

	public function syncReplace(ChunkManager $level, Block $find, Block $replace, ?callable $callable = null) : void {
		$time = microtime(true);

		$minX = $this->lowCorner->x;
		$maxX = $this->highCorner->x;
		$minY = $this->lowCorner->y;
		$maxY = $this->highCorner->y;
		$minZ = $this->lowCorner->z;
		$maxZ = $this->highCorner->z;

		$find = ($find->getId() << 4) | $find->getDamage();//fullBlock

		$replaceId = $replace->getId();
		$replaceMeta = $replace->getDamage();

		$iterator = new BlockIterator($level);

		for($x = $minX; $x <= $maxX; ++$x) {
			for($z = $minZ; $z <= $maxZ; ++$z) {
				for($y = $minY; $y <= $maxY; ++$y) {
					$iterator->moveTo($x, $y, $z);
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

	public function getClipboard() : Clipboard {
		return $this->clipboard;
	}

	public function getLowCorner() : Vector3 {
		return $this->lowCorner;
	}

	public function getHighCorner() : Vector3 {
		return $this->highCorner;
	}

	public function setClipboard(Clipboard $clipboard) : self {
		$this->clipboard = $clipboard;
		return $this;
	}

	/**
	 * @param Level $level
	 * @param bool $create
	 * @return Chunk[]|null[]
	 */
	private function getChunks(Level $level, bool $create = true) : array {
		$chunks = [];

		$minChunkX = $this->lowCorner->x >> 4;
		$maxChunkX = $this->highCorner->x >> 4;
		$minChunkZ = $this->lowCorner->z >> 4;
		$maxChunkZ = $this->highCorner->z >> 4;

		for($chunkX = $minChunkX; $chunkX <= $maxChunkX; ++$chunkX) {
			for($chunkZ = $minChunkZ; $chunkZ <= $maxChunkZ; ++$chunkZ) {
				$chunks[] = $level->getChunk($chunkX, $chunkZ, $create);
			}
		}

		return $chunks;
	}
}