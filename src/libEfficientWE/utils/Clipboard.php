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
	private ?Vector3 $relativePos;
	private ?Vector3 $capVector;

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

	public function getRelativePos() : Vector3{
		if($this->relativePos === null)
			throw new \RuntimeException("RelativePos is not set");
		return $this->relativePos;
	}

	public function setRelativePos(Vector3 $relativePos) : self{
		$this->relativePos = $relativePos;
		return $this;
	}

	public function getCapVector() : Vector3{
		if($this->capVector === null)
			throw new \RuntimeException("CapVector is not set");
		return $this->capVector;
	}

	public function setCapVector(Vector3 $capVector) : self{
		$this->capVector = $capVector;
		return $this;
	}

}
