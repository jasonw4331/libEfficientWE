<?php

declare(strict_types=1);

namespace libEfficientWE\shapes;

use libEfficientWE\task\ClipboardPasteTask;
use libEfficientWE\task\read\CuboidCopyTask;
use libEfficientWE\utils\Clipboard;
use pocketmine\block\Block;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Vector3;
use pocketmine\promise\Promise;
use pocketmine\promise\PromiseResolver;
use pocketmine\world\World;
use function array_map;
use function max;
use function microtime;
use function min;

/**
 * A representation of a cuboid shape.
 */
class Cuboid extends Shape{

	private function __construct(protected Vector3 $highCorner){
		parent::__construct(null);
	}

	public function getLowCorner() : Vector3{
		return Vector3::zero();
	}

	public function getHighCorner() : Vector3{
		return $this->highCorner;
	}

	/**
	 * Returns the largest {@link Cuboid} object which fits between the given {@link Vector3} objects.
	 */
	public static function fromVector3(Vector3 $min, Vector3 $max) : self{
		$minX = min($min->x, $max->x);
		$minY = min($min->y, $max->y);
		$minZ = min($min->z, $max->z);
		$maxX = max($min->x, $max->x);
		$maxY = max($min->y, $max->y);
		$maxZ = max($min->z, $max->z);

		if(self::canMortonEncode($maxX - $minX, $maxY - $minY, $maxZ - $minZ)){
			throw new \InvalidArgumentException("All axis lengths must be less than 2^20 blocks");
		}

		return new self(new Vector3($maxX - $minX, $maxY - $minY, $maxZ - $minZ));
	}

	/**
	 * Returns the largest {@link Cuboid} object which fits within the given {@link AxisAlignedBB} object.
	 */
	public static function fromAABB(AxisAlignedBB $alignedBB) : self{
		$minX = min($alignedBB->minX, $alignedBB->maxX);
		$minY = min($alignedBB->minY, $alignedBB->maxY);
		$minZ = min($alignedBB->minZ, $alignedBB->maxZ);
		$maxX = max($alignedBB->minX, $alignedBB->maxX);
		$maxY = max($alignedBB->minY, $alignedBB->maxY);
		$maxZ = max($alignedBB->minZ, $alignedBB->maxZ);

		if(self::canMortonEncode($maxX - $minX, $maxY - $minY, $maxZ - $minZ)){
			throw new \InvalidArgumentException("All axis lengths must be less than 2^20 blocks");
		}

		return new self(new Vector3($maxX - $minX, $maxY - $minY, $maxZ - $minZ));
	}

	public function copy(World $world, Vector3 $worldPos, ?PromiseResolver $resolver = null) : Promise{
		$time = microtime(true);
		$resolver ??= new PromiseResolver();

		[$temporaryChunkLoader, $chunkLockId, $chunks] = $this->prepWorld($world);

		$this->clipboard->setWorldMin($worldPos)->setWorldMax($worldPos->addVector($this->highCorner));

		$world->getServer()->getAsyncPool()->submitTask(new CuboidCopyTask(
			$world->getId(),
			$chunks,
			$this->clipboard,
			function(Clipboard $clipboard) use ($world, $chunks, $temporaryChunkLoader, $chunkLockId, $time, $resolver) : void{
				if(!parent::resolveWorld($world, array_keys($chunks), $temporaryChunkLoader, $chunkLockId)){
					$resolver->reject();
					return;
				}

				$this->clipboard->setFullBlocks($clipboard->getFullBlocks());

				$resolver->resolve([
					'chunks' => $chunks,
					'time' => microtime(true) - $time,
					'blockCount' => count($clipboard->getFullBlocks()),
				]);
			}
		));
		return $resolver->getPromise();
	}

	public function set(World $world, Block $block, bool $fill, ?PromiseResolver $resolver = null) : Promise{
		$time = microtime(true);
		$resolver ??= new PromiseResolver();

		[$temporaryChunkLoader, $chunkLockId, $chunks] = $this->prepWorld($world);

		$fullBlocks = $fill ? $this->clipboard->getFullBlocks() :
			array_filter($this->clipboard->getFullBlocks(), function(int $mortonCode) : bool {
			[$x, $y, $z] = morton3d_decode($mortonCode);
				return $x === 0 || $x === $this->highCorner->x ||
					$y === 0 || $y === $this->highCorner->y ||
					$z === 0 || $z === $this->highCorner->z;
			}, ARRAY_FILTER_USE_KEY);
		$fullBlocks = array_map(static fn(?int $fullBlock) => $block->getFullId(), $fullBlocks);

		$world->getServer()->getAsyncPool()->submitTask(new ClipboardPasteTask(
			$world->getId(),
			$chunks,
			$this->clipboard->getWorldMin(),
			$fullBlocks,
			true,
			static function(array $chunks, int $changedBlocks) use ($world, $temporaryChunkLoader, $chunkLockId, $time, $resolver) : void{
				if(!parent::resolveWorld($world, array_keys($chunks), $temporaryChunkLoader, $chunkLockId)){
					$resolver->reject();
					return;
				}

				$resolver->resolve([
					'chunks' => $chunks,
					'time' => microtime(true) - $time,
					'blockCount' => $changedBlocks,
				]);
			}
		));
		return $resolver->getPromise();
	}
}
