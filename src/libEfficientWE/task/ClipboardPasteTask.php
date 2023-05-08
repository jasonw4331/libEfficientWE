<?php

declare(strict_types=1);

namespace libEfficientWE\task;

use Closure;
use pocketmine\block\VanillaBlocks;
use pocketmine\math\Vector3;
use pocketmine\scheduler\AsyncTask;
use pocketmine\utils\AssumptionFailedError;
use pocketmine\world\format\Chunk;
use pocketmine\world\format\io\FastChunkSerializer;
use pocketmine\world\format\SubChunk;
use pocketmine\world\generator\ThreadLocalGeneratorContext;
use pocketmine\world\SimpleChunkManager;
use pocketmine\world\utils\SubChunkExplorer;
use pocketmine\world\utils\SubChunkExplorerStatus;
use function array_map;
use function floor;
use function igbinary_serialize;
use function igbinary_unserialize;
use function morton2d_decode;
use function morton3d_decode;

/**
 * @internal
 * @phpstan-type OnCompletion \Closure(array<int, Chunk> $chunks, int $changedBlocks) : void
 */
final class ClipboardPasteTask extends AsyncTask{
	private const TLS_KEY_ON_COMPLETION = "onCompletion";

	protected string $chunks;

	protected string $worldPos;

	private int $changedBlocks = 0;

	/**
	 * @phpstan-param array<int, Chunk|null> $chunks
	 * @phpstan-param array<int, int|null>   $blockStateIds
	 * @phpstan-param OnCompletion           $onCompletion
	 */
	public function __construct(
		protected int $worldId,
		array $chunks,
		Vector3 $worldPos,
		protected array $blockStateIds,
		protected bool $replaceAir,
		Closure $onCompletion
	){
		$this->chunks = igbinary_serialize(array_map(
			fn(?Chunk $c) => $c !== null ? FastChunkSerializer::serializeTerrain($c) : null,
			$chunks
		)) ?? throw new AssumptionFailedError("igbinary_serialize() returned null");

		$this->worldPos = igbinary_serialize($worldPos) ?? throw new AssumptionFailedError("igbinary_serialize() returned null");

		$this->storeLocal(self::TLS_KEY_ON_COMPLETION, $onCompletion);
	}

	public function onRun() : void{
		$context = ThreadLocalGeneratorContext::fetch($this->worldId);
		if($context === null){
			throw new AssumptionFailedError("Generator context should have been initialized before any Task execution");
		}
		$manager = new SimpleChunkManager($context->getWorldMinY(), $context->getWorldMaxY());

		/** @var string[] $serialChunks */
		$serialChunks = igbinary_unserialize($this->chunks);
		/** @var array<int, Chunk> $chunks */
		$chunks = array_map(
			fn(?string $serialized) => $serialized !== null ? FastChunkSerializer::deserializeTerrain($serialized) : null,
			$serialChunks
		);

		/** @var Vector3 $worldPos */
		$worldPos = igbinary_unserialize($this->worldPos);

		foreach($chunks as $chunkHash => $c){
			[$chunkX, $chunkZ] = morton2d_decode($chunkHash);
			$this->prepChunkManager($manager, $chunkX, $chunkZ, $c);
		}

		$iterator = new SubChunkExplorer($manager);

		foreach($this->blockStateIds as $mortonCode => $blockStateId){
			[$x, $y, $z] = morton3d_decode($mortonCode);
			$ax = (int) floor($worldPos->x + $x);
			$ay = (int) floor($worldPos->y + $y);
			$az = (int) floor($worldPos->z + $z);
			if($blockStateId !== null){
				// make sure the chunk/block exists on this thread
				if($iterator->moveTo($ax, $ay, $az) !== SubChunkExplorerStatus::INVALID){
					// if replaceAir is false, do not set blocks where the clipboard has air
					if($this->replaceAir || $blockStateId !== VanillaBlocks::AIR()->getStateId()){
						$iterator->currentSubChunk?->setBlockStateId($ax & SubChunk::COORD_MASK, $ay & SubChunk::COORD_MASK, $az & SubChunk::COORD_MASK, $blockStateId);
						++$this->changedBlocks;
					}
				}
			}
		}

		$serialChunks = [];
		foreach($chunks as $chunkHash => $c){
			[$chunkX, $chunkZ] = morton2d_decode($chunkHash);
			$chunk = $manager->getChunk($chunkX, $chunkZ) ?? throw new AssumptionFailedError("Chunk should exist");
			$serialChunks[$chunkHash] = $c->isTerrainDirty() ? FastChunkSerializer::serializeTerrain($chunk) : null;
		}
		$this->chunks = igbinary_serialize($serialChunks) ?? throw new AssumptionFailedError("igbinary_serialize() returned null");
	}

	private function prepChunkManager(SimpleChunkManager $manager, int $chunkX, int $chunkZ, ?Chunk &$chunk) : void{
		$manager->setChunk($chunkX, $chunkZ, $chunk ?? new Chunk([], false));
		if($chunk === null){
			$chunk = $manager->getChunk($chunkX, $chunkZ);
			if($chunk === null){
				throw new AssumptionFailedError("We just set this chunk, so it must exist");
			}
			$chunk->setTerrainDirtyFlag(Chunk::DIRTY_FLAG_BLOCKS, true);
		}
	}

	public function onCompletion() : void{
		/**
		 * @var Closure              $onCompletion
		 * @phpstan-var OnCompletion $onCompletion
		 */
		$onCompletion = $this->fetchLocal(self::TLS_KEY_ON_COMPLETION);

		/**
		 * @var string[]|null[]                 $serialChunks
		 * @phpstan-var array<int, string|null> $serialChunks
		 */
		$serialChunks = igbinary_unserialize($this->chunks);
		$chunks = [];
		foreach($serialChunks as $chunkHash => $c){
			if($c !== null){
				$chunks[$chunkHash] = FastChunkSerializer::deserializeTerrain($c);
			}
		}

		$onCompletion($chunks, $this->changedBlocks);
	}
}
