<?php
declare(strict_types=1);

namespace libEfficientWE\shapes;

use libEfficientWE\utils\Clipboard;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Vector3;

abstract class Shape {

	protected Clipboard $clipboard;

	public function __construct(?Clipboard $clipboard = null) {
		$this->clipboard = $clipboard ?? new Clipboard();
	}

	abstract static function fromVector3(Vector3 $min, Vector3 $max) : self;

	abstract static function fromAABB(AxisAlignedBB $alignedBB) : self;

	public function getClipboard() : Clipboard {
		return $this->clipboard;
	}

	public function setClipboard(Clipboard $clipboard) : self {
		$this->clipboard = $clipboard;
		return $this;
	}

}