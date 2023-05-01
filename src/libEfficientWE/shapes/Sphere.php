<?php

declare(strict_types=1);

namespace libEfficientWE\shapes;

use libEfficientWE\task\SphereTask;
use pocketmine\block\Block;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Vector3;
use pocketmine\promise\Promise;
use pocketmine\promise\PromiseResolver;
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
 * A representation of a sphere shape.
 *
 * @phpstan-import-type BlockPosHash from World
 */
class Sphere extends Shape {

	protected float $radius;

	private function __construct(protected Vector3 $center, float $radius) {
		$this->radius = abs($radius);
		parent::__construct(null);
	}

	public function getCenter() : Vector3{
		return $this->center;
	}

	public function getRadius() : float {
		return $this->radius;
	}

	public static function fromVector3(Vector3 $min, Vector3 $max) : Shape {
		$minX = min($min->x, $max->x);
		$minY = min($min->y, $max->y);
		$minZ = min($min->z, $max->z);
		$maxX = max($min->x, $max->x);
		$maxY = max($min->y, $max->y);
		$maxZ = max($min->z, $max->z);

		$center = new Vector3($minX + $maxX / 2, $minY + $maxY / 2, $minZ + $maxZ / 2);
		$radius = $maxY - $minY / 2;
		return new self($center, $radius);
	}

	public static function fromAABB(AxisAlignedBB $alignedBB) : Shape {
		$minX = min($alignedBB->minX, $alignedBB->maxX);
		$minY = min($alignedBB->minY, $alignedBB->maxY);
		$minZ = min($alignedBB->minZ, $alignedBB->maxZ);
		$maxX = max($alignedBB->minX, $alignedBB->maxX);
		$maxY = max($alignedBB->minY, $alignedBB->maxY);
		$maxZ = max($alignedBB->minZ, $alignedBB->maxZ);

		$center = new Vector3($minX + $maxX / 2, $minY + $maxY / 2, $minZ + $maxZ / 2);
		$radius = $maxY - $minY / 2;
		return new self($center, $radius);
	}

	public function copy(World $world, Vector3 $worldPos) : void{
		$absoluteBasePos = $this->center->subtractVector($worldPos->floor());

		$relativeMaximums = $this->center->add($this->radius, $this->radius, $this->radius);
		$xCap = $relativeMaximums->x;
		$yCap = $relativeMaximums->y;
		$zCap = $relativeMaximums->z;

		$relativeMinimums = $this->center->subtract($this->radius, $this->radius, $this->radius);
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
					if($this->center->distance(new Vector3($x, $y, $z)) <= $this->radius && $subChunkExplorer->moveTo($ax, $ay, $az) !== SubChunkExplorerStatus::INVALID) {
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

		$world->getServer()->getAsyncPool()->submitTask(new SphereTask(
			$world->getId(),
			$chunkX,
			$chunkZ,
			$centerChunk,
			$adjacentChunks,
			$this->clipboard,
			$worldPos,
			$this->radius,
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
