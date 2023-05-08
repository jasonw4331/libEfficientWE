<?php

declare(strict_types=1);

namespace libEfficientWE\utils;

use pocketmine\math\Vector3;
use RuntimeException;

final class Clipboard{

	/** @phpstan-var array<int, int|null> $blockStateIds */
	private array $blockStateIds = [];
	private ?Vector3 $worldMin;
	private ?Vector3 $worldMax;

	/**
	 * @phpstan-return array<int, int|null>
	 */
	public function getBlockStateIds() : array{
		return $this->blockStateIds;
	}

	/**
	 * @phpstan-param array<int, int|null> $blockStateIds
	 */
	public function setBlockStateIds(array $blockStateIds) : self{
		$this->blockStateIds = $blockStateIds;
		return $this;
	}

	public function getWorldMin() : Vector3{
		if($this->worldMin === null)
			throw new RuntimeException("WorldMin is not set");
		return $this->worldMin;
	}

	public function setWorldMin(Vector3 $worldMin) : self{
		$this->worldMin = $worldMin;
		return $this;
	}

	public function getWorldMax() : Vector3{
		if($this->worldMax === null)
			throw new RuntimeException("WorldMax is not set");
		return $this->worldMax;
	}

	public function setWorldMax(Vector3 $worldMax) : self{
		$this->worldMax = $worldMax;
		return $this;
	}

}
