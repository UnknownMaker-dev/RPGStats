<?php

namespace RPGStats\Task;

use pocketmine\scheduler\Task;
use RPGStats\RPGStatsPlugin;

/** Descarrega no disco, de tempos em tempos, o que mudou desde a última gravação. */
final class AutoSaveTask extends Task
{
    public function __construct(private RPGStatsPlugin $plugin) {}

    public function onRun(): void
    {
        $this->plugin->getDataManager()->flush();
    }
}
