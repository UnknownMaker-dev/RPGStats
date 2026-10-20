<?php

namespace RPGStats\Task;

use pocketmine\scheduler\Task;
use RPGStats\RPGStatsPlugin;

/**
 * Cobra a mana dos modos ligados.
 *
 * O intervalo é o "ciclo" que o `custo-mana-por-ciclo` do modos.yml usa como unidade:
 * mudar o intervalo no config muda o custo real por segundo de todos os modos.
 */
final class ModeTickTask extends Task
{
    public function __construct(private RPGStatsPlugin $plugin) {}

    public function onRun(): void
    {
        $this->plugin->getModeManager()->tick();
    }
}
