<?php

declare(strict_types=1);

namespace libEfficientWE\shapes;

use libEfficientWE\task\CylinderTask;
use pocketmine\block\Block;
use pocketmine\math\Axis;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Vector2;
use pocketmine\math\Vector3;
use pocketmine\promise\Promise;
use pocketmine\promise\PromiseResolver;
use pocketmine\utils\AssumptionFailedError;
use pocketmine\world\format\Chunk;
use pocketmine\world\format\SubChunk;
use pocketmine\world\utils\SubChunkExplorer;
use pocketmine\world\utils\SubChunkExplorerStatus;
use pocketmine\world\World;
use function abs;
use function max;
use function microtime;
use function min;

/**
 * A representation of a cylinder shape. The default axis is {@link Axis::Y}, making the cylinder base at its lowest coordinate, but it can be
 * changed to {@link Axis::X} or {@link Axis::Z} to be horizontal instead.
 *
 * @phpstan-import-type BlockPosHash from World
 */
class Cylinder extends Shape {

	protected float $radius;

	private function __construct(protected Vector3 $centerOfBase, float $radius, protected float $height, protected int $axis) {
		$this->radius = abs($radius);
		parent::__construct(null);
	}

	public function getCenterOfBase() : Vector3{
		return $this->centerOfBase;
	}

	public function getRadius() : float {
		return $this->radius;
	}

	public function getHeight() : float {
		return $this->height;
	}

	public function getAxis() : int{
		return $this->axis;
	}

	public static function fromVector3(Vector3 $min, Vector3 $max, int $axis = Axis::Y) : Shape {
		$minX = min($min->x, $max->x);
		$minY = min($min->y, $max->y);
		$minZ = min($min->z, $max->z);
		$maxX = max($min->x, $max->x);
		$maxY = max($min->y, $max->y);
		$maxZ = max($min->z, $max->z);
		if($axis !== Axis::X && $axis !== Axis::Y && $axis !== Axis::Z) {
			throw new \InvalidArgumentException("Axis must be one of Axis::X, Axis::Y or Axis::Z");
		}

		$relativeCenterOfBase = (match($axis) {
			Axis::Y => new Vector3($minX + $maxX / 2, $minY, $minZ + $maxZ / 2),
			Axis::X => new Vector3($minX, $minY + $maxY / 2, $minZ + $maxZ / 2),
			Axis::Z => new Vector3($minX + $maxX / 2, $minY + $maxY / 2, $minZ)
		})->subtract($minX, $minY, $minZ);
		$radius = match($axis) {
			Axis::Y => min($maxX - $minX, $maxZ - $minZ) / 2,
			Axis::X => min($maxY - $minY, $maxZ - $minZ) / 2,
			Axis::Z => min($maxX - $minX, $maxY - $minY) / 2
		};
		$height = match($axis) {
			Axis::Y => $maxY - $minY,
			Axis::X => $maxX - $minX,
			Axis::Z => $maxZ - $minZ
		};
		return new self($relativeCenterOfBase, $radius, $height, $axis);
	}

	public static function fromAABB(AxisAlignedBB $alignedBB, int $axis = Axis::Y) : Shape {
		$minX = (int) min($alignedBB->minX, $alignedBB->maxX);
		$minY = (int) min($alignedBB->minY, $alignedBB->maxY);
		$minZ = (int) min($alignedBB->minZ, $alignedBB->maxZ);
		$maxX = (int) max($alignedBB->minX, $alignedBB->maxX);
		$maxY = (int) max($alignedBB->minY, $alignedBB->maxY);
		$maxZ = (int) max($alignedBB->minZ, $alignedBB->maxZ);
		if($axis !== Axis::X && $axis !== Axis::Y && $axis !== Axis::Z) {
			throw new \InvalidArgumentException("Axis must be one of Axis::X, Axis::Y or Axis::Z");
		}

		$relativeCenterOfBase = (match($axis) {
			Axis::Y => new Vector3($minX + $maxX / 2, $minY, $minZ + $maxZ / 2),
			Axis::X => new Vector3($minX, $minY + $maxY / 2, $minZ + $maxZ / 2),
			Axis::Z => new Vector3($minX + $maxX / 2, $minY + $maxY / 2, $minZ)
		})->subtract($minX, $minY, $minZ);
		$radius = match($axis) {
			Axis::Y => min($maxX - $minX, $maxZ - $minZ) / 2,
			Axis::X => min($maxY - $minY, $maxZ - $minZ) / 2,
			Axis::Z => min($maxX - $minX, $maxY - $minY) / 2
		};
		$height = match($axis) {
			Axis::Y => $maxY - $minY,
			Axis::X => $maxX - $minX,
			Axis::Z => $maxZ - $minZ
		};
		return new self($relativeCenterOfBase, $radius, $height, $axis);
	}

	public function copy(World $world, Vector3 $worldPos) : void{
		$absoluteBasePos = $this->centerOfBase->subtractVector($worldPos->floor());

		$relativeMaximums = $this->centerOfBase->add($this->radius, $this->height, $this->radius);
		$xCap = $relativeMaximums->x;
		$yCap = $relativeMaximums->y;
		$zCap = $relativeMaximums->z;

		$relativeMinimums = $this->centerOfBase->subtract($this->radius, 0, $this->radius);
		$minX = $relativeMinimums->x;
		$minY = $relativeMinimums->y;
		$minZ = $relativeMinimums->z;

		/** @var array<BlockPosHash, int|null> $blocks */
		$blocks = [];
		$subChunkExplorer = new SubChunkExplorer($world);

		// loop from min to max if coordinate is in cylinder, save fullblockId
		for($x = 0; $x <= $xCap; ++$x) {
			$ax = (int) floor($minX + $x);
			for($z = 0; $z <= $zCap; ++$z) {
				$az = (int) floor($minZ + $z);
				for($y = 0; $y <= $yCap; ++$y) {
					$ay = (int) floor($minY + $y);
					// check if coordinate is in cylinder depending on axis
					$inCylinder = match ($this->axis) {
						Axis::Y => (new Vector2($this->centerOfBase->x, $this->centerOfBase->z))->distanceSquared(new Vector2($x, $z)) <= $this->radius ** 2 && $y <= $this->height,
						Axis::X => (new Vector2($this->centerOfBase->y, $this->centerOfBase->z))->distanceSquared(new Vector2($y, $z)) <= $this->radius ** 2 && $x <= $this->height,
						Axis::Z => (new Vector2($this->centerOfBase->x, $this->centerOfBase->y))->distanceSquared(new Vector2($x, $y)) <= $this->radius ** 2 && $z <= $this->height,
						default => throw new AssumptionFailedError("Invalid axis $this->axis")
					};
					if($inCylinder && $subChunkExplorer->moveTo($ax, $ay, $az) !== SubChunkExplorerStatus::INVALID) {
						$blocks[World::blockHash($ax, $ay, $az)] = $subChunkExplorer->currentSubChunk?->getFullBlock($ax & SubChunk::COORD_MASK, $ay & SubChunk::COORD_MASK, $az & SubChunk::COORD_MASK);
					}
				}
			}
		}

		$this->clipboard->setFullBlocks($blocks)->setRelativePos($absoluteBasePos)->setCapVector($relativeMaximums);
	}

	public function paste(World $world, Vector3 $worldPos, bool $replaceAir, ?PromiseResolver $resolver = null) : Promise{
		$time = microtime(true);
		$resolver ??= new PromiseResolver();

		[$chunkX, $chunkZ, $temporaryChunkLoader, $chunkPopulationLockId, $centerChunk, $adjacentChunks] = $this->prepWorld($world);

		$world->getServer()->getAsyncPool()->submitTask(new CylinderTask(
			$world->getId(),
			$chunkX,
			$chunkZ,
			$centerChunk,
			$adjacentChunks,
			$this->clipboard,
			$worldPos,
			$this->radius,
			$this->height,
			true,
			$replaceAir,
			static function(Chunk $centerChunk, array $adjacentChunks, int $changedBlocks) use ($time, $world, $chunkX, $chunkZ, $temporaryChunkLoader, $chunkPopulationLockId, $resolver) : void{
				if(!static::resolveWorld($world, $chunkX, $chunkZ, $temporaryChunkLoader, $chunkPopulationLockId)) {
					$resolver->reject();
					return;
				}

				$resolver->resolve([
					'chunks' => [$centerChunk] + $adjacentChunks,
					'time' => microtime(true) - $time,
					'blockCount' => $changedBlocks,
				]);
			}
		));
		return $resolver->getPromise();
	}

	public function set(World $world, Block $block, bool $fill, ?PromiseResolver $resolver = null) : Promise{
		// TODO: Implement set() method.
	}

	public function replace(World $world, Block $find, Block $replace, ?PromiseResolver $resolver = null) : Promise{
		// TODO: Implement replace() method.
	}
}
