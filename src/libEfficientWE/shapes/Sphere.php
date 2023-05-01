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
		$minX = (int)min($min->x, $max->x);
		$minY = (int)min($min->y, $max->y);
		$minZ = (int)min($min->z, $max->z);
		$maxX = (int)max($min->x, $max->x);
		$maxY = (int)max($min->y, $max->y);
		$maxZ = (int)max($min->z, $max->z);

		$center = new Vector3(($maxX + $minX) / 2, ($maxY + $minY) / 2, ($maxZ + $minZ) / 2);
		$radius = ($maxX - $minX) / 2;
		return new self($center, $radius);
	}

	public static function fromAABB(AxisAlignedBB $alignedBB) : Shape {
		$minX = (int)min($alignedBB->minX, $alignedBB->maxX);
		$minY = (int)min($alignedBB->minY, $alignedBB->maxY);
		$minZ = (int)min($alignedBB->minZ, $alignedBB->maxZ);
		$maxX = (int)max($alignedBB->minX, $alignedBB->maxX);
		$maxY = (int)max($alignedBB->minY, $alignedBB->maxY);
		$maxZ = (int)max($alignedBB->minZ, $alignedBB->maxZ);

		$center = new Vector3(($maxX + $minX) / 2, ($maxY + $minY) / 2, ($maxZ + $minZ) / 2);
		$radius = ($maxX - $minX) / 2;
		return new self($center, $radius);
	}

	public function copy(World $world, Vector3 $relativeCenter) : void{
		$absoluteBasePos = $this->relativeCenter->subtractVector($relativeCenter->floor());

		$relativeMaximums = $this->relativeCenter->add($this->radius, $this->radius, $this->radius);
		$xCap = $relativeMaximums->x;
		$yCap = $relativeMaximums->y;
		$zCap = $relativeMaximums->z;

		$relativeMinimums = $this->relativeCenter->subtract($this->radius, $this->radius, $this->radius);
		$minX = $relativeMinimums->x;
		$minY = $relativeMinimums->y;
		$minZ = $relativeMinimums->z;

		/** @var array<BlockPosHash, int|null> $blocks */
		$blocks = [];
		$subChunkExplorer = new SubChunkExplorer($world);

		// loop from min to max if coordinate is in cylinder, save fullblockId
		for($x = 0; $x <= $xCap; ++$x) {
			$ax = $minX + $x;
			for($z = 0; $z <= $zCap; ++$z) {
				$az = $minZ + $z;
				for($y = 0; $y <= $yCap; ++$y) {
					$ay = $minY + $y;
					if($this->relativeCenter->distance(new Vector3($x, $y, $z)) <= $this->radius && $subChunkExplorer->moveTo((int) $ax, (int) $ay, (int) $az) !== SubChunkExplorerStatus::INVALID) {
						$blocks[World::blockHash($ax, $ay, $az)] = $subChunkExplorer->currentSubChunk?->getFullBlock($ax & SubChunk::COORD_MASK, $ay & SubChunk::COORD_MASK, $az & SubChunk::COORD_MASK);
					}
				}
			}
		}

		$this->clipboard->setFullBlocks($blocks)->setRelativePos($absoluteBasePos)->setCapVector($relativeMaximums);
	}

	public function paste(World $world, Vector3 $relativePos, bool $replaceAir, ?PromiseResolver $resolver = null) : Promise{
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
			$relativePos,
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