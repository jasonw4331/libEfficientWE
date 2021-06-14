<?php
declare(strict_types=1);

namespace libEfficientWE\task;

use libEfficientWE\shapes\Sphere;
use pocketmine\level\Level;

class AsyncSphereTask extends AsyncChunksChangeTask {

	private string $sphere;
	private int $action;

	public function __construct(Sphere $sphere, Level $level, array $chunks, int $action, ?callable $callable = null) {
		$this->sphere = self::serialize($sphere);

		$this->setClipboard($sphere->getClipboard());
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
		$sphere = $this->getSphere();
		$sphere->setClipboard($this->getClipboard());

		switch($this->action) {
			case self::PASTE:
				$sphere->syncPaste($level, $this->relativePos, $this->replaceAir, [$this, "updateStatistics"]);

				$caps = $sphere->getClipboard()->getCapVector();
				$minPos = $this->relativePos;
				$maxPos = $this->relativePos->add($caps);
				$this->saveChunks($level, $minPos, $maxPos);
				break;
			case self::REPLACE:
				$sphere->syncReplace($level, $this->find, $this->replace, [$this, "updateStatistics"]);
				$this->saveChunks($level, $sphere->getLowCorner(), $sphere->getHighCorner());
				break;
			case self::SET:
				$sphere->syncSet($level, $this->block, [$this, "updateStatistics"]);
				$this->saveChunks($level, $sphere->getLowCorner(), $sphere->getHighCorner());
				break;
		}
	}

	protected function getSphere() : Sphere {
		return self::unserialize($this->sphere);
	}
}