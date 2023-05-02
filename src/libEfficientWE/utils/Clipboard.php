<?php

declare(strict_types=1);

namespace libEfficientWE\utils;

use pocketmine\math\Vector3;
use pocketmine\world\World;

/**
 * @phpstan-import-type BlockPosHash from World
 */
final class Clipboard{

	/** @phpstan-var array<BlockPosHash, int|null> $fullBlocks */
	private array $fullBlocks = [];
	private ?Vector3 $worldMin;
	private ?Vector3 $worldMax = null;

	/**
	 * @phpstan-return array<BlockPosHash, int|null>
	 */
	public function getFullBlocks() : array{
		return $this->fullBlocks;
	}

	/**
	 * @phpstan-param array<BlockPosHash, int|null> $fullBlocks
	 */
	public function setFullBlocks(array $fullBlocks) : self{
		$this->fullBlocks = $fullBlocks;
		return $this;
	}

	public function getWorldMin() : Vector3{
		if($this->worldMin === null)
			throw new \RuntimeException("WorldMin is not set");
		return $this->worldMin;
	}

	public function setWorldMin(Vector3 $worldMin) : self{
		$this->worldMin = $worldMin;
		return $this;
	}

	public function getWorldMax() : Vector3{
		if($this->worldMax === null)
			throw new \RuntimeException("WorldMax is not set");
		return $this->worldMax;
	}

	public function setWorldMax(Vector3 $worldMax) : self{
		$this->worldMax = $worldMax;
		return $this;
	}

}
