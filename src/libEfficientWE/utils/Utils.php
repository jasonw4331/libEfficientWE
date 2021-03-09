<?php
declare(strict_types=1);
namespace libEfficientWE\utils;

use pocketmine\block\Block;
use pocketmine\block\BlockFactory;
use pocketmine\block\WoodenFence;
use pocketmine\level\Level;

class Utils {

	public static function updateChunks(Level $level, int $minChunkX, int $maxChunkX, int $minChunkZ, int $maxChunkZ) : void {
		for($chunkX = $minChunkX; $chunkX <= $maxChunkX; ++$chunkX) {
			for($chunkZ = $minChunkZ; $chunkZ <= $maxChunkZ; ++$chunkZ) {
				$level->clearChunkCache($chunkX, $chunkZ);
				foreach($level->getChunkLoaders($chunkX, $chunkZ) as $loader) {
					$loader->onChunkChanged($level->getChunk($chunkX, $chunkZ));
				}
			}
		}

		for($chunkX = $minChunkX; $chunkX <= $maxChunkX; ++$chunkX) {
			for($chunkZ = $minChunkZ; $chunkZ <= $maxChunkZ; ++$chunkZ) {
				$level->setChunk($chunkX, $chunkZ, $level->getChunk($chunkX, $chunkZ), false);
			}
		}

	}

	public static function getBlockFromString(string $block) : ?Block {
		$blockdata = explode(":", $block, 2);
		$data = array_map("intval", $blockdata);

		$name = strtolower($blockdata[0]);
		foreach(BlockFactory::getBlockStatesArray() as $bl) {
			if(strtolower($bl->getName()) === $name) {
				return Block::get($bl->getId(), $data[1] ?? $bl->getDamage());
			}
		}

		return Block::get(...$data);
	}
}
