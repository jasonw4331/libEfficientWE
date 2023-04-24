<?php

declare(strict_types=1);

namespace libEfficientWE\shapes;

use libEfficientWE\task\CylinderTask;
use pocketmine\block\Block;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Vector2;
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
use function min;

/**
 * @phpstan-import-type BlockPosHash from World
 */
class Cylinder extends Shape {

	protected float $radius;

	private function __construct(protected Vector3 $relativeCenter, float $radius, protected int $height) {
		$this->radius = abs($radius);
		parent::__construct(null);
	}

	public function getRelativeCenter() : Vector3{
		return $this->relativeCenter;
	}

	public function getRadius() : float {
		return $this->radius;
	}

	public function getHeight() : int {
		return $this->height;
	}

	public static function fromVector3(Vector3 $min, Vector3 $max) : Shape {
		$minX = (int) min($min->x, $max->x);
		$minY = (int) min($min->y, $max->y);
		$minZ = (int) min($min->z, $max->z);
		$maxX = (int) max($min->x, $max->x);
		$maxY = (int) max($min->y, $max->y);
		$maxZ = (int) max($min->z, $max->z);

		$center = new Vector3(($maxX + $minX) / 2, ($maxY + $minY) / 2, ($maxZ + $minZ) / 2);
		$radius = ($maxX - $minX) / 2;
		$height = ($maxY - $minY);
		return new self($center, $radius, $height);
	}

	public static function fromAABB(AxisAlignedBB $alignedBB) : Shape {
		$minX = (int) min($alignedBB->minX, $alignedBB->maxX);
		$minY = (int) min($alignedBB->minY, $alignedBB->maxY);
		$minZ = (int) min($alignedBB->minZ, $alignedBB->maxZ);
		$maxX = (int) max($alignedBB->minX, $alignedBB->maxX);
		$maxY = (int) max($alignedBB->minY, $alignedBB->maxY);
		$maxZ = (int) max($alignedBB->minZ, $alignedBB->maxZ);

		$center = new Vector3(($maxX + $minX) / 2, ($maxY + $minY) / 2, ($maxZ + $minZ) / 2);
		$radius = ($maxX - $minX) / 2;
		$height = ($maxY - $minY);
		return new self($center, $radius, $height);
	}

	public function copy(World $world, Vector3 $relativeCenter) : void{
		$absoluteBasePos = $this->relativeCenter->subtractVector($relativeCenter->floor());

		$relativeMaximums = $this->relativeCenter->add($this->radius, $this->height, $this->radius);
		$xCap = $relativeMaximums->x;
		$yCap = $relativeMaximums->y;
		$zCap = $relativeMaximums->z;

		$relativeMinimums = $this->relativeCenter->subtract($this->radius, 0, $this->radius);
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
					if((new Vector2($this->relativeCenter->x, $this->relativeCenter->z))->distance(new Vector2($x, $z)) <= $this->radius && $subChunkExplorer->moveTo($ax, $ay, $az) !== SubChunkExplorerStatus::INVALID) {
						$blocks[World::blockHash($ax, $ay, $az)] = $subChunkExplorer->currentSubChunk?->getFullBlock($ax & SubChunk::COORD_MASK, $ay & SubChunk::COORD_MASK, $az & SubChunk::COORD_MASK);
					}
				}
			}
		}

		$this->clipboard->setFullBlocks($blocks)->setRelativePos($absoluteBasePos)->setCapVector($relativeMaximums);
	}

	public function paste(World $world, Vector3 $relativeCenter, bool $replaceAir, ?PromiseResolver $resolver = null) : Promise{
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
			$relativeCenter,
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
