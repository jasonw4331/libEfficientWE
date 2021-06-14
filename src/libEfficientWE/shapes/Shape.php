<?php
declare(strict_types=1);
namespace libEfficientWE\shapes;

use libEfficientWE\utils\Clipboard;
use pocketmine\block\Block;
use pocketmine\level\ChunkManager;
use pocketmine\level\format\Chunk;
use pocketmine\level\Level;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Vector3;

abstract class Shape {

	protected Clipboard $clipboard;

	protected function __construct(?Clipboard $clipboard = null) {
		$this->clipboard = $clipboard ?? new Clipboard();
	}

	abstract public static function fromVector3(Vector3 $min, Vector3 $max) : self;

	abstract public static function fromAABB(AxisAlignedBB $alignedBB) : self;

	public function getClipboard() : Clipboard {
		return $this->clipboard;
	}

	public function setClipboard(Clipboard $clipboard) : self {
		$this->clipboard = $clipboard;
		return $this;
	}

	abstract public function asyncCut(ChunkManager $level, Vector3 $relative_pos, ?callable $callable = null) : void;

	abstract public function syncCut(ChunkManager $level, Vector3 $relative_pos, ?callable $callable = null) : void;

	abstract public function syncCopy(ChunkManager $level, Vector3 $relative_pos, ?callable $callable = null) : void;

	abstract public function asyncPaste(Level $level, Vector3 $relative_pos, bool $replace_air = true, ?callable $callable = null) : void;

	abstract public function syncPaste(ChunkManager $level, Vector3 $relative_pos, bool $replace_air = true, ?callable $callable = null) : void;

	abstract public function asyncSet(Level $level, Block $block, ?callable $callable = null) : void;

	abstract public function syncSet(ChunkManager $level, Block $block, ?callable $callable = null) : void;

	abstract public function asyncReplace(Level $level, Block $find, Block $replace, ?callable $callable = null) : void;

	abstract public function syncReplace(ChunkManager $level, Block $find, Block $replace, ?callable $callable = null) : void;

	abstract public function asyncRotate(Level $level, int $direction, ?callable $callable = null) : void;

	abstract public function syncRotate(ChunkManager $level, int $direction, ?callable $callable = null) : void;

	/**
	 * @param ChunkManager $level
	 * @return Chunk[]|null[]
	 */
	abstract protected function getChunks(ChunkManager $level) : array;

}