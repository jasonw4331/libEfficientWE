<?php
declare(strict_types=1);
namespace libEfficientWE\task;

use libEfficientWE\shapes\Cuboid;
use pocketmine\block\Block;
use pocketmine\level\Level;
use pocketmine\math\Vector3;

class AsyncCuboidTask extends AsyncChunksChangeTask {

	public const PASTE = 0;
	public const REPLACE = 0;
	public const SET = 0;
	/** @var int */
	protected $action;
	/** @var bool */
	protected $set_chunks = false;
	/** @var string */
	private $cuboid;
	/** @var Vector3 */
	private $relativePos;
	/** @var bool */
	private $replaceAir;
	/** @var Block */
	private $find;
	/** @var Block */
	private $replace;
	/** @var Block */
	private $block;

	public function __construct(Cuboid $cuboid, Level $level, array $chunks, int $action, ?callable $callable = null) {
		$this->cuboid = self::serialize($cuboid);

		$this->setLevel($level);
		$this->setChunks($chunks);
		$this->action = $action;
		$this->setCallable($callable);
	}

	public function onRun() : void {
		$level = $this->getChunkManager();
		$cuboid = $this->getCuboid();

		switch($this->action) {
			case self::PASTE:
				$cuboid->syncPaste($level, $this->relativePos, $this->replaceAir, [$this, "updateStatistics"]);

				$caps = $cuboid->getClipboard()->getCapVector();
				$minPos = $this->relativePos;
				$maxPos = $this->relativePos->add($caps);
				$this->saveChunks($level, $minPos, $maxPos);
				break;
			case self::REPLACE:
				$cuboid->syncReplace($level, $this->find, $this->replace, [$this, "updateStatistics"]);
				$this->saveChunks($level, $cuboid->getLowCorner(), $cuboid->getHighCorner());
				break;
			case self::SET:
				$cuboid->syncSet($level, $this->block, [$this, "updateStatistics"]);
				$this->saveChunks($level, $cuboid->getLowCorner(), $cuboid->getHighCorner());
				break;
		}
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

	protected function getCuboid() : Cuboid {
		return self::unserialize($this->cuboid);
	}
}