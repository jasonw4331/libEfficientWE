<?php
declare(strict_types=1);

namespace libEfficientWE\task;

use libEfficientWE\shapes\Cylinder;
use pocketmine\level\Level;

class AsyncCylinderTask extends AsyncChunksChangeTask {

	private string $cylinder;
	private int $action;

	public function __construct(Cylinder $cylinder, Level $level, array $chunks, int $action, ?callable $callable = null) {
		$this->cylinder = self::serialize($cylinder);

		$this->setClipboard($cylinder->getClipboard());
		$this->setLevel($level);
		$this->setChunks($chunks);
		$this->action = $action;
		$this->setCallable($callable);
	}

	/**
	 * @inheritDoc
	 */
	public function onRun() {
		$level = $this->getChunkManager();
		$cuboid = $this->getCylinder();
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

	protected function getCylinder() : Cylinder {
		return self::unserialize($this->cylinder);
	}
}