<?php

declare(strict_types=1);

namespace libEfficientWE\shapes;

use libEfficientWE\task\write\ConeTask;
use pocketmine\block\Block;
use pocketmine\math\Axis;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Facing;
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
use function array_map;
use function floor;
use function max;
use function microtime;
use function min;
use function morton3d_encode;

/**
 * A representation of a cone shape. The facing direction determines the face of the cone's tip. The cone's base is
 * always the opposite face of passed {@link Facing} direction.
 */
class Cone extends Shape{

	protected float $radius;

	private function __construct(protected Vector3 $centerOfBase, float $radius, protected float $height, protected int $facing){
		$this->radius = abs($radius);
		parent::__construct(null);
	}

	public function getCenterOfBase() : Vector3{
		return $this->centerOfBase;
	}

	public function getRadius() : float{
		return $this->radius;
	}

	public function getHeight() : float{
		return $this->height;
	}

	public function getDirection() : int{
		return $this->facing;
	}

	/**
	 * Returns the largest {@link Cone} object which fits between of the given {@link Vector3} objects. The cone's tip
	 * will be the difference between the two given {@link Vector3} objects for a given {@link Facing} direction.
	 */
	public static function fromVector3(Vector3 $min, Vector3 $max, int $facing = Facing::UP) : Shape{
		$minX = min($min->x, $max->x);
		$minY = min($min->y, $max->y);
		$minZ = min($min->z, $max->z);
		$maxX = max($min->x, $max->x);
		$maxY = max($min->y, $max->y);
		$maxZ = max($min->z, $max->z);
		Facing::validate($facing);

		if(self::canMortonEncode($maxX - $minX, $maxY - $minY, $maxZ - $minZ)){
			throw new \InvalidArgumentException("Sphere diameter must be less than 2^20 blocks");
		}

		$axis = Facing::axis($facing);
		$relativeCenterOfBase = (match ($axis) {
			Axis::Y => new Vector3($minX + $maxX / 2, Facing::isPositive($facing) ? $maxY : $minY, $minZ + $maxZ / 2),
			Axis::X => new Vector3(Facing::isPositive($facing) ? $maxX : $minX, $minY + $maxY / 2, $minZ + $maxZ / 2),
			Axis::Z => new Vector3($minX + $maxX / 2, $minY + $maxY / 2, Facing::isPositive($facing) ? $maxZ : $minZ),
			default => throw new AssumptionFailedError("Unhandled axis $axis")
		})->subtract($minX, $minY, $minZ);
		$height = match ($axis) {
			Axis::Y => $maxY - $minY,
			Axis::X => $maxX - $minX,
			Axis::Z => $maxZ - $minZ,
			default => throw new AssumptionFailedError("Unhandled axis $axis")
		};
		$radius = match ($axis) {
			Axis::Y => min($maxX - $minX, $maxZ - $minZ) / 2,
			Axis::X => min($maxY - $minY, $maxZ - $minZ) / 2,
			Axis::Z => min($maxX - $minX, $maxY - $minY) / 2,
			default => throw new AssumptionFailedError("Unhandled axis $axis")
		};

		return new self($relativeCenterOfBase, $radius, $height, $facing);
	}

	/**
	 * Returns the largest {@link Cone} object which fits inside of the given {@link AxisAlignedBB} object. The cone's
	 * tip will be at the center of the given {@link AxisAlignedBB} object for a given {@link Facing} direction.
	 */
	public static function fromAABB(AxisAlignedBB $alignedBB, int $facing = Facing::UP) : Shape{
		$minX = min($alignedBB->minX, $alignedBB->maxX);
		$minY = min($alignedBB->minY, $alignedBB->maxY);
		$minZ = min($alignedBB->minZ, $alignedBB->maxZ);
		$maxX = max($alignedBB->minX, $alignedBB->maxX);
		$maxY = max($alignedBB->minY, $alignedBB->maxY);
		$maxZ = max($alignedBB->minZ, $alignedBB->maxZ);
		Facing::validate($facing);

		if(self::canMortonEncode($maxX - $minX, $maxY - $minY, $maxZ - $minZ)){
			throw new \InvalidArgumentException("Sphere diameter must be less than 2^20 blocks");
		}

		$axis = Facing::axis($facing);
		$relativeCenterOfBase = (match ($axis) {
			Axis::Y => new Vector3($minX + $maxX / 2, Facing::isPositive($facing) ? $maxY : $minY, $minZ + $maxZ / 2),
			Axis::X => new Vector3(Facing::isPositive($facing) ? $maxX : $minX, $minY + $maxY / 2, $minZ + $maxZ / 2),
			Axis::Z => new Vector3($minX + $maxX / 2, $minY + $maxY / 2, Facing::isPositive($facing) ? $maxZ : $minZ),
			default => throw new AssumptionFailedError("Unhandled axis $axis")
		})->subtract($minX, $minY, $minZ);
		$height = match ($axis) {
			Axis::Y => $maxY - $minY,
			Axis::X => $maxX - $minX,
			Axis::Z => $maxZ - $minZ,
			default => throw new AssumptionFailedError("Unhandled axis $axis")
		};
		$radius = match ($axis) {
			Axis::Y => min($maxX - $minX, $maxZ - $minZ) / 2,
			Axis::X => min($maxY - $minY, $maxZ - $minZ) / 2,
			Axis::Z => min($maxX - $minX, $maxY - $minY) / 2,
			default => throw new AssumptionFailedError("Unhandled axis $axis")
		};

		return new self($relativeCenterOfBase, $radius, $height, $facing);
	}

	public function copy(World $world, Vector3 $worldPos) : void{
		$maxVector = match ($this->facing) {
			Facing::UP => $this->centerOfBase->add($this->radius, $this->height, $this->radius),
			Facing::DOWN => $this->centerOfBase->add($this->radius, 0, $this->radius),
			Facing::SOUTH => $this->centerOfBase->add($this->radius, $this->radius, $this->height),
			Facing::NORTH => $this->centerOfBase->add($this->radius, $this->radius, 0),
			Facing::EAST => $this->centerOfBase->add($this->height, $this->radius, $this->radius),
			Facing::WEST => $this->centerOfBase->add(0, $this->radius, $this->radius),
		};
		$minVector = match ($this->facing) {
			Facing::UP => $this->centerOfBase->subtract($this->radius, 0, $this->radius),
			Facing::DOWN => $this->centerOfBase->subtract($this->radius, $this->height, $this->radius),
			Facing::SOUTH => $this->centerOfBase->subtract($this->radius, $this->radius, 0),
			Facing::NORTH => $this->centerOfBase->subtract($this->radius, $this->radius, $this->height),
			Facing::EAST => $this->centerOfBase->subtract(0, $this->radius, $this->radius),
			Facing::WEST => $this->centerOfBase->subtract($this->height, $this->radius, $this->radius),
		};

		$maxX = $maxVector->x;
		$maxY = $maxVector->y;
		$maxZ = $maxVector->z;

		$minX = $minVector->x;
		$minY = $minVector->y;
		$minZ = $minVector->z;

		/** @var array<BlockPosHash, int|null> $blocks */
		$blocks = [];
		$subChunkExplorer = new SubChunkExplorer($world);

		$coneTip = match ($this->facing) {
			Facing::UP => $this->centerOfBase->add(0, $this->height, 0),
			Facing::DOWN => $this->centerOfBase->subtract(0, $this->height, 0),
			Facing::SOUTH => $this->centerOfBase->add(0, 0, $this->height),
			Facing::NORTH => $this->centerOfBase->subtract(0, 0, $this->height),
			Facing::EAST => $this->centerOfBase->add($this->height, 0, 0),
			Facing::WEST => $this->centerOfBase->subtract($this->height, 0, 0),
			default => throw new AssumptionFailedError("Unhandled facing $this->facing")
		};
		$axisVector = (new Vector3(0, -$this->height, 0))->normalize();

		// loop from 0 to max. if coordinate is in cone, save fullblockId
		for($x = 0; $x <= $maxX; ++$x){
			$ax = (int) floor($minX + $x);
			for($z = 0; $z <= $maxZ; ++$z){
				$az = (int) floor($minZ + $z);
				for($y = 0; $y <= $maxY; ++$y){
					$ay = (int) floor($minY + $y);
					// check if coordinate is in cylinder depending on axis
					$relativePoint = match ($this->facing) {
						Facing::UP => new Vector3($x, $y, $z),
						Facing::DOWN => new Vector3($x, $this->height - $y, $z),
						Facing::SOUTH => new Vector3($x, $z, $y),
						Facing::NORTH => new Vector3($x, $z, $this->height - $y),
						Facing::EAST => new Vector3($y, $z, $x),
						Facing::WEST => new Vector3($this->height - $y, $z, $x),
						default => throw new AssumptionFailedError("Unhandled facing $this->facing")
					};

					$relativeVector = $relativePoint->subtractVector($coneTip);
					$projectionLength = $axisVector->dot($relativeVector);
					$projection = $axisVector->multiply($projectionLength);
					$orthogonalVector = $relativeVector->subtractVector($projection);
					$orthogonalDistance = $orthogonalVector->length();
					$maxRadiusAtHeight = $projectionLength / $this->height * $this->radius;
					$inCone = $orthogonalDistance <= $maxRadiusAtHeight;

					if($inCone && $subChunkExplorer->moveTo($ax, $ay, $az) !== SubChunkExplorerStatus::INVALID){
						$blocks[morton3d_encode($x, $y, $z)] = $subChunkExplorer->currentSubChunk?->getFullBlock($ax & SubChunk::COORD_MASK, $ay & SubChunk::COORD_MASK, $az & SubChunk::COORD_MASK);
					}
				}
			}
		}

		$this->clipboard->setFullBlocks($blocks)->setWorldMin($worldPos)->setWorldMax($worldPos->addVector($maxVector));
	}

	/**
	 * @inheritDoc
	 */
	public function paste(World $world, Vector3 $worldPos, bool $replaceAir, ?PromiseResolver $resolver = null) : Promise{
		$time = microtime(true);
		$resolver ??= new PromiseResolver();

		[$chunkX, $chunkZ, $temporaryChunkLoader, $chunkPopulationLockId, $centerChunk, $adjacentChunks] = $this->prepWorld($world);

		$world->getServer()->getAsyncPool()->submitTask(new ConeTask(
			$world->getId(),
			$chunkX,
			$chunkZ,
			$centerChunk,
			$adjacentChunks,
			$this->clipboard,
			$worldPos,
			$this->radius,
			$this->height,
			$this->centerOfBase,
			$this->facing,
			true,
			$replaceAir,
			static function(Chunk $centerChunk, array $adjacentChunks, int $changedBlocks) use ($time, $world, $chunkX, $chunkZ, $temporaryChunkLoader, $chunkPopulationLockId, $resolver) : void{
				if(!static::resolveWorld($world, $chunkX, $chunkZ, $temporaryChunkLoader, $chunkPopulationLockId)){
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
		$time = microtime(true);
		$resolver ??= new PromiseResolver();

		[$chunkX, $chunkZ, $temporaryChunkLoader, $chunkPopulationLockId, $centerChunk, $adjacentChunks] = $this->prepWorld($world);

		// edit all clipboard block ids to be $block->getFullId()
		$setClipboard = clone $this->clipboard;
		$setClipboard->setFullBlocks(array_map(static fn(?int $fullBlock) => $block->getFullId(), $setClipboard->getFullBlocks()));

		$world->getServer()->getAsyncPool()->submitTask(new ConeTask(
			$world->getId(),
			$chunkX,
			$chunkZ,
			$centerChunk,
			$adjacentChunks,
			$setClipboard,
			$setClipboard->getWorldMin(),
			$this->radius,
			$this->height,
			$this->centerOfBase,
			$this->facing,
			$fill,
			true,
			static function(Chunk $centerChunk, array $adjacentChunks, int $changedBlocks) use ($time, $world, $chunkX, $chunkZ, $temporaryChunkLoader, $chunkPopulationLockId, $resolver) : void{
				if(!static::resolveWorld($world, $chunkX, $chunkZ, $temporaryChunkLoader, $chunkPopulationLockId)){
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

	public function replace(World $world, Block $find, Block $replace, ?PromiseResolver $resolver = null) : Promise{
		$time = microtime(true);
		$resolver ??= new PromiseResolver();

		[$chunkX, $chunkZ, $temporaryChunkLoader, $chunkPopulationLockId, $centerChunk, $adjacentChunks] = $this->prepWorld($world);

		// edit all clipboard block ids to be $block->getFullId()
		$replaceClipboard = clone $this->clipboard;
		$replaceClipboard->setFullBlocks(array_map(static fn(?int $fullBlock) => $fullBlock === $find->getFullId() ? $replace->getFullId() : $fullBlock, $replaceClipboard->getFullBlocks()));

		$world->getServer()->getAsyncPool()->submitTask(new ConeTask(
			$world->getId(),
			$chunkX,
			$chunkZ,
			$centerChunk,
			$adjacentChunks,
			$replaceClipboard,
			$replaceClipboard->getWorldMin(),
			$this->radius,
			$this->height,
			$this->centerOfBase,
			$this->facing,
			true,
			true,
			static function(Chunk $centerChunk, array $adjacentChunks, int $changedBlocks) use ($time, $world, $chunkX, $chunkZ, $temporaryChunkLoader, $chunkPopulationLockId, $resolver) : void{
				if(!static::resolveWorld($world, $chunkX, $chunkZ, $temporaryChunkLoader, $chunkPopulationLockId)){
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
}
