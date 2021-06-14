<?php
declare(strict_types=1);
namespace libEfficientWE\task;

use libEfficientWE\shapes\Cuboid;
use pocketmine\level\Level;

class AsyncCuboidTask extends AsyncChunksChangeTask {

	protected int $action;
	protected bool $set_chunks = false;
	private string $cuboid;

	public function __construct(Cuboid $cuboid, Level $level, array $chunks, int $action, ?callable $callable = null) {
		$this->cuboid = self::serialize($cuboid);

		$this->setClipboard($cuboid->getClipboard());
		$this->setLevel($level);
		$this->setChunks($chunks);
		$this->action = $action;
		$this->setCallable($callable);
	}

	public function onRun() : void {
		$level = $this->getChunkManager();
		$cuboid = $this->getCuboid();
		$cuboid->setClipboard($this->getClipboard());


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

	protected function getCuboid() : Cuboid {
		return self::unserialize($this->cuboid);
	}
}