<?php
declare(strict_types=1);
namespace libEfficientWE\utils;

use pocketmine\math\Vector3;

class Clipboard {

	/** @var int[]|null */
	private $fullBlocks;

	/** @var Vector3|null */
	private $relativePos;

	/** @var Vector3|null */
	private $capVector;

	/**
	 * @return int[]|null
	 */
	public function getFullBlocks() : ?array {
		return $this->fullBlocks;
	}

	/**
	 * @param int[] $fullBlocks
	 */
	public function setFullBlocks(array $fullBlocks) : self {
		$this->fullBlocks = $fullBlocks;
		return $this;
	}

	public function getRelativePos() : ?Vector3 {
		return $this->relativePos;
	}

	public function setRelativePos(Vector3 $relativePos) : self {
		$this->relativePos = $relativePos;
		return $this;
	}

	public function getCapVector() : ?Vector3 {
		return $this->capVector;
	}

	public function setCapVector(Vector3 $capVector) : self {
		$this->capVector = $capVector;
		return $this;
	}

}