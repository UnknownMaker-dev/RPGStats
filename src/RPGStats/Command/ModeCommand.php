<?php

namespace RPGStats\Command;

use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\player\Player;
use RPGStats\RPGStatsPlugin;

/**
 * /modo — liga, desliga e lista os modos.
 *
 * Chamar o comando com o id de um modo já ligado o desliga. É o comportamento que o
 * jogador espera de uma transformação: o mesmo gesto entra e sai dela.
 */
class ModeCommand extends Command
{
    public function __construct(private RPGStatsPlugin $plugin)
    {
        parent::__construct(
            "modo",
            "Liga e desliga seus modos",
            "/modo [lista|info <id>|<id>|desligar]",
            ["modos", "mode"],
        );

        $this->setPermission("rpgstats.modo");
    }

    public function execute(CommandSender $sender, string $label, array $args): bool
    {
        if (!$this->testPermission($sender)) {
            return false;
        }

        if (!$sender instanceof Player) {
            $sender->sendMessage("§cSó um jogador pode usar modos.");
            return false;
        }

        $sub = strtolower($args[0] ?? "lista");

        return match ($sub) {
            "lista", "list" => $this->showList($sender),
            "info" => $this->showInfo($sender, $args[1] ?? ""),
            "desligar", "off" => $this->turnOff($sender),
            default => $this->toggle($sender, $sub),
        };
    }

    private function showList(Player $player): bool
    {
        $name = $player->getName();
        $modes = $this->plugin->getModeRegistry()->all();

        if ($modes === []) {
            $player->sendMessage("§eNenhum modo configurado no modos.yml.");
            return true;
        }

        $manager = $this->plugin->getDataManager();
        $modeManager = $this->plugin->getModeManager();

        $player->sendMessage("§6─── Modos ───");

        foreach ($modes as $mode) {
            $active = $modeManager->isActive($name, $mode->getId());
            $locked = ($mode->permission !== "" && !$player->hasPermission($mode->permission))
                || $manager->getLevel($name) < $mode->requiredLevel;

            $marker = match (true) {
                $active => "§a▶",
                $locked => "§8✖",
                default => "§7•",
            };

            $line = "{$marker} §f{$mode->name} §8({$mode->getId()})";

            if ($mode->manaPerCycle > 0) {
                $line .= " §8· §b{$mode->manaPerCycle} mana/ciclo";
            }

            if ($locked) {
                $line .= " §8· §cnível {$mode->requiredLevel}";
            }

            $player->sendMessage($line);
        }

        $player->sendMessage("§7Use §f/modo <id>§7 para ligar ou desligar.");

        return true;
    }

    private function showInfo(Player $player, string $id): bool
    {
        $mode = $this->plugin->getModeRegistry()->get($id);

        if ($mode === null) {
            $player->sendMessage("§cEsse modo não existe. Veja /modo lista.");
            return false;
        }

        $player->sendMessage("§6─── {$mode->name} ───");

        if ($mode->description !== "") {
            $player->sendMessage("§7{$mode->description}");
        }

        foreach ($mode->describeBonuses() as $line) {
            $player->sendMessage("  " . $line);
        }

        $player->sendMessage("§7Nível exigido: §f{$mode->requiredLevel}");
        $player->sendMessage("§7Custo: §b{$mode->manaPerCycle}§7 mana por ciclo");
        $player->sendMessage($mode->duration > 0
            ? "§7Duração: §f{$mode->duration}s"
            : "§7Duração: §fenquanto houver mana");

        return true;
    }

    private function toggle(Player $player, string $id): bool
    {
        $modeManager = $this->plugin->getModeManager();

        if ($modeManager->isActive($player->getName(), $id)) {
            $mode = $this->plugin->getModeRegistry()->get($id);
            $modeManager->deactivate($player, $id, "§7{$mode->name} desligado.");

            return true;
        }

        $error = $modeManager->activate($player, $id);

        if ($error !== null) {
            $player->sendMessage($error);
            return false;
        }

        $mode = $this->plugin->getModeRegistry()->get($id);
        $player->sendMessage("§a{$mode->name} ligado!");

        return true;
    }

    private function turnOff(Player $player): bool
    {
        $active = $this->plugin->getModeManager()->getActiveModes($player->getName());

        if ($active === []) {
            $player->sendMessage("§eVocê não está com nenhum modo ligado.");
            return true;
        }

        $this->plugin->getModeManager()->deactivateAll($player);
        $player->sendMessage("§7Todos os modos foram desligados.");

        return true;
    }
}
