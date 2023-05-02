<?php

declare(strict_types=1);

namespace libEfficientWE\shapes;

use libEfficientWE\task\ChunksChangeTask;
use libEfficientWE\utils\Clipboard;
use pocketmine\block\Block;
use pocketmine\block\VanillaBlocks;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Vector3;
use pocketmine\promise\Promise;
use pocketmine\promise\PromiseResolver;
use pocketmine\world\ChunkLoader;
use pocketmine\world\ChunkLockId;
use pocketmine\world\format\Chunk;
use pocketmine\world\World;
use function array_merge;
use function cos;
use function deg2rad;
use function microtime;
use function morton3d_decode;
use function morton3d_encode;
use function sin;

/**
 * An abstract class for polygonal shapes to interact with the world using {@link ChunksChangeTask} classes.
 *
 * @internal
 * @phpstan-import-type ChunkPosHash from World
 * @phpstan-import-type BlockPosHash from World
 * @phpstan-type promiseReturn array{"chunks": Chunk[], "time": float, "blockCount": int}
 */
abstract class Shape{

	private CONST MORTON_BITS = 2 ** 20; // 2 ^ 20 or 1048576

	protected Clipboard $clipboard;

	protected function __construct(?Clipboard $clipboard = null){
		$this->clipboard = $clipboard ?? new Clipboard();
	}

	/**
	 * @phpstan-return array<BlockPosHash, int|null>
	 */
	public function getClipboardBlocks() : array{
		return $this->clipboard->getFullBlocks();
	}

	abstract public static function fromVector3(Vector3 $min, Vector3 $max) : self;

	abstract public static function fromAABB(AxisAlignedBB $alignedBB) : self;

	public static function canMortonEncode(float $xDiff, float $yDiff, float $zDiff) : bool {
		return $xDiff < self::MORTON_BITS && $yDiff < self::MORTON_BITS && $zDiff < self::MORTON_BITS;
	}

	/**
	 * @phpstan-param PromiseResolver<promiseReturn>|null $resolver
	 * @phpstan-return Promise<promiseReturn>
	 */
	public final function cut(World $world, Vector3 $worldPos, ?PromiseResolver $resolver = null) : Promise{
		$time = microtime(true);
		$resolver ??= new PromiseResolver();

		$this->copy($world, $worldPos);

		$this->set($world, VanillaBlocks::AIR(), true)->onCompletion(
			static fn(array $value) => $resolver->resolve(array_merge($value, ['time' => microtime(true) - $time])),
			static fn() => $resolver->reject()
		);

		return $resolver->getPromise();
	}

	abstract public function copy(World $world, Vector3 $worldPos) : void;

	/**
	 * @phpstan-param PromiseResolver<promiseReturn>|null $resolver
	 * @phpstan-return Promise<promiseReturn>
	 */
	abstract public function paste(World $world, Vector3 $worldPos, bool $replaceAir, ?PromiseResolver $resolver = null) : Promise;

	/**
	 * @phpstan-param PromiseResolver<promiseReturn>|null $resolver
	 * @phpstan-return Promise<promiseReturn>
	 */
	abstract public function set(World $world, Block $block, bool $fill, ?PromiseResolver $resolver = null) : Promise;

	/**
	 * @phpstan-param PromiseResolver<promiseReturn>|null $resolver
	 * @phpstan-return Promise<promiseReturn>
	 */
	abstract public function replace(World $world, Block $find, Block $replace, ?PromiseResolver $resolver = null) : Promise;

	/**
	 * @phpstan-param PromiseResolver<promiseReturn>|null $resolver
	 * @phpstan-return Promise<promiseReturn>
	 */
	public final function rotate(World $world, Vector3 $worldPos, Vector3 $relativeCenter, float $roll, float $yaw, float $pitch, bool $replaceAir, ?PromiseResolver $resolver = null) : Promise{
		$time = microtime(true);
		$resolver ??= new PromiseResolver();

		/** @phpstan-var PromiseResolver<promiseReturn> $totalledResolver */
		$totalledResolver = new PromiseResolver();
		$changedBlocks = 0;

		$this->cut($world, $worldPos)->onCompletion(
			function(array $value) use ($world, $worldPos, $relativeCenter, $roll, $yaw, $pitch, $replaceAir, $totalledResolver, &$changedBlocks) : void{
				['blockCount' => $changedBlocks] = $value;
				$cosYaw = cos(deg2rad($yaw));
				$sinYaw = sin(deg2rad($yaw));
				$cosRoll = cos(deg2rad($roll));
				$sinRoll = sin(deg2rad($roll));
				$cosPitch = cos(deg2rad($pitch));
				$sinPitch = sin(deg2rad($pitch));
				$pos = Vector3::zero();

				$newBlocks = [];
				foreach($this->clipboard->getFullBlocks() as $mortonCode => $block){
					[$x, $y, $z] = morton3d_decode($mortonCode);

					$pos->x = $x - $relativeCenter->x;
					$pos->y = $y - $relativeCenter->y;
					$pos->z = $z - $relativeCenter->z;

					// Apply rotations
					$pos->x *= $cosYaw;
					$pos->x -= $pos->y * $sinYaw;
					$pos->y *= $cosYaw;
					$pos->y += $pos->x * $sinYaw;
					$pos->x *= $cosRoll;
					$pos->x -= $pos->z * $sinRoll;
					$pos->z *= $cosRoll;
					$pos->z += $pos->x * $sinRoll;
					$pos->y *= $cosPitch;
					$pos->y -= $pos->z * $sinPitch;
					$pos->z *= $cosPitch;
					$pos->z += $pos->y * $sinPitch;

					$pos->x += $relativeCenter->x;
					$pos->y += $relativeCenter->y;
					$pos->z += $relativeCenter->z;

					$newBlocks[morton3d_encode((int) $pos->x, (int) $pos->y, (int) $pos->z)] = $block;
				}
				$this->clipboard->setFullBlocks($newBlocks);

				$this->paste($world, $worldPos, $replaceAir, $totalledResolver);
			},
			static fn() => $resolver->reject()
		);
		$totalledResolver->getPromise()->onCompletion(
			static fn(array $value) => $resolver->resolve(array_merge($value, ['time' => microtime(true) - $time, 'blockCount' => $changedBlocks + $value['blockCount']])),
			static fn() => $resolver->reject()
		);

		return $resolver->getPromise();
	}

	/**
	 * @phpstan-param PromiseResolver<promiseReturn>|null $resolver
	 * @phpstan-return Promise<promiseReturn>
	 */
	public final function translate(World $world, Vector3 $worldPos, int $direction, int $offset, bool $replaceAir, ?PromiseResolver $resolver = null) : Promise{
		$time = microtime(true);
		$resolver ??= new PromiseResolver();

		/** @phpstan-var PromiseResolver<promiseReturn> $totalledResolver */
		$totalledResolver = new PromiseResolver();
		$changedBlocks = 0;

		$this->cut($world, $worldPos)->onCompletion(
			function(array $value) use ($world, $worldPos, $direction, $offset, $replaceAir, $totalledResolver, &$changedBlocks) : void{
				['blockCount' => $changedBlocks] = $value;
				$this->paste($world, $worldPos->getSide($direction, $offset), $replaceAir, $totalledResolver);
			},
			static fn() => $resolver->reject()
		);
		$totalledResolver->getPromise()->onCompletion(
			static fn(array $value) => $resolver->resolve(array_merge($value, ['time' => microtime(true) - $time, 'blockCount' => $changedBlocks + $value['blockCount']])),
			static fn() => $resolver->reject()
		);

		return $resolver->getPromise();
	}

	/**
	 * @return array{int, int, ChunkLoader, ChunkLockId, Chunk|null, array<ChunkPosHash, Chunk|null>}
	 */
	protected final function prepWorld(World $world) : array{
		$chunkPopulationLockId = new ChunkLockId();

		$temporaryChunkLoader = new class implements ChunkLoader{
		};

		$worldMax = $this->clipboard->getWorldMax();
		$worldMin = $this->clipboard->getWorldMin();
		$minChunkX = $worldMin->x >> 4;
		$minChunkZ = $worldMin->z >> 4;
		$maxChunkX = ($worldMin->x + $worldMax->x) >> 4;
		$maxChunkZ = ($worldMin->z + $worldMax->z) >> 4;

		// get center of all chunks
		$chunkX = ($minChunkX + $maxChunkX) >> 1;
		$chunkZ = ($minChunkZ + $maxChunkZ) >> 1;

		for($xx = $minChunkX; $xx <= $maxChunkX; ++$xx){
			for($zz = $minChunkZ; $zz <= $maxChunkZ; ++$zz){
				$world->lockChunk($xx, $zz, $chunkPopulationLockId);
				$world->registerChunkLoader($temporaryChunkLoader, $xx, $zz);
			}
		}

		$centerChunk = $world->loadChunk($chunkX, $chunkZ);
		$adjacentChunks = $world->getAdjacentChunks($chunkX, $chunkZ);

		return [$chunkX, $chunkZ, $temporaryChunkLoader, $chunkPopulationLockId, $centerChunk, $adjacentChunks];
	}

	protected final static function resolveWorld(World $world, int $chunkX, int $chunkZ, ChunkLoader $temporaryChunkLoader, ChunkLockId $chunkPopulationLockId) : bool{
		if(!$world->isLoaded()){
			return false;
		}
		$world->unlockChunk($chunkX, $chunkZ, $chunkPopulationLockId);
		$world->unregisterChunkLoader($temporaryChunkLoader, $chunkX, $chunkZ);

		return true;
	}
}
