<?php

declare(strict_types=1);

namespace libEfficientWE\task\read;

use Closure;
use libEfficientWE\utils\Clipboard;
use pocketmine\math\Vector3;
use pocketmine\scheduler\AsyncTask;
use pocketmine\utils\AssumptionFailedError;
use pocketmine\world\format\Chunk;
use pocketmine\world\format\io\FastChunkSerializer;
use pocketmine\world\generator\ThreadLocalGeneratorContext;
use pocketmine\world\SimpleChunkManager;
use pocketmine\world\World;
use function array_map;
use function igbinary_serialize;
use function igbinary_unserialize;
use function morton2d_decode;

/**
 * @internal
 * @phpstan-import-type ChunkPosHash from World
 * @phpstan-type OnCompletion \Closure(Clipboard) : void
 */
abstract class ChunksCopyTask extends AsyncTask{
	private const TLS_KEY_ON_COMPLETION = "onCompletion";

	protected string $chunks;

	protected string $worldPos;

	protected string $clipboard;

	/**
	 * @phpstan-param array<int, Chunk|null> $chunks
	 * @phpstan-param OnCompletion           $onCompletion
	 */
	public function __construct(
		protected int $worldId,
		array $chunks,
		Clipboard $clipboard,
		Closure $onCompletion
	){
		$this->chunks = igbinary_serialize(array_map(
			fn(?Chunk $c) => $c !== null ? FastChunkSerializer::serializeTerrain($c) : null,
			$chunks
		)) ?? throw new AssumptionFailedError("igbinary_serialize() returned null");

		$this->clipboard = igbinary_serialize($clipboard) ?? throw new AssumptionFailedError("igbinary_serialize() returned null");

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
		/** @var array<ChunkPosHash, Chunk> $chunks */
		$chunks = array_map(
			fn(?string $serialized) => $serialized !== null ? FastChunkSerializer::deserializeTerrain($serialized) : null,
			$serialChunks
		);
		foreach($chunks as $chunkHash => $c){
			[$chunkX, $chunkZ] = morton2d_decode($chunkHash);
			$this->prepChunkManager($manager, $chunkX, $chunkZ, $c);
		}

		/** @var Clipboard $clipboard */
		$clipboard = igbinary_unserialize($this->clipboard);

		$clipboard->setBlockStateIds($this->readBlocks($manager, $clipboard->getWorldMin(), $clipboard->getWorldMax()));

		$this->clipboard = igbinary_serialize($clipboard) ?? throw new AssumptionFailedError("igbinary_serialize() returned null");
	}

	private function prepChunkManager(SimpleChunkManager $manager, int $chunkX, int $chunkZ, ?Chunk &$chunk) : void{
		$manager->setChunk($chunkX, $chunkZ, $chunk ?? new Chunk([], false));
		if($chunk === null){
			$chunk = $manager->getChunk($chunkX, $chunkZ);
			if($chunk === null){
				throw new AssumptionFailedError("We just set this chunk, so it must exist");
			}
		}
	}

	/**
	 * @phpstan-return array<int, int|null>
	 */
	abstract protected function readBlocks(SimpleChunkManager $manager, Vector3 $worldPos, Vector3 $worldMaxPos) : array;

	public function onCompletion() : void{
		/**
		 * @var Closure              $onCompletion
		 * @phpstan-var OnCompletion $onCompletion
		 */
		$onCompletion = $this->fetchLocal(self::TLS_KEY_ON_COMPLETION);

		/** @var Clipboard $clipboard */
		$clipboard = igbinary_unserialize($this->clipboard);

		$onCompletion($clipboard);
	}
}
